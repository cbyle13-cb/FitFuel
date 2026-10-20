<?php
require __DIR__.'/../API/recipe_images.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
foreach(['https://scontent-iad3-1.cdninstagram.com/v/a.jpg?oe=1','https://scontent.xx.fbcdn.net/a.jpg','https://p16-sign-va.tiktokcdn.com/a.jpeg','https://p19.tiktokcdn-us.com/a','https://i.pinimg.com/a.jpg'] as $u)check(ff_is_expiring_image_url($u),"expiring: $u");
foreach(['https://fitfuel.bylerfinancial.com/assets/recipes/a.jpg','https://example.com/a.jpg','https://evilcdninstagram.com/a.jpg','https://cdninstagram.com.evil.net/a.jpg',''] as $u)check(!ff_is_expiring_image_url($u),"not expiring: $u");
check(ff_cache_recipe_image(new mysqli(),5,'http://scontent.cdninstagram.com/a.jpg')===null,'http rejected without fetching');
check(ff_cache_recipe_image(new mysqli(),5,'https://example.com/a.jpg')===null,'non-CDN never fetched');check(ff_cache_recipe_image(new mysqli(),0,'https://scontent.cdninstagram.com/a.jpg')===null,'needs a recipe id');
require_once __DIR__.'/../API/nutrition_store.php';
foreach(ff_source_photo_fixes() as [$name,$key,$file]){$p=__DIR__.'/../assets/recipes/'.$file;check(is_file($p)&&getimagesize($p)[2]===IMAGETYPE_JPEG&&filesize($p)>20000,"committed photo for $name");check(strpbrk($key,'_%')===false&&strlen($key)>=10,"specific source key for $name");}
$credits=file_get_contents(__DIR__.'/../assets/recipes/CREDITS.md');
foreach(json_decode(file_get_contents(__DIR__.'/../starter-recipes.json'),true) as $r){if(empty($r['image_url']))continue;$file=basename($r['image_url']);check(str_starts_with($r['image_url'],'https://fitfuel.bylerfinancial.com/assets/recipes/')&&is_file(__DIR__.'/../assets/recipes/'.$file),"starter photo for {$r['recipe_name']}");check(str_contains($credits,"`$file`"),"credit for $file");}
foreach(ff_stock_photo_recipes() as [$name,$file]){check(is_file(__DIR__.'/../assets/recipes/'.$file)&&getimagesize(__DIR__.'/../assets/recipes/'.$file)[2]===IMAGETYPE_JPEG,"stock photo for $name");check(str_contains($credits,"`$file`"),"credit for $file");}
echo "Recipe images: all checks passed.\n";
