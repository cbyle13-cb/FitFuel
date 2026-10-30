<?php
require_once 'session.php';
require_once 'recipe_images.php';
require_once 'meal_planner.php';
$uid=require_login();ensure_recipe_image_column($conn);ff_ensure_schema($conn);
$fid=family_id_for_user($conn,$uid);
if(!$fid)json_response(['success'=>false,'message'=>'Join or create a family first.'],403);
function fm_recipes(mysqli $c,int $fid): array {
    $st=$c->prepare("SELECT r.* FROM recipes r LEFT JOIN family_members fm ON fm.user_id=r.user_id AND fm.family_id=? WHERE r.hidden_at IS NULL AND (r.user_id IS NULL OR fm.family_id=?) ORDER BY r.recipe_name");$st->bind_param('ii',$fid,$fid);$st->execute();$a=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $out=[];foreach($a as $r){$r['ingredients']=json_decode($r['ingredients']??'[]',true)?:preg_split('/\r\n|\r|\n/',$r['ingredients']??'',-1,PREG_SPLIT_NO_EMPTY);$out[(int)$r['id']]=$r;}return $out;
}
// Profiles stay server-side: responses only carry names and portions, never another member's weight or targets.
function fm_members(mysqli $c,int $fid): array {
    $st=$c->prepare("SELECT u.id,u.first_name,fm.member_role FROM family_members fm JOIN users u ON u.id=fm.user_id WHERE fm.family_id=? AND u.is_active=1 ORDER BY fm.member_role='owner' DESC,u.id");$st->bind_param('i',$fid);$st->execute();$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $out=[];foreach($rows as $m){$m['id']=(int)$m['id'];$m['profile']=ff_profile($c,$m['id']);$m['opt_in']=ff_family_opt_ins($m['profile']);$out[$m['id']]=$m;}return $out;
}
function fm_logged(mysqli $c,int $member,string $date,array &$cache): array {
    $key=$member.'|'.$date;if(isset($cache[$key]))return $cache[$key];
    $st=$c->prepare("SELECT meal_type,COALESCE(SUM(calories*servings),0) calories,COALESCE(SUM(protein_g*servings),0) protein,COALESCE(SUM(carbs_g*servings),0) carbs,COALESCE(SUM(fat_g*servings),0) fat FROM food_logs WHERE user_id=? AND food_date=? GROUP BY meal_type");$st->bind_param('is',$member,$date);$st->execute();$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $out=[];foreach($rows as $r)$out[$r['meal_type']]=$r;return $cache[$key]=$out;
}
function fm_target(mysqli $c,array $m,string $date,string $type,array &$cache): array {return ff_member_meal_target($m['profile'],$type,fm_logged($c,$m['id'],$date,$cache));}
function fm_fit(mysqli $c,array $m,array $recipe,string $date,string $type,array &$cache): float {return ff_has_targets($m['profile'])?ff_fit_member_portion($recipe,fm_target($c,$m,$date,$type,$cache)):1.;}
function fm_meal(mysqli $c,int $fid,int $id,bool $lock=false): array {
    $st=$c->prepare('SELECT * FROM family_meal_plans WHERE id=? AND family_id=? LIMIT 1'.($lock?' FOR UPDATE':''));$st->bind_param('ii',$id,$fid);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();
    if(!$r)throw new InvalidArgumentException('Family meal unavailable.');return $r;
}
function fm_portions(mysqli $c,int $mealId): array {
    $st=$c->prepare('SELECT p.*,fl.id AS live_log FROM family_meal_portions p LEFT JOIN food_logs fl ON fl.id=p.logged_food_id AND fl.user_id=p.user_id WHERE p.family_meal_id=?');$st->bind_param('i',$mealId);$st->execute();$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $out=[];foreach($rows as $r)$out[(int)$r['user_id']]=$r;return $out;
}
// Refit every unlogged, non-overridden portion. Members without a row get one, skipped if they opted out of this meal.
function fm_refit(mysqli $c,array $meal,array $recipe,array $members,array &$cache): void {
    $rows=fm_portions($c,(int)$meal['id']);$mealId=(int)$meal['id'];
    $st=$c->prepare('INSERT INTO family_meal_portions(family_meal_id,user_id,portion_servings,skipped) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE portion_servings=VALUES(portion_servings)');
    foreach($members as $m){$row=$rows[$m['id']]??null;if($row&&$row['live_log'])continue;
        $skip=$row?(int)$row['skipped']:($m['opt_in'][$meal['meal_type']]?0:1);$n=fm_fit($c,$m,$recipe,$meal['plan_date'],$meal['meal_type'],$cache);
        $st->bind_param('iidi',$mealId,$m['id'],$n,$skip);$st->execute();}
    $st->close();
}
function fm_participants(array $members,string $type,mysqli $c,string $date,array &$cache): array {
    $out=[];foreach($members as $m)if($m['opt_in'][$type])$out[]=['profile'=>$m['profile'],'target'=>fm_target($c,$m,$date,$type,$cache)];return $out;
}
function fm_eligible_for(array $recipe,array $members,array $userIds): bool {foreach($userIds as $id)if(isset($members[$id])&&!ff_eligible($recipe,$members[$id]['profile']))return false;return ff_complete($recipe)&&ff_high_protein($recipe);}
function fm_lines(array $recipe,array $rows,array $members,int $uid): array {
    $lines=[];$batch=0.;
    foreach($members as $m){$row=$rows[$m['id']]??null;if(!$row)continue;$skipped=(int)$row['skipped']===1;$n=(float)($row['override_servings']??$row['portion_servings']);if(!$skipped)$batch+=$n;
        $lines[]=array_merge(ff_portion_macros($recipe,$n),['user_id'=>$m['id'],'first_name'=>$m['first_name'],'is_me'=>$m['id']===$uid,'skipped'=>$skipped,'override'=>$row['override_servings']!==null,'logged'=>(bool)($row['live_log']??false),'needs_targets'=>!ff_has_targets($m['profile'])]);}
    return [$lines,$batch];
}
function fm_public_recipe(array $r): array {
    $out=[];foreach(['id','recipe_name','servings','ingredients','image_url','meal_category','meal_types','calories_per_serving','protein_per_serving','carbs_per_serving','fat_per_serving','fiber_per_serving','total_cooked_weight_g'] as $k)$out[$k]=$r[$k]??null;return $out;
}
function fm_week(mysqli $c,int $fid,string $start,array $recipes,array $members,int $uid): array {
    $end=(new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');
    $st=$c->prepare('SELECT * FROM family_meal_plans WHERE family_id=? AND plan_date BETWEEN ? AND ? ORDER BY plan_date,meal_order');$st->bind_param('iss',$fid,$start,$end);$st->execute();$meals=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $week=[];foreach($meals as $meal){$r=$recipes[(int)$meal['recipe_id']]??null;if(!$r)continue;[$lines,$batch]=fm_lines($r,fm_portions($c,(int)$meal['id']),$members,$uid);
        $week[$meal['plan_date']][]=['id'=>(int)$meal['id'],'plan_date'=>$meal['plan_date'],'meal_type'=>$meal['meal_type'],'recipe'=>fm_public_recipe($r),'batch_servings'=>round($batch,2),'batch_multiplier'=>round($batch/max(.1,(float)$r['servings']),3),'portions'=>$lines];}
    return $week;
}
$cache=[];$method=$_SERVER['REQUEST_METHOD'];
try{
    $recipes=fm_recipes($conn,$fid);
    if($method==='GET'){
        $action=$_GET['action']??'family_week';$members=fm_members($conn,$fid);
        if($action==='family_week'){$start=ff_date((string)($_GET['start']??date('Y-m-d')));
            json_response(['success'=>true,'start'=>$start,'members'=>array_values(array_map(fn($m)=>['user_id'=>$m['id'],'first_name'=>$m['first_name'],'is_me'=>$m['id']===$uid],$members)),'can_manage'=>($members[$uid]['member_role']??'')==='owner','week'=>(object)fm_week($conn,$fid,$start,$recipes,$members,$uid)]);}
        if($action==='portion_preview'){
            $r=$recipes[(int)($_GET['recipe_id']??0)]??null;if(!$r)throw new InvalidArgumentException('Recipe unavailable.');
            if(!ff_complete($r))throw new InvalidArgumentException('Add complete nutrition to this recipe first.');
            $type=(string)($_GET['meal_type']??'');if(!in_array($type,FF_FAMILY_MEALS,true)){$types=ff_recipe_meal_types($r);$type=count($types)===count(FF_FAMILY_MEALS)?'dinner':$types[0];}
            $date=ff_date((string)($_GET['date']??date('Y-m-d')));$rows=[];
            foreach($members as $m)$rows[$m['id']]=['portion_servings'=>fm_fit($conn,$m,$r,$date,$type,$cache),'override_servings'=>null,'skipped'=>$m['opt_in'][$type]?0:1,'live_log'=>null];
            [$lines,$batch]=fm_lines($r,$rows,$members,$uid);
            json_response(['success'=>true,'meal_type'=>$type,'date'=>$date,'recipe'=>fm_public_recipe($r),'batch_servings'=>round($batch,2),'batch_multiplier'=>round($batch/max(.1,(float)$r['servings']),3),'portions'=>$lines]);}
        json_response(['success'=>false,'message'=>'Unknown action.'],400);
    }
    if($method!=='POST')json_response(['success'=>false,'message'=>'POST required.'],405);
    $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))$in=[];$action=$in['action']??'';
    if(!in_array($action,['family_plan_generate','family_plan_swap','family_portion_override','family_plan_log'],true))json_response(['success'=>false,'message'=>'Unknown action.'],400);
    $conn->begin_transaction();
    // Serialize family plan mutations so two members generating at once cannot interleave.
    $st=$conn->prepare('SELECT id FROM families WHERE id=? FOR UPDATE');$st->bind_param('i',$fid);$st->execute();$st->get_result();$st->close();
    $members=fm_members($conn,$fid);
    if($action==='family_plan_generate'){
        $start=ff_date((string)($in['start_date']??date('Y-m-d')));$end=(new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');
        // Meals someone already logged stay; everything else in the week is replaced.
        $st=$conn->prepare('SELECT m.id,m.plan_date,m.meal_type,m.recipe_id,MAX(fl.id IS NOT NULL) logged FROM family_meal_plans m LEFT JOIN family_meal_portions p ON p.family_meal_id=m.id LEFT JOIN food_logs fl ON fl.id=p.logged_food_id AND fl.user_id=p.user_id WHERE m.family_id=? AND m.plan_date BETWEEN ? AND ? GROUP BY m.id,m.plan_date,m.meal_type,m.recipe_id');$st->bind_param('iss',$fid,$start,$end);$st->execute();$existing=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
        $kept=[];$used=[];$del=$conn->prepare('DELETE FROM family_meal_portions WHERE family_meal_id=?');$delMeal=$conn->prepare('DELETE FROM family_meal_plans WHERE id=? AND family_id=?');
        foreach($existing as $e){$id=(int)$e['id'];if((int)$e['logged']){$kept[$e['plan_date'].'|'.$e['meal_type']]=true;$used[(int)$e['recipe_id']]=($used[(int)$e['recipe_id']]??0)+1;continue;}$del->bind_param('i',$id);$del->execute();$delMeal->bind_param('ii',$id,$fid);$delMeal->execute();}
        $del->close();$delMeal->close();$warnings=[];
        $ins=$conn->prepare('INSERT INTO family_meal_plans(family_id,plan_date,meal_type,meal_order,recipe_id,created_by) VALUES(?,?,?,?,?,?)');
        for($i=0;$i<7;$i++){$date=(new DateTimeImmutable($start))->modify("+$i days")->format('Y-m-d');
            foreach(FF_FAMILY_MEALS as $order=>$type){if(isset($kept[$date.'|'.$type]))continue;$eaters=fm_participants($members,$type,$conn,$date,$cache);if(!$eaters)continue;
                $r=ff_family_pick($recipes,$eaters,$type,$used);if(!$r){$warnings[$type]="No complete high-protein $type recipe works for everyone's excluded foods.";continue;}
                $used[(int)$r['id']]=($used[(int)$r['id']]??0)+1;$o=$order+1;$rid=(int)$r['id'];$ins->bind_param('issiii',$fid,$date,$type,$o,$rid,$uid);$ins->execute();
                fm_refit($conn,['id'=>$conn->insert_id,'plan_date'=>$date,'meal_type'=>$type],$r,$members,$cache);}}
        $ins->close();$conn->commit();json_response(['success'=>true,'warnings'=>array_values($warnings)]);
    }
    $meal=fm_meal($conn,$fid,(int)($in['meal_id']??0),true);$rows=fm_portions($conn,(int)$meal['id']);$mealId=(int)$meal['id'];
    if($action==='family_plan_swap'){
        foreach($rows as $row)if($row['live_log'])throw new InvalidArgumentException('Someone already logged this meal. Delete that food log before swapping.');
        $r=$recipes[(int)($in['recipe_id']??0)]??null;$eating=array_keys(array_filter($rows,fn($x)=>!(int)$x['skipped']));if(!$rows)$eating=array_keys(array_filter($members,fn($m)=>$m['opt_in'][$meal['meal_type']]));
        if(!$r||!fm_eligible_for($r,$members,$eating))throw new InvalidArgumentException('Choose a complete high-protein recipe that avoids everyone’s excluded foods.');
        $rid=(int)$r['id'];$st=$conn->prepare('UPDATE family_meal_plans SET recipe_id=? WHERE id=? AND family_id=?');$st->bind_param('iii',$rid,$mealId,$fid);$st->execute();$st->close();
        // Overrides were servings of the old recipe, so they no longer apply.
        $st=$conn->prepare('UPDATE family_meal_portions SET override_servings=NULL WHERE family_meal_id=?');$st->bind_param('i',$mealId);$st->execute();$st->close();
        fm_refit($conn,$meal,$r,$members,$cache);$conn->commit();json_response(['success'=>true]);
    }
    if($action==='family_portion_override'){
        $target=(int)($in['user_id']??$uid);if(!isset($members[$target]))throw new InvalidArgumentException('That person is not in your family.');
        if($target!==$uid&&($members[$uid]['member_role']??'')!=='owner')json_response(['success'=>false,'message'=>'Only the family owner can change someone else’s portion.'],403);
        $row=$rows[$target]??null;if($row&&$row['live_log'])throw new InvalidArgumentException('This portion is already logged.');
        $r=$recipes[(int)$meal['recipe_id']]??null;if(!$r)throw new InvalidArgumentException('Recipe unavailable.');
        $skip=array_key_exists('skip',$in)?((bool)$in['skip']?1:0):($row?(int)$row['skipped']:0);$override=$row['override_servings']??null;
        if(array_key_exists('servings',$in)){$v=$in['servings'];if($v===null||$v==='')$override=null;elseif(!is_numeric($v)||(float)$v<.25||(float)$v>3)throw new InvalidArgumentException('Portions must be 0.25–3 servings.');else $override=round((float)$v,2);}
        $n=fm_fit($conn,$members[$target],$r,$meal['plan_date'],$meal['meal_type'],$cache);
        $st=$conn->prepare('INSERT INTO family_meal_portions(family_meal_id,user_id,portion_servings,override_servings,skipped) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE portion_servings=VALUES(portion_servings),override_servings=VALUES(override_servings),skipped=VALUES(skipped)');$st->bind_param('iiddi',$mealId,$target,$n,$override,$skip);$st->execute();$st->close();
        $conn->commit();json_response(['success'=>true]);
    }
    if($action==='family_plan_log'){
        $row=$rows[$uid]??null;if(!$row||(int)$row['skipped'])throw new InvalidArgumentException('You are not eating this meal. Join it first.');
        if($row['live_log']){$conn->commit();json_response(['success'=>true]);}
        $r=$recipes[(int)$meal['recipe_id']]??null;if(!$r)throw new InvalidArgumentException('Recipe unavailable.');$n=(float)($row['override_servings']??$row['portion_servings']);$rid=(int)$r['id'];
        $st=$conn->prepare("INSERT INTO food_logs(user_id,food_date,meal_type,food_name,servings,calories,protein_g,carbs_g,fat_g,fiber_g,recipe_id,source) VALUES(?,?,?,?,?,?,?,?,?,?,?,'family_meal')");$cal=(float)$r['calories_per_serving'];$pro=(float)$r['protein_per_serving'];$cb=(float)$r['carbs_per_serving'];$fat=(float)$r['fat_per_serving'];$fiber=(float)($r['fiber_per_serving']??0);
        $st->bind_param('isssddddddi',$uid,$meal['plan_date'],$meal['meal_type'],$r['recipe_name'],$n,$cal,$pro,$cb,$fat,$fiber,$rid);$st->execute();$foodId=$conn->insert_id;$st->close();
        $st=$conn->prepare('UPDATE family_meal_portions SET logged_food_id=? WHERE family_meal_id=? AND user_id=?');$st->bind_param('iii',$foodId,$mealId,$uid);$st->execute();$st->close();
        // Rebalance this member's other unlogged meals that day around what they actually ate.
        $cache=[];$st=$conn->prepare('SELECT m.id,m.plan_date,m.meal_type,m.recipe_id FROM family_meal_plans m JOIN family_meal_portions p ON p.family_meal_id=m.id AND p.user_id=? LEFT JOIN food_logs fl ON fl.id=p.logged_food_id AND fl.user_id=p.user_id WHERE m.family_id=? AND m.plan_date=? AND m.id<>? AND fl.id IS NULL');$st->bind_param('iisi',$uid,$fid,$meal['plan_date'],$mealId);$st->execute();$others=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
        $up=$conn->prepare('UPDATE family_meal_portions SET portion_servings=? WHERE family_meal_id=? AND user_id=?');
        foreach($others as $o){$or=$recipes[(int)$o['recipe_id']]??null;if(!$or)continue;$n=fm_fit($conn,$members[$uid],$or,$o['plan_date'],$o['meal_type'],$cache);$oid=(int)$o['id'];$up->bind_param('dii',$n,$oid,$uid);$up->execute();}$up->close();
        $conn->commit();json_response(['success'=>true]);
    }
}catch(InvalidArgumentException $e){try{$conn->rollback();}catch(Throwable $x){}json_response(['success'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){try{$conn->rollback();}catch(Throwable $x){}error_log('FitFuel family planner: '.$e->getMessage());json_response(['success'=>false,'message'=>'Unable to update the family menu. Please try again.'],500);}
