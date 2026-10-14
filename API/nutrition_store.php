<?php
require_once 'nutrition_core.php';
function ff_ensure_schema(mysqli $c): void {
    $c->query("CREATE TABLE IF NOT EXISTS nutrition_settings (user_id INT UNSIGNED PRIMARY KEY, settings_json TEXT NOT NULL) ENGINE=InnoDB");
    $columns=['meal_plans'=>['portion_servings'=>'DECIMAL(6,2) NOT NULL DEFAULT 1','meal_type'=>"VARCHAR(20) NOT NULL DEFAULT 'lunch'",'logged_food_id'=>'INT UNSIGNED NULL'], 'recipes'=>['meal_category'=>"VARCHAR(20) NOT NULL DEFAULT 'any'",'nutrition_status'=>"VARCHAR(20) NOT NULL DEFAULT 'provided'",'nutrition_notes'=>'TEXT NULL','starter_key'=>'VARCHAR(80) NULL']];
    foreach($columns as $table=>$fields){$existing=[];$res=$c->query("SHOW COLUMNS FROM `$table`");while($row=$res->fetch_assoc())$existing[]=$row['Field'];foreach($fields as $field=>$ddl)if(!in_array($field,$existing,true)){try{$c->query("ALTER TABLE `$table` ADD COLUMN `$field` $ddl");}catch(mysqli_sql_exception $e){if($e->getCode()!==1060)throw $e;}}}
    // Nullable unique keys make starter seeding repeatable even on concurrent first visits.
    $res=$c->query("SHOW INDEX FROM recipes WHERE Key_name='uq_ff_starter'");if(!$res->num_rows){try{$c->query('ALTER TABLE recipes ADD UNIQUE KEY uq_ff_starter (starter_key)');}catch(mysqli_sql_exception $e){if($e->getCode()!==1061)throw $e;}}
    ff_seed_recipes($c);
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
    $st=$c->prepare("INSERT IGNORE INTO recipes(user_id,recipe_name,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,fiber_per_serving,meal_category,nutrition_status,nutrition_notes,starter_key,source_type) VALUES(NULL,?,?,?,?,?,?,?,?,?,?, 'estimated',?,?,'manual')");
    foreach($recipes as $r){$i=json_encode($r['ingredients']);$note='Estimated from ingredient quantities; check brands and cooked/raw weights.';$st->bind_param('sssddddddsss',$r['recipe_name'],$i,$r['instructions'],$r['servings'],$r['calories_per_serving'],$r['protein_per_serving'],$r['carbs_per_serving'],$r['fat_per_serving'],$r['fiber_per_serving'],$r['meal_category'],$note,$r['starter_key']);$st->execute();}$st->close();
}
