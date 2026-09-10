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

test('connection uses OAuth discovery, PKCE and no key in the MCP URL',()=>{
  const source=read('fitfuel-connector-setup.html');
  const oauth=read('API/oauth_lib.php'),mcp=read('API/mcp.php');
  assert.match(source,/OAuth 2\.1 with PKCE/);
  assert.match(oauth,/FITFUEL_CHATGPT_REDIRECT/);
  assert.match(oauth,/hash\('sha256', \$token\)/);
  assert.match(mcp,/fitfuel_oauth_user/);
  assert.doesNotMatch(mcp,/\$_GET\['key'\]/);
});
