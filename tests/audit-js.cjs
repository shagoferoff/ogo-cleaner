const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(__dirname + '/../ogo-media-cleaner/assets/audit.js', 'utf8');
async function scenario({responses, stoppingAt = -1, mode = 'start', initialRun = '', action = '', coverageFiles}) {
  const elements = new Map();
  for (const id of ['ogo-audit-start','ogo-audit-scan','ogo-audit-stop','ogo-audit-progress']) {
    elements.set(id, {disabled:false, textContent:'', addEventListener(type, fn) {this[type] = fn;}});
  }
  if (coverageFiles) elements.set('ogo-audit-upload',{files:coverageFiles});
  const requests = []; let redirect;
  const context = {
    document:{getElementById:id=>elements.get(id)},
    ogoAudit:{action,url:'/ajax',nonce:'test-nonce',run:initialRun,reportUrl:'https://ogo.test/tools.php?page=ogo-media-cleaner&project=audit'},
    URL, URLSearchParams, FormData, confirm:()=>true,
    window:{location:{href:'https://ogo.test/tools.php',assign:url=>{redirect=url;}}},
    fetch:async (url, options)=>{
      const body = Object.fromEntries(options.body);
      requests.push(body);
      if (requests.length === stoppingAt) elements.get('ogo-audit-stop').click();
      const response = responses[requests.length-1];
      if (response instanceof Error) throw response;
      assert.ok(response, 'Unexpected extra AJAX call');
      return {json:async()=>response};
    }
  };
  vm.runInNewContext(source, context);
  await elements.get(mode==='start'?'ogo-audit-start':'ogo-audit-scan').click();
  return {elements,requests,redirect};
}
const response = (complete, scanned=0) => ({success:true,data:{state:{run:'test-run',complete,scanned},stats:{totals:{urls:2,links:5,materials:2}}}});
(async()=>{
  let r = await scenario({responses:[response(false),response(false,50),response(true,70)]});
  assert.deepStrictEqual(r.requests.map(x=>x.mode),['start','scan','scan']);
  assert.ok(r.requests.every(x=>x.action==='ogo_mc_audit' && x.nonce==='test-nonce'));
  assert.strictEqual(r.requests[1].run,'test-run');
  assert.ok(r.redirect.includes('project=audit') && r.redirect.includes('run=test-run'));
  r = await scenario({responses:[response(false),response(false,50)],stoppingAt:2});
  assert.strictEqual(r.requests.length,2);
  assert.ok(r.redirect, 'Stopping after current batch must preserve a report link');
  r = await scenario({responses:[response(true,70)],mode:'resume',initialRun:'test-run'});
  assert.strictEqual(r.requests[0].mode,'scan');
  r = await scenario({responses:[{success:false,data:'Stale run'}],mode:'resume',initialRun:'old-run'});
  assert.ok(!r.redirect && r.elements.get('ogo-audit-progress').textContent.includes('Stale run'));
  assert.strictEqual(r.elements.get('ogo-audit-scan').disabled,false);
  r = await scenario({responses:[new Error('Network unavailable')]});
  assert.ok(!r.redirect && r.elements.get('ogo-audit-progress').textContent.includes('Network unavailable'));
  assert.strictEqual(r.elements.get('ogo-audit-start').disabled,false);
  r = await scenario({responses:[response(false),response(true,50)],action:'ogo_mc_external'});
  assert.ok(r.requests.every(x=>x.action==='ogo_mc_external' && x.nonce==='test-nonce'));
  const zip=new Blob(['sample'],{type:'application/zip'});
  r = await scenario({responses:[response(false),response(true,200)],action:'ogo_mc_404',coverageFiles:[zip]});
  assert.strictEqual(await r.requests[0].coverage.text(),'sample');
  assert.ok(!('coverage' in r.requests[1]) && r.requests.every(x=>x.action==='ogo_mc_404'));
  r = await scenario({responses:[],action:'ogo_mc_404',coverageFiles:[]});
  assert.strictEqual(r.requests.length,0);
  assert.ok(r.elements.get('ogo-audit-progress').textContent.includes('Виберіть ZIP або CSV'));
  console.log('Passed audit browser-flow checks: scan, resume, stop, stale run and network errors.');
})().catch(e=>{console.error(e);process.exitCode=1;});
