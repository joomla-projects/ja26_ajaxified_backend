import assert from 'node:assert/strict';
import test from 'node:test';
import ModuleAutosaveAdapter from '../../../../media_source/com_modules/src/module-autosave-adapter.es6.js';
import ModuleAutosaveController, { OPTIONS_KEY as MODULE_KEY } from '../../../../media_source/com_modules/src/module-autosave-controller.es6.js';
import StyleAutosaveAdapter from '../../../../media_source/com_templates/src/style-autosave-adapter.es6.js';
import StyleAutosaveController, { OPTIONS_KEY as STYLE_KEY } from '../../../../media_source/com_templates/src/style-autosave-controller.es6.js';

class Control extends EventTarget { constructor(id, name, value = '') { super(); this.id=id; this.name=name; this.value=value; this.checked=false; this.isConnected=true; } }
const schema={fingerprint:'a'.repeat(64),fields:[{path:['params','colour'],id:'jform_params_colour',kind:'string',maxLength:20}]};
const fixture=(formId, staticNames)=>{const fields=Object.fromEntries(staticNames.map((name)=>[name,new Control(`jform_${name}`,`jform[${name}]`,name)]));const dynamic=new Control('jform_params_colour','jform[params][colour]','blue');const showtitle=[new Control('jform_showtitle0','jform[showtitle]','0'),new Control('jform_showtitle1','jform[showtitle]','1')];showtitle[1].checked=true;const controls=[...Object.values(fields),...showtitle,dynamic];const form=new Control(formId,'adminForm');form.contains=(node)=>controls.includes(node);form.querySelectorAll=(selector)=>selector.includes('jform[showtitle]')?showtitle:selector.includes('jform[params][colour]')?[dynamic]:[];const documentSource=new EventTarget();documentSource.getElementById=(id)=>id===formId?form:controls.find((node)=>node.id===id)||null;return{fields,showtitle,dynamic,form,documentSource};};
const factories=(counter)=>({apiClientFactory:()=>({}),runtimeFactory:(options)=>({start(){counter.count+=1;return this;},destroy(){options.adapter.destroy();}}),coordinatorFactory:()=>({start(){return this;},destroy(){}}),presenterFactory:()=>({start(){return this;},destroy(){}})});
const options=(key,config)=>(name,fallback)=>name===key?config:name==='com_autosave.runtime'?{endpoints:{initialize:'i',preserve:'p',detect:'d',read:'r',discard:'x',prepareCanonicalAction:'c',getCanonicalActionOutcome:'o'}}:name==='csrf.token'?'token':fallback;

test('Module extension schema captures the real show-title radio group and approved params',()=>{const{fields,showtitle,dynamic,form}=fixture('module-form',['title','note','version_note','showtitle','position','content']);const adapter=new ModuleAutosaveAdapter({descriptor:{context:'com_modules.module',targetId:'7',payloadSchemaVersion:1},form,fields:{...fields,showtitle},dynamicFields:{colour:[dynamic]},schema}).initializeBaseline();assert.deepEqual(Object.keys(adapter.capture()),['title','note','version_note','showtitle','position','content','schemaFingerprint','params']);assert.equal(adapter.capture().showtitle,'1');assert.deepEqual(adapter.capture().params,{colour:'blue'});});

test('Module and Template Style production controllers activate once and remain isolated',async()=>{const module=fixture('module-form',['title','note','version_note','showtitle','position','content']);const style=fixture('style-form',['title']);const moduleCount={count:0},styleCount={count:0};const moduleConfig={enabled:true,context:'com_modules.module',targetId:'7',payloadSchemaVersion:1,formId:'module-form',fieldIds:Object.fromEntries(Object.entries(module.fields).map(([k,v])=>[k,v.id])),dynamicSchema:schema};const styleConfig={enabled:true,context:'com_templates.style',targetId:'9',payloadSchemaVersion:1,formId:'style-form',fieldIds:{title:'jform_title'},dynamicSchema:schema};const mc=new ModuleAutosaveController({documentSource:module.documentSource,optionsReader:options(MODULE_KEY,moduleConfig),...factories(moduleCount)});const sc=new StyleAutosaveController({documentSource:style.documentSource,optionsReader:options(STYLE_KEY,styleConfig),...factories(styleCount)});mc.start();sc.start();await new Promise((resolve)=>setImmediate(resolve));await mc.reconcile();await sc.reconcile();assert.equal(moduleCount.count,1);assert.equal(styleCount.count,1);module.documentSource.dispatchEvent(new Event('joomla:updated'));style.documentSource.dispatchEvent(new Event('joomla:updated'));await new Promise((resolve)=>setImmediate(resolve));assert.equal(moduleCount.count,1);assert.equal(styleCount.count,1);assert.equal(new StyleAutosaveAdapter({descriptor:{context:'com_templates.style',targetId:'9',payloadSchemaVersion:1},form:style.form,fields:style.fields,dynamicFields:{colour:[style.dynamic]},schema}).initializeBaseline().capture().params.colour,'blue');});

test('extension configuration adapters reject widened recovery payloads and dangerous schemas', () => {
  const module = fixture('module-form', ['title', 'note', 'version_note', 'showtitle', 'position', 'content']);
  const adapter = new ModuleAutosaveAdapter({
    descriptor: { context: 'com_modules.module', targetId: '7', payloadSchemaVersion: 1 },
    form: module.form,
    fields: { ...module.fields, showtitle: module.showtitle },
    dynamicFields: { colour: [module.dynamic] },
    schema,
  }).initializeBaseline();
  const payload = adapter.capture();
  payload.showtitle = '1';

  assert.throws(() => adapter.apply({ ...payload, module: 'mod_other' }), /recovery payload/);
  assert.throws(() => adapter.apply({ ...payload, params: { colour: 'blue', token: 'secret' } }), /params/);
  assert.throws(() => new ModuleAutosaveAdapter({
    descriptor: { context: 'com_modules.module', targetId: '7', payloadSchemaVersion: 1 },
    form: module.form,
    fields: module.fields,
    dynamicFields: { __proto__: [module.dynamic] },
    schema: {
      fingerprint: 'b'.repeat(64),
      fields: [{ path: ['params', '__proto__'], id: 'bad', kind: 'string', maxLength: 20 }],
    },
  }), /schema field/);
});
