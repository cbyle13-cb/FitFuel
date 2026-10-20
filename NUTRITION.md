# Macro-aware meal planning

Settings saves formula sex, birth date, height, current and goal weight, activity, fitness goal, 2–6 meals/day, comma-separated ingredient exclusions, and a daily water goal in ounces. Enable **Recalculate targets automatically on new weigh-ins** to use automatic targets. Manual mode preserves edited targets. The existing adult Mifflin–St Jeor calculator and protein/fat/carbohydrate rules also run server-side. At goal weight, automatic weight-loss/gain calculations switch to maintenance.

On Sunday, log your weigh-in in Progress, then Generate week in Meal Plan. Latest non-future weigh-ins update current weight and automatic targets in one transaction. Historical entries do not replace current weight. Week selection defaults to Sunday and can be changed. Existing plans remain unchanged until generated again.

The planner uses complete high-protein recipes (25g protein and at least 20% of calories from protein per base serving), explicit meal categories, and ingredient exclusions. It scores calories and all three macros, prioritizes calories/protein, and optimizes 0.5–2.5 servings in 0.1-serving increments. It reports any daily macro more than 10% from target instead of promising an exact fit. Matching a food exclusion is literal text matching, not a certified allergen screen.

Swap rebalances the day's unlogged portions; logged meals stay fixed. Log meal uses the planned date, recipe, and exact portion and is idempotent per plan row. Deleting that food log allows logging again. Regeneration replaces plan rows but preserves food logs.

Groceries scale whole-recipe ingredient quantities by planned servings / recipe yield. Prep combines all meals for each recipe, displays total servings, container count, batch multiplier, and scaled ingredients. The dashboard uses the saved water goal.

18 shared starter recipes are seeded once using unique versioned keys. Existing user recipes, settings, logs, and plans are preserved. Additive schema changes run automatically on the first authenticated nutrition request; no manual SQL or data reset is needed. The database account needs CREATE/ALTER privileges, as with existing image/workout migrations. Old plans get one serving by default.

## Import nutrition

Caption nutrition and serving counts are parsed and preserved. When calories are absent, a built-in generic ingredient catalog estimates per-serving calories/macros/fiber without a paid API key. It supports exact catalog foods, measured weights, common volume measures with food-specific weights, fractions, and some count measures. Values are approximate, raw/dry unless the food name explicitly says cooked. They are **not verified USDA API results**. Unknown foods, ambiguous units, or missing quantities produce `incomplete` status and a list for review; such recipes cannot enter the planner. Partial estimates show only the recognized ingredients and must not be treated as total recipe nutrition.

Imported serving count must be checked: captions without a stated yield start at one serving. Review nutrition in Recipes lets the recipe owner correct yield and macros and choose a category. The import page and manual recipe form also have an Estimate nutrition button. Provided nutrition is never silently overwritten. Changing an estimated recipe's ingredients/yield requires re-estimation or manual review.

This release does not use USDA network lookup. Its optional future API integration must use owner-configured server-side credentials and require confirmation of food matches and raw/cooked state. USDA API guide: https://fdc.nal.usda.gov/api-guide/.

## Family Menu

One recipe is cooked once per meal and portioned for each family member's own targets. Open **Family Menu**, pick a week, and tap **Generate family week**. Each day gets breakfast, lunch, dinner, and one snack (a slot is skipped when nobody eats it).

- **Recipes** follow the planner rules: complete nutrition, 25g+ protein and at least 20% of calories from protein per serving, a matching meal category (or `any`), and no excluded foods for *anyone eating that meal*. Recipes are not repeated within a week until the eligible pool runs out.
- **Meal shares** (Settings → Family Menu) split each person's daily targets across breakfast/lunch/dinner/snack (default 25/30/35/10, must total 100%). Unchecking a meal skips it by default; each person can still join or skip a single meal.
- **Portions** are fit per person in 0.05-serving steps from 0.25 to 3 servings, scoring calories and macros with missed protein weighted most. If someone already logged food that day, the meal uses their *remaining* macros spread over the meals they have not eaten yet. Logging a family portion rebalances that person's other unlogged meals that day.
- **Cook N× recipe** is the sum of everyone's portions ÷ recipe yield; ingredients scale with `RecipeCore.scaledIngredients`. Add **Total cooked weight (g)** to a recipe to show each portion in grams.
- **Adjust** sets a fixed portion (members adjust their own; the family owner can adjust anyone). Swapping a recipe clears adjustments. Logged meals stay when the week is regenerated and cannot be swapped.
- **Privacy**: members see names and portions only — never another member's weight, targets, birth date, or other body data.
- **Groceries → Family week** totals the whole family's batches. **Split for family** in any recipe popup previews today's portions.

### Teen targets (under 18)

Automatic targets for ages 13–17 use maintenance calories only — never a deficit or surplus — and about 0.55 g protein per lb (~1.2 g/kg). Teens are still growing, and the adult formula was not validated for them. The app shows a reminder that a doctor or registered dietitian should confirm teen goals. Manual targets remain available. Automatic targets are not calculated under 13.

### Family migrations

`ff_ensure_schema` adds `family_meal_plans`, `family_meal_portions`, and the nullable `recipes.total_cooked_weight_g` with `CREATE TABLE IF NOT EXISTS` / add-column-if-missing. Nothing existing is dropped or altered. The new tables have no foreign keys; membership is enforced in `API/family_planner.php`.

## Meal types

Each recipe has one or more meal types (breakfast, lunch, dinner, snack) stored in `recipes.meal_types` as an ordered list; the first is the main type and is mirrored into the older `meal_category` column. No type (or `any`) means the recipe fits every meal. The personal planner and the Family Menu only put a recipe into a slot that matches one of its types.

- **Edit**: open a recipe → **Edit meal types** (recipe owner, or the family owner for recipes added by family members). Add-recipe, Review nutrition, and the recipe import page use the same checkboxes. Starter recipes are shared by every family, so their types come from `starter-recipes.json`.
- **Importer**: recipe JSON `meal_types` (or `meal_category`/`meal_type`) now pre-checks the import page boxes and is saved; previously the import page dropped it.
- **Backfill** (`meal-types-v1`, run once via `ff_migrations`): sets starter types, and types for the 15 hunt4shredz imports matched by their committed photo URL and name, only while they are still untyped.

## Recipe photos

- Starter recipes have no photo. The 15 hunt4shredz imports use committed files in `assets/recipes/`. Other imports store whatever photo URL the source page advertises (`og:image`); Instagram, Facebook, TikTok and Pinterest CDN links are signed and expire, which breaks photos later.
- New saves from those CDNs are copied into `recipe_image_cache` at save time and served by `API/recipe_image.php` (same visibility as the recipe list).
- `recipe-photos-v1` (run once) points "Creamy Chicken Alfredo Fried Rice" (573 cal) at its committed photo, only if it still uses an outside link.
- `recipe-photos-v2` (run once) points 19 saved imports at committed photos taken from their own source posts or pages (list in `ff_source_photo_fixes()`), matched by recipe name plus a unique piece of the source URL, only when the photo is empty or an outside link. It also renames the import saved as "The reel text includes the nutrition information…" to "High-Protein Pizza Bites".
- Any photo that fails to load shows `assets/recipes/placeholder.svg` instead of a broken-image icon.

## Removed recipes

`remove-shrimp-tuna-v1` (run once) soft-hides Shrimp Rice Skillet, Tuna Yogurt Wraps, and Tuna Toast Snack Plates (`recipes.hidden_at`; names in `ff_removed_recipe_names()`), and deletes the planner and Family Menu entries that used them. Food logs are kept. Hidden recipes never appear in recipe lists, plan generation, swaps, or edits. They were also removed from `starter-recipes.json`, so new installs never seed them. To bring one back, set its `hidden_at` to NULL.
