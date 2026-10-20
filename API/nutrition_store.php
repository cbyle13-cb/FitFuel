<?php
require_once 'nutrition_core.php';
function ff_ensure_schema(mysqli $c): void {
    $c->query("CREATE TABLE IF NOT EXISTS nutrition_settings (user_id INT UNSIGNED PRIMARY KEY, settings_json TEXT NOT NULL) ENGINE=InnoDB");
    $columns=['meal_plans'=>['portion_servings'=>'DECIMAL(6,2) NOT NULL DEFAULT 1','meal_type'=>"VARCHAR(20) NOT NULL DEFAULT 'lunch'",'logged_food_id'=>'INT UNSIGNED NULL'], 'recipes'=>['meal_category'=>"VARCHAR(20) NOT NULL DEFAULT 'any'",'nutrition_status'=>"VARCHAR(20) NOT NULL DEFAULT 'provided'",'nutrition_notes'=>'TEXT NULL','starter_key'=>'VARCHAR(80) NULL','total_cooked_weight_g'=>'DECIMAL(8,1) NULL','meal_types'=>'VARCHAR(64) NULL','hidden_at'=>'DATETIME NULL']];
    foreach($columns as $table=>$fields){$existing=[];$res=$c->query("SHOW COLUMNS FROM `$table`");while($row=$res->fetch_assoc())$existing[]=$row['Field'];foreach($fields as $field=>$ddl)if(!in_array($field,$existing,true)){try{$c->query("ALTER TABLE `$table` ADD COLUMN `$field` $ddl");}catch(mysqli_sql_exception $e){if($e->getCode()!==1060)throw $e;}}}
    // Family Menu: one cooked meal per family slot, one portion row per member. Additive only; no foreign keys so
    // a type mismatch on an older live table can never block page loads. Membership is enforced in family_planner.php.
    $c->query("CREATE TABLE IF NOT EXISTS family_meal_plans (id INT UNSIGNED NOT NULL AUTO_INCREMENT, family_id INT UNSIGNED NOT NULL, plan_date DATE NOT NULL, meal_type VARCHAR(20) NOT NULL, meal_order INT UNSIGNED NOT NULL DEFAULT 1, recipe_id INT UNSIGNED NOT NULL, created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_family_meal_slot(family_id,plan_date,meal_type), KEY idx_family_meal_recipe(recipe_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->query("CREATE TABLE IF NOT EXISTS family_meal_portions (id INT UNSIGNED NOT NULL AUTO_INCREMENT, family_meal_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, portion_servings DECIMAL(6,2) NOT NULL DEFAULT 1, override_servings DECIMAL(6,2) NULL, skipped TINYINT(1) NOT NULL DEFAULT 0, logged_food_id INT UNSIGNED NULL, PRIMARY KEY(id), UNIQUE KEY uq_family_portion(family_meal_id,user_id), KEY idx_family_portion_user(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Locally cached copies of photos from expiring social-media CDN links (see recipe_images.php).
    $c->query("CREATE TABLE IF NOT EXISTS recipe_image_cache (recipe_id INT UNSIGNED NOT NULL, mime VARCHAR(40) NOT NULL, bytes MEDIUMBLOB NOT NULL, source_url VARCHAR(2048) NULL, fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(recipe_id)) ENGINE=InnoDB");
    $c->query("CREATE TABLE IF NOT EXISTS ff_migrations (name VARCHAR(80) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(name)) ENGINE=InnoDB");
    // Nullable unique keys make starter seeding repeatable even on concurrent first visits.
    $res=$c->query("SHOW INDEX FROM recipes WHERE Key_name='uq_ff_starter'");if(!$res->num_rows){try{$c->query('ALTER TABLE recipes ADD UNIQUE KEY uq_ff_starter (starter_key)');}catch(mysqli_sql_exception $e){if($e->getCode()!==1061)throw $e;}}
    ff_seed_recipes($c);
    ff_data_migrations($c);
}
// Run-once data fixes. Each claims its name first, so it never re-applies after a user edits the same rows later.
function ff_data_migrations(mysqli $c): void {
    $done=array_column($c->query('SELECT name FROM ff_migrations')->fetch_all(MYSQLI_ASSOC),'name');
    foreach(['meal-types-v1'=>'ff_migrate_meal_types','recipe-photos-v1'=>'ff_migrate_recipe_photos','recipe-photos-v2'=>'ff_migrate_recipe_photos_v2','remove-shrimp-tuna-v1'=>'ff_migrate_remove_shrimp_tuna'] as $name=>$fn){
        if(in_array($name,$done,true))continue;$c->begin_transaction();
        try{$st=$c->prepare('INSERT IGNORE INTO ff_migrations(name) VALUES(?)');$st->bind_param('s',$name);$st->execute();$claimed=$st->affected_rows===1;$st->close();if($claimed)$fn($c);$c->commit();}
        catch(Throwable $e){$c->rollback();error_log('FitFuel migration '.$name.': '.$e->getMessage());}
    }
}
// Starter meal types come from starter-recipes.json. The 15 hunt4shredz imports are matched by their own committed photo
// URL plus name, and only while untyped (meal_category 'any', no meal_types), so no one else's recipes are touched.
function ff_migrate_meal_types(mysqli $c): void {
    $st=$c->prepare("UPDATE recipes SET meal_types=? WHERE starter_key=? AND meal_types IS NULL");
    foreach(json_decode(file_get_contents(__DIR__.'/../starter-recipes.json'),true,512,JSON_THROW_ON_ERROR) as $r){$types=implode(',',ff_meal_types_clean($r['meal_types']??[]));$key=$r['starter_key'];$st->bind_param('ss',$types,$key);$st->execute();}$st->close();
    $st=$c->prepare("UPDATE recipes SET meal_types=?,meal_category=? WHERE user_id IS NOT NULL AND image_url=? AND recipe_name=? AND meal_types IS NULL AND (meal_category IS NULL OR meal_category='' OR meal_category='any')");
    foreach(ff_import_meal_types() as $url=>[$name,$types]){$list=implode(',',$types);$primary=$types[0];$st->bind_param('ssss',$list,$primary,$url,$name);$st->execute();}$st->close();
}
function ff_import_meal_types(): array {
    $base='https://fitfuel.bylerfinancial.com/assets/recipes/';
    return [$base.'sweet-potato-beef-taco-boats.jpg'=>['Sweet Potato Beef Taco Boats',['lunch', 'dinner']],
        $base.'cinnamon-sugar-air-fried-donuts.jpg'=>['High Protein Cinnamon Sugar Air Fried Donuts',['breakfast', 'snack']],
        $base.'steak-queso-street-corn-bowl.jpg'=>['Steak & Queso Street Corn Bowl',['lunch', 'dinner']],
        $base.'crispy-cheesy-chicken-quesadillas.jpg'=>['Crispy Cheesy Chicken Quesadillas with Avocado Lime Crema',['lunch', 'dinner']],
        $base.'honey-chipotle-chicken-burrito-meal-prep.jpg'=>['Honey Chipotle Chicken Burrito Meal Prep',['lunch', 'dinner']],
        $base.'buffalo-chicken-burrito-meal-prep.jpg'=>['High Protein Buffalo Chicken Burrito Meal Prep',['lunch', 'dinner']],
        $base.'high-protein-mcgriddles.jpg'=>['High Protein Inspired McGriddles',['breakfast']],
        $base.'crispy-chili-beef-bowls.jpg'=>['High Protein Crispy Chili Beef Bowls',['lunch', 'dinner']],
        $base.'chipotle-honey-chicken-tacos.jpg'=>['Chipotle Honey Chicken Tacos with Green Goddess Sauce',['lunch', 'dinner']],
        $base.'cheesy-beyti-kebab-skewers.jpg'=>['High Protein Cheesy Beyti Kebab Skewers',['dinner']],
        $base.'sweet-chili-popcorn-chicken-bites.jpg'=>['Crispy Sweet Chili Popcorn Chicken Bites',['snack', 'lunch']],
        $base.'cheesy-beef-taquitos.jpg'=>['High Protein Low Cal Cheesy Beef Taquitos',['snack', 'lunch']],
        $base.'beef-queso-game-day-dip.jpg'=>['High Protein Beef Queso Game Day Dip',['snack']],
        $base.'steak-chimichurri-sweet-potato-bowl.jpg'=>['Steak Chimichurri & Mashed Sweet Potato Bowl',['dinner', 'lunch']],
        $base.'chicken-shawarma-meal-prep-burritos.jpg'=>['High Protein Chicken Shawarma Meal Prep Burritos',['lunch', 'dinner']]];
}
// Broken hotlinked photos: point the named recipe at its committed photo, only while it still uses an outside link.
function ff_migrate_recipe_photos(mysqli $c): void {
    $st=$c->prepare("UPDATE recipes SET image_url=? WHERE user_id IS NOT NULL AND recipe_name=? AND calories_per_serving BETWEEN ? AND ? AND (image_url IS NULL OR image_url='' OR image_url NOT LIKE '%/assets/recipes/%')");
    foreach([['Creamy Chicken Alfredo Fried Rice','creamy-chicken-alfredo-fried-rice.jpg',572,574]] as [$name,$file,$lo,$hi]){$url='https://fitfuel.bylerfinancial.com/assets/recipes/'.$file;$st->bind_param('ssdd',$url,$name,$lo,$hi);$st->execute();}$st->close();
}

/** Committed source photos for saved imports, keyed by recipe name and a unique piece of the source URL. */
function ff_source_photo_fixes(): array {
    return [
        ['Bacon, Egg, Cheese & Chives Egg Bites','/DTsy2FtkcoH','bacon-egg-cheese-chives-egg-bites.jpg'],
        ['Baconator Bowls','/Dc806rwtgG3','baconator-bowls.jpg'],
        ['Buffalo Chicken Dip','allrecipes.com/recipe/68461/','buffalo-chicken-dip.jpg'],
        ['Chicken Shawarma Wraps','/DcB7O3whrEi','chicken-shawarma-wraps.jpg'],
        ['Creamy Cajun Chicken Pasta','/DeiAxyxPvDU','creamy-cajun-chicken-pasta.jpg'],
        ['Crispy Hot Honey Chicken Alfredo','/DZ5OPgNRGK6','crispy-hot-honey-chicken-alfredo.jpg'],
        ['Garlic Butter Chicken & Creamy Potatoes','/DZNlssHiqVc','garlic-butter-chicken-creamy-potatoes.jpg'],
        ['Greek Beef Wraps with Roasted Garlic Feta Sauce & Hot Honey','/DcUW46zIuTH','greek-beef-wraps-feta-hot-honey.jpg'],
        ['High Protein Buffalo Chicken Potato Bowls','/DN--B8LDK4b','buffalo-chicken-potato-bowls.jpg'],
        ['High Protein Grilled Chicken Alfredo Pasta','/DE8HSkcsMDx','grilled-chicken-alfredo-pasta.jpg'],
        ['High Protein Honey BBQ Chicken & Garlic Parmesan Potatoes','/DHlxKYHNSjt','honey-bbq-chicken-garlic-parmesan-potatoes.jpg'],
        ['High Protein Lemon Garlic Chicken & Crispy Potatoes','/DEsCsfwsZMq','lemon-garlic-chicken-crispy-potatoes.jpg'],
        ['High-Protein Chicken & Sweet Potato Power Bowl','/DdFF9bTzEb8','chicken-sweet-potato-power-bowl.jpg'],
        ['Hot Honey Ribs','howtobbqright.com/2025/02/06/hot-honey-ribs','hot-honey-ribs.jpg'],
        ['Mexican Street Corn Recipe','lilluna.com/mexican-street-corn','mexican-street-corn.jpg'],
        ['Quick and Easy Buffalo Chicken Dip','allrecipes.com/recipe/266408/','quick-buffalo-chicken-dip.jpg'],
        ['Shredded Chipotle Beef','/DdfBzT4EmrF','shredded-chipotle-beef.jpg'],
        ['Street Corn Chicken Bowls','/Dc6V36LqGnV','street-corn-chicken-bowls.jpg'],
        ['High-Protein Pizza Bites','/DdtucebzoR6','high-protein-pizza-bites.jpg'],
    ];
}

function ff_migrate_recipe_photos_v2(mysqli $c): void {
    // The pizza bites import saved part of the AI reply as its name; the reel caption says "HIGH-PROTEIN PIZZA BITES".
    $c->query("UPDATE recipes SET recipe_name='High-Protein Pizza Bites' WHERE user_id IS NOT NULL AND source_url LIKE '%/DdtucebzoR6%' AND recipe_name LIKE 'The reel text includes the nutrition information%'");
    $st=$c->prepare("UPDATE recipes SET image_url=? WHERE user_id IS NOT NULL AND recipe_name=? AND source_url LIKE ? AND (image_url IS NULL OR image_url='' OR (image_url LIKE 'http%' AND image_url NOT LIKE 'https://fitfuel.bylerfinancial.com/%'))");
    foreach(ff_source_photo_fixes() as [$name,$key,$file]){$url='https://fitfuel.bylerfinancial.com/assets/recipes/'.$file;$like='%'.$key.'%';$st->bind_param('sss',$url,$name,$like);$st->execute();}
    $st->close();
}

/** Recipes Christopher asked to remove (shrimp or tuna as the main protein), by exact name. */
function ff_removed_recipe_names(): array {return ['Shrimp Rice Skillet','Tuna Yogurt Wraps','Tuna Toast Snack Plates'];}

// Soft-hide the recipes (rows stay, so food log history keeps its recipe link) and drop every planner and
// Family Menu entry that points at them, so no plan shows or swaps to a hidden recipe. Food logs are kept.
function ff_migrate_remove_shrimp_tuna(mysqli $c): void {
    $names=ff_removed_recipe_names();$in=implode(',',array_fill(0,count($names),'?'));$types=str_repeat('s',count($names));
    $st=$c->prepare("UPDATE recipes SET hidden_at=NOW() WHERE hidden_at IS NULL AND recipe_name IN ($in)");$st->bind_param($types,...$names);$st->execute();$st->close();
    $c->query('DELETE p FROM family_meal_portions p JOIN family_meal_plans m ON m.id=p.family_meal_id JOIN recipes r ON r.id=m.recipe_id WHERE r.hidden_at IS NOT NULL');
    $c->query('DELETE m FROM family_meal_plans m JOIN recipes r ON r.id=m.recipe_id WHERE r.hidden_at IS NOT NULL');
    $c->query('DELETE mp FROM meal_plans mp JOIN recipes r ON r.id=mp.recipe_id WHERE r.hidden_at IS NOT NULL');
}
function ff_profile(mysqli $c,int $uid): array {
    $st=$c->prepare('SELECT * FROM user_profiles WHERE user_id=?');$st->bind_param('i',$uid);$st->execute();$p=$st->get_result()->fetch_assoc()?:[];$st->close();
    $st=$c->prepare('SELECT settings_json FROM nutrition_settings WHERE user_id=?');$st->bind_param('i',$uid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
    $prefs=json_decode($row['settings_json']??'{}',true)?:[];
    return array_merge(['meals_per_day'=>4,'daily_water_goal'=>100,'excluded_foods'=>'','auto_targets'=>false],$p,$prefs);
}
function ff_save_prefs(mysqli $c,int $uid,array $prefs): void {
    $json=json_encode($prefs,JSON_THROW_ON_ERROR);$st=$c->prepare('INSERT INTO nutrition_settings(user_id,settings_json) VALUES(?,?) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)');$st->bind_param('is',$uid,$json);$st->execute();$st->close();
}
function ff_update_targets(mysqli $c,int $uid,array $p): array {
    $t=ff_targets($p);$st=$c->prepare('UPDATE user_profiles SET daily_calorie_goal=?,daily_protein_goal=?,daily_carbs_goal=?,daily_fat_goal=?,daily_fiber_goal=? WHERE user_id=?');$st->bind_param('dddddi',$t['daily_calorie_goal'],$t['daily_protein_goal'],$t['daily_carbs_goal'],$t['daily_fat_goal'],$t['daily_fiber_goal'],$uid);$st->execute();$st->close();return $t;
}
function ff_seed_recipes(mysqli $c): void {
    $recipes=json_decode(file_get_contents(__DIR__.'/../starter-recipes.json'),true,512,JSON_THROW_ON_ERROR);
    if((int)$c->query("SELECT COUNT(*) n FROM recipes WHERE starter_key LIKE 'ff-protein-v1-%'")->fetch_assoc()['n']>=count($recipes))return;
    $st=$c->prepare("INSERT IGNORE INTO recipes(user_id,recipe_name,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,fiber_per_serving,meal_category,meal_types,nutrition_status,nutrition_notes,starter_key,source_type) VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?, 'estimated',?,?,'manual')");
    foreach($recipes as $r){$i=json_encode($r['ingredients']);$note='Estimated from ingredient quantities; check brands and cooked/raw weights.';$types=implode(',',ff_meal_types_clean($r['meal_types']??[]));$st->bind_param('sssddddddssss',$r['recipe_name'],$i,$r['instructions'],$r['servings'],$r['calories_per_serving'],$r['protein_per_serving'],$r['carbs_per_serving'],$r['fat_per_serving'],$r['fiber_per_serving'],$r['meal_category'],$types,$note,$r['starter_key']);$st->execute();}$st->close();
}
