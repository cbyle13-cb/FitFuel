"""Run real endpoints against isolated temporary MariaDB data; never touch production."""
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
with tempfile.TemporaryDirectory(prefix='fitfuel-nutrition-') as td:
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
  for uid in [1,2]:php(f"session_save_path('{sessions}');session_id('fftest{uid}');session_start();$_SESSION['user_id']={uid};session_write_close();")
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
  boot=call('api.php?action=bootstrap&date=2026-10-14&plan_start=2026-10-11');assert len([r for r in boot['recipes'] if r['starter_key']])==18
  call('api.php?action=bootstrap');assert scalar("SELECT COUNT(*) FROM recipes WHERE starter_key IS NOT NULL")=='18'
  assert scalar('SELECT COUNT(*) FROM food_logs')=='1';assert scalar('SELECT COUNT(*) FROM progress_logs')=='1';assert scalar('SELECT portion_servings FROM meal_plans WHERE id=1')=='1.00'
  profile={'sex':'male','date_of_birth':'1977-02-01','height_inches':70,'current_weight':223,'goal_weight':185,'planner_activity':'moderate','planner_goal':'fat_loss','meals_per_day':4,'excluded_foods':'shrimp','daily_water_goal':96,'auto_targets':True}
  saved=call('profile.php',profile)['profile'];assert saved['daily_water_goal']==96;assert saved['sex']=='male';initial=float(saved['daily_calorie_goal'])
  call(body={'action':'progress_add','log_date':time.strftime('%Y-%m-%d'),'weight':215});updated=call('profile.php')['profile'];assert float(updated['current_weight'])==215;assert float(updated['daily_calorie_goal'])<initial
  call(body={'action':'progress_add','log_date':'2020-01-01','weight':260});assert float(call('profile.php')['profile']['current_weight'])==215
  call(body={'action':'progress_add','weight':-2},status=422);assert scalar('SELECT COUNT(*) FROM progress_logs')=='3'
  week='2026-10-11';call(body={'action':'plan_generate','start_date':week});boot=call('api.php?action=bootstrap&plan_start='+week);assert len(boot['plans'])==7;assert all(len(v)==4 for v in boot['plans'].values());assert scalar('SELECT COUNT(*) FROM meal_plans WHERE user_id=2')=='0'
  for meals in boot['plans'].values():
   for r in meals:assert .5<=float(r['portion_servings'])<=2.5;assert 'shrimp' not in r['recipe_name'].lower()
  day=boot['plans'][week];meal=day[0];call(body={'action':'plan_log','plan_id':int(meal['plan_id'])});call(body={'action':'plan_log','plan_id':int(meal['plan_id'])});assert scalar("SELECT COUNT(*) FROM food_logs WHERE source='meal_plan'")=='1'
  call(body={'action':'plan_log','plan_id':int(meal['plan_id'])},uid=2,status=422)
  replacement=next(r for r in boot['recipes'] if r['starter_key'] and r['meal_category']=='lunch' and int(r['id'])!=int(day[1]['recipe_id']))
  call(body={'action':'plan_swap','plan_date':week,'plan_id':int(day[1]['plan_id']),'recipe_id':int(replacement['id'])})
  after=call('api.php?action=bootstrap&plan_start='+week)['plans'][week];assert int(after[1]['recipe_id'])==int(replacement['id']);assert after[0]['portion_servings']==meal['portion_servings'];assert after[0]['logged_food_id']
  call(body={'action':'plan_swap','plan_date':week,'plan_id':int(meal['plan_id']),'recipe_id':int(replacement['id'])},status=422)
  foreign=int(scalar('SELECT id FROM recipes WHERE user_id=2 LIMIT 1'));call(body={'action':'plan_save','plan_date':week,'recipe_ids':[foreign]},status=422)
  before=scalar('SELECT COUNT(*) FROM meal_plans');call(body={'action':'plan_generate','start_date':'2026-99-99'},status=422);assert scalar('SELECT COUNT(*) FROM meal_plans')==before
  call('profile.php',{'auto_targets':False,'daily_calorie_goal':2100,'daily_protein_goal':175});call(body={'action':'progress_add','log_date':time.strftime('%Y-%m-%d'),'weight':210});assert float(call('profile.php')['profile']['daily_calorie_goal'])==2100
  call(body={'action':'recipe_add','recipe_name':'Partial estimate','servings':2,'ingredients':['200 g chicken breast','some sauce'],'instructions':'Cook chicken.'});partial=next(r for r in call('api.php?action=recipes')['recipes'] if r['recipe_name']=='Partial estimate');assert partial['nutrition_status']=='incomplete'
  call(body={'action':'recipe_nutrition_save','id':int(partial['id']),'servings':2,'calories_per_serving':400,'protein_per_serving':35,'carbs_per_serving':35,'fat_per_serving':13,'fiber_per_serving':2,'meal_category':'lunch'})
  reviewed=next(r for r in call('api.php?action=recipes')['recipes'] if r['id']==partial['id']);assert reviewed['nutrition_status']=='provided'
  call(body={'action':'recipe_nutrition_save','id':int(partial['id'])},uid=2,status=403)
  estimate=call('nutrition.php',{'servings':2,'ingredients':['200 g chicken breast','100 g dry rice']})['estimate'];assert estimate['nutrition_status']=='estimated'
  call('profile.php',{'auto_targets':True,'date_of_birth':'2015-01-01'},status=422);assert float(call('profile.php')['profile']['daily_calorie_goal'])==2100
  print('Nutrition integration: migration, preservation, profile, weigh-ins, generation, swaps, idempotent logs, ownership, estimates, review, and rollback passed.')
 except Exception:
  print((base/'db.log').read_text());print((base/'php.log').read_text());raise
 finally:
  if server:server.terminate();server.wait(timeout=5)
  db.terminate();db.wait(timeout=10);log.close();webLog.close()
