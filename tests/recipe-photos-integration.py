"""One-time source photo fix (recipe-photos-v2) against isolated temporary MariaDB data; never touch production."""
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
with tempfile.TemporaryDirectory(prefix='fitfuel-photos-') as td:
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
  sql("ALTER TABLE recipes ADD COLUMN image_url VARCHAR(2048) NULL AFTER source_url, ADD COLUMN meal_category VARCHAR(20) NOT NULL DEFAULT 'any';INSERT INTO users VALUES(3,'test3@example.com','Outside','Three','user',1);")
  dead='https://scontent-iad3-1.cdninstagram.com/v/t51/x.jpg?oe=68000000'
  garbled='The reel text includes the nutrition information, so here is the recipe JSON'
  rows=[(1,'Baconator Bowls','https://www.instagram.com/reel/Dc806rwtgG3/?igsh=abc',dead),           # dead CDN link -> fixed
        (1,'Chicken Shawarma Wraps','https://www.instagram.com/reel/DcB7O3whrEi/',None),                # empty -> fixed
        (1,'Hot Honey Ribs','https://howtobbqright.com/2025/02/06/hot-honey-ribs/',''),                  # empty string -> fixed
        (2,'Buffalo Chicken Dip','https://www.allrecipes.com/recipe/68461/buffalo-chicken-dip/',None),   # any user's copy -> fixed
        (1,garbled,'https://www.instagram.com/reel/DdtucebzoR6/',dead),                                  # renamed + fixed
        (1,'Baconator Bowls','https://www.instagram.com/reel/SOMEOTHER1/',None),                         # other source -> untouched
        (1,'Mexican Street Corn Recipe','https://lilluna.com/mexican-street-corn/','API/recipe_image.php?id=9'),  # cached copy -> untouched
        (1,'Quick and Easy Buffalo Chicken Dip','https://www.allrecipes.com/recipe/266408/x/','https://fitfuel.bylerfinancial.com/assets/recipes/mine.jpg'),  # already ours -> untouched
        (1,'Street Corn Chicken Bowl','https://www.instagram.com/reel/Dc6V36LqGnV/',None)]               # different name -> untouched
  sql(';'.join("INSERT INTO recipes(user_id,recipe_name,source_url,image_url,ingredients,instructions,servings,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,fiber_per_serving) VALUES(%d,%s,%s,%s,'[\"x\"]','Cook.',4,300,30,30,10,2)"%(u,json.dumps(n),json.dumps(s),'NULL' if i is None else json.dumps(i)) for u,n,s,i in rows))
  call('api.php?action=recipes')
  img=lambda where:scalar("SELECT COALESCE(image_url,'NULL') FROM recipes WHERE "+where)
  assert img("source_url LIKE '%Dc806rwtgG3%'")==A+'baconator-bowls.jpg'
  assert img("recipe_name='Chicken Shawarma Wraps'")==A+'chicken-shawarma-wraps.jpg'
  assert img("recipe_name='Hot Honey Ribs'")==A+'hot-honey-ribs.jpg'
  assert img("recipe_name='Buffalo Chicken Dip'")==A+'buffalo-chicken-dip.jpg'
  assert scalar("SELECT COUNT(*) FROM recipes WHERE recipe_name LIKE 'The reel text%'")=='0'
  assert img("recipe_name='High-Protein Pizza Bites'")==A+'high-protein-pizza-bites.jpg'
  assert img("source_url LIKE '%SOMEOTHER1%'")=='NULL'
  assert img("recipe_name='Mexican Street Corn Recipe'")=='API/recipe_image.php?id=9'
  assert img("recipe_name='Quick and Easy Buffalo Chicken Dip'")=='https://fitfuel.bylerfinancial.com/assets/recipes/mine.jpg'
  assert img("recipe_name='Street Corn Chicken Bowl'")=='NULL'
  # Run once: a later change by the user is not overwritten.
  sql("UPDATE recipes SET image_url='https://example.com/new.jpg' WHERE recipe_name='Chicken Shawarma Wraps'");call('api.php?action=recipes')
  assert img("recipe_name='Chicken Shawarma Wraps'")=='https://example.com/new.jpg'
  assert scalar("SELECT COUNT(*) FROM ff_migrations WHERE name='recipe-photos-v2'")=='1'
  print('Recipe photos integration: scoped one-time photo fix, pizza bites rename, and run-once behavior passed.')
 except Exception:
  print((base/'db.log').read_text());print((base/'php.log').read_text());raise
 finally:
  if server:server.terminate();server.wait(timeout=5)
  db.terminate();db.wait(timeout=10);log.close();webLog.close()
