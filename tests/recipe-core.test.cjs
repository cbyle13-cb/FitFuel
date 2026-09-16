const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '..', 'recipe-core.js'), 'utf8');
const context = {};
vm.runInNewContext(`${source}\nthis.recipeCore = RecipeCore;`, context);
const core = context.recipeCore;

test('scales whole, decimal, mixed, and unicode ingredient quantities', () => {
  assert.equal(core.scaleIngredient('2 cups flour', 2), '4 cups flour');
  assert.equal(core.scaleIngredient('1.5 cups milk', 2), '3 cups milk');
  assert.equal(core.scaleIngredient('1 1/2 cups oats', 2), '3 cups oats');
  assert.equal(core.scaleIngredient('½ cup yogurt', 3), '1 1/2 cup yogurt');
});

test('scales quantity ranges while preserving their separator', () => {
  assert.equal(core.scaleIngredient('1-2 tbsp oil', 2), '2-4 tbsp oil');
  assert.equal(core.scaleIngredient('1 to 2 pinches salt', 1.5), '1 1/2 to 3 pinches salt');
});

test('scales imported ingredient quantities after a divider', () => {
  assert.equal(core.scaleIngredient('Chicken breast — 6 oz', 1.5), 'Chicken breast — 9 oz');
  assert.equal(core.scaleIngredient('Lime juice - 1/2-1 tbsp', 2), 'Lime juice - 1-2 tbsp');
});

test('leaves ingredients without an explicit quantity unchanged', () => {
  assert.equal(core.scaleIngredient('Salt to taste', 3), 'Salt to taste');
  assert.equal(core.scaleIngredient('Fresh basil, torn', 0.5), 'Fresh basil, torn');
});
