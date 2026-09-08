const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('browser importer uses the shared server-side extractor', () => {
  assert.match(read('API/import_recipe.php'), /recipe_import_from_url/);
  assert.match(read('recipe-import.html'), /API\/import_recipe\.php/);
  assert.match(read('index.html'), /function webImport\(\)\{location\.href="recipe-import\.html"\}/);
});

test('Shortcut supports URL-only server extraction and legacy structured fields', () => {
  const endpoint = read('API/shortcut_recipe.php');
  assert.match(endpoint, /\$in\['url'\].*\$in\['recipe_url'\]/s);
  assert.match(endpoint, /recipe_import_from_url\(\$sharedUrl\)/);
  assert.match(endpoint, /\$in\['recipe_name'\].*\$in\['title'\]/s);
  assert.match(endpoint, /\$in\['import_key'\].*\$in\['import_token'\]/s);
});

test('remote fetch validates every redirect and pins DNS resolution', () => {
  const importer = read('API/recipe_importer.php');
  assert.match(importer, /CURLOPT_FOLLOWLOCATION=>false/);
  assert.match(importer, /CURLOPT_RESOLVE/);
  assert.match(importer, /recipe_import_validate_url\(\$url\)/);
  assert.match(importer, /FILTER_FLAG_NO_PRIV_RANGE \| FILTER_FLAG_NO_RES_RANGE/);
});
