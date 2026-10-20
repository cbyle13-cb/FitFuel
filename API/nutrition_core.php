<?php
// Pure functions shared by the planner, profile, and weigh-in handlers.
function ff_targets(array $p, ?string $today=null): array {
    $now=new DateTimeImmutable($today??date('Y-m-d'));
    $dob=DateTimeImmutable::createFromFormat('!Y-m-d',(string)($p['date_of_birth']??''));
    if(!$dob || $dob->format('Y-m-d')!==($p['date_of_birth']??'') || $dob>$now) throw new InvalidArgumentException('Enter a valid date of birth.');
    $age=$dob->diff($now)->y;
    if($age<13 || $age>120) throw new InvalidArgumentException('Automatic targets are for ages 13–120. Ask a pediatrician to set targets for younger children.');
    // Teens (13–17) are still growing, so a calorie deficit can short-change growth and the adult formula was never
    // validated for them. Use maintenance only (never below it) and about 0.55 g protein per lb (~1.2 g/kg): above the
    // 0.85 g/kg teen DRI and in line with guidance for active teens, without adult cutting-phase protein.
    $teen=$age<18;
    $sex=$p['sex']??'';$h=(float)($p['height_inches']??0);$w=(float)($p['current_weight']??0);
    if(!in_array($sex,['male','female'],true)||$h<36||$h>96||$w<50||$w>1000) throw new InvalidArgumentException('Set sex, height, and current weight in Settings.');
    $factors=['sedentary'=>1.2,'light'=>1.375,'moderate'=>1.55,'active'=>1.725,'extra_active'=>1.9];
    $adjust=['fat_loss'=>-500,'fat_loss_fast'=>-1000,'maintenance'=>0,'muscle_gain'=>250];
    $activity=$p['planner_activity']??'moderate';$goal=$p['planner_goal']??'maintenance';
    if(!isset($factors[$activity],$adjust[$goal])) throw new InvalidArgumentException('Choose an activity level and fitness goal.');
    if($teen)$goal='maintenance';
    $goalWeight=(float)($p['goal_weight']??0);
    if(($goal==='fat_loss'||$goal==='fat_loss_fast')&&$goalWeight>0&&$w<=$goalWeight)$goal='maintenance';
    if($goal==='muscle_gain'&&$goalWeight>0&&$w>=$goalWeight)$goal='maintenance';
    $bmr=round(10*$w*.45359237+6.25*$h*2.54-5*$age+($sex==='male'?5:-161));
    $cal=ceil(max($bmr,round($bmr*$factors[$activity])+$adjust[$goal])/10)*10;
    $protein=round($w*($teen?.55:($goal==='maintenance'?.7:.8)));$fat=round($cal*.25/9);
    return ['daily_calorie_goal'=>$cal,'daily_protein_goal'=>$protein,'daily_carbs_goal'=>max(0,round(($cal-4*$protein-9*$fat)/4)),'daily_fat_goal'=>$fat,'daily_fiber_goal'=>$teen?($sex==='male'?($age<14?31:38):26):($age>50?($sex==='male'?30:21):($sex==='male'?38:25))];
}
function ff_high_protein(array $r): bool {return (float)($r['protein_per_serving']??0)>=25 && (float)($r['protein_per_serving']??0)*4>=.2*(float)($r['calories_per_serving']??0);}
function ff_complete(array $r): bool {
    if(($r['nutrition_status']??'')==='incomplete')return false;
    foreach(['calories','protein','carbs','fat'] as $k){$v=$r[$k.'_per_serving']??null;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)return false;}
    return (float)$r['calories_per_serving']>0&&(float)($r['servings']??1)>0&&(float)$r['protein_per_serving']>0;
}
function ff_eligible(array $r,array $p): bool {
    if(!ff_complete($r)||!ff_high_protein($r))return false;
    $ingredients=$r['ingredients']??[];if(is_string($ingredients))$ingredients=json_decode($ingredients,true)?:[$ingredients];
    $hay=strtolower(($r['recipe_name']??'').' '.implode(' ',$ingredients));
    foreach(preg_split('/[,\n]+/',(string)($p['excluded_foods']??''),-1,PREG_SPLIT_NO_EMPTY) as $food){$food=trim(strtolower($food));if($food!==''&&str_contains($hay,$food))return false;}
    return true;
}
function ff_totals(array $meals): array {
    $out=['calories'=>0.,'protein'=>0.,'carbs'=>0.,'fat'=>0.];
    foreach($meals as $r)foreach($out as $k=>$v)$out[$k]+=(float)$r[$k.'_per_serving']*(float)($r['portion_servings']??1);
    return $out;
}
function ff_score(array $t,array $p): float {
    $score=0;
    foreach(['calories'=>'calorie','protein'=>'protein','carbs'=>'carbs','fat'=>'fat'] as $k=>$field){$goal=(float)($p['daily_'.$field.'_goal']??0);if($goal<=0)continue;$e=($t[$k]-$goal)/$goal;$score+=$e*$e*($k==='protein'?($e<0?8:3):($k==='calories'?6:1));}
    return $score;
}
function ff_fit_portions(array $meals,array $p): array {
    $cal=(float)$p['daily_calorie_goal'];$base=array_sum(array_column($meals,'calories_per_serving'));
    foreach($meals as &$r)$r['portion_servings']=max(.5,min(2.5,round($cal/max(1,$base)*10)/10));unset($r);
    // Coordinate descent in 0.1-serving steps. Bounds keep portions practical.
    for($pass=0;$pass<80;$pass++){
        $best=ff_score(ff_totals($meals),$p);$changed=false;
        foreach($meals as $i=>$r)foreach([-.1,.1] as $delta){$n=round($r['portion_servings']+$delta,1);if($n<.5||$n>2.5)continue;$candidate=$meals;$candidate[$i]['portion_servings']=$n;$score=ff_score(ff_totals($candidate),$p);if($score<$best-1e-9){$meals=$candidate;$best=$score;$changed=true;}}
        if(!$changed)break;
    }
    return $meals;
}
function ff_plan_day(array $recipes,array $p,int $day=0): array {
    if((float)($p['daily_calorie_goal']??0)<=0||(float)($p['daily_protein_goal']??0)<=0)throw new InvalidArgumentException('Save calorie and protein targets in Settings first.');
    $count=(int)($p['meals_per_day']??4);if($count<2||$count>6)throw new InvalidArgumentException('Choose 2–6 meals per day.');
    $pool=array_values(array_filter($recipes,fn($r)=>ff_eligible($r,$p)));
    if(!$pool)throw new InvalidArgumentException('No eligible high-protein recipes. Add complete nutrition or adjust excluded foods.');
    $best=[];$bestScore=INF;$size=count($pool);
    $slots=$count===2?['lunch','dinner']:array_merge(['breakfast','lunch','dinner'],array_fill(0,$count-3,'snack'));
    for($attempt=0;$attempt<96;$attempt++){
        $meals=[];$used=[];$seed=($day+1)*7919+($attempt+1)*104729;
        foreach($slots as $i=>$slot){
            $category=array_values(array_filter($pool,fn($r)=>($r['meal_category']??'any')===$slot||($r['meal_category']??'any')==='any'));
            if(!$category)$category=$pool;
            $unused=array_values(array_filter($category,fn($r)=>!in_array($r['id'],$used,true)));if($unused)$category=$unused;
            $seed=($seed*48271)%2147483647;$r=$category[$seed%count($category)];$r['meal_type']=$slot;$meals[]=$r;$used[]=$r['id'];
        }
        $meals=ff_fit_portions($meals,$p);$score=ff_score(ff_totals($meals),$p);
        if($score<$bestScore){$best=$meals;$bestScore=$score;}
    }
    return $best;
}
function ff_plan_warning(array $meals,array $p): string {
    $t=ff_totals($meals);$miss=[];
    foreach(['calories'=>'calorie','protein'=>'protein','carbs'=>'carbs','fat'=>'fat'] as $k=>$f){$g=(float)($p['daily_'.$f.'_goal']??0);if($g>0&&abs($t[$k]-$g)/$g>.1)$miss[]=$k;}
    return $miss?'More than 10% from target: '.implode(', ',$miss).'. Try swapping a meal or adding recipes with a different macro balance.':'';
}
// Family Menu: one recipe cooked once, portioned for each member's own targets.
const FF_FAMILY_MEALS=['breakfast','lunch','dinner','snack'];
function ff_default_meal_shares(): array {return ['breakfast'=>25,'lunch'=>30,'dinner'=>35,'snack'=>10];}
function ff_validate_meal_shares($shares): array {
    if(!is_array($shares))throw new InvalidArgumentException('Enter a percentage for each meal.');$out=[];
    foreach(FF_FAMILY_MEALS as $m){$v=$shares[$m]??null;if(!is_numeric($v)||(float)$v<0||(float)$v>100)throw new InvalidArgumentException('Meal shares must be 0–100%.');$out[$m]=round((float)$v,1);}
    if(abs(array_sum($out)-100)>.5)throw new InvalidArgumentException('Meal shares must add up to 100%.');
    return $out;
}
function ff_meal_shares(array $p): array {try{return ff_validate_meal_shares($p['meal_shares']??null);}catch(InvalidArgumentException $e){return ff_default_meal_shares();}}
function ff_family_opt_ins(array $p): array {$in=is_array($p['family_meals']??null)?$p['family_meals']:[];$out=[];foreach(FF_FAMILY_MEALS as $m)$out[$m]=array_key_exists($m,$in)?(bool)$in[$m]:true;return $out;}
// $logged: meal_type => macros already logged that day. Remaining macros spread over meal types not yet eaten.
function ff_member_meal_target(array $p,string $type,array $logged=[]): array {
    $shares=ff_meal_shares($p);$eaten=['calories'=>0.,'protein'=>0.,'carbs'=>0.,'fat'=>0.];
    foreach($logged as $m)foreach($eaten as $k=>$v)$eaten[$k]+=(float)($m[$k]??0);
    $open=0.;foreach($shares as $m=>$share)if($m===$type||!isset($logged[$m]))$open+=$share;
    $fraction=$open>0?($shares[$type]??0)/$open:0;$out=[];
    foreach(['calories'=>'calorie','protein'=>'protein','carbs'=>'carbs','fat'=>'fat'] as $k=>$f)$out[$k]=max(0,(float)($p['daily_'.$f.'_goal']??0)-$eaten[$k])*$fraction;
    return $out;
}
// Whole cooked batch weight in grams; blank or 0 clears it.
function ff_cooked_weight($v): ?float {if($v===null||$v===''||(is_numeric($v)&&(float)$v==0))return null;if(!is_numeric($v)||(float)$v<0||(float)$v>50000)throw new InvalidArgumentException('Cooked weight must be 1–50,000 g.');return round((float)$v,1);}
function ff_has_targets(array $p): bool {return (float)($p['daily_calorie_goal']??0)>0&&(float)($p['daily_protein_goal']??0)>0;}
// Best single-meal portion in 0.05-serving steps, 0.25–3 servings. ff_score weights missed protein most.
function ff_fit_member_portion(array $recipe,array $target): float {
    $goal=['daily_calorie_goal'=>$target['calories'],'daily_protein_goal'=>$target['protein'],'daily_carbs_goal'=>$target['carbs'],'daily_fat_goal'=>$target['fat']];
    if($goal['daily_calorie_goal']<=0&&$goal['daily_protein_goal']<=0)return .25;
    $best=1.;$bestScore=INF;
    for($i=5;$i<=60;$i++){$n=$i*.05;$score=ff_score(ff_totals([array_merge($recipe,['portion_servings'=>$n])]),$goal);if($score<$bestScore-1e-9){$best=$n;$bestScore=$score;}}
    return round($best,2);
}
function ff_portion_macros(array $recipe,float $servings): array {
    $w=(float)($recipe['total_cooked_weight_g']??0);$yield=max(.1,(float)($recipe['servings']??1));
    return ['servings'=>round($servings,2),'grams'=>$w>0?round($servings*$w/$yield):null,'calories'=>round((float)$recipe['calories_per_serving']*$servings),'protein'=>round((float)$recipe['protein_per_serving']*$servings,1),'carbs'=>round((float)$recipe['carbs_per_serving']*$servings,1),'fat'=>round((float)$recipe['fat_per_serving']*$servings,1)];
}
// $members: [['profile'=>array,'target'=>array]] for those eating. Lowest total fit score wins; earlier uses this week
// are penalized so the week varies before any recipe repeats.
function ff_family_pick(array $recipes,array $members,string $type,array $used=[]): ?array {
    $best=null;$bestScore=INF;
    foreach($recipes as $r){
        $category=$r['meal_category']??'any';if($category!=='any'&&$category!==$type)continue;
        $score=0.;foreach($members as $m){if(!ff_eligible($r,$m['profile'])){$score=INF;break;}if(!ff_has_targets($m['profile']))continue;$n=ff_fit_member_portion($r,$m['target']);$score+=ff_score(ff_totals([array_merge($r,['portion_servings'=>$n])]),['daily_calorie_goal'=>$m['target']['calories'],'daily_protein_goal'=>$m['target']['protein'],'daily_carbs_goal'=>$m['target']['carbs'],'daily_fat_goal'=>$m['target']['fat']]);}
        if($score===INF)continue;$score+=1000*($used[(int)$r['id']]??0);
        if($score<$bestScore-1e-9){$best=$r;$bestScore=$score;}
    }
    return $best;
}
