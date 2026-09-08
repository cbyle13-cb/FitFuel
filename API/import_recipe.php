<?php
require_once 'session.php';
require_once 'recipe_importer.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success'=>false,'message'=>'POST required.'],405);
$input=json_decode(file_get_contents('php://input'),true);
if(!is_array($input))$input=[];
try{json_response(['success'=>true,'recipe'=>recipe_import_from_url(trim((string)($input['url']??'')))]);}
catch(RecipeImportException $e){json_response(['success'=>false,'message'=>$e->getMessage()],$e->httpStatus);}
