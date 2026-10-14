<?php
require_once 'session.php';
require_once 'nutrition_store.php';
$uid=require_login();ff_ensure_schema($conn);
if($_SERVER['REQUEST_METHOD']==='GET')json_response(['success'=>true,'profile'=>ff_profile($conn,$uid)]);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['success'=>false,'message'=>'POST required.'],405);
$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))json_response(['success'=>false,'message'=>'Invalid request.'],400);
try{
    $old=ff_profile($conn,$uid);$p=array_merge($old,$in);
    $prefs=[];foreach(['sex','planner_activity','planner_goal','meals_per_day','excluded_foods','daily_water_goal','auto_targets'] as $k)$prefs[$k]=$p[$k]??null;
    $prefs['auto_targets']=(bool)$prefs['auto_targets'];$prefs['meals_per_day']=(int)($prefs['meals_per_day']??4);$prefs['daily_water_goal']=(float)($prefs['daily_water_goal']??100);$prefs['excluded_foods']=trim((string)($prefs['excluded_foods']??''));
    if($prefs['meals_per_day']<2||$prefs['meals_per_day']>6||$prefs['daily_water_goal']<=0||$prefs['daily_water_goal']>300||strlen($prefs['excluded_foods'])>2000)throw new InvalidArgumentException('Choose 2–6 meals, a water goal of 1–300 oz, and a shorter food exclusion list.');
    if($prefs['auto_targets'])$p=array_merge($p,ff_targets(array_merge($p,$prefs)));
    foreach(['daily_calorie_goal','daily_protein_goal','daily_carbs_goal','daily_fat_goal','daily_fiber_goal'] as $k)if($p[$k]!==null&&(!is_numeric($p[$k])||(float)$p[$k]<0||(float)$p[$k]>10000))throw new InvalidArgumentException('Enter valid daily targets.');
    foreach(['current_weight','goal_weight'] as $k)if($p[$k]!==null&&((float)$p[$k]<50||(float)$p[$k]>1000))throw new InvalidArgumentException('Weight must be 50–1000 lb.');
    $conn->begin_transaction();
    $dob=$p['date_of_birth']??null;$h=$p['height_inches']??null;$w=$p['current_weight']??null;$g=$p['goal_weight']??null;
    $st=$conn->prepare('UPDATE user_profiles SET date_of_birth=?,height_inches=?,current_weight=?,goal_weight=?,daily_calorie_goal=?,daily_protein_goal=?,daily_carbs_goal=?,daily_fat_goal=?,daily_fiber_goal=? WHERE user_id=?');$st->bind_param('sddddddddi',$dob,$h,$w,$g,$p['daily_calorie_goal'],$p['daily_protein_goal'],$p['daily_carbs_goal'],$p['daily_fat_goal'],$p['daily_fiber_goal'],$uid);$st->execute();$st->close();ff_save_prefs($conn,$uid,$prefs);$conn->commit();json_response(['success'=>true,'profile'=>ff_profile($conn,$uid)]);
}catch(InvalidArgumentException $e){$conn->rollback();json_response(['success'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){$conn->rollback();error_log($e->getMessage());json_response(['success'=>false,'message'=>'Unable to save profile.'],500);}
