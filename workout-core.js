/* Pure progression rules; weights are per dumbbell or single implement, in lb. */
(function(root){
 const completed=e=>(e.sets||[]).filter(s=>s.done&&s.reps>0);
 function suggest(target, previous, timed=false){
  const base={weight:target.weight,reps:target.min,reason:'Choose a comfortable starting weight and record your first session.'};
  if(!previous)return base;
  const sets=completed(previous);
  if(!sets.length)return base;
  const weight=Math.min(target.cap,Math.min(...sets.map(s=>s.weight)));
  const reps=Math.max(target.min,Math.min(target.max,Math.min(...sets.map(s=>s.reps))));
  if(previous.effort==='pain')return {weight,reps:target.min,reason:'Discomfort recorded. Review or swap this exercise before continuing; no automatic increase.'};
  if(previous.effort==='hard')return {weight,reps:target.min,reason:'Last session was hard. Repeat the load with a lower rep target.'};
  if(previous.sets.length!==target.sets.length||sets.length!==previous.sets.length)return {weight,reps,reason:'Repeat this target: the previous session did not match all planned sets.'};
  if(!['easy','right'].includes(previous.effort))return {weight,reps,reason:'Repeat this target until you record how the completed exercise felt.'};
  if(sets.every(s=>s.reps>=target.max)&&sets.every(s=>s.weight===sets[0].weight)){
   if(!timed && target.increment>0 && weight+target.increment<=target.cap)return {weight:+(weight+target.increment).toFixed(2),reps:target.min,reason:'All sets reached the top of your range. Increase one weight step and restart at the lower rep target.'};
   return {weight,reps:target.max,reason:'Top of range reached. Keep this target or edit the range/equipment when ready.'};
  }
  return {weight,reps:Math.min(target.max,reps+(timed?5:1)),reason:timed?'Add up to 5 seconds with the same load.':'Add one rep per set with the same load.'};
 }
 function metrics(e){const sets=completed(e);return {sets:sets.length,reps:sets.reduce((n,s)=>n+s.reps,0),weight:Math.max(0,...sets.map(s=>s.weight)),volume:sets.reduce((n,s)=>n+s.weight*s.reps,0)};}
 const api={suggest,metrics,completed};root.WorkoutCore=api;if(typeof module!=='undefined')module.exports=api;
})(typeof window==='undefined'?globalThis:window);
