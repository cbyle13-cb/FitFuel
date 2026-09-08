<?php

class RecipeImportException extends RuntimeException {
    public int $httpStatus;
    public function __construct(string $message, int $httpStatus = 422) { parent::__construct($message); $this->httpStatus = $httpStatus; }
}

function recipe_import_public_ips(string $host): array {
    if ($host === 'localhost' || str_ends_with($host, '.local')) return [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
    $ips = [];
    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? '';
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [];
        $ips[] = $ip;
    }
    return array_values(array_unique($ips));
}

function recipe_import_validate_url(string $url): array {
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) throw new RecipeImportException('Enter a valid recipe URL.', 400);
    $parts = parse_url($url); $scheme = strtolower((string)($parts['scheme'] ?? '')); $host = strtolower((string)($parts['host'] ?? '')); $port=(int)($parts['port']??($scheme==='https'?443:80));
    if (!in_array($scheme, ['http','https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) throw new RecipeImportException('Only public http/https recipe URLs are supported.', 400);
    if (!in_array($port,[80,443],true)) throw new RecipeImportException('That webpage port is not allowed.',400);
    $ips = recipe_import_public_ips($host);
    if (!$ips) throw new RecipeImportException('That host is not allowed.', 400);
    return [$parts, $ips];
}

function recipe_import_resolve_url(string $base, string $location): string {
    if (filter_var($location, FILTER_VALIDATE_URL)) return $location;
    $parts = parse_url($base);
    if (str_starts_with($location, '//')) return ($parts['scheme'] ?? 'https').':'.$location;
    $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
    if (isset($parts['port'])) $origin .= ':'.$parts['port'];
    if (str_starts_with($location, '/')) return $origin.$location;
    return $origin.preg_replace('~/[^/]*$~', '/', $parts['path'] ?? '/').$location;
}

function recipe_import_fetch_html(string $initialUrl): array {
    $url = $initialUrl;
    for ($redirect=0; $redirect<=4; $redirect++) {
        [$parts,$ips] = recipe_import_validate_url($url); $headers=[];
        $port=(int)($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));$resolvedIp=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'FitFuel Recipe Importer/2.0 (+https://fitfuel.bylerfinancial.com)',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml'],CURLOPT_ENCODING=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[($parts['host'] ?? '').':'.$port.':'.$resolvedIp],CURLOPT_HEADERFUNCTION=>function($curl,string $line)use(&$headers):int{$length=strlen($line);$pieces=explode(':',$line,2);if(count($pieces)===2)$headers[strtolower(trim($pieces[0]))]=trim($pieces[1]);return $length;}]);
        $html=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$ctype=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=curl_error($ch);curl_close($ch);
        if ($status>=300 && $status<400 && isset($headers['location'])) { if($redirect===4)throw new RecipeImportException('That webpage redirected too many times.');$url=recipe_import_resolve_url($url,$headers['location']);continue; }
        if ($html===false || $status<200 || $status>=400) throw new RecipeImportException('FitFuel could not load that webpage.'.($error?' '.$error:''));
        if ($ctype && stripos($ctype,'text/html')===false && stripos($ctype,'application/xhtml')===false) throw new RecipeImportException('The shared URL is not an HTML webpage.');
        if (strlen($html)>5000000) throw new RecipeImportException('That webpage is too large to import safely.',413);
        return [$html,$url];
    }
    throw new RecipeImportException('FitFuel could not load that webpage.');
}

function recipe_import_type_is_recipe($type): bool { if(is_string($type))return strtolower($type)==='recipe'||str_ends_with(strtolower($type),'/recipe');if(is_array($type))foreach($type as$item)if(recipe_import_type_is_recipe($item))return true;return false; }
function recipe_import_find_node($node) { if(!is_array($node))return null;if(isset($node['@type'])&&recipe_import_type_is_recipe($node['@type']))return $node;foreach($node as$child)if(is_array($child)){ $found=recipe_import_find_node($child);if($found)return $found;}return null; }
function recipe_import_instruction_text($value): string { if(is_string($value))return trim(strip_tags($value));if(!is_array($value))return '';$lines=[];foreach($value as$item){if(is_string($item))$text=trim(strip_tags($item));elseif(is_array($item)&&isset($item['text']))$text=trim(strip_tags((string)$item['text']));elseif(is_array($item)&&isset($item['itemListElement']))$text=recipe_import_instruction_text($item['itemListElement']);else$text='';if($text!=='')$lines[]=$text;}return implode("\n",$lines); }
function recipe_import_minutes($value): ?int { if(!is_string($value)||$value==='')return null;try{$d=new DateInterval($value);return($d->d*1440)+($d->h*60)+$d->i;}catch(Throwable $e){return null;} }
function recipe_import_number($value): float { if($value===null||$value==='')return 0;if(is_numeric($value))return(float)$value;return preg_match('/-?\d+(?:\.\d+)?/',(string)$value,$m)?(float)$m[0]:0; }

function recipe_import_from_url(string $url): array {
    [$html,$finalUrl]=recipe_import_fetch_html(trim($url));libxml_use_internal_errors(true);$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NOWARNING|LIBXML_NOERROR);$xpath=new DOMXPath($dom);$node=null;
    foreach($xpath->query('//script[@type="application/ld+json"]')as$script){$raw=trim($script->textContent);if($raw==='')continue;$decoded=json_decode($raw,true);if($decoded===null)continue;$node=recipe_import_find_node($decoded);if($node)break;}
    if(!$node)throw new RecipeImportException('FitFuel could not find structured recipe data on this page. You can still enter it manually.');
    $ingredients=[];foreach(($node['recipeIngredient']??[])as$ingredient){$ingredient=trim(strip_tags((string)$ingredient));if($ingredient!=='')$ingredients[]=$ingredient;}
    $nutrition=is_array($node['nutrition']??null)?$node['nutrition']:[];$servings=recipe_import_number($node['recipeYield']??1);if($servings<=0)$servings=1;
    $image=$node['image']??'';if(is_array($image)){$image=$image['url']??($image[0]??'');if(is_array($image))$image=$image['url']??'';}
    return ['recipe_name'=>trim(strip_tags((string)($node['name']??'')))?:'Imported Recipe','description'=>trim(strip_tags((string)($node['description']??''))),'source_url'=>$finalUrl,'source_type'=>'web','ingredients'=>$ingredients,'instructions'=>recipe_import_instruction_text($node['recipeInstructions']??[]),'servings'=>$servings,'prep_time_minutes'=>recipe_import_minutes($node['prepTime']??null),'cook_time_minutes'=>recipe_import_minutes($node['cookTime']??null),'calories_per_serving'=>recipe_import_number($nutrition['calories']??0),'protein_per_serving'=>recipe_import_number($nutrition['proteinContent']??0),'carbs_per_serving'=>recipe_import_number($nutrition['carbohydrateContent']??0),'fat_per_serving'=>recipe_import_number($nutrition['fatContent']??0),'fiber_per_serving'=>recipe_import_number($nutrition['fiberContent']??0),'image_url'=>(string)$image];
}
