<?php
require __DIR__.'/../API/nutrition_core.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$adult=['sex'=>'male','date_of_birth'=>'1976-10-16','height_inches'=>70,'current_weight'=>200,'goal_weight'=>185,'planner_activity'=>'moderate','planner_goal'=>'fat_loss'];
$teenIn=['sex'=>'female','date_of_birth'=>'2010-05-01','height_inches'=>65,'current_weight'=>130,'goal_weight'=>115,'planner_activity'=>'moderate','planner_goal'=>'fat_loss_fast'];
$a=ff_targets($adult,'2026-10-20');$t=ff_targets($teenIn,'2026-10-20');$tm=ff_targets(array_merge($teenIn,['planner_goal'=>'maintenance']),'2026-10-20');
check($t===$tm,'teen goal is forced to maintenance');check($t['daily_protein_goal']===round(130*.55),'teen protein about 0.55 g/lb');check($t['daily_fiber_goal']===26,'teen fiber');
$bmr=round(10*130*.45359237+6.25*65*2.54-5*16-161);check($t['daily_calorie_goal']>=round($bmr*1.55),'teen never below maintenance');
check(ff_targets(array_merge($teenIn,['planner_goal'=>'muscle_gain']),'2026-10-20')===$t,'teen surplus ignored too');
check($a['daily_protein_goal']===160.0,'adult protein unchanged (0.8 g/lb fat loss)');
try{ff_targets(array_merge($teenIn,['date_of_birth'=>'2015-01-01']),'2026-10-20');throw new RuntimeException('child accepted');}catch(InvalidArgumentException $e){}
check(ff_meal_shares([])===ff_default_meal_shares(),'default shares');check(ff_meal_shares(['meal_shares'=>['breakfast'=>200]])===ff_default_meal_shares(),'bad shares fall back');
try{ff_validate_meal_shares(['breakfast'=>50,'lunch'=>50,'dinner'=>50,'snack'=>0]);throw new RuntimeException('shares over 100 accepted');}catch(InvalidArgumentException $e){}
check(ff_validate_meal_shares(['breakfast'=>20,'lunch'=>30,'dinner'=>40,'snack'=>10])['dinner']===40.0,'valid shares');
check(ff_family_opt_ins(['family_meals'=>['lunch'=>false]])===['breakfast'=>true,'lunch'=>false,'dinner'=>true,'snack'=>true],'opt-ins default to eating');
$p=array_merge($adult,$a);$dinner=ff_member_meal_target($p,'dinner');check(abs($dinner['calories']-$a['daily_calorie_goal']*.35)<.01,'dinner share of day');
// Logged breakfast+lunch: dinner gets 35/(35+10) of what is left.
$logged=['breakfast'=>['calories'=>900,'protein'=>60,'carbs'=>80,'fat'=>25],'lunch'=>['calories'=>900,'protein'=>60,'carbs'=>80,'fat'=>25]];$left=ff_member_meal_target($p,'dinner',$logged);
check(abs($left['calories']-($a['daily_calorie_goal']-1800)*35/45)<.01,'remaining calories spread over open meals');check(abs($left['protein']-($a['daily_protein_goal']-120)*35/45)<.01,'remaining protein');
$over=ff_member_meal_target($p,'dinner',['lunch'=>['calories'=>99999,'protein'=>999,'carbs'=>999,'fat'=>999]]);check($over['calories']===0.&&$over['protein']===0.,'never negative');
$recipe=['id'=>1,'recipe_name'=>'Steak bowl','ingredients'=>['600 g steak'],'servings'=>4,'calories_per_serving'=>500,'protein_per_serving'=>45,'carbs_per_serving'=>40,'fat_per_serving'=>15,'fiber_per_serving'=>3,'meal_category'=>'dinner','total_cooked_weight_g'=>1200];
$big=ff_fit_member_portion($recipe,ff_member_meal_target($p,'dinner'));$small=ff_fit_member_portion($recipe,['calories'=>450,'protein'=>25,'carbs'=>60,'fat'=>15]);
check($big>=.25&&$big<=3&&$small>=.25&&$small<=3,'portion bounds');check($big>$small,'bigger targets get bigger portions');
check(ff_fit_member_portion($recipe,['calories'=>20000,'protein'=>2000,'carbs'=>2000,'fat'=>700])===3.,'upper bound');check(ff_fit_member_portion($recipe,['calories'=>0,'protein'=>0,'carbs'=>0,'fat'=>0])===.25,'nothing left means minimum');
// Protein weighted most: with protein short and calories over, the fit leans toward protein.
$lean=ff_fit_member_portion($recipe,['calories'=>500,'protein'=>67.5,'carbs'=>40,'fat'=>15]);check($lean>1,'protein shortfall raises portion');
$m=ff_portion_macros($recipe,1.5);check($m['grams']===450.0&&$m['protein']===67.5&&$m['calories']===750.0,'portion macros and grams');check(ff_portion_macros(array_merge($recipe,['total_cooked_weight_g'=>null]),1)['grams']===null,'no weight, no grams');
check(ff_cooked_weight('')===null&&ff_cooked_weight(0)===null&&ff_cooked_weight('950')===950.0,'cooked weight parsing');try{ff_cooked_weight(-5);throw new RuntimeException('negative weight');}catch(InvalidArgumentException $e){}
$recipes=json_decode(file_get_contents(__DIR__.'/../starter-recipes.json'),true);foreach($recipes as $i=>&$r)$r['id']=$i+1;unset($r);
$teenP=array_merge($teenIn,$t,['excluded_foods'=>'chicken']);$members=[['profile'=>$p,'target'=>ff_member_meal_target($p,'dinner')],['profile'=>$teenP,'target'=>ff_member_meal_target($teenP,'dinner')]];
$used=[];for($i=0;$i<5;$i++){$pick=ff_family_pick($recipes,$members,'dinner',$used);check($pick!==null,'dinner found');check(ff_eligible($pick,$teenP)&&ff_eligible($pick,$p),'everyone eligible');check(in_array($pick['meal_category'],['dinner','any'],true),'category match');$used[$pick['id']]=($used[$pick['id']]??0)+1;}
check(max($used)===1||count($used)===count(array_filter($recipes,fn($r)=>$r['meal_category']==='dinner'&&ff_eligible($r,$teenP))),'no repeats before pool exhausted');
check(ff_family_pick([array_merge($recipes[0],['protein_per_serving'=>6,'calories_per_serving'=>105])],$members,$recipes[0]['meal_category'])===null,'25 g protein rule');
echo "Family core: all checks passed.\n";
