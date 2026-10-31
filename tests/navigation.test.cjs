const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');

const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const setupPages=['shortcut-setup.html','recipe-import.html','fitfuel-connector-setup.html'];

// Parse markup only (no scripts) and walk up to make sure nothing hides the link.
function visibleBackLink(file){
 const doc=new JSDOM(read(file)).window.document;
 const link=doc.getElementById('backLink');
 assert.ok(link,`${file} has a header back link`);
 assert.equal(link.getAttribute('href'),'./',`${file} back link goes to the app start page`);
 assert.ok(!link.hasAttribute('target'),`${file} back link stays in the app window`);
 assert.ok(!/history\.back/.test(link.getAttribute('onclick')||''),`${file} back link does not depend on history`);
 for(let el=link;el&&el.nodeType===1;el=el.parentElement){
  assert.ok(!el.classList.contains('hidden'),`${file}: back link is not inside .hidden (${el.id||el.tagName})`);
  assert.ok(!el.hidden,`${file}: back link is not inside [hidden]`);
  assert.ok(!/display\s*:\s*none/i.test(el.getAttribute('style')||''),`${file}: back link is not inside display:none`);
  assert.notEqual(el.tagName,'DETAILS',`${file}: back link is not inside a collapsed <details>`);
 }
 return doc;
}

for(const file of setupPages){
 test(`${file} always shows a Back to FitFuel link`,()=>{visibleBackLink(file)});
 test(`${file} never opens a new window`,()=>{
  const html=read(file);
  assert.doesNotMatch(html,/target\s*=\s*["']?_blank/i);
  assert.doesNotMatch(html,/window\.open\s*\(/);
 });
}

test('recipe import Back button is outside the hidden review form',()=>{
 const doc=visibleBackLink('recipe-import.html');
 const btn=doc.getElementById('backBtn');
 assert.ok(btn);
 assert.equal(btn.closest('#form'),null);
});

test('back link stays visible after the page scripts run and before any key exists',()=>{
 for(const file of ['shortcut-setup.html','recipe-import.html']){
  const dom=new JSDOM(read(file),{url:'https://fitfuel.example/'+file,runScripts:'dangerously'});
  const link=dom.window.document.getElementById('backLink');
  assert.ok(link&&link.isConnected,file);
  assert.notEqual(dom.window.getComputedStyle(link).display,'none',file);
  dom.window.close();
 }
});

test('401 while creating a key links to login with a return path back to the page',async()=>{
 const dom=new JSDOM(read('shortcut-setup.html'),{url:'https://fitfuel.example/shortcut-setup.html',runScripts:'dangerously'}),w=dom.window;
 w.fetch=async()=>({ok:false,status:401,json:async()=>({success:false,message:'Please log in.'})});
 w.document.getElementById('createBtn').click();
 await new Promise(r=>setTimeout(r,20));
 const a=w.document.querySelector('#status a');
 assert.equal(a.getAttribute('href'),'./?return=shortcut-setup.html');
 assert.ok(w.document.getElementById('backLink').isConnected);
 dom.window.close();
 const ri=new JSDOM(read('recipe-import.html'),{url:'https://fitfuel.example/recipe-import.html',runScripts:'dangerously'}),rw=ri.window;
 rw.fetch=async()=>({ok:false,status:401,json:async()=>({success:false,message:'Please log in.'})});
 rw.document.getElementById('enableKeyBtn').click();
 await new Promise(r=>setTimeout(r,20));
 assert.equal(rw.document.querySelector('#keyStatus a').getAttribute('href'),'./?return=recipe-import.html');
 assert.equal(rw.document.getElementById('enableKeyBtn').disabled,false);
 ri.window.close();
});

test('created key is shown in a selectable box with a copy button and a Done link',async()=>{
 const dom=new JSDOM(read('shortcut-setup.html'),{url:'https://fitfuel.example/shortcut-setup.html',runScripts:'dangerously'}),w=dom.window;
 w.fetch=async()=>({ok:true,status:200,json:async()=>({success:true,import_token:'abc123'})});
 w.document.getElementById('createBtn').click();
 await new Promise(r=>setTimeout(r,20));
 assert.equal(w.document.getElementById('tokenBox').textContent,'abc123');
 assert.equal(w.document.getElementById('tokenBox').style.display,'block');
 assert.equal(w.document.getElementById('copyBtn').style.display,'block');
 assert.equal(w.document.getElementById('doneLink').getAttribute('href'),'./');
 dom.window.close();
});

test('login only returns to known FitFuel pages',()=>{
 const html=read('index.html'),inline=html.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace('init();','');
 const dom=new JSDOM('<div id="sub"></div><nav id="nav"></nav><main id="app"></main>',{url:'https://fitfuel.example/',runScripts:'dangerously'}),w=dom.window;
 w.eval(read('meal-core.js')+'\n'+inline+'\nthis.loginReturnTarget=loginReturnTarget;');
 assert.equal(w.loginReturnTarget('?return=shortcut-setup.html'),'./shortcut-setup.html');
 assert.equal(w.loginReturnTarget('?return=recipe-import.html'),'./recipe-import.html');
 assert.equal(w.loginReturnTarget('?return=https://evil.example/'),null);
 assert.equal(w.loginReturnTarget('?return=//evil.example'),null);
 assert.equal(w.loginReturnTarget(''),null);
 assert.match(html,/if\(loginReturn\(\)\)return;await load\(\);show\("home"\)/);
 dom.window.close();
});

test('app links to the setup pages in the same window',()=>{
 const html=read('index.html');
 assert.match(html,/location\.href='shortcut-setup\.html'/);
 assert.match(html,/location\.href='recipe-import\.html'/);
 assert.doesNotMatch(html,/href="(?:\.\/)?(?:shortcut-setup|recipe-import|fitfuel-connector-setup)\.html"[^>]*target=/);
 const manifest=JSON.parse(read('manifest.json'));
 assert.equal(manifest.scope,'./');assert.equal(manifest.start_url,'./');
});

test('plan tabs use the names My Meal Plan and Family Meal Plan, with routes unchanged',()=>{
 const html=read('index.html'),inline=html.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace('init();','');
 const dom=new JSDOM('<div id="sub"></div><nav id="nav"></nav><main id="app"></main>',{url:'https://fitfuel.example/',runScripts:'dangerously'}),w=dom.window;
 w.eval(read('meal-core.js')+'\n'+inline+'\nnav("plan");');
 const labels=[...w.document.querySelectorAll('#nav button')].map(b=>b.textContent);
 assert.ok(labels.includes('My Meal Plan'));assert.ok(labels.includes('Family Meal Plan'));
 assert.ok(!labels.includes('Meal Plan'));assert.ok(!labels.includes('Family Menu'));
 const buttons=[...w.document.querySelectorAll('#nav button')];
 assert.match(buttons.find(b=>b.textContent==='My Meal Plan').getAttribute('onclick'),/show\('plan'\)/);
 assert.match(buttons.find(b=>b.textContent==='Family Meal Plan').getAttribute('onclick'),/show\('familymenu'\)/);
 dom.window.close();
 const visible=['index.html','family-menu.js','meal-planner.js'].map(read).join('\n');
 assert.doesNotMatch(visible,/Family Menu|FAMILY MENU|family menu/);
 assert.match(read('family-menu.js'),/FAMILY MEAL PLAN/);
 assert.match(read('meal-planner.js'),/MY MEAL PLAN/);
});
