'use strict';
const MealCore=(()=>{
 function dateKey(d=new Date()){return`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;}
 function shift(key,n){const d=new Date(key+'T12:00:00');d.setDate(d.getDate()+n);return dateKey(d);}
 function weekStart(key=dateKey()){const d=new Date(key+'T12:00:00');return shift(key,-d.getDay());}
 function highProtein(r){return Number(r.protein_per_serving)>=25&&Number(r.protein_per_serving)*4>=Number(r.calories_per_serving)*.2;}
 function complete(r){return r.nutrition_status!=='incomplete'&&['calories','protein','carbs','fat'].every(k=>Number.isFinite(Number(r[k+'_per_serving']))&&r[k+'_per_serving']!==null&&r[k+'_per_serving']!==undefined&&Number(r[k+'_per_serving'])>=0)&&Number(r.calories_per_serving)>0&&Number(r.protein_per_serving)>0;}
 function eligible(r,p){const hay=(r.recipe_name+' '+(r.ingredients||[]).join(' ')).toLowerCase();return complete(r)&&highProtein(r)&&!String(p.excluded_foods||'').split(/[,\n]+/).map(x=>x.trim().toLowerCase()).filter(Boolean).some(x=>hay.includes(x));}
 function totals(meals){return meals.reduce((t,r)=>{for(const k of Object.keys(t))t[k]+=Number(r[k+'_per_serving']||0)*Number(r.portion_servings||1);return t},{calories:0,protein:0,carbs:0,fat:0});}
 function warning(meals,p){const t=totals(meals);return Object.entries({calories:'calorie',protein:'protein',carbs:'carbs',fat:'fat'}).filter(([k,f])=>Number(p['daily_'+f+'_goal'])>0&&Math.abs(t[k]-Number(p['daily_'+f+'_goal']))/Number(p['daily_'+f+'_goal'])>.1).map(([k])=>k);}
 function prep(plans){const groups=new Map();Object.values(plans||{}).flat().forEach(r=>{const id=Number(r.recipe_id||r.id);if(!groups.has(id))groups.set(id,{...r,portions:0,meals:0});const g=groups.get(id);g.portions+=Number(r.portion_servings||1);g.meals++;});return [...groups.values()].map(r=>({...r,batches:r.portions/Math.max(.1,Number(r.servings)||1)}));}
 return{dateKey,shift,weekStart,highProtein,complete,eligible,totals,warning,prep};
})();
