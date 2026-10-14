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
