'use strict';
const RecipeCore=(()=>{
 const unicodeFractions={'¼':.25,'½':.5,'¾':.75,'⅓':1/3,'⅔':2/3,'⅕':.2,'⅖':.4,'⅗':.6,'⅘':.8,'⅙':1/6,'⅚':5/6,'⅛':.125,'⅜':.375,'⅝':.625,'⅞':.875};
 const fractionPattern='(?:\\d+\\s+\\d+\\/\\d+|\\d+\\/\\d+|\\d+(?:\\.\\d+)?\\s*[¼½¾⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]|[¼½¾⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]|\\d+(?:\\.\\d+)?)';
 function number(value){
  const text=String(value).trim();
  const unicode=text.match(/^(-?\d+(?:\.\d+)?)?\s*([¼½¾⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞])$/);
  if(unicode)return Number(unicode[1]||0)+unicodeFractions[unicode[2]];
  const mixed=text.match(/^(\d+)\s+(\d+)\/(\d+)$/);
  if(mixed)return Number(mixed[1])+Number(mixed[2])/Number(mixed[3]);
  const fraction=text.match(/^(\d+)\/(\d+)$/);
  if(fraction&&Number(fraction[2]))return Number(fraction[1])/Number(fraction[2]);
  const parsed=Number(text);return Number.isFinite(parsed)?parsed:null;
 }
 function format(value){
  const rounded=Math.round(Number(value)*100)/100;
  if(!Number.isFinite(rounded))return'';
  const whole=Math.floor(rounded+1e-9),remainder=rounded-whole;
  const fractions=[[1/8,'1/8'],[1/6,'1/6'],[1/5,'1/5'],[1/4,'1/4'],[1/3,'1/3'],[3/8,'3/8'],[2/5,'2/5'],[1/2,'1/2'],[3/5,'3/5'],[5/8,'5/8'],[2/3,'2/3'],[3/4,'3/4'],[4/5,'4/5'],[5/6,'5/6'],[7/8,'7/8']];
  if(remainder<.02)return String(whole);
  const match=fractions.find(([part])=>Math.abs(remainder-part)<.025);
  if(match)return`${whole?whole+' ':''}${match[1]}`;
  return String(Number(rounded.toFixed(2)));
 }
 function scaleToken(token,multiplier){const value=number(token);return value===null?token:format(value*multiplier);}
 function scaleQuantity(text,multiplier){
  const range=new RegExp(`^(${fractionPattern})(\\s*(?:-|–|to)\\s*)(${fractionPattern})`,'iu');
  const single=new RegExp(`^(${fractionPattern})`,'iu');
  if(range.test(text))return text.replace(range,(_,a,join,b)=>`${scaleToken(a,multiplier)}${join}${scaleToken(b,multiplier)}`);
  return text.replace(single,token=>scaleToken(token,multiplier));
 }
 function scaleIngredient(line,multiplier){
  const text=String(line||'');if(!Number.isFinite(Number(multiplier))||Number(multiplier)<=0||Number(multiplier)===1)return text;
  const leading=new RegExp(`^\\s*${fractionPattern}`,'iu');
  if(leading.test(text))return(text.match(/^\s*/)?.[0]||'')+scaleQuantity(text.trimStart(),Number(multiplier));
  const divider=new RegExp(`([—–-]\\s*)(${fractionPattern}(?:\\s*(?:-|–|to)\\s*${fractionPattern})?)`,'iu');
  return text.replace(divider,(_,dash,quantity)=>dash+scaleQuantity(quantity,Number(multiplier)));
 }
 function scaledIngredients(ingredients,multiplier){return(ingredients||[]).map(line=>scaleIngredient(line,multiplier));}
 return{number,format,scaleIngredient,scaledIngredients};
})();
