"""Shrimp and tuna removal (remove-shrimp-tuna-v1) against isolated temporary MariaDB data; never touch production."""
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
with tempfile.TemporaryDirectory(prefix='fitfuel-removal-') as td:
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
  A='https://fitfuel.bylerfinancial.com/assets/recipes/'
  sql("INSERT INTO families(id,name,invite_code,created_by) VALUES(1,'Byler','FFAAAA1111',1);INSERT INTO family_members(family_id,user_id,member_role) VALUES(1,1,'owner'),(1,2,'member');")
  call('api.php?action=recipes')  # creates the schema and runs every migration once
  # Simulate the live database: the three starters seeded from the old starter-recipes.json, plus plans that use them.
  sql("DELETE FROM ff_migrations WHERE name='remove-shrimp-tuna-v1';"
      "INSERT INTO recipes(user_id,recipe_name,starter_key,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,fiber_per_serving,meal_category) VALUES"
      "(NULL,'Shrimp Rice Skillet','ff-protein-v1-shrimp','[\"1000 g shrimp\"]','Cook.',5,450,40,50,8,3,'dinner'),"
      "(NULL,'Tuna Yogurt Wraps','ff-protein-v1-tuna','[\"600 g tuna\"]','Wrap.',4,380,42,35,7,4,'lunch'),"
      "(NULL,'Tuna Toast Snack Plates','ff-protein-v1-tuna-snack','[\"500 g tuna\"]','Toast.',4,250,30,20,4,3,'snack'),"
      "(1,'Tuna Melt Sandwich',NULL,'[\"tuna\"]','Melt.',2,400,35,30,15,2,'lunch');")
  rid=lambda n:int(scalar(f"SELECT id FROM recipes WHERE recipe_name='{n}'"))
  shrimp,tuna,snack,melt=rid('Shrimp Rice Skillet'),rid('Tuna Yogurt Wraps'),rid('Tuna Toast Snack Plates'),rid('Tuna Melt Sandwich')
  salmon=rid('Salmon Quinoa and Broccoli')
  sql(f"INSERT INTO food_logs(user_id,food_date,meal_type,food_name,servings,calories,protein_g,carbs_g,fat_g,fiber_g,recipe_id) VALUES(1,'2026-10-12','lunch','Tuna Yogurt Wraps',1,380,42,35,7,4,{tuna});"
      f"INSERT INTO meal_plans(user_id,plan_date,recipe_id,meal_order,meal_type) VALUES(1,'2026-10-12',{tuna},1,'lunch'),(1,'2026-10-21',{shrimp},2,'dinner'),(1,'2026-10-21',{salmon},3,'dinner');"
      f"INSERT INTO family_meal_plans(id,family_id,plan_date,meal_type,meal_order,recipe_id) VALUES(901,1,'2026-10-21','snack',1,{snack}),(902,1,'2026-10-21','dinner',1,{salmon});"
      "INSERT INTO family_meal_portions(family_meal_id,user_id,portion_servings) VALUES(901,1,1),(901,2,1),(902,1,1);")
  names={r['recipe_name'] for r in call('api.php?action=recipes')['recipes']}
  assert not names & {'Shrimp Rice Skillet','Tuna Yogurt Wraps','Tuna Toast Snack Plates'},names
  assert 'Salmon Quinoa and Broccoli' in names and 'Tuna Melt Sandwich' in names
  assert scalar(f"SELECT COUNT(*) FROM recipes WHERE id IN ({shrimp},{tuna},{snack}) AND hidden_at IS NOT NULL")=='3'  # soft-hidden, not deleted
  assert scalar(f"SELECT COUNT(*) FROM meal_plans WHERE recipe_id IN ({shrimp},{tuna},{snack})")=='0' and scalar(f"SELECT COUNT(*) FROM meal_plans WHERE recipe_id={salmon}")=='1'
  assert scalar("SELECT COUNT(*) FROM family_meal_plans WHERE id=901")=='0' and scalar("SELECT COUNT(*) FROM family_meal_portions WHERE family_meal_id=901")=='0'
  assert scalar("SELECT COUNT(*) FROM family_meal_plans WHERE id=902")=='1' and scalar("SELECT COUNT(*) FROM family_meal_portions WHERE family_meal_id=902")=='1'
  assert scalar(f"SELECT COUNT(*) FROM food_logs WHERE recipe_id={tuna}")=='1'  # history kept
  # Hidden recipes can't come back through edits, swaps, or the Family Menu.
  call(body={'action':'recipe_meal_types_save','id':tuna,'meal_types':['lunch']},status=404)
  fam=call('family_planner.php?action=family_week&start=2026-10-19')
  assert all(m['recipe']['id'] not in (shrimp,tuna,snack) for day in (fam.get('week') or {}).values() for m in day)
  call('api.php?action=recipes')
  assert scalar("SELECT COUNT(*) FROM ff_migrations WHERE name='remove-shrimp-tuna-v1'")=='1' and scalar("SELECT COUNT(*) FROM recipes WHERE starter_key IS NOT NULL")=='18'
  print('Recipe removal integration: shrimp and tuna recipes soft-hidden, plan references removed, salmon and history kept.')
 except Exception:
  print((base/'db.log').read_text()[-2000:]);print((base/'php.log').read_text()[-3000:]);raise
 finally:
  if server:server.terminate();server.wait(timeout=5)
  db.terminate();db.wait(timeout=10);log.close();webLog.close()
