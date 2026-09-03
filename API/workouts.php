<?php
// Versioned workout records reuse workout_logs; no destructive migration required.
require_once 'session.php';
$uid = require_login(); current_user($conn, $uid);
header('Cache-Control: no-store');
function bad_workout($message) { json_response(['success'=>false,'message'=>$message],400); }
function workout_number($v,$min,$max) { return is_numeric($v) && is_finite((float)$v) && $v >= $min && $v <= $max; }
function validate_workout($p) {
    if (!is_array($p) || ($p['schema']??'') !== 'fitfuel.workout.v1' || !in_array($p['kind']??'', ['template','session','draft'],true)) bad_workout('Invalid workout record.');
    if (!is_string($p['name']??null) || !trim($p['name']) || strlen($p['name'])>100) bad_workout('Enter a workout name under 100 characters.');
    $date=$p['date']??''; $d=DateTime::createFromFormat('!Y-m-d',$date);
    if (!$d || $d->format('Y-m-d')!==$date) bad_workout('Enter a valid date.');
    if (!workout_number($p['minutes']??0,0,1440) || strlen($p['notes']??'')>4000) bad_workout('Check duration and notes.');
    if (!is_array($p['exercises']??null) || count($p['exercises'])<1 || count($p['exercises'])>30) bad_workout('Use 1–30 exercises.');
    if (!preg_match('/^[a-f0-9-]{36}$/',$p['token']??'')) bad_workout('Invalid workout identifier.');
    $completed=0;
    foreach($p['exercises'] as $e) {
        if (!is_array($e) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/',$e['exerciseId']??'')) bad_workout('Invalid exercise.');
        foreach(['min'=>[1,600], 'max'=>[1,600], 'weight'=>[0,2000], 'increment'=>[0,100], 'cap'=>[0,2000], 'rest'=>[0,600]] as $key=>$range) {
            if (!workout_number($e[$key]??null,$range[0],$range[1])) bad_workout('Invalid exercise targets.');
        }
        if ($e['min']>$e['max'] || $e['weight']>$e['cap']) bad_workout('Check rep range and maximum weight.');
        if (!in_array($e['effort']??'', ['unknown','easy','right','hard','pain'],true)) bad_workout('Invalid effort.');
        if (!is_array($e['sets']??null) || count($e['sets'])<1 || count($e['sets'])>12) bad_workout('Use 1–12 sets per exercise.');
        foreach($e['sets'] as $set) {
            if (!workout_number($set['weight']??null,0,2000) || !workout_number($set['reps']??null,0,600) || !is_bool($set['done']??null)) bad_workout('Invalid set.');
            if ($set['done']) { if ($set['reps']<=0) bad_workout('Completed sets need reps or seconds.'); $completed++; }
        }
    }
    if ($p['kind']==='session' && !$completed) bad_workout('Complete at least one set before finishing.');
}
try {
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $st=$conn->prepare('SELECT id,workout_date,workout_name,duration_minutes,notes,completed FROM workout_logs WHERE user_id=? ORDER BY workout_date DESC,id DESC');
        $st->bind_param('i',$uid);$st->execute();$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$records=[];$legacy=[];
        foreach($rows as $row) { $p=json_decode($row['notes']??'',true); if(is_array($p)&&($p['schema']??'')==='fitfuel.workout.v1') {$p['id']=(int)$row['id'];$records[]=$p;} else {$legacy[]=$row;} }
        json_response(['success'=>true,'records'=>$records,'legacy'=>$legacy]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') json_response(['success'=>false,'message'=>'Method not allowed.'],405);
    $in=json_decode(file_get_contents('php://input'),true);$action=$in['action']??'';$id=(int)($in['id']??0);
    if (!in_array($action,['save','delete'],true)) bad_workout('Unknown action.');
    if ($id) {
        $st=$conn->prepare('SELECT notes FROM workout_logs WHERE id=? AND user_id=?');$st->bind_param('ii',$id,$uid);$st->execute();$row=$st->get_result()->fetch_assoc();
        if(!$row || (json_decode($row['notes']??'',true)['schema']??'')!=='fitfuel.workout.v1') json_response(['success'=>false,'message'=>'Workout not found.'],404);
    }
    if ($action==='delete') { if(!$id) bad_workout('Missing workout.');$st=$conn->prepare('DELETE FROM workout_logs WHERE id=? AND user_id=?');$st->bind_param('ii',$id,$uid);$st->execute();json_response(['success'=>true]); }
    $p=$in['record']??null;validate_workout($p);unset($p['id']);$notes=json_encode($p,JSON_UNESCAPED_UNICODE);
    if(strlen($notes)>60000) bad_workout('Workout is too large.');
    $date=$p['date'];$name=trim($p['name']);$dur=(int)($p['minutes']??0);$done=$p['kind']==='session'?1:0;
    if($id) {$st=$conn->prepare('UPDATE workout_logs SET workout_date=?,workout_name=?,duration_minutes=?,notes=?,completed=? WHERE id=? AND user_id=?');$st->bind_param('ssisiii',$date,$name,$dur,$notes,$done,$id,$uid);}
    else {
        // Serialize creation per user and reuse the token after a lost response/retry.
        $conn->begin_transaction();
        $lock=$conn->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->bind_param('i',$uid);$lock->execute();$lock->get_result()->fetch_assoc();
        $needle='%'. $p['token'] .'%';$check=$conn->prepare('SELECT id FROM workout_logs WHERE user_id=? AND notes LIKE ? LIMIT 1');$check->bind_param('is',$uid,$needle);$check->execute();$existing=$check->get_result()->fetch_assoc();
        if($existing){$conn->commit();json_response(['success'=>true,'id'=>(int)$existing['id']]);}
        $st=$conn->prepare('INSERT INTO workout_logs(user_id,workout_date,workout_name,duration_minutes,notes,completed) VALUES(?,?,?,?,?,?)');$st->bind_param('issisi',$uid,$date,$name,$dur,$notes,$done);}
    $st->execute();$saved_id=$id?:$conn->insert_id;if(!$id)$conn->commit();json_response(['success'=>true,'id'=>$saved_id]);
} catch(Throwable $e) { json_response(['success'=>false,'message'=>'Workout could not be saved or loaded. Please retry.'],500); }
