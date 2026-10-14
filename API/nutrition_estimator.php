<?php
// Offline generic estimates. Unknown ingredients or quantities are NEVER treated as zero.
function ff_fraction(string $s): float {
    $s=strtr($s,['½'=>' 1/2','¼'=>' 1/4','¾'=>' 3/4','⅓'=>' 1/3','⅔'=>' 2/3','⅛'=>' 1/8']);$s=trim($s);
    if(preg_match('/^(?:(\d+)\s+)?(\d+)\/(\d+)$/',$s,$m))return (float)($m[1]??0)+(float)$m[2]/max(1,(float)$m[3]);
    return is_numeric($s)?(float)$s:0;
}
function ff_ingredient_estimate(string $line,array $catalog): ?array {
    $line=trim(preg_replace('/^[•*\-\s]+/u','',$line));
    if(preg_match('/^(salt|black pepper)(?: to taste)?$/i',$line))return [0,0,0,0,0];
    if(!preg_match('/^((?:\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?\s*[½¼¾⅓⅔⅛]?|[½¼¾⅓⅔⅛]))\s*(kg|g|grams?|lb|lbs|pounds?|oz|ounces?|cups?|tbsp|tablespoons?|tsp|teaspoons?|ml|l|slices?|cloves?)?\s+(.+)$/iu',$line,$m))return null;
    $amount=ff_fraction($m[1]);$unit=strtolower($m[2]??'');$name=strtolower(trim($m[3]));
    // Only exact canonical foods and explicit, conservative aliases.
    $aliases=['chicken breasts'=>'chicken breast','skinless chicken breast'=>'chicken breast','greek yogurt'=>'nonfat greek yogurt','fat free greek yogurt'=>'nonfat greek yogurt','cottage cheese'=>'low fat cottage cheese','oats'=>'rolled oats','eggs'=>'egg','egg white'=>'egg whites','skim milk'=>'nonfat milk','potatoes'=>'potato','sweet potatoes'=>'sweet potato','bell peppers'=>'bell pepper','carrots'=>'carrot','onions'=>'onion','bananas'=>'banana','apples'=>'apple','rice, dry'=>'dry rice','rice, cooked'=>'cooked rice','pasta, dry'=>'dry pasta','quinoa, dry'=>'dry quinoa','tuna, drained'=>'canned tuna drained','shrimp'=>'shrimp peeled','garlic cloves'=>'garlic'];
    $name=$aliases[$name]??$name;$food=null;foreach($catalog as $f)if($f['name']===$name){$food=$f;break;}if(!$food||$amount<=0)return null;
    $weights=['kg'=>1000,'g'=>1,'gram'=>1,'grams'=>1,'lb'=>453.59237,'lbs'=>453.59237,'pound'=>453.59237,'pounds'=>453.59237,'oz'=>28.3495,'ounce'=>28.3495,'ounces'=>28.3495];
    if(isset($weights[$unit]))$grams=$amount*$weights[$unit];
    elseif(in_array($unit,['cup','cups','tbsp','tablespoon','tablespoons','tsp','teaspoon','teaspoons','ml','l'],true)&&$food['cup_grams']){$factor=in_array($unit,['tbsp','tablespoon','tablespoons'],true)?1/16:(in_array($unit,['tsp','teaspoon','teaspoons'],true)?1/48:($unit==='ml'?1/236.588:($unit==='l'?1000/236.588:1)));$grams=$amount*$factor*$food['cup_grams'];}
    elseif(in_array($unit,['','slice','slices','clove','cloves'],true)&&$food['each_grams'])$grams=$amount*$food['each_grams'];else return null;
    return array_map(fn($n)=>$n*$grams/100,$food['values']);
}
function ff_estimate(array $ingredients,float $servings): array {
    if($servings<=0||$servings>1000)throw new InvalidArgumentException('Enter a positive recipe serving count.');
    $catalog=json_decode(file_get_contents(__DIR__.'/../nutrition-foods.json'),true);$totals=[0,0,0,0,0];$unknown=[];
    foreach($ingredients as $line){$n=ff_ingredient_estimate((string)$line,$catalog);if($n===null){$unknown[]=$line;continue;}foreach($n as $i=>$v)$totals[$i]+=$v;}
    $out=['nutrition_status'=>$unknown||!$ingredients?'incomplete':'estimated','nutrition_notes'=>$unknown?'Needs review: '.implode('; ',$unknown):'Estimated from generic ingredients; verify brands, raw/cooked weights, and serving count.','unmatched_ingredients'=>$unknown];
    foreach(['calories','protein','carbs','fat','fiber'] as $i=>$k)$out[$k.'_per_serving']=round($totals[$i]/$servings,1);
    return $out;
}
function ff_enrich_recipe(array $r): array {
    if((float)($r['calories_per_serving']??0)>0){$r['nutrition_status']=$r['nutrition_status']??'provided';return $r;}
    $ingredients=$r['ingredients']??[];if(is_string($ingredients))$ingredients=json_decode($ingredients,true)?:preg_split('/\r?\n/',$ingredients);
    $estimate=ff_estimate($ingredients,max(.1,(float)($r['servings']??1)));
    return array_merge($r,$estimate);
}
