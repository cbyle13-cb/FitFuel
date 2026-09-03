// Run with NODE_PATH pointing to a jsdom installation; no production account required.
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');
const {JSDOM}=require('jsdom');
test('routine editing, logging, history, charts and account changes',async()=>{
 const dom=new JSDOM('<div id="sub"></div><nav id="nav"></nav><main id="app"></main>',{url:'https://fitfuel.example/',runScripts:'dangerously'}),w=dom.window;
 w.confirm=()=>true;w.alert=message=>{throw Error(message)};let db=[],nextId=1;
 const catalog=JSON.parse(fs.readFileSync('exercises.json','utf8'));
 w.fetch=async(url,opt)=>({ok:true,status:200,json:async()=>{
  if(url.includes('exercises.json'))return catalog;
  if(opt?.method==='POST'){const body=JSON.parse(opt.body);if(body.action==='save'){const record={...body.record,id:body.id||nextId++};db=db.filter(r=>r.id!==record.id);db.push(record);return {success:true,id:record.id};}return {success:true};}
  return {success:true,records:db,legacy:[]};
 }});
 let html=fs.readFileSync('index.html','utf8'),inline=html.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace('init();','');w.eval(inline+'\n'+fs.readFileSync('workout-core.js','utf8')+'\n'+fs.readFileSync('workouts.js','utf8')+'\nwindow.testState=s;window.ui=WorkoutUI;');
 w.testState.user={id:1,first_name:'Test'};w.testState.authenticated=true;
 await w.ui.open();w.ui.starter(0);assert.equal(w.document.querySelectorAll('#workoutEditor section').length,5);
 w.ui.top('name','Personal routine');w.ui.target(0,'weight',15);await w.ui.save('template');assert.equal(db[0].name,'Personal routine');assert.equal(db[0].exercises[0].sets[0].weight,15);
 w.ui.start(db[0].id);assert.equal(w.document.querySelectorAll('.set-grid input[type=checkbox]').length,15);
 for(let i=0;i<5;i++){for(let j=0;j<3;j++){w.ui.set(i,j,'done',true);w.ui.set(i,j,'reps',12);}w.ui.target(i,'effort','right');}
 await w.ui.save('session');assert.equal(db.filter(x=>x.kind==='session').length,1);assert.match(w.document.getElementById('app').textContent,/Workout history/);
 w.ui.view('charts');assert.equal(w.document.querySelectorAll('svg').length,3);
 w.ui.view('exercises');assert.equal(w.document.querySelectorAll('.exercise-card').length,30);
 w.ui.start(db[0].id);await w.ui.save('draft');assert.equal(db.filter(x=>x.kind==='draft').length,1);
 w.testState.user={id:2,first_name:'Other'};db=[];await w.ui.open();assert.doesNotMatch(w.document.getElementById('app').textContent,/Personal routine/);
 dom.window.close();
});
