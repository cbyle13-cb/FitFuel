const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');

test('calculator center calculates and saves the displayed snapshot without automatic overwrite',async()=>{
 const dom=new JSDOM('<div id="sub"></div><nav id="nav"></nav><main id="app"></main>',{url:'https://fitfuel.example/',runScripts:'dangerously'}),w=dom.window;
 let html=fs.readFileSync('index.html','utf8'),inline=html.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace('init();','');
 w.eval(fs.readFileSync('meal-core.js','utf8')+'\n'+fs.readFileSync('calculators.js','utf8')+'\n'+inline+'\ns.user={id:1,first_name:"Test"};s.profile={date_of_birth:"1976-10-01",height_inches:70,current_weight:225,goal_weight:185,activity_level:"moderate",fitness_goal:"fat_loss"};calculators();this.runNutritionCalculator=runNutritionCalculator;this.saveCalculatedTargets=saveCalculatedTargets;');
 assert.match(w.document.getElementById('app').textContent,/Calories & Macros/);
 assert.match(w.document.getElementById('app').textContent,/Goal Timeline/);
 assert.match(w.document.getElementById('app').textContent,/Estimated 1-Rep Max/);
 w.document.getElementById('cSex').value='male';w.runNutritionCalculator();
 assert.match(w.document.getElementById('nutritionResult').textContent,/203g/);
 assert.match(w.document.getElementById('nutritionResult').textContent,/Use These Targets in FitFuel/);
 let saved;
 w.fetch=async(url,opt)=>{if(opt?.body)saved=JSON.parse(opt.body);return {ok:true,json:async()=>({success:true})}};
 w.document.getElementById('cWeight').value='200';
 await w.saveCalculatedTargets();
 assert.equal(saved.current_weight,225);
 assert.equal(saved.daily_protein_goal,203);
 assert.equal(saved.planner_activity,'moderate');
 assert.equal(saved.planner_goal,'fat_loss');
 assert.equal(saved.auto_targets,false);
 assert.equal(saved.sex,'male');
 assert.match(w.document.getElementById('targetSaveMessage').textContent,/Targets saved/);
 dom.window.close();
});
