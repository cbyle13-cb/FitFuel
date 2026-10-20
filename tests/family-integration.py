"""Family Menu endpoints against isolated temporary MariaDB data; never touch production."""
import json, os, shlex, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PHP=shlex.split(os.environ.get('FF_PHP','php'))
DB=os.environ.get('FF_MARIADBD','mariadbd')
def freeport():
 with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
def php(code):
 result=subprocess.run(PHP+['-r',code],text=True,capture_output=True)
 if result.returncode:raise RuntimeError(result.stdout+result.stderr)
 return result.stdout
with tempfile.TemporaryDirectory(prefix='fitfuel-family-') as td:
 base=Path(td);app=base/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git'));data=base/'db';data.mkdir();sessions=base/'sessions';sessions.mkdir();dbport=freeport();webport=freeport();log=open(base/'db.log','w');webLog=open(base/'php.log','w')
 db=subprocess.Popen([DB,'--no-defaults','--user=root',f'--datadir={data}','--socket=','--skip-grant-tables','--bind-address=127.0.0.1',f'--port={dbport}',f'--pid-file={base}/mysql.pid'],stdout=log,stderr=log)
 server=None
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',dbport),timeout=.2):break
   except OSError:
    if db.poll() is not None:raise RuntimeError((base/'db.log').read_text())
    time.sleep(.1)
  else:raise RuntimeError('Database did not start')
  config=f"<?php return ['db_host'=>'127.0.0.1:{dbport}','db_user'=>'root','db_password'=>'','db_name'=>'fitfuel_test'];"
  (base/'fitfuel_private.php').write_text(config)
  schema=(ROOT/'tests/nutrition-fixture.sql').read_text()
  # mysqli accepts the standard host:port syntax already used by the application.
  php(f"$c=new mysqli('127.0.0.1','root','','',{dbport});$c->multi_query("+json.dumps(schema)+");do{if($r=$c->store_result())$r->free();}while($c->more_results()&&$c->next_result());")
  for uid in [1,2,3]:php(f"session_save_path('{sessions}');session_id('fftest{uid}');session_start();$_SESSION['user_id']={uid};session_write_close();")
  server=subprocess.Popen(PHP+['-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{webport}','-t',str(app)],stdout=webLog,stderr=webLog)
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',webport),timeout=.2):break
   except OSError:time.sleep(.05)
  def call(path='api.php',body=None,uid=1,status=200):
   headers={'Cookie':f'PHPSESSID=fftest{uid}','Content-Type':'application/json'};req=urllib.request.Request(f'http://127.0.0.1:{webport}/API/'+path,data=None if body is None else json.dumps(body).encode(),headers=headers)
   try:
    with urllib.request.urlopen(req,timeout=20) as r:code=r.status;raw=r.read().decode()
   except urllib.error.HTTPError as e:code=e.code;raw=e.read().decode()
   assert code==status,(path,code,raw)
   try:return json.loads(raw)
   except ValueError:raise AssertionError(raw)
  def scalar(sql):return php(f"$c=new mysqli('127.0.0.1','root','','fitfuel_test',{dbport});echo $c->query("+json.dumps(sql)+")->fetch_row()[0];")
  def sql(q):php(f"$c=new mysqli('127.0.0.1','root','','fitfuel_test',{dbport});$c->multi_query("+json.dumps(q)+");do{if($r=$c->store_result())$r->free();}while($c->more_results()&&$c->next_result());")
  sql("INSERT INTO users VALUES(3,'test3@example.com','Outside','Three','user',1);INSERT INTO user_profiles(user_id,current_weight,daily_calorie_goal,daily_protein_goal,daily_carbs_goal,daily_fat_goal) VALUES(3,180,2000,150,200,60);INSERT INTO families(id,name,invite_code,created_by) VALUES(1,'Byler','FFAAAA1111',1),(2,'Two solo','FFBBBB2222',2),(3,'Other','FFCCCC3333',3);INSERT INTO family_members(family_id,user_id,member_role) VALUES(1,1,'owner'),(2,2,'owner'),(3,3,'owner');")
  sql("INSERT INTO recipes(user_id,recipe_name,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,fiber_per_serving) VALUES(1,'Shrimp power bowl','[\"800 g shrimp\"]','Cook.',4,450,40,40,10,2)")
  # Migration: additive and idempotent across many requests; existing rows untouched.
  for _ in range(2):call('family_planner.php?action=family_week&start=2026-10-11')
  assert scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='fitfuel_test' AND table_name='recipes' AND column_name='total_cooked_weight_g'")=='1'
  assert scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='fitfuel_test' AND table_name IN('family_meal_plans','family_meal_portions')")=='2'
  assert scalar('SELECT COUNT(*) FROM food_logs')=='1' and scalar('SELECT COUNT(*) FROM meal_plans')=='1' and scalar("SELECT recipe_name FROM recipes WHERE id=1")=='Existing recipe'
  # Teen settings: maintenance-only auto targets, opt out of family lunch.
  teen=call('profile.php',{'sex':'female','date_of_birth':'2010-05-01','height_inches':65,'current_weight':130,'planner_activity':'moderate','planner_goal':'fat_loss_fast','auto_targets':True,'excluded_foods':'shrimp','family_meals':{'breakfast':True,'lunch':False,'dinner':True,'snack':True}},uid=2)['profile']
  assert float(teen['daily_protein_goal'])==round(130*.55) and teen['family_meals']['lunch'] is False and teen['meal_shares']['dinner']==35
  call('profile.php',{'meal_shares':{'breakfast':50,'lunch':50,'dinner':50,'snack':0}},uid=2,status=422)
  # Deterministic family + solo-family cleanup on join.
  assert call('family_planner.php?action=family_week',uid=2)['members'][0]['first_name']=='Test'
  call(body={'action':'join_family','invite_code':'ffaaaa1111'},uid=2)
  assert scalar('SELECT COUNT(*) FROM families WHERE id=2')=='0' and scalar('SELECT GROUP_CONCAT(family_id) FROM family_members WHERE user_id=2')=='1'
  assert call('api.php?action=bootstrap',uid=2)['family_info']['id']==1
  call(body={'action':'join_family','invite_code':'FFCCCC3333'},uid=1);assert scalar('SELECT COUNT(*) FROM families WHERE id=1')=='1'
  assert call('api.php?action=bootstrap',uid=1)['family_info']['id']==3
  sql('DELETE FROM family_members WHERE family_id=3 AND user_id=1');assert call('api.php?action=bootstrap',uid=1)['family_info']['id']==1
  week='2026-10-11';gen=call('family_planner.php',{'action':'family_plan_generate','start_date':week});assert gen['success']
  data=call('family_planner.php?action=family_week&start='+week,uid=2);raw=json.dumps(data)
  for secret in ['current_weight','daily_','date_of_birth','height','email','excluded_foods','body_fat']:assert secret not in raw,secret
  assert len(data['week'])==7 and all(len(v)==4 for v in data['week'].values()) and not data['can_manage']
  for meals in data['week'].values():
   for m in meals:
    eating=[p for p in m['portions'] if not p['skipped']];assert float(m['recipe']['protein_per_serving'])>=25
    if any(p['user_id']==2 for p in eating):assert 'shrimp' not in m['recipe']['recipe_name'].lower();assert all(.25<=p['servings']<=3 for p in eating)
    assert abs(sum(p['servings'] for p in eating)-m['batch_servings'])<.02 and abs(m['batch_multiplier']-m['batch_servings']/float(m['recipe']['servings']))<.01
    teenLine=next(p for p in m['portions'] if p['user_id']==2);assert teenLine['skipped']==(m['meal_type']=='lunch')
  dinner=next(m for m in data['week'][week] if m['meal_type']=='dinner');mid=dinner['id'];me=lambda m,u:next(p for p in m['portions'] if p['user_id']==u)
  dad,kid=me(dinner,1),me(dinner,2);assert dad['servings']>kid['servings'],(dad,kid)
  # Membership: outsiders cannot see or touch this family's meals.
  assert call('family_planner.php?action=family_week&start='+week,uid=3)['week']=={}
  for body in [{'action':'family_plan_log','meal_id':mid},{'action':'family_portion_override','meal_id':mid,'servings':1},{'action':'family_plan_swap','meal_id':mid,'recipe_id':1}]:call('family_planner.php',body,uid=3,status=422)
  # Overrides: owner may adjust anyone, members only themselves, bounds enforced.
  call('family_planner.php',{'action':'family_portion_override','meal_id':mid,'user_id':1,'servings':1},uid=2,status=403)
  call('family_planner.php',{'action':'family_portion_override','meal_id':mid,'servings':5},uid=2,status=422)
  call('family_planner.php',{'action':'family_portion_override','meal_id':mid,'user_id':2,'servings':1.5},uid=1)
  day=lambda u=1:next(m for m in call('family_planner.php?action=family_week&start='+week,uid=u)['week'][week] if m['id']==mid)
  assert me(day(),2)['servings']==1.5 and me(day(),2)['override']
  call('family_planner.php',{'action':'family_portion_override','meal_id':mid,'servings':None},uid=2);assert not me(day(),2)['override']
  call('family_planner.php',{'action':'family_portion_override','meal_id':mid,'skip':True},uid=2);d=day();assert me(d,2)['skipped'] and abs(d['batch_servings']-me(d,1)['servings'])<.02
  call('family_planner.php',{'action':'family_plan_log','meal_id':mid},uid=2,status=422)
  call('family_planner.php',{'action':'family_portion_override','meal_id':mid,'skip':False},uid=2)
  # Swap: excluded foods of anyone eating are rejected; overrides cleared.
  shrimp=int(scalar("SELECT id FROM recipes WHERE recipe_name='Shrimp power bowl'"))
  call('family_planner.php',{'action':'family_plan_swap','meal_id':mid,'recipe_id':shrimp},status=422)
  other=next(r for r in call('api.php?action=recipes')['recipes'] if r['starter_key'] and r['meal_category']=='dinner' and int(r['id'])!=int(dinner['recipe']['id']))
  call('family_planner.php',{'action':'family_portion_override','meal_id':mid,'servings':2.5})
  call('family_planner.php',{'action':'family_plan_swap','meal_id':mid,'recipe_id':int(other['id'])});d=day();assert d['recipe']['id']==int(other['id']) and not me(d,1)['override']
  # Logging is per person and idempotent; logged meals survive regeneration and block swaps.
  call('family_planner.php',{'action':'family_plan_log','meal_id':mid});call('family_planner.php',{'action':'family_plan_log','meal_id':mid})
  assert scalar("SELECT COUNT(*) FROM food_logs WHERE source='family_meal' AND user_id=1")=='1' and float(scalar("SELECT servings FROM food_logs WHERE source='family_meal'"))==me(d,1)['servings']
  call('family_planner.php',{'action':'family_plan_swap','meal_id':mid,'recipe_id':int(dinner['recipe']['id'])},status=422)
  call('family_planner.php',{'action':'family_plan_generate','start_date':week});assert day()['id']==mid and me(day(),1)['logged']
  call('family_planner.php',{'action':'family_plan_generate','start_date':'2026-99-99'},status=422)
  # Remaining macros: a big logged breakfast shrinks the rest of that day's portions.
  rid=int(other['id']);q=lambda date:next(p for p in call(f'family_planner.php?action=portion_preview&recipe_id={rid}&meal_type=dinner&date={date}')['portions'] if p['user_id']==1)['servings']
  before=q('2026-10-14');call(body={'action':'food_add','food_date':'2026-10-14','meal_type':'breakfast','food_name':'Huge breakfast','servings':1,'calories':1500,'protein_g':100,'carbs_g':150,'fat_g':50});assert q('2026-10-14')<before
  # Cooked weight gives grams; invalid weights are rejected before saving.
  mine=int(scalar("SELECT id FROM recipes WHERE recipe_name='Existing recipe'"))
  call(body={'action':'recipe_nutrition_save','id':mine,'servings':4,'calories_per_serving':400,'protein_per_serving':40,'carbs_per_serving':40,'fat_per_serving':8,'fiber_per_serving':2,'meal_category':'dinner','total_cooked_weight_g':1200})
  prev=call(f'family_planner.php?action=portion_preview&recipe_id={mine}',uid=2);line=next(p for p in prev['portions'] if p['user_id']==2);assert line['grams']==round(line['servings']*300)
  n=scalar('SELECT COUNT(*) FROM recipes');call(body={'action':'recipe_add','recipe_name':'Bad weight','total_cooked_weight_g':-4},status=422);assert scalar('SELECT COUNT(*) FROM recipes')==n
  call(f'family_planner.php?action=portion_preview&recipe_id={int(scalar("SELECT id FROM recipes WHERE user_id=1 LIMIT 1"))}',uid=3,status=422)
  print('Family integration: migration, teen targets, join cleanup, generation, privacy, membership, overrides, skips, swaps, logs, remaining macros, and cooked weight passed.')
 except Exception:
  print((base/'db.log').read_text());print((base/'php.log').read_text());raise
 finally:
  if server:server.terminate();server.wait(timeout=5)
  db.terminate();db.wait(timeout=10);log.close();webLog.close()
