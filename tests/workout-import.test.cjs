const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const read=file=>fs.readFileSync(file,'utf8');

test('weekly workout import uses hashed account-scoped keys and idempotent records',()=>{
  const source=read('API/workout_import_lib.php');
  assert.match(source,/hash\('sha256', \$token\)/);
  assert.match(source,/WHERE t\.token_hash=\?/);
  assert.match(source,/fitfuel_week_token\(\$userId, \$week, \$slot\)/);
  assert.match(source,/Label exactly three sessions as priority/);
  assert.match(source,/source' => 'chatgpt_weekly_plan'/);
});

test('MCP exposes one read action and one weekly-plan write action',()=>{
  const source=read('API/mcp.php');
  assert.match(source,/'name'=>'get_training_context'/);
  assert.match(source,/'name'=>'save_weekly_plan'/);
  assert.match(source,/Call get_training_context first/);
  assert.match(source,/'minItems'=>3,'maxItems'=>5/);
});

test('connection setup never requests or embeds the FitFuel password',()=>{
  const source=read('fitfuel-connector-setup.html');
  assert.doesNotMatch(source,/type=["']password/i);
  assert.match(source,/API\/shortcut_token\.php/);
  assert.match(source,/Do not post it, email it, or commit it to GitHub/);
});
