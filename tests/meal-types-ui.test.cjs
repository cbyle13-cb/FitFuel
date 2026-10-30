const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');const {JSDOM}=require('jsdom');
function setup(){const d=new JSDOM('<header><div id="sub"></div></header><nav id="nav"></nav><main id="app"></main>',{url:'https://fitfuel.example',runScripts:'dangerously'}),w=d.window;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'))};
 w.eval(fs.readFileSync('meal-core.js','utf8')+'\n'+fs.readFileSync('recipe-core.js','utf8')+'\n'+fs.readFileSync('index.html','utf8').match(/<script>\s*([\s\S]*?)<\/script>/)[1]+'\n'+fs.readFileSync('meal-planner.js','utf8')+'\n'+fs.readFileSync('family-menu.js','utf8')+'\nthis.state=s;this.recipes=recipes;this.openRecipe=openRecipe;this.editMealTypes=editMealTypes;this.saveMealTypes=saveMealTypes;this.recipeForm=recipeForm;this.saveRcp=saveRcp;this.planner=MealPlanner;this.fallback=recipeImageFallback;this.recipeImageFallback=recipeImageFallback;this.MC=MealCore;this.setUser=u=>s.user=u;this.recipesPage=recipes;this.setRecipeMeal=setRecipeMeal;this.listR=listR;');
 w.state.user={id:1,first_name:'Chris'};w.state.family=[{id:1,member_role:'owner'},{id:2,member_role:'member'}];w.alert=x=>{throw Error(x)};return w;
}
const base={servings:4,ingredients:['800 g beef'],calories_per_serving:571,protein_per_serving:43,carbs_per_serving:48,fat_per_serving:22,nutrition_status:'provided'};
function capture(w,list){w.fetch=async(url,opt)=>{if(opt?.body)list.push(JSON.parse(opt.body));return{ok:true,status:200,json:async()=>({success:true,recipes:w.state.recipes,recipe:{}})}}}
test('MealCore reads lists, legacy categories, and any',()=>{const w=setup(),c=w.MC;assert.deepEqual([...c.mealTypes({meal_types:'snack,lunch'})],['snack','lunch']);assert.deepEqual([...c.mealTypes({meal_category:'breakfast'})],['breakfast']);assert.equal(c.mealTypes({meal_category:'any'}).length,4);assert.ok(c.fits({meal_types:'lunch,dinner'},'dinner'));assert.ok(!c.fits({meal_types:'lunch,dinner'},'snack'));});
test('recipe cards show meal types and broken photos swap to the placeholder once',()=>{const w=setup();w.state.recipes=[{...base,id:5,user_id:1,recipe_name:'Taco Boats',meal_types:'lunch,dinner',image_url:'https://scontent.cdninstagram.com/x.jpg'}];w.recipes();const text=w.document.getElementById('rl').textContent;assert.match(text,/Lunch · Dinner/);
 const img=w.document.querySelector('.recipe-photo');img.dispatchEvent(new w.Event('error'));assert.match(img.src,/assets\/recipes\/placeholder\.svg$/);assert.equal(img.closest('button').hidden,false);img.dispatchEvent(new w.Event('error'));assert.match(img.src,/placeholder\.svg$/);
 assert.ok(fs.readFileSync('assets/recipes/placeholder.svg','utf8').includes('<svg'));});
test('owner edits meal types from the recipe popup; starters and members cannot',async()=>{const w=setup(),sent=[];capture(w,sent);w.state.recipes=[{...base,id:5,user_id:1,recipe_name:'Taco Boats',meal_category:'any'},{...base,id:6,user_id:null,recipe_name:'Starter',meal_category:'dinner'},{...base,id:7,user_id:2,recipe_name:'Kid wrap',meal_types:'snack'}];
 w.openRecipe(6);assert.doesNotMatch(w.document.getElementById('recipeDialog').textContent,/Edit meal types/);assert.match(w.document.getElementById('mealTypeSummary').textContent,/Dinner/);
 w.openRecipe(7);assert.match(w.document.getElementById('recipeDialog').textContent,/Edit meal types/);
 w.openRecipe(5);assert.match(w.document.getElementById('mealTypeSummary').textContent,/Any meal/);w.editMealTypes(5);w.document.getElementById('mt_dinner').checked=true;w.document.getElementById('mt_lunch').checked=true;
 await w.saveMealTypes(5,w.document.createElement('button'));assert.deepEqual(sent[0],{action:'recipe_meal_types_save',id:5,meal_types:['lunch','dinner']});
 w.setUser({id:2});w.openRecipe(5);assert.doesNotMatch(w.document.getElementById('recipeDialog').textContent,/Edit meal types/);});
test('add-recipe form and nutrition review send meal types',async()=>{const w=setup(),sent=[];capture(w,sent);w.state.recipes=[{...base,id:5,user_id:1,recipe_name:'Taco Boats',meal_types:'snack,lunch'}];
 w.recipeForm({name:'Wrap'});w.document.getElementById('newType_snack').checked=true;w.document.getElementById('newType_lunch').checked=true;await w.saveRcp();assert.deepEqual(sent[0].meal_types,['lunch','snack']);assert.equal(sent[0].meal_category,'lunch');
 w.planner.review(5);assert.equal(w.document.getElementById('reviewType_snack').checked,true);assert.equal(w.document.getElementById('reviewType_dinner').checked,false);await w.planner.saveReview(5,w.document.createElement('button'));assert.deepEqual(sent[1].meal_types,['lunch','snack']);});
test('recipe import page keeps the JSON meal type when saving',async()=>{const html=fs.readFileSync('recipe-import.html','utf8');const d=new JSDOM(html,{url:'https://fitfuel.example/recipe-import.html',runScripts:'dangerously',pretendToBeVisual:true}),w=d.window,sent=[];
 w.fetch=async(url,opt)=>{if(opt?.body)sent.push({url,body:JSON.parse(opt.body)});return{ok:true,status:200,json:async()=>({success:true,recipe_name:'X'})}};
 const doc=w.document;doc.getElementById('jsonInput').value=JSON.stringify({recipe_name:'Buffalo Chicken Burrito',meal_category:'lunch',meal_types:['lunch','dinner'],servings:8,ingredients:['900 g chicken breast'],instructions:'Roll.',calories_per_serving:276,protein_per_serving:38.4,carbs_per_serving:15.1,fat_per_serving:10});
 doc.getElementById('jsonBtn').click();assert.equal(doc.getElementById('importType_lunch').checked,true);assert.equal(doc.getElementById('importType_dinner').checked,true);assert.equal(doc.getElementById('importType_snack').checked,false);
 doc.getElementById('saveBtn').click();await new Promise(r=>setTimeout(r,20));const save=sent.find(x=>/api\.php|shortcut_recipe\.php/.test(x.url));assert.ok(save,'save request sent');assert.deepEqual(save.body.meal_types,['lunch','dinner']);assert.equal(save.body.meal_category,'lunch');});

function libraryPage(w){w.state.recipes=[{...base,id:1,user_id:1,recipe_name:'Taco Boats',meal_types:'lunch,dinner'},{...base,id:2,user_id:null,recipe_name:'Overnight Oats',meal_category:'breakfast',ingredients:['oats']},{...base,id:3,user_id:1,recipe_name:'Mystery Bowl',meal_category:'any'},{...base,id:4,user_id:1,recipe_name:'Queso Dip',meal_types:'snack',protein_per_serving:10,calories_per_serving:300},{...base,id:5,user_id:1,recipe_name:'Beef Chili',meal_types:'dinner,lunch',ingredients:['beef','beans']}];w.recipesPage();}
const names=w=>[...w.document.querySelectorAll('#rl .recipe-name-button')].map(b=>b.textContent).sort();
const chip=(w,m)=>w.document.querySelector(`.meal-chip[data-meal="${m}"]`);
test('meal chips filter by every type a recipe has; untyped recipes show only under All',()=>{const w=setup();libraryPage(w);
 assert.deepEqual([...w.document.querySelectorAll('.meal-chip')].map(b=>b.textContent),['All','Breakfast','Lunch','Dinner','Snack']);
 assert.equal(chip(w,'all').getAttribute('aria-pressed'),'true');assert.equal(names(w).length,5);
 chip(w,'lunch').click();assert.deepEqual(names(w),['Beef Chili','Taco Boats']);assert.equal(chip(w,'lunch').getAttribute('aria-pressed'),'true');assert.equal(chip(w,'all').getAttribute('aria-pressed'),'false');
 chip(w,'dinner').click();assert.deepEqual(names(w),['Beef Chili','Taco Boats']);
 chip(w,'breakfast').click();assert.deepEqual(names(w),['Overnight Oats']);
 chip(w,'snack').click();assert.deepEqual(names(w),['Queso Dip']);
 chip(w,'all').click();assert.ok(names(w).includes('Mystery Bowl'));});
test('meal filter combines with search and the recipe filter, and shows an empty message',()=>{const w=setup();libraryPage(w);
 chip(w,'lunch').click();const rs=w.document.getElementById('rs');rs.value='beans';rs.dispatchEvent(new w.Event('input'));assert.deepEqual(names(w),['Beef Chili']);
 rs.value='';rs.dispatchEvent(new w.Event('input'));chip(w,'snack').click();const pf=w.document.getElementById('proteinFilter');pf.value='protein';pf.dispatchEvent(new w.Event('change'));
 assert.deepEqual(names(w),[]);assert.match(w.document.getElementById('rl').textContent,/No recipes match in Snack/);});
test('meal filter choice is remembered across visits',()=>{const w=setup();libraryPage(w);chip(w,'breakfast').click();assert.equal(w.localStorage.getItem('ff.recipeMeal'),'breakfast');
 w.recipesPage();assert.equal(chip(w,'breakfast').getAttribute('aria-pressed'),'true');assert.deepEqual(names(w),['Overnight Oats']);
 w.localStorage.setItem('ff.recipeMeal','brunch');w.recipesPage();assert.equal(chip(w,'all').getAttribute('aria-pressed'),'true');});
test('chip styles wrap so every chip stays visible on mobile',()=>{const css=fs.readFileSync('ui-refresh.css','utf8');assert.match(css,/\.meal-filter\{display:flex;flex-wrap:wrap/);assert.match(css,/meal-chip\[aria-pressed="true"\]\{background:var\(--brand\)/);assert.match(fs.readFileSync('index.html','utf8'),/ui-refresh\.css\?v=7/);});
