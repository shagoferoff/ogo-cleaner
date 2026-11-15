const assert=require('node:assert/strict'),path=require('node:path');
let playwright;try{playwright=require('playwright');}catch(_){playwright=require(path.join(path.dirname(process.execPath),'../node_modules/playwright'));}
(async()=>{
 const browser=await playwright.chromium.launch({headless:true,executablePath:process.env.OGO_BROWSER_BINARY||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1200}}),page=await context.newPage();
  let passed=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const check=(value,label)=>{assert.ok(value,label);passed++;console.log('PASS '+label);};
  const base='http://127.0.0.1:18838';
  const api=async(op,data={})=>{const r=await context.request.post(base+'/api',{form:{nonce:'nonce',op,...data}});return await r.json();};
  async function setup(){
   await context.request.get(base+'/reset');
   for(const [source,target] of [[5681,7058],[3,4]]){const p=await api('preview',{source,target});assert.ok(p.success);const r=await api('merge',{source,target,hash:p.data.hash});assert.ok(r.success);}
   await page.goto(base+'/page');
  }
  await setup();
  await page.locator('#ogo-simple-delete-scan').click();
  await page.waitForFunction(()=>document.querySelectorAll('#ogo-simple-delete-list tbody tr').length===2);
  check(await page.locator('#ogo-simple-delete-list input:checked').count()===2,'all sources from existing completed merges are automatically selected');
  check((await page.locator('#ogo-simple-delete-list').innerText()).includes('Готово:'),'readiness explanations are visible');
  page.once('dialog',d=>d.dismiss());await page.locator('#ogo-simple-delete-run').click();
  await page.waitForFunction(()=>document.getElementById('ogo-simple-progress').textContent.includes('Видалення скасовано'));
  check((await api('delete_scan')).data.items.length===2,'cancel leaves every source intact');
  await page.locator('#ogo-simple-delete-list tr[data-delete-id="3"] input').uncheck();
  page.once('dialog',d=>d.accept());await page.locator('#ogo-simple-delete-run').click();
  await page.waitForFunction(()=>document.getElementById('ogo-simple-progress').textContent.includes('Видалено джерел: 1'));
  check((await api('delete_scan')).data.items[0].id===3,'only selected source is removed');
  check(await page.locator('#ogo-simple-delete-list tr[data-delete-id="5681"] input').isDisabled(),'deleted row is disabled');
  let r=await context.request.get(base+'/tag/advokat/',{maxRedirects:0});
  check(r.status()===301&&r.headers()['location'].includes('/tag/%d0'),'real HTTP 301 survives source deletion');
  r=await context.request.get(base+'/export');check((await r.text()).split('\n').filter(Boolean).length===2,'CSV keeps rules after source deletion');
  await page.screenshot({path:'tests/tag-manager-3.9-delete.png',fullPage:true});
  await page.reload();await page.locator('#ogo-simple-delete-scan').click();
  await page.waitForFunction(()=>document.querySelectorAll('#ogo-simple-delete-list tbody tr').length===1);
  check((await page.locator('#ogo-simple-delete-list').innerText()).includes('безпілотник'),'reload lists only remaining source');
  await setup();let release;let calls=0;const gate=new Promise(r=>release=r);
  await page.route('**/api',async route=>{if(new URLSearchParams(route.request().postData()).get('op')==='delete'){calls++;await gate;}await route.continue();});
  page.once('dialog',d=>d.accept());await page.locator('#ogo-simple-delete-run').click();
  while(calls===0)await page.waitForTimeout(20);
  await page.locator('#ogo-simple-stop').click();release();
  await page.waitForFunction(()=>document.getElementById('ogo-simple-progress').textContent.includes('Зупинено.'));
  check(calls===1&&(await api('delete_scan')).data.items.length===1,'one-click bulk operation stops after current deletion');
  await page.unroute('**/api');
  await setup();calls=0;
  await page.route('**/api',async route=>{if(new URLSearchParams(route.request().postData()).get('op')==='delete'){calls++;await route.abort('failed');}else await route.continue();});
  page.once('dialog',d=>d.accept());await page.locator('#ogo-simple-delete-run').click();
  await page.waitForFunction(()=>document.getElementById('ogo-simple-progress').textContent.includes('Зупинено.'));
  check(calls===1&&(await api('delete_scan')).data.items.length===2,'ambiguous network error stops further destructive requests');
  await page.unroute('**/api');
  await setup();page.once('dialog',d=>d.accept());await page.locator('#ogo-simple-delete-run').click();
  await page.waitForFunction(()=>document.getElementById('ogo-simple-progress').textContent.includes('Видалено джерел: 2'));
  check((await api('delete_scan')).data.items.length===0,'bulk action removes all eligible sources without manual term-by-term deletion');
  for(const slug of ['advokat','%D0%B1%D0%B5%D0%B7%D0%BF%D1%96%D0%BB%D0%BE%D1%82%D0%BD%D0%B8%D0%BA']){r=await context.request.get(base+'/tag/'+slug+'/',{maxRedirects:0});check(r.status()===301,'deleted-source URL remains a 301: '+slug);}
  check(errors.length===0,'no JavaScript errors in destructive workflow');
  await page.setViewportSize({width:820,height:1200});await page.screenshot({path:'tests/tag-manager-3.9-narrow.png',fullPage:true});
  console.log(`ALL ${passed} BULK DELETION BROWSER CHECKS PASSED`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
