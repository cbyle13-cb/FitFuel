<?php
require_once 'session.php';
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['success'=>false,'message'=>'POST requests only.'],405);
$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))json_response(['success'=>false,'message'=>'Invalid request.'],400);
$email=strtolower(trim($in['email']??''));$password=$in['password']??'';
if($email===''||$password==='')json_response(['success'=>false,'message'=>'Email and password are required.'],400);
$st=$conn->prepare("SELECT id,email,password_hash,first_name,last_name,role,is_active FROM users WHERE email=? LIMIT 1");$st->bind_param("s",$email);$st->execute();$u=$st->get_result()->fetch_assoc();$st->close();
if(!$u||!password_verify($password,$u['password_hash']))json_response(['success'=>false,'message'=>'Invalid email or password.'],401);
if((int)$u['is_active']!==1)json_response(['success'=>false,'message'=>'This account is inactive.'],403);
start_fitfuel_session();session_regenerate_id(true);
$_SESSION['user_id']=(int)$u['id'];$_SESSION['email']=$u['email'];$_SESSION['first_name']=$u['first_name'];$_SESSION['role']=$u['role'];
unset($u['password_hash'],$u['is_active']);json_response(['success'=>true,'message'=>'Login successful.','user'=>$u]);
?>