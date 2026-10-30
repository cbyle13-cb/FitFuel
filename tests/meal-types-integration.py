"""Meal types, run-once backfills, and recipe photos against isolated temporary MariaDB data; never touch production."""
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
with tempfile.TemporaryDirectory(prefix='fitfuel-types-') as td:
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
  # Simulate the live database before this release: image_url and meal_category exist, imports were saved as 'any'.
  sql("ALTER TABLE recipes ADD COLUMN image_url VARCHAR(2048) NULL AFTER source_url, ADD COLUMN meal_category VARCHAR(20) NOT NULL DEFAULT 'any';INSERT INTO users VALUES(3,'test3@example.com','Outside','Three','user',1);INSERT INTO families(id,name,invite_code,created_by) VALUES(1,'Byler','FFAAAA1111',1),(3,'Other','FFCCCC3333',3);INSERT INTO family_members(family_id,user_id,member_role) VALUES(1,1,'owner'),(1,2,'member'),(3,3,'owner');")
  rows=[(1,'Sweet Potato Beef Taco Boats',A+'sweet-potato-beef-taco-boats.jpg',571,43),(1,'High Protein Beef Queso Game Day Dip',A+'beef-queso-game-day-dip.jpg',242,28),(1,'High Protein Inspired McGriddles',A+'high-protein-mcgriddles.jpg',430,40),(1,'Steak Chimichurri & Mashed Sweet Potato Bowl',A+'steak-chimichurri-sweet-potato-bowl.jpg',660,48),
   (3,'Sweet Potato Beef Taco Boats','https://example.com/other.jpg',571,43),(1,'Creamy Chicken Alfredo Fried Rice','https://scontent-iad3-1.cdninstagram.com/v/t51/expired.jpg?oe=68000000',573,51),(1,'Creamy Chicken Alfredo Fried Rice','https://example.com/someone-else.jpg',300,20),(1,'Creamy Cajun Chicken Pasta','https://scontent-iad3-1.cdninstagram.com/v/t51/cajun.jpg?oe=68000000',985,57)]
  sql(';'.join("INSERT INTO recipes(user_id,recipe_name,image_url,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,fiber_per_serving) VALUES(%d,%s,%s,'[\"800 g beef\"]','Cook.',4,%d,%d,30,10,2)"%(u,json.dumps(n),json.dumps(i),c,p) for u,n,i,c,p in rows))
  recipes=lambda u=1:call('api.php?action=recipes',uid=u)['recipes']
  one=lambda name,u=1,img=None:next(r for r in recipes(u) if r['recipe_name']==name and (img is None or r['image_url']==img))
  call('api.php?action=recipes');call('api.php?action=recipes',uid=2)
  assert scalar("SELECT COUNT(*) FROM ff_migrations WHERE name IN ('meal-types-v1','recipe-photos-v1')")=='2'
  assert one('Sweet Potato Beef Taco Boats')['meal_types']=='lunch,dinner' and one('Sweet Potato Beef Taco Boats')['meal_category']=='lunch'
  assert one('High Protein Beef Queso Game Day Dip')['meal_types']=='snack' and one('High Protein Inspired McGriddles')['meal_types']=='breakfast' and one('Steak Chimichurri & Mashed Sweet Potato Bowl')['meal_types']=='dinner,lunch'
  assert scalar("SELECT COALESCE(meal_types,'NULL') FROM recipes WHERE user_id=3")=='NULL' and scalar("SELECT meal_category FROM recipes WHERE user_id=3")=='any'
  assert scalar("SELECT meal_types FROM recipes WHERE starter_key='ff-protein-v1-chicken-rice'")=='lunch,dinner' and scalar("SELECT COUNT(*) FROM recipes WHERE starter_key IS NOT NULL AND meal_types IS NULL")=='0'
  # Photos: only the hotlinked 573-cal Alfredo row moves to the committed asset; Cajun and look-alikes untouched.
  assert scalar("SELECT image_url FROM recipes WHERE recipe_name='Creamy Chicken Alfredo Fried Rice' AND calories_per_serving=573")==A+'creamy-chicken-alfredo-fried-rice.jpg'
  assert scalar("SELECT image_url FROM recipes WHERE recipe_name='Creamy Chicken Alfredo Fried Rice' AND calories_per_serving=300")=='https://example.com/someone-else.jpg'
  assert 'cdninstagram' in scalar("SELECT image_url FROM recipes WHERE recipe_name='Creamy Cajun Chicken Pasta'")
  assert (ROOT/'assets/recipes/creamy-chicken-alfredo-fried-rice.jpg').stat().st_size>20000 and (ROOT/'assets/recipes/placeholder.svg').exists()
  # Run-once: later user edits stick, even back to "any".
  taco=int(one('Sweet Potato Beef Taco Boats')['id']);call(body={'action':'recipe_meal_types_save','id':taco,'meal_types':[]});call('api.php?action=recipes')
  assert scalar(f'SELECT COALESCE(meal_types,"NULL") FROM recipes WHERE id={taco}')=='NULL' and scalar(f'SELECT meal_category FROM recipes WHERE id={taco}')=='any'
  call(body={'action':'recipe_meal_types_save','id':taco,'meal_types':['Dinner','lunch','dinner','brunch']});assert scalar(f'SELECT meal_types FROM recipes WHERE id={taco}')=='dinner,lunch'
  # Permissions: owner of recipe or family owner; members and outsiders can't; starters are shared.
  starter=int(scalar("SELECT id FROM recipes WHERE starter_key='ff-protein-v1-oats'"))
  call(body={'action':'recipe_meal_types_save','id':starter,'meal_types':['snack']},status=403)
  call(body={'action':'recipe_meal_types_save','id':taco,'meal_types':['snack']},uid=3,status=403)
  call(body={'action':'recipe_meal_types_save','id':taco,'meal_types':['snack']},uid=2,status=403)
  kid=call(body={'action':'recipe_add','recipe_name':'Kid Wrap','meal_types':['snack','lunch'],'servings':2,'calories_per_serving':400,'protein_per_serving':35,'carbs_per_serving':30,'fat_per_serving':10,'ingredients':['300 g chicken breast'],'instructions':'Wrap.'},uid=2)['id']
  assert scalar(f'SELECT meal_types FROM recipes WHERE id={kid}')=='snack,lunch' and scalar(f'SELECT meal_category FROM recipes WHERE id={kid}')=='snack'
  call(body={'action':'recipe_meal_types_save','id':kid,'meal_types':['lunch']});assert scalar(f'SELECT meal_types FROM recipes WHERE id={kid}')=='lunch'
  call(body={'action':'recipe_meal_types_save','id':999999,'meal_types':['lunch']},status=404)
  # Nutrition review keeps or replaces types.
  call(body={'action':'recipe_nutrition_save','id':taco,'servings':4,'calories_per_serving':571,'protein_per_serving':43,'carbs_per_serving':48,'fat_per_serving':22,'fiber_per_serving':5,'meal_types':['lunch']});assert scalar(f'SELECT meal_types FROM recipes WHERE id={taco}')=='lunch'
  # Family Menu slots honor meal types.
  call('family_planner.php',{'action':'family_plan_generate','start_date':'2026-10-11'})
  for meals in call('family_planner.php?action=family_week&start=2026-10-11')['week'].values():
   for m in meals:
    r=m['recipe'];types=[x for x in (r['meal_types'] or '').split(',') if x] or ([r['meal_category']] if r['meal_category']!='any' else ['breakfast','lunch','dinner','snack']);assert m['meal_type'] in types,(m['meal_type'],r['recipe_name'],types)
  assert call(f'family_planner.php?action=portion_preview&recipe_id={kid}')['meal_type']=='lunch'
  # Cached photos are served only to people who can see the recipe.
  alfredo=int(scalar("SELECT id FROM recipes WHERE recipe_name='Creamy Chicken Alfredo Fried Rice' AND calories_per_serving=573"))
  sql(f"INSERT INTO recipe_image_cache(recipe_id,mime,bytes,source_url) VALUES({alfredo},'image/jpeg',0xFFD8FFE000104A4649460001,'https://x.cdninstagram.com/a.jpg')")
  def raw(path,uid):
   req=urllib.request.Request(f'http://127.0.0.1:{webport}/API/'+path,headers={'Cookie':f'PHPSESSID=fftest{uid}'})
   try:
    with urllib.request.urlopen(req,timeout=20) as r:return r.status,r.headers.get('Content-Type'),r.read()
   except urllib.error.HTTPError as e:return e.code,None,b''
  st,ct,data=raw(f'recipe_image.php?id={alfredo}',2);assert st==200 and ct=='image/jpeg' and data[:3]==b'\xff\xd8\xff'
  assert raw(f'recipe_image.php?id={alfredo}',3)[0]==404 and raw('recipe_image.php?id=424242',1)[0]==404
  # Non-CDN links are never fetched or rewritten.
  call(body={'action':'recipe_add','recipe_name':'Linked','image_url':'https://example.com/a.jpg','servings':1,'calories_per_serving':400,'protein_per_serving':40,'carbs_per_serving':30,'fat_per_serving':10,'ingredients':['200 g chicken breast'],'instructions':'Cook.'});assert scalar("SELECT image_url FROM recipes WHERE recipe_name='Linked'")=='https://example.com/a.jpg'
  print('Meal types integration: run-once backfills, scoped photo fix, edits, permissions, importer types, family slots, and cached photo access passed.')
 except Exception:
  print((base/'db.log').read_text());print((base/'php.log').read_text());raise
 finally:
  if server:server.terminate();server.wait(timeout=5)
  db.terminate();db.wait(timeout=10);log.close();webLog.close()
