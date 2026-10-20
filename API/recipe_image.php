<?php
require_once 'session.php';
$uid=require_login();$id=(int)($_GET['id']??0);$fid=family_id_for_user($conn,$uid);
// Same visibility as the recipe list: shared starters, your own recipes, and your family's.
$st=$conn->prepare("SELECT c.mime,c.bytes FROM recipe_image_cache c JOIN recipes r ON r.id=c.recipe_id LEFT JOIN family_members fm ON fm.user_id=r.user_id AND fm.family_id=? WHERE c.recipe_id=? AND (r.user_id IS NULL OR r.user_id=? OR fm.family_id IS NOT NULL) LIMIT 1");
$f=$fid??0;$st->bind_param('iii',$f,$id,$uid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
if(!$row){http_response_code(404);exit;}
header('Content-Type: '.$row['mime']);header('Cache-Control: private, max-age=604800');header('X-Content-Type-Options: nosniff');echo $row['bytes'];
