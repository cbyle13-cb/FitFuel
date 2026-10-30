(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  else root.FitFuelCalculators=api;
})(typeof globalThis!=='undefined'?globalThis:this,function(){
  const activityFactors={sedentary:1.2,light:1.375,moderate:1.55,active:1.725,extra_active:1.9};
  const goalAdjustments={fat_loss_slow:-250,fat_loss:-500,fat_loss_fast:-750,maintenance:0,muscle_gain:250};

  function finite(value){const number=Number(value);return Number.isFinite(number)?number:null}
  function ageOn(dateOfBirth,asOf=new Date()){
    const dob=new Date(String(dateOfBirth)+'T12:00:00');
    if(!dateOfBirth||Number.isNaN(dob.getTime())||dob>asOf)return null;
    let age=asOf.getFullYear()-dob.getFullYear();
    if(asOf.getMonth()<dob.getMonth()||(asOf.getMonth()===dob.getMonth()&&asOf.getDate()<dob.getDate()))age--;
    return age;
  }
  function bmiCategory(bmi){return bmi<18.5?'Below healthy adult range':bmi<25?'Healthy adult range':bmi<30?'Above healthy adult range':'Well above healthy adult range'}
  function healthyWeightRange(heightInches){
    const height=finite(heightInches);if(height===null||height<36||height>96)throw Error('Enter a valid height.');
    const divisor=703/(height*height);
    return {low:Math.round(18.5/divisor),high:Math.round(24.9/divisor)};
  }
  function dailyTargets(input,asOf=new Date()){
    const sex=input.sex,age=ageOn(input.dateOfBirth,asOf),height=finite(input.heightInches),weight=finite(input.weightPounds);
    if(!['male','female'].includes(sex))throw Error('Select the sex used by the BMR formula.');
    if(age===null||age>120)throw Error('Enter a valid date of birth.');
    if(age<18)throw Error('These formulas are for adults age 18 and older.');
    if(height===null||height<36||height>96||weight===null||weight<50||weight>1000)throw Error('Enter a valid height and current weight.');
    if(!Object.prototype.hasOwnProperty.call(activityFactors,input.activity))throw Error('Select an activity level.');
    if(!Object.prototype.hasOwnProperty.call(goalAdjustments,input.goal))throw Error('Select a goal.');
    const kg=weight*.45359237,cm=height*2.54,bmi=weight*703/(height*height);
    const bmr=Math.round(10*kg+6.25*cm-5*age+(sex==='male'?5:-161));
    const tdee=Math.round(bmr*activityFactors[input.activity]);
    const calories=Math.ceil(Math.max(bmr,tdee+goalAdjustments[input.goal])/10)*10;
    const strengthFocused=input.macroStyle!=='balanced';
    const proteinRate=strengthFocused&&['fat_loss_slow','fat_loss','fat_loss_fast','muscle_gain'].includes(input.goal)?.9:.7;
    const protein=Math.round(weight*proteinRate);
    const fatPercent=input.macroStyle==='lower_carb'?.30:.25;
    const fat=Math.round(calories*fatPercent/9);
    const carbs=Math.max(0,Math.round((calories-protein*4-fat*9)/4));
    const fiber=age>50?(sex==='male'?30:21):(sex==='male'?38:25);
    return {age,bmi:Number(bmi.toFixed(1)),bmiCategory:bmiCategory(bmi),bmr,tdee,calories,protein,carbs,fat,fiber,healthyWeight:healthyWeightRange(height)};
  }
  function hydration(weightPounds,exerciseMinutes=0){
    const weight=finite(weightPounds),minutes=finite(exerciseMinutes);
    if(weight===null||weight<50||weight>1000)throw Error('Enter a valid current weight.');
    if(minutes===null||minutes<0||minutes>600)throw Error('Enter valid exercise minutes.');
    const baseline=Math.round(weight*.5),exercise=Math.round(minutes/30*12);
    return {baseline,exercise,total:baseline+exercise};
  }
  function goalTimeline(currentWeight,goalWeight,weeklyChange,start=new Date()){
    const current=finite(currentWeight),goal=finite(goalWeight),pace=Math.abs(finite(weeklyChange));
    if(current===null||goal===null||current<50||goal<50||current>1000||goal>1000)throw Error('Enter valid current and goal weights.');
    if(!pace||pace>.02*current)throw Error('Choose a weekly pace no greater than 2% of body weight.');
    const pounds=Math.abs(current-goal),weeks=Math.ceil(pounds/pace),date=new Date(start);date.setDate(date.getDate()+weeks*7);
    return {pounds:Number(pounds.toFixed(1)),weeks,date:date.toISOString().slice(0,10),direction:goal<current?'loss':'gain'};
  }
  function oneRepMax(weightPounds,reps){
    const weight=finite(weightPounds),count=Math.round(finite(reps));
    if(weight===null||weight<=0||weight>2000||!count||count<1||count>12)throw Error('Enter a weight and 1–12 completed reps.');
    const max=count===1?weight:weight*(1+count/30),round5=value=>Math.round(value/5)*5;
    return {max:round5(max),training:{60:round5(max*.6),70:round5(max*.7),80:round5(max*.8),90:round5(max*.9)}};
  }
  function heartRateZones(age,restingHeartRate){
    const years=Math.round(finite(age)),rest=finite(restingHeartRate);
    if(!years||years<18||years>120||rest===null||rest<35||rest>120)throw Error('Enter a valid age and resting heart rate.');
    const max=208-.7*years,reserve=max-rest,range=(low,high)=>({low:Math.round(rest+reserve*low),high:Math.round(rest+reserve*high)});
    return {max:Math.round(max),zones:{recovery:range(.5,.6),easy:range(.6,.7),moderate:range(.7,.8),hard:range(.8,.9)}};
  }
  return {ageOn,dailyTargets,healthyWeightRange,hydration,goalTimeline,oneRepMax,heartRateZones};
});
