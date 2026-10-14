<?php
require __DIR__.'/../API/nutrition_core.php';require __DIR__.'/../API/nutrition_estimator.php';require __DIR__.'/../API/recipe_importer.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$p=['sex'=>'male','date_of_birth'=>'1977-02-01','height_inches'=>70,'current_weight'=>223,'goal_weight'=>185,'planner_activity'=>'moderate','planner_goal'=>'fat_loss','meals_per_day'=>4];
$t=ff_targets($p,'2026-10-14');check($t['daily_protein_goal']===178.0,'protein target');$lighter=ff_targets(array_merge($p,['current_weight'=>215]),'2026-10-14');check($lighter['daily_calorie_goal']<$t['daily_calorie_goal'],'calories decrease with weigh-in');check($lighter['daily_protein_goal']<$t['daily_protein_goal'],'protein adjusts');
try{ff_targets(array_merge($p,['date_of_birth'=>'2015-01-01']),'2026-10-14');throw new RuntimeException('child accepted');}catch(InvalidArgumentException $e){}
try{ff_targets(array_merge($p,['date_of_birth'=>'2026-02-30']),'2026-10-14');throw new RuntimeException('invalid date accepted');}catch(InvalidArgumentException $e){}
$recipes=json_decode(file_get_contents(__DIR__.'/../starter-recipes.json'),true);foreach($recipes as $i=>&$r)$r['id']=$i+1;unset($r);check(count($recipes)===18,'18 starters');
foreach($recipes as $r){check(ff_complete($r)&&ff_high_protein($r),$r['recipe_name'].' is eligible');$estimate=ff_estimate($r['ingredients'],$r['servings']);check($estimate['nutrition_status']==='estimated','starter estimate complete');check(abs($estimate['calories_per_serving']-$r['calories_per_serving'])<.11,'starter ingredient macros agree');}
$p=array_merge($p,$t);for($day=0;$day<7;$day++){$plan=ff_plan_day($recipes,$p,$day);check(count($plan)===4,'four meals');foreach($plan as $r)check($r['portion_servings']>=.5&&$r['portion_servings']<=2.5,'portion bounds');$totals=ff_totals($plan);check(abs($totals['calories']/$t['daily_calorie_goal']-1)<.1,'calorie fit');check(abs($totals['protein']/$t['daily_protein_goal']-1)<.1,'protein fit');}
$p['excluded_foods']='chicken, shrimp';$plan=ff_plan_day($recipes,$p);foreach($plan as $r)check(ff_eligible($r,$p),'exclusions applied');
foreach([2,3,5,6] as $n){$p['meals_per_day']=$n;check(count(ff_plan_day($recipes,$p))===$n,'meal count');}
check(!ff_eligible(array_merge($recipes[0],['nutrition_status'=>'incomplete']),[]),'partial excluded');
$bad=ff_estimate(['200 g chicken breast','some special sauce'],2);check($bad['nutrition_status']==='incomplete'&&count($bad['unmatched_ingredients'])===1,'unknown amounts flagged');
$good=ff_estimate(['200 g chicken breast','½ cup rolled oats'],2);check($good['nutrition_status']==='estimated','unicode estimate');check($good['protein_per_serving']>25,'estimated protein');
$provided=ff_enrich_recipe(['calories_per_serving'=>500,'protein_per_serving'=>35,'ingredients'=>['unknown']]);check($provided['calories_per_serving']===500,'provided nutrition preserved');
$caption="Chicken bowl\nIngredients\n800 g chicken breast\n300 g dry rice\nInstructions\nCook chicken and rice.\nNutrition\nServings: 4\nCalories per serving: 500\nProtein per serving: 50\nCarbs per serving: 60\nFat per serving: 7";
$r=recipe_import_from_text($caption);check($r['servings']===4.0&&count($r['ingredients'])===2&&$r['protein_per_serving']===50.0,'caption nutrition and yield preserved');check(!str_contains($r['instructions'],'Nutrition'),'nutrition removed from directions');
$impossible=ff_plan_day([$recipes[0]],array_merge($p,['daily_protein_goal'=>1000]));check(ff_plan_warning($impossible,array_merge($p,['daily_protein_goal'=>1000]))!=='','impossible targets reported');
echo "Nutrition core: all checks passed.\n";
