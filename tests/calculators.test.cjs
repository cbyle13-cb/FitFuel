const {test}=require('node:test');
const assert=require('node:assert/strict');
const calc=require('../calculators.js');

test('strength-focused fat-loss macros use Mifflin-St Jeor and fill all targets',()=>{
 const result=calc.dailyTargets({sex:'male',dateOfBirth:'1976-10-01',heightInches:70,weightPounds:225,activity:'moderate',goal:'fat_loss',macroStyle:'strength'},new Date('2026-09-11T12:00:00'));
 assert.deepEqual({age:result.age,bmi:result.bmi,protein:result.protein,fiber:result.fiber},{age:49,bmi:32.3,protein:203,fiber:38});
 assert.equal(result.calories>=result.bmr,true);assert.equal(result.carbs>=0,true);
 assert.deepEqual(result.healthyWeight,{low:129,high:174});
});
test('balanced maintenance uses a lower protein starting rate',()=>assert.equal(calc.dailyTargets({sex:'female',dateOfBirth:'1980-01-01',heightInches:64,weightPounds:150,activity:'light',goal:'maintenance',macroStyle:'balanced'},new Date('2026-09-11')).protein,105));
test('hydration adds exercise fluid to baseline',()=>assert.deepEqual(calc.hydration(220,45),{baseline:110,exercise:18,total:128}));
test('goal timeline returns weeks and date',()=>assert.deepEqual(calc.goalTimeline(225,185,1,new Date('2026-09-11T12:00:00')),{pounds:40,weeks:40,date:'2027-06-18',direction:'loss'}));
test('one rep max returns rounded training loads',()=>assert.deepEqual(calc.oneRepMax(50,10),{max:65,training:{60:40,70:45,80:55,90:60}}));
test('heart-rate zones use heart-rate reserve',()=>{const r=calc.heartRateZones(49,65);assert.equal(r.max,174);assert.deepEqual(r.zones.easy,{low:130,high:141})});
test('invalid inputs are rejected',()=>{assert.throws(()=>calc.dailyTargets({}),/sex/);assert.throws(()=>calc.oneRepMax(50,20),/1–12/);assert.throws(()=>calc.goalTimeline(225,185,10),/2%/)});
