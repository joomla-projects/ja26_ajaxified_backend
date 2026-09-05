import AutosaveIntegrationController, { defaultOptionsReader, isPlainObject, resolveAutosaveUiMount } from 'com_autosave.integration-controller';
import AutosaveCreateBinding from 'com_autosave.create-binding';
import ModuleAutosaveAdapter, { validateSchema } from './module-autosave-adapter.es6.js';
const OPTIONS_KEY='com_modules.autosave.module'; const TASK_POLICY=Object.freeze({'module.apply':{intent:'apply',transport:'ajax',canonical:true},'module.save':{intent:'save-exit',transport:'native',canonical:true},'module.save2new':{intent:'save-new',transport:'native',canonical:true},'module.save2copy':{intent:'save-copy',transport:'native',canonical:true},'module.cancel':{intent:'cancel',transport:'native',canonical:false}});
const validateConfiguration = (value) => {
  if (!isPlainObject(value) || value.enabled !== true || value.context !== 'com_modules.module' || value.payloadSchemaVersion !== 1 || value.formId !== 'module-form' || !isPlainObject(value.fieldIds)) return null;
  const mode = value.mode === 'create' ? 'create' : 'existing';
  const resolvedTargetId = mode === 'create' ? null : String(value.targetId);
  if (mode === 'create') {
    if (value.targetId !== null) throw new TypeError('Invalid Module create target mode.');
  } else if (!/^[1-9][0-9]*$/.test(resolvedTargetId)) {
    throw new TypeError('Invalid Module target.');
  }
  if (value.createScope !== undefined
    && !(typeof value.createScope === 'string' && value.createScope.length > 0 && value.createScope.length <= 255 && !/[\x00-\x1F\x7F]/.test(value.createScope))) throw new TypeError('Invalid Module creation scope.');
  value.dynamicSchema = validateSchema(value.dynamicSchema);
  return Object.freeze({ ...value, mode, targetId: resolvedTargetId });
};
export default class ModuleAutosaveController extends AutosaveIntegrationController {
  constructor({ documentSource=globalThis.document, optionsReader=defaultOptionsReader, adapterFactory=(o)=>new ModuleAutosaveAdapter(o), createBindingFactory=(o)=>new AutosaveCreateBinding(o), ...options } = {}) {
    if (typeof createBindingFactory !== 'function') throw new TypeError('The Module Autosave controller configuration is invalid.');
    let createBinding = null;
    const resolve=()=>{const c=validateConfiguration(optionsReader(OPTIONS_KEY,null)); if(!c){createBinding=null;return null;}
      if (c.mode === 'create' && !createBinding) createBinding = createBindingFactory({ context: c.context });
      else if (c.mode === 'existing') createBinding = null;
      const createMode = createBinding?.descriptor() || null;
      const form=documentSource.getElementById(c.formId); if(!form?.isConnected)return null;
      const fields={}; Object.entries(c.fieldIds).forEach(([k,id])=>{fields[k]=documentSource.getElementById(id);});
      fields.showtitle=[...form.querySelectorAll('[name="jform[showtitle]"]')];
      const dynamicFields={}; c.dynamicSchema.fields.forEach((f)=>{dynamicFields[f.path[1]]=[...form.querySelectorAll(`[name="jform[params][${f.path[1]}]"]`)]; if(!dynamicFields[f.path[1]].length){const e=documentSource.getElementById(f.id);if(e)dynamicFields[f.path[1]]=[e];}});
      const required=['title','note','version_note','position']; if(required.some((k)=>!fields[k]?.isConnected)||fields.showtitle.length!==2||fields.showtitle.some((control)=>!control.isConnected)||Object.values(dynamicFields).some((v)=>!v.length))return null;
      const all=[...required.map((k)=>fields[k]),...fields.showtitle,...(fields.content?[fields.content]:[]),...Object.values(dynamicFields).flat()];
      return {descriptor:{context:c.context,targetId:createMode?.targetId||c.targetId,payloadSchemaVersion:1},createMode,form,identityParts:[createMode?.formInstanceId||c.targetId,...all],taskPolicy:TASK_POLICY,statusMount:resolveAutosaveUiMount(form,'[data-joomla-autosave-status-ui]'),recoveryMount:resolveAutosaveUiMount(form,'[data-joomla-autosave-recovery-ui]'),presentationConfiguration:{locale:c.locale||'',timeZone:c.timeZone||''},pairProperties:{fields,dynamicFields,schema:c.dynamicSchema},createScope:c.mode==='create'?(c.createScope||null):undefined};};
    super({...options,documentSource,optionsReader,integrationResolver:resolve,adapterFactory:(r)=>adapterFactory({descriptor:r.descriptor,form:r.form,...r.pairProperties}).initializeBaseline()});
  }
}
export { OPTIONS_KEY, TASK_POLICY, validateConfiguration };
