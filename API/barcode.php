<?php
require_once 'session.php';require_login();
if($_SERVER['REQUEST_METHOD']!=='GET')json_response(['success'=>false,'message'=>'GET requests only.'],405);
$barcode=preg_replace('/\D+/','',$_GET['barcode']??'');
if($barcode===''||strlen($barcode)<8||strlen($barcode)>18)json_response(['success'=>false,'message'=>'Enter a valid product barcode.'],400);
$url='https://world.openfoodfacts.org/api/v3/product/'.rawurlencode($barcode).'.json';$ch=curl_init($url);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['User-Agent: FitFuel/1.0 (food barcode lookup)']]);
$raw=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
if($raw===false||$http<200||$http>=300)json_response(['success'=>false,'message'=>'Food database lookup failed. Try again or enter the food manually.'],502);
$d=json_decode($raw,true);if(!is_array($d)||(int)($d['status']??0)!==1)json_response(['success'=>false,'found'=>false,'message'=>'That barcode was not found in Open Food Facts.'],404);
$p=$d['product']??[];$n=$p['nutriments']??[];$ss=trim((string)($p['serving_size']??''));$sg=null;
if(preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*g\b/i',$ss,$m))$sg=(float)str_replace(',','.',$m[1]);
function nv($a,$keys){foreach($keys as $k)if(isset($a[$k])&&is_numeric($a[$k]))return(float)$a[$k];return 0.0;}
$g=['calories'=>nv($n,['energy-kcal_100g','energy-kcal_value']),'protein_g'=>nv($n,['proteins_100g']),'carbs_g'=>nv($n,['carbohydrates_100g']),'fat_g'=>nv($n,['fat_100g']),'fiber_g'=>nv($n,['fiber_100g'])];
$s=['calories'=>nv($n,['energy-kcal_serving']),'protein_g'=>nv($n,['proteins_serving']),'carbs_g'=>nv($n,['carbohydrates_serving']),'fat_g'=>nv($n,['fat_serving']),'fiber_g'=>nv($n,['fiber_serving'])];
$has=false;foreach($s as $v)if($v>0){$has=true;break;}if(!$has&&$sg!==null){foreach($g as $k=>$v)$s[$k]=round($v*$sg/100,2);$has=true;}if(!$has)$s=$g;
json_response(['success'=>true,'found'=>true,'product'=>['barcode'=>$barcode,'name'=>trim((string)($p['product_name']??$p['product_name_en']??'Unknown product')),'brand'=>trim((string)($p['brands']??'')),'serving_size'=>$ss,'calories'=>$s['calories'],'protein_g'=>$s['protein_g'],'carbs_g'=>$s['carbs_g'],'fat_g'=>$s['fat_g'],'fiber_g'=>$s['fiber_g'],'image_url'=>$p['image_front_url']??$p['image_url']??null,'source_url'=>'https://world.openfoodfacts.org/product/'.$barcode]]);
?>