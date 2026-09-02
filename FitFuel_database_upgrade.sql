-- FitFuel database upgrade. BACK UP THE DATABASE FIRST.
CREATE TABLE IF NOT EXISTS families (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT,
 name VARCHAR(150) NOT NULL,
 invite_code VARCHAR(20) NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uq_families_invite_code(invite_code), KEY idx_families_created_by(created_by),
 CONSTRAINT fk_families_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_members (
 family_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
 member_role ENUM('owner','member') NOT NULL DEFAULT 'member',
 joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(family_id,user_id), KEY idx_family_members_user(user_id),
 CONSTRAINT fk_family_members_family FOREIGN KEY(family_id) REFERENCES families(id) ON DELETE CASCADE,
 CONSTRAINT fk_family_members_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS water_logs (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL, log_date DATE NOT NULL,
 ounces DECIMAL(7,2) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_water_user_date(user_id,log_date),
 CONSTRAINT fk_water_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meal_plans (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL, plan_date DATE NOT NULL,
 recipe_id INT UNSIGNED NOT NULL, meal_order INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id),
 KEY idx_meal_plans_user_date(user_id,plan_date),
 CONSTRAINT fk_meal_plans_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_meal_plans_recipe FOREIGN KEY(recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE food_logs ADD COLUMN IF NOT EXISTS barcode VARCHAR(32) NULL AFTER recipe_id;
ALTER TABLE food_logs ADD COLUMN IF NOT EXISTS brand VARCHAR(150) NULL AFTER barcode;
ALTER TABLE food_logs ADD COLUMN IF NOT EXISTS serving_size VARCHAR(100) NULL AFTER brand;
ALTER TABLE food_logs ADD COLUMN IF NOT EXISTS serving_unit VARCHAR(30) NULL AFTER serving_size;
ALTER TABLE food_logs ADD COLUMN IF NOT EXISTS source VARCHAR(50) NULL AFTER serving_unit;

-- Existing users: create a family for any account that does not have one.
INSERT INTO families(name,invite_code,created_by)
SELECT CONCAT(first_name, ' FitFuel Family'),
       CONCAT('FF',UPPER(SUBSTRING(MD5(CONCAT(id,UNIX_TIMESTAMP())),1,8))), id
FROM users u
WHERE NOT EXISTS(SELECT 1 FROM family_members fm WHERE fm.user_id=u.id)
ORDER BY id;

INSERT INTO family_members(family_id,user_id,member_role)
SELECT f.id,u.id,'owner' FROM users u JOIN families f ON f.created_by=u.id
WHERE NOT EXISTS(SELECT 1 FROM family_members fm WHERE fm.user_id=u.id);

-- Starter recipes
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Greek Yogurt Protein Bowl','manual','["Greek yogurt — 1 cup","Protein powder — 1 scoop","Berries — 1/2 cup","Almond butter — 1 tbsp"]','Mix yogurt and protein powder. Top with berries and almond butter.',1,330,38,28,7,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Greek Yogurt Protein Bowl' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Chicken Fajita Bowl','manual','["Chicken breast — 6 oz","Rice — 3/4 cup cooked","Bell pepper — 1","Onion — 1/2","Salsa — 2 tbsp"]','Cook chicken with peppers and onion. Serve over rice with salsa.',1,520,52,42,15,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Chicken Fajita Bowl' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Lean Beef Taco Bowl','manual','["Lean ground beef — 6 oz","Rice — 1/2 cup cooked","Black beans — 1/2 cup","Lettuce — 1 cup","Salsa — 2 tbsp"]','Brown beef with taco seasoning. Layer with rice, beans, lettuce and salsa.',1,550,48,40,19,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Lean Beef Taco Bowl' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Protein Shake','manual','["Protein powder — 1 scoop","Milk — 1 cup","Ice — 1 cup"]','Blend until smooth.',1,240,30,15,5,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Protein Shake' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Cottage Cheese Berry Bowl','manual','["Cottage cheese — 1 cup","Berries — 1/2 cup","Chia seeds — 1 tbsp"]','Combine and serve chilled.',1,280,30,22,8,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Cottage Cheese Berry Bowl' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'High-Protein Chicken Pasta','manual','["Chicken breast — 6 oz","Protein pasta — 2 oz dry","Marinara — 1/2 cup","Parmesan — 1 tbsp"]','Cook pasta. Add chicken and marinara. Finish with parmesan.',1,570,55,48,15,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='High-Protein Chicken Pasta' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Turkey Burger Bowl','manual','["Lean ground turkey — 6 oz","Potato — 8 oz","Lettuce — 1 cup","Pickles — 1/4 cup"]','Cook turkey and serve with roasted potato and salad ingredients.',1,500,48,35,18,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Turkey Burger Bowl' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Egg & Turkey Breakfast Bowl','manual','["Eggs — 2","Egg whites — 1/2 cup","Turkey sausage — 2 oz","Spinach — 1 cup"]','Cook sausage, then add eggs, whites and spinach.',1,390,37,18,20,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Egg & Turkey Breakfast Bowl' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Chicken Caesar Protein Wrap','manual','["Chicken breast — 5 oz","High-protein tortilla — 1","Romaine — 1 cup","Light Caesar dressing — 2 tbsp","Parmesan — 1 tbsp"]','Fill tortilla with chicken, romaine, dressing and parmesan.',1,450,45,30,16,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Chicken Caesar Protein Wrap' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Turkey Chili','manual','["Lean ground turkey — 6 oz","Kidney beans — 1/2 cup","Diced tomatoes — 1 cup","Onion — 1/2","Chili seasoning — 1 tbsp"]','Brown turkey. Add remaining ingredients and simmer 20–30 minutes.',1,500,50,38,14,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Turkey Chili' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Overnight Protein Oats','manual','["Oats — 1/2 cup","Greek yogurt — 1/2 cup","Protein powder — 1 scoop","Milk — 1/2 cup","Berries — 1/2 cup"]','Mix and refrigerate overnight.',1,410,35,45,10,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Overnight Protein Oats' AND user_id IS NULL);
INSERT INTO recipes(user_id,recipe_name,source_type,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,is_favorite)
SELECT NULL,'Buffalo Chicken Bowl','manual','["Chicken breast — 6 oz","Rice — 1/2 cup cooked","Buffalo sauce — 2 tbsp","Greek yogurt — 2 tbsp","Celery — 1/2 cup"]','Toss cooked chicken with buffalo sauce. Serve over rice with yogurt sauce and celery.',1,480,53,30,15,0
WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE recipe_name='Buffalo Chicken Bowl' AND user_id IS NULL);
