<?php
require_once 'nutrition_store.php';
require_once 'nutrition_estimator.php';
function ff_date(string $date): string {$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid plan date.');return $date;}
function ff_plan_read(mysqli $c,int $uid,string $start): array {
    $end=(new DateTimeImmutable(ff_date($start)))->modify('+6 days')->format('Y-m-d');
    $st=$c->prepare('SELECT mp.id AS plan_id,mp.plan_date,mp.recipe_id,mp.meal_order,mp.portion_servings,mp.meal_type,fl.id AS logged_food_id,r.* FROM meal_plans mp JOIN recipes r ON r.id=mp.recipe_id LEFT JOIN food_logs fl ON fl.id=mp.logged_food_id AND fl.user_id=mp.user_id WHERE mp.user_id=? AND mp.plan_date BETWEEN ? AND ? ORDER BY mp.plan_date,mp.meal_order');$st->bind_param('iss',$uid,$start,$end);$st->execute();$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();$plans=[];
    foreach($rows as $r){$r['ingredients']=json_decode($r['ingredients']??'[]',true)?:preg_split('/\r?\n/',$r['ingredients']??'',-1,PREG_SPLIT_NO_EMPTY);$plans[$r['plan_date']][]=$r;}return $plans;
}
function ff_plan_write(mysqli $c,int $uid,string $date,array $meals): void {
    $st=$c->prepare('DELETE FROM meal_plans WHERE user_id=? AND plan_date=?');$st->bind_param('is',$uid,$date);$st->execute();$st->close();
    $st=$c->prepare('INSERT INTO meal_plans(user_id,plan_date,recipe_id,meal_order,portion_servings,meal_type) VALUES(?,?,?,?,?,?)');
    foreach($meals as $i=>$r){$rid=(int)($r['recipe_id']??$r['id']);$order=$i+1;$portion=(float)($r['portion_servings']??1);$type=$r['meal_type']??'lunch';$st->bind_param('isiids',$uid,$date,$rid,$order,$portion,$type);$st->execute();}$st->close();
}
function ff_planner_action(mysqli $c,int $uid,array $in): void {
    $action=$in['action']??'';
    if(!in_array($action,['plan_generate','plan_swap','plan_log','nutrition_estimate','recipe_nutrition_save'],true))return;
    try{
        if($action==='nutrition_estimate')json_response(['success'=>true,'estimate'=>ff_estimate(is_array($in['ingredients']??null)?$in['ingredients']:[],(float)($in['servings']??1))]);
        if($action==='recipe_nutrition_save'){
            $id=(int)($in['id']??0);$r=recipeRow($c,$id);if(!$r||(int)$r['user_id']!==$uid)json_response(['success'=>false,'message'=>'You can edit your own recipes only.'],403);
            foreach(['calories','protein','carbs','fat','fiber'] as $k){$v=$in[$k.'_per_serving']??null;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>10000)throw new InvalidArgumentException('Enter valid per-serving nutrition.');}
            $serv=(float)($in['servings']??0);if($serv<=0||$serv>1000)throw new InvalidArgumentException('Enter the number of servings the whole recipe makes.');
            $category=$in['meal_category']??'any';if(!in_array($category,['any','breakfast','lunch','dinner','snack'],true))throw new InvalidArgumentException('Invalid meal category.');
            $types=array_key_exists('meal_types',$in)?ff_meal_types_clean($in['meal_types']):ff_meal_types_clean($r['meal_types']??'');if(array_key_exists('meal_types',$in))$category=$types?$types[0]:'any';elseif($types&&$types[0]!==$category)$types=$category==='any'?[]:array_values(array_unique(array_merge([$category],$types)));$typeList=$types?implode(',',$types):null;
            $weight=array_key_exists('total_cooked_weight_g',$in)?ff_cooked_weight($in['total_cooked_weight_g']):(isset($r['total_cooked_weight_g'])?(float)$r['total_cooked_weight_g']:null);
            $st=$c->prepare("UPDATE recipes SET servings=?,calories_per_serving=?,protein_per_serving=?,carbs_per_serving=?,fat_per_serving=?,fiber_per_serving=?,meal_category=?,meal_types=?,total_cooked_weight_g=?,nutrition_status='provided',nutrition_notes='Nutrition reviewed by recipe owner.' WHERE id=? AND user_id=?");$st->bind_param('ddddddssdii',$serv,$in['calories_per_serving'],$in['protein_per_serving'],$in['carbs_per_serving'],$in['fat_per_serving'],$in['fiber_per_serving'],$category,$typeList,$weight,$id,$uid);$st->execute();$st->close();json_response(['success'=>true]);
        }
        $c->begin_transaction();
        // Serialize mutations for one user. Concurrent generate/log requests stay atomic.
        $st=$c->prepare('SELECT user_id FROM user_profiles WHERE user_id=? FOR UPDATE');$st->bind_param('i',$uid);$st->execute();$st->get_result();$st->close();
        $p=ff_profile($c,$uid);$recipes=recipesFor($c,$uid);
        if($action==='plan_log'){
            $id=(int)($in['plan_id']??0);$st=$c->prepare('SELECT mp.*,r.recipe_name,r.calories_per_serving,r.protein_per_serving,r.carbs_per_serving,r.fat_per_serving,r.fiber_per_serving,fl.id AS existing_log FROM meal_plans mp JOIN recipes r ON r.id=mp.recipe_id LEFT JOIN food_logs fl ON fl.id=mp.logged_food_id AND fl.user_id=mp.user_id WHERE mp.id=? AND mp.user_id=? FOR UPDATE');$st->bind_param('ii',$id,$uid);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();if(!$r)throw new InvalidArgumentException('Planned meal unavailable.');
            if(!$r['existing_log']){$st=$c->prepare("INSERT INTO food_logs(user_id,food_date,meal_type,food_name,servings,calories,protein_g,carbs_g,fat_g,fiber_g,recipe_id,source) VALUES(?,?,?,?,?,?,?,?,?,?,?,'meal_plan')");$st->bind_param('isssddddddi',$uid,$r['plan_date'],$r['meal_type'],$r['recipe_name'],$r['portion_servings'],$r['calories_per_serving'],$r['protein_per_serving'],$r['carbs_per_serving'],$r['fat_per_serving'],$r['fiber_per_serving'],$r['recipe_id']);$st->execute();$foodId=$c->insert_id;$st->close();$st=$c->prepare('UPDATE meal_plans SET logged_food_id=? WHERE id=? AND user_id=?');$st->bind_param('iii',$foodId,$id,$uid);$st->execute();$st->close();}
            $c->commit();json_response(['success'=>true]);
        }
        if($action==='plan_generate'){
            $start=ff_date((string)($in['start_date']??date('Y-m-d')));$warnings=[];
            for($i=0;$i<7;$i++){$date=(new DateTimeImmutable($start))->modify("+$i days")->format('Y-m-d');$meals=ff_plan_day($recipes,$p,$i);ff_plan_write($c,$uid,$date,$meals);$warnings[$date]=ff_plan_warning($meals,$p);}
            $c->commit();json_response(['success'=>true,'warnings'=>$warnings]);
        }
        $date=ff_date((string)($in['plan_date']??''));$plans=ff_plan_read($c,$uid,$date);$meals=$plans[$date]??[];$id=(int)($in['plan_id']??0);$rid=(int)($in['recipe_id']??0);$found=false;$replacement=null;
        foreach($recipes as $r)if((int)$r['id']===$rid&&ff_eligible($r,$p))$replacement=$r;
        if(!$replacement)throw new InvalidArgumentException('Choose an eligible recipe with complete nutrition.');
        foreach($meals as &$r)if((int)$r['plan_id']===$id){if($r['logged_food_id'])throw new InvalidArgumentException('This meal is already logged. Delete its food log before swapping.');$r=array_merge($replacement,['recipe_id'=>$rid,'meal_type'=>$r['meal_type'],'portion_servings'=>$r['portion_servings'],'plan_id'=>$id,'logged_food_id'=>null]);$found=true;}unset($r);
        if(!$found)throw new InvalidArgumentException('Planned meal unavailable.');
        // Keep logged portions fixed; optimize only the unlogged meals.
        $remaining=$p;foreach($meals as $r)if($r['logged_food_id'])foreach(['calories'=>'calorie','protein'=>'protein','carbs'=>'carbs','fat'=>'fat'] as $k=>$f)$remaining['daily_'.$f.'_goal']=max(0,(float)$remaining['daily_'.$f.'_goal']-(float)$r[$k.'_per_serving']*(float)$r['portion_servings']);
        $unlogged=ff_fit_portions(array_values(array_filter($meals,fn($r)=>!$r['logged_food_id'])),$remaining);$j=0;
        $st=$c->prepare('UPDATE meal_plans SET recipe_id=?,portion_servings=? WHERE id=? AND user_id=?');foreach($meals as &$r)if(!$r['logged_food_id']){$r=$unlogged[$j++];$planId=(int)$r['plan_id'];$recipeId=(int)$r['recipe_id'];$portion=(float)$r['portion_servings'];$st->bind_param('idii',$recipeId,$portion,$planId,$uid);$st->execute();}unset($r);$st->close();$c->commit();json_response(['success'=>true,'warning'=>ff_plan_warning($meals,$p)]);
    }catch(InvalidArgumentException $e){$c->rollback();json_response(['success'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){$c->rollback();error_log('FitFuel planner: '.$e->getMessage());json_response(['success'=>false,'message'=>'Unable to save your meal plan. Please try again.'],500);}
}
