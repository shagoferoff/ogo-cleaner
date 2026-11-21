const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(__dirname + '/../ogo-media-cleaner/assets/admin.js', 'utf8');
const config = project => ({
  project, url:'/wp-admin/admin-ajax.php', nonce:'test-nonce', run:'current-run',
  currentRun:'current-run', hasRun:true, complete:true,
  vkMode:project==='vk', unwrapping:['segodnya','miralinks_unwrap'].includes(project),
  updatingAttributes:['tsn','korrespondent','source_batch_1','telegram_urls'].includes(project),
  replacing:['day_urls','legacy_urls','dossier_urls','horoscope_urls','garden_urls','pets_urls','nashregion_urls','dim_urls','woman_urls','editorial_urls','vybir_home'].includes(project)
});
const response = (complete=true,remaining=0) => ({success:true,data:{state:{complete,scanned:50},stats:[],remaining}});
async function scenario({project='legacy_missing',mode='apply',selected=false,ids=[],backup=true,hasBackup=true,confirm=true,overrides={},responses=[response()],stopAt=-1,script=source}={}) {
  const elements = new Map();
  for (const id of ['ogo-progress','ogo-stop','ogo-backup']) elements.set(id,{checked:backup,textContent:'',addEventListener(type,fn){this[type]=fn;}});
  if (!hasBackup) elements.delete('ogo-backup');
  const button={dataset:{mode,selected:selected?'1':''},disabled:false,addEventListener(type,fn){this[type]=fn;}};
  const requests=[],prompts=[],alerts=[];let redirect;
  const context={
    ogoMC:{...config(project),...overrides}, URLSearchParams,
    document:{getElementById:id=>elements.get(id),querySelectorAll:selector=>selector==='[data-mode]'?[button]:ids.map(value=>({value}))},
    window:{location:{pathname:'/wp-admin/tools.php',assign:url=>{redirect=url;}}},
    confirm:prompt=>{prompts.push(prompt);return confirm;}, alert:message=>alerts.push(message),
    fetch:async(url,options)=>{
      assert.strictEqual(url,'/wp-admin/admin-ajax.php');
      assert.strictEqual(options.method,'POST');assert.strictEqual(options.credentials,'same-origin');
      requests.push([...options.body.entries()]);
      if(requests.length===stopAt) elements.get('ogo-stop').click();
      const result=responses[requests.length-1]; assert.ok(result,'Unexpected extra request');
      if(result instanceof Error)throw result;
      return {json:async()=>result};
    }
  };
  vm.runInNewContext(script,context);
  await button.click();
  return {requests,prompts,alerts,redirect,button,progress:elements.get('ogo-progress').textContent};
}
(async()=>{
  const projects=['legacy_missing','segodnya','miralinks_unwrap','tsn','korrespondent','source_batch_1','telegram_urls','editorial_urls','vybir_home','woman_urls','dim_urls','nashregion_urls','pets_urls','garden_urls','horoscope_urls','ustream','flash','vk','legacy_urls','day_urls','day'];
  for(const project of projects) {
    const r=await scenario({project});
    assert.strictEqual(r.prompts.length,1);assert.strictEqual(r.requests.length,1);
    const body=Object.fromEntries(r.requests[0]);
    assert.strictEqual(body.project,project);assert.strictEqual(body.action,'ogo_mc');assert.strictEqual(body.nonce,'test-nonce');assert.strictEqual(body.backup,'1');
    assert.ok(r.redirect.includes('project='+project));
    // Optional differential check against a supplied previous release.
    if(process.env.OGO_PREVIOUS_ADMIN_JS && !['legacy_missing','segodnya','miralinks_unwrap','korrespondent','source_batch_1','telegram_urls','vybir_home','flash'].includes(project)) {
      const previous=await scenario({project,script:fs.readFileSync(process.env.OGO_PREVIOUS_ADMIN_JS,'utf8')});
      assert.deepStrictEqual(JSON.parse(JSON.stringify(r)),JSON.parse(JSON.stringify(previous)),'Behavior changed for '+project);
    }
  }
  let r=await scenario({confirm:false});assert.strictEqual(r.requests.length,0);assert.ok(!r.redirect);
  r=await scenario({backup:false});assert.strictEqual(r.requests.length,0);assert.strictEqual(r.alerts.length,1);
  r=await scenario({overrides:{run:'old-run'}});assert.strictEqual(r.requests.length,0);assert.strictEqual(r.alerts.length,1);
  r=await scenario({selected:true});assert.strictEqual(r.requests.length,0);assert.strictEqual(r.alerts.length,1);
  const ids=Array.from({length:21},(_,i)=>String(i+1));
  r=await scenario({selected:true,ids,responses:[response(true,20),response()]});
  assert.strictEqual(r.requests.length,2);
  assert.deepStrictEqual(r.requests.map(body=>body.filter(([key])=>key==='ids[]').length),[20,1]);
  assert.ok(r.requests.every(body=>Object.fromEntries(body).selected==='1'));
  r=await scenario({responses:[response(true,20),response()]});assert.strictEqual(r.requests.length,2);
  r=await scenario({responses:[response(true,20)],stopAt:1});assert.strictEqual(r.requests.length,1);
  r=await scenario({mode:'restore',overrides:{run:'old-run'},backup:false});
  assert.ok(r.redirect.includes('run=old-run'));assert.strictEqual(Object.fromEntries(r.requests[0]).run,'old-run');
  r=await scenario({project:'gazeta',mode:'restore',hasBackup:false,overrides:{archived:true,run:'old-gazeta-run'}});
  assert.strictEqual(Object.fromEntries(r.requests[0]).project,'gazeta');
  assert.ok(r.redirect.includes('run=old-gazeta-run'));
  assert.ok(!r.progress.includes('Перевірено:'));
  r=await scenario({mode:'start',responses:[response(false),response(false),response()]});
  assert.deepStrictEqual(r.requests.map(body=>Object.fromEntries(body).mode),['start','scan','scan']);
  const started=response(false);
  started.data.state.run='new-cleanup-run';
  r=await scenario({project:'miralinks_unwrap',mode:'start',overrides:{auditRun:'chosen-audit-run'},responses:[started,response()]});
  assert.strictEqual(Object.fromEntries(r.requests[0]).audit_run,'chosen-audit-run');
  assert.strictEqual(Object.fromEntries(r.requests[1]).run,'new-cleanup-run');
  r=await scenario({mode:'scan',responses:[response(false),response()]});assert.strictEqual(r.requests.length,2);
  for(const error of [new Error('Network unavailable'),{success:false,data:'Access denied'}]) {
    r=await scenario({responses:[error]});assert.ok(!r.redirect);assert.ok(r.progress.includes('Зупинено:'));assert.strictEqual(r.button.disabled,false);
  }
  console.log('Passed admin browser checks: '+projects.length+' active operation prompts, cancellation, backup, stale run, selection, batching, stop, scan, rollback and errors.');
})().catch(error=>{console.error(error);process.exitCode=1;});
