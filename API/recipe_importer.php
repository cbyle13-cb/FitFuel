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
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'FitFuel Recipe Importer/2.2 (+https://fitfuel.bylerfinancial.com)',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml'],CURLOPT_ENCODING=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[($parts['host'] ?? '').':'.$port.':'.$resolvedIp],CURLOPT_HEADERFUNCTION=>function($curl,string $line)use(&$headers):int{$length=strlen($line);$pieces=explode(':',$line,2);if(count($pieces)===2)$headers[strtolower(trim($pieces[0]))]=trim($pieces[1]);return $length;}]);
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

function recipe_import_social_noise(string $line): bool {
    $line=trim($line);
    if($line==='')return true;
    if(filter_var($line,FILTER_VALIDATE_URL)||preg_match('/^https?:\/\//i',$line))return true;
    if(preg_match('/^link\s*:\s*https?:\/\//i',$line))return true;
    if(preg_match('/^(?:#\w+\s*)+$/u',$line))return true;
    if(preg_match('/\b(?:follow|comment|dm me|link in bio|save this|share this|tag a friend)\b/i',$line))return true;
    return false;
}

function recipe_import_heading(string $line): string {
    $line=html_entity_decode(strip_tags($line),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $line=trim($line);
    $line=preg_replace('/^[\s#>*_`~\-–—:;,.!✅☑️▪️▫️🍴🥣📝👩‍🍳🧑‍🍳👨‍🍳🔥🛒]+/u','',$line);
    $line=preg_replace('/[\s#>*_`~\-–—:;,.!]+$/u','',$line);
    return trim($line);
}

function recipe_import_likely_ingredient(string $line): bool {
    $line=trim(preg_replace('/^[\s\-•*✅☑️▪️▫️]+/u','',$line));
    if($line===''||recipe_import_social_noise($line))return false;
    if(preg_match('/^(?:\d+\s+)?(?:\d+\s*\/\s*\d+|\d+(?:\.\d+)?|[¼½¾⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞])\s*(?:cups?|c\.?|tbsp\.?|tablespoons?|tsp\.?|teaspoons?|oz\.?|ounces?|lbs?\.?|pounds?|g\b|grams?|kg\b|ml\b|l\b|cloves?|cans?|packages?|pkgs?|slices?|pieces?|large\b|medium\b|small\b)?\s+\S+/iu',$line))return true;
    if(preg_match('/^(?:a\s+)?(?:pinch|dash|handful)\s+(?:of\s+)?\S+/iu',$line))return true;
    if(preg_match('/^(?:salt|pepper|oil|olive oil|cooking spray)\s+(?:to taste|as needed)$/iu',$line))return true;
    return false;
}

function recipe_import_likely_instruction(string $line): bool {
    $line=trim(preg_replace('/^[\s\-•*✅☑️]+/u','',$line));
    if($line===''||recipe_import_social_noise($line))return false;
    if(preg_match('/^(?:step\s*)?\d+[\.)\-:]\s*/iu',$line))return true;
    if(preg_match('/^(?:preheat|heat|add|mix|stir|combine|whisk|blend|cook|bake|roast|grill|smoke|sear|sauté|saute|boil|simmer|pour|place|set|season|toss|fold|slice|dice|chop|cut|remove|rest|serve|top|garnish|air fry|microwave|refrigerate|chill|marinate|transfer|finish)\b/iu',$line))return true;
    if(mb_strlen($line)>70&&preg_match('/\b(?:until|then|minutes?|degrees?|oven|pan|skillet|bowl|heat|cook|bake|mix|stir|serve)\b/iu',$line))return true;
    return false;
}

function recipe_import_from_text(string $rawText, string $sourceUrl=''): array {
    $text=trim(str_replace(["\r\n","\r"],"\n",strip_tags($rawText)));
    if($text==='')throw new RecipeImportException('No recipe caption or text was shared.',400);
    if(strlen($text)>60000)$text=substr($text,0,60000);
    $lines=array_values(array_filter(array_map('trim',explode("\n",$text)),fn($line)=>$line!==''));
    $ingredientHeader='/^(?:ingredients?|what you(?:’|\')?ll need|you(?:’|\')?ll need)$/iu';
    $instructionHeader='/^(?:directions?|instructions?|method|steps?|how to make(?: it)?)$/iu';
    $stopHeader='/^(?:nutrition(?:\s+per\s+serving)?|macros?|source|link)$/iu';
    $section='';$ingredients=[];$steps=[];$title='';
    foreach($lines as$line){
        $heading=recipe_import_heading($line);
        if(preg_match($ingredientHeader,$heading)){$section='ingredients';continue;}
        if(preg_match($instructionHeader,$heading)){$section='instructions';continue;}
        if(preg_match($stopHeader,$heading)||preg_match('/^link\s*:/i',$line)){$section='';continue;}
        if($title===''&&!recipe_import_social_noise($line))$title=recipe_import_heading($line);
        if($section==='ingredients'){
            $item=trim(preg_replace('/^[\s\-•*✅☑️]+/u','',$line));
            if($item!==''&&!recipe_import_social_noise($item))$ingredients[]=$item;
        } elseif($section==='instructions'){
            $step=trim(preg_replace('/^\s*(?:step\s*)?\d+[\.)\-:]?\s*/iu','',$line));
            if($step!==''&&!recipe_import_social_noise($step))$steps[]=$step;
        }
    }

    if(!$ingredients||!$steps){
        $heuristicIngredients=[];$heuristicSteps=[];$instructionStarted=false;$ingredientIndexes=[];
        foreach($lines as$i=>$line){
            $heading=recipe_import_heading($line);
            if($line===$title||preg_match($ingredientHeader,$heading)||preg_match($instructionHeader,$heading)||preg_match($stopHeader,$heading)||recipe_import_social_noise($line))continue;
            $clean=trim(preg_replace('/^[\s\-•*✅☑️▪️▫️]+/u','',$line));
            if(!$instructionStarted&&recipe_import_likely_ingredient($clean)){$heuristicIngredients[]=$clean;$ingredientIndexes[$i]=true;continue;}
            if(recipe_import_likely_instruction($clean)){$instructionStarted=true;$heuristicSteps[]=trim(preg_replace('/^\s*(?:step\s*)?\d+[\.)\-:]?\s*/iu','',$clean));continue;}
            if($instructionStarted&&mb_strlen($clean)>18&&!recipe_import_social_noise($clean))$heuristicSteps[]=$clean;
        }
        if(!$steps&&count($heuristicIngredients)>=2&&!$heuristicSteps&&$ingredientIndexes){
            $lastIngredientIndex=max(array_keys($ingredientIndexes));
            foreach($lines as$i=>$line){
                $heading=recipe_import_heading($line);
                if($i<=$lastIngredientIndex||recipe_import_social_noise($line)||preg_match($ingredientHeader,$heading)||preg_match($instructionHeader,$heading)||preg_match($stopHeader,$heading))continue;
                $clean=trim(preg_replace('/^[\s\-•*✅☑️▪️▫️]+/u','',$line));
                if(mb_strlen($clean)>20&&!recipe_import_likely_ingredient($clean))$heuristicSteps[]=$clean;
            }
        }
        if(!$ingredients&&count($heuristicIngredients)>=2)$ingredients=$heuristicIngredients;
        if(!$steps&&$heuristicSteps)$steps=$heuristicSteps;
    }

    if(!$ingredients||!$steps)throw new RecipeImportException('FitFuel found the shared text, but could not reliably identify both ingredients and instructions. Try a post whose caption includes the recipe, or share the caption text with the link.');
    if($title===''||preg_match($ingredientHeader,recipe_import_heading($title)))$title='Imported Social Recipe';
    return ['recipe_name'=>substr($title,0,255),'description'=>'','source_url'=>$sourceUrl,'source_type'=>'social','ingredients'=>array_slice(array_values(array_unique($ingredients)),0,250),'instructions'=>implode("\n",array_values(array_unique($steps))),'servings'=>1,'prep_time_minutes'=>null,'cook_time_minutes'=>null,'calories_per_serving'=>0,'protein_per_serving'=>0,'carbs_per_serving'=>0,'fat_per_serving'=>0,'fiber_per_serving'=>0,'image_url'=>''];
}

function recipe_import_from_url(string $url): array {
    [$html,$finalUrl]=recipe_import_fetch_html(trim($url));libxml_use_internal_errors(true);$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NOWARNING|LIBXML_NOERROR);$xpath=new DOMXPath($dom);$node=null;
    foreach($xpath->query('//script[@type="application/ld+json"]')as$script){$raw=trim($script->textContent);if($raw==='')continue;$decoded=json_decode($raw,true);if($decoded===null)continue;$node=recipe_import_find_node($decoded);if($node)break;}
    if(!$node)throw new RecipeImportException('FitFuel could not find structured recipe data on this page. You can still enter it manually.');
    $ingredients=[];foreach(($node['recipeIngredient']??[])as$ingredient){$ingredient=trim(strip_tags((string)$ingredient));if($ingredient!=='')$ingredients[]=$ingredient;}
    $nutrition=is_array($node['nutrition']??null)?$node['nutrition']:[];$servings=recipe_import_number($node['recipeYield']??1);if($servings<=0)$servings=1;
    $image=$node['image']??'';if(is_array($image)){$image=$image['url']??($image[0]??'');if(is_array($image))$image=$image['url']??'';}
    return ['recipe_name'=>trim(strip_tags((string)($node['name']??'')))?:'Imported Recipe','description'=>trim(strip_tags((string)($node['description']??''))),'source_url'=>$finalUrl,'source_type'=>'web','ingredients'=>$ingredients,'instructions'=>recipe_import_instruction_text($node['recipeInstructions']??[]),'servings'=>$servings,'prep_time_minutes'=>recipe_import_minutes($node['prepTime']??null),'cook_time_minutes'=>recipe_import_minutes($node['cookTime']??null),'calories_per_serving'=>recipe_import_number($nutrition['calories']??0),'protein_per_serving'=>recipe_import_number($nutrition['proteinContent']??0),'carbs_per_serving'=>recipe_import_number($nutrition['carbohydrateContent']??0),'fat_per_serving'=>recipe_import_number($nutrition['fatContent']??0),'fiber_per_serving'=>recipe_import_number($nutrition['fiberContent']??0),'image_url'=>(string)$image];
}
