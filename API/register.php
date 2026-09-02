<?php
require_once 'session.php';
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['success'=>false,'message'=>'POST requests only.'],405);
$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))json_response(['success'=>false,'message'=>'Invalid request.'],400);
$email=strtolower(trim($in['email']??''));$password=$in['password']??'';$first=trim($in['first_name']??'');$last=trim($in['last_name']??'');$fname=trim($in['family_name']??'');
if(!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['success'=>false,'message'=>'Please enter a valid email address.'],400);
if(strlen($password)<8)json_response(['success'=>false,'message'=>'Password must be at least 8 characters.'],400);
if($first==='')json_response(['success'=>false,'message'=>'First name is required.'],400);
$st=$conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");$st->bind_param("s",$email);$st->execute();$st->store_result();$exists=$st->num_rows>0;$st->close();
if($exists)json_response(['success'=>false,'message'=>'An account with that email already exists.'],409);
$conn->begin_transaction();
try{
$hash=password_hash($password,PASSWORD_DEFAULT);
$st=$conn->prepare("INSERT INTO users(email,password_hash,first_name,last_name) VALUES(?,?,?,?)");$st->bind_param("ssss",$email,$hash,$first,$last);if(!$st->execute())throw new Exception();$uid=$conn->insert_id;$st->close();
$st=$conn->prepare("INSERT INTO user_profiles(user_id) VALUES(?)");$st->bind_param("i",$uid);if(!$st->execute())throw new Exception();$st->close();
$code='FF'.strtoupper(substr(bin2hex(random_bytes(5)),0,8));$family=$fname!==''?$fname:$first."'s FitFuel Family";
$st=$conn->prepare("INSERT INTO families(name,invite_code,created_by) VALUES(?,?,?)");$st->bind_param("ssi",$family,$code,$uid);if(!$st->execute())throw new Exception();$fid=$conn->insert_id;$st->close();
$role='owner';$st=$conn->prepare("INSERT INTO family_members(family_id,user_id,member_role) VALUES(?,?,?)");$st->bind_param("iis",$fid,$uid,$role);if(!$st->execute())throw new Exception();$st->close();
$conn->commit();start_fitfuel_session();session_regenerate_id(true);$_SESSION['user_id']=$uid;$_SESSION['email']=$email;$_SESSION['first_name']=$first;$_SESSION['role']='user';
json_response(['success'=>true,'message'=>'FitFuel account created successfully.','user'=>['id'=>$uid,'email'=>$email,'first_name'=>$first,'last_name'=>$last,'role'=>'user']]);
}catch(Throwable $e){$conn->rollback();json_response(['success'=>false,'message'=>'Unable to create account.'],500);}
?>