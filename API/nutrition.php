<?php
// Stateless public calculator: no account or database reads, usable by import-key users.
require_once 'nutrition_estimator.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'message'=>'POST required.']);exit;}
$raw=file_get_contents('php://input',false,null,0,65001);$in=json_decode($raw,true);
if(strlen($raw)>65000||!is_array($in)||!is_array($in['ingredients']??null)||count($in['ingredients'])>250){http_response_code(400);echo json_encode(['success'=>false,'message'=>'Send up to 250 ingredient lines.']);exit;}
try{foreach($in['ingredients'] as $line)if(!is_string($line)||strlen($line)>1000)throw new InvalidArgumentException('Invalid ingredient line.');echo json_encode(['success'=>true,'estimate'=>ff_estimate($in['ingredients'],(float)($in['servings']??1))]);}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
