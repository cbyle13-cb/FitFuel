const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');

test('calculator center renders, calculates macros, and offers profile save',()=>{
 const dom=new JSDOM('<div id="sub"></div><nav id="nav"></nav><main id="app"></main>',{url:'https://fitfuel.example/',runScripts:'dangerously'}),w=dom.window;
 let html=fs.readFileSync('index.html','utf8'),inline=html.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace('init();','');
 w.eval(fs.readFileSync('calculators.js','utf8')+'\n'+inline+'\ns.user={id:1,first_name:"Test"};s.profile={date_of_birth:"1976-10-01",height_inches:70,current_weight:225,goal_weight:185,activity_level:"moderate",fitness_goal:"fat_loss"};calculators();');
 assert.match(w.document.getElementById('app').textContent,/Calories & Macros/);
 assert.match(w.document.getElementById('app').textContent,/Goal Timeline/);
 assert.match(w.document.getElementById('app').textContent,/Estimated 1-Rep Max/);
 w.document.getElementById('cSex').value='male';w.runNutritionCalculator();
 assert.match(w.document.getElementById('nutritionResult').textContent,/203g/);
 assert.match(w.document.getElementById('nutritionResult').textContent,/Use These Targets in FitFuel/);
 dom.window.close();
});
