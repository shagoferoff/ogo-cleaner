const assert=require('node:assert/strict'),path=require('node:path');
let playwright;try{playwright=require('playwright');}catch(_){playwright=require(path.join(path.dirname(process.execPath),'../node_modules/playwright'));}
(async()=>{
 const browser=await playwright.chromium.launch({headless:true,executablePath:process.env.OGO_BROWSER_BINARY||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1300}}),page=await context.newPage(),base='http://127.0.0.1:18838';
  let checks=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
  const api=async(op,data={})=>{const r=await context.request.post(base+'/api',{form:{nonce:'nonce',op,...data}});return await r.json();};
  const getCSV=async href=>{const url=new URL(href);return await context.request.get(base+'/export'+url.search);};
  const lines=text=>text.trim().split('\n').filter(Boolean);
  await context.request.get(base+'/reset');
  assert.ok((await api('slug',{id:3,old_slug:'безпілотник',slug:'bezpilotnyk'})).success);
  await context.request.get(base+'/seed-export-test');await page.goto(base+'/page');
  check((await page.locator('body').innerText()).includes('Повний CSV усіх правил — резервна копія'),'full export is explicitly labelled as a backup');
  check(await page.locator('[data-ogo-export-latest]').isHidden(),'legacy data is never disguised as a last batch');
  await page.locator('#ogo-simple-slugs').click();
  await page.waitForFunction(()=>document.querySelectorAll('#ogo-simple-slug-list tbody tr').length===6);
  for(let id=100;id<105;id++){const row=page.locator(`#ogo-simple-slug-list tr[data-id="${id}"]`);await row.locator('input[type=checkbox]').check();await row.locator('input[type=text]').fill('tag-'+id);}
  page.once('dialog',d=>d.accept());await page.locator('#ogo-simple-slug-run').click();
  await page.waitForFunction(()=>document.getElementById('ogo-simple-progress').textContent.includes('Змінено slug: 5'));
  const latest=page.locator('[data-ogo-export-latest]');check((await latest.innerText()).includes('(5)'),'five changes produce a five-row batch button immediately');
  const firstHref=await latest.getAttribute('href');let response=await getCSV(firstHref),csv=await response.text();
  check(lines(csv).length===5&&!csv.includes('bezpilotnyk'),'download contains exactly five new rules and excludes old rule');
  check(response.headers()['content-disposition'].includes('batch-'),'download filename identifies a specific batch');
  response=await context.request.get(base+'/export?scope=all');check(lines(await response.text()).length===6,'full backup remains available separately');
  await page.reload();check((await page.locator('[data-ogo-export-latest]').innerText()).includes('(5)'),'batch link and count survive reload');
  check(lines(await (await getCSV(firstHref)).text()).length===5,'repeated download retains only the same five sources');
  const boxes=page.locator('#ogo-export-selected input[type=checkbox]');await boxes.nth(0).check();await boxes.nth(1).check();
  const body=new URLSearchParams({scope:'selected',_wpnonce:'nonce'});body.append('keys[]',await boxes.nth(0).inputValue());body.append('keys[]',await boxes.nth(1).inputValue());
  response=await context.request.post(base+'/export',{data:body.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}});
  check(lines(await response.text()).length===2,'selected export contains exactly the two checked rules');
  const rowId='7058';await page.locator('#ogo-simple-slugs').click();await page.waitForFunction(()=>document.querySelectorAll('#ogo-simple-slug-list tbody tr').length===1);
  await page.locator(`#ogo-simple-slug-list tr[data-id="${rowId}"] input[type=checkbox]`).check();
  await page.locator(`#ogo-simple-slug-list tr[data-id="${rowId}"] input[type=text]`).fill('advokaty');
  page.once('dialog',d=>d.accept());await page.locator('#ogo-simple-slug-run').click();
  await page.waitForFunction(()=>document.getElementById('ogo-simple-progress').textContent.includes('Змінено slug: 1'));
  const secondHref=await page.locator('[data-ogo-export-latest]').getAttribute('href');
  check(firstHref!==secondHref&&lines(await (await getCSV(secondHref)).text()).length===1,'next action exports only its one new redirect');
  check(lines(await (await getCSV(firstHref)).text()).length===5,'previous batch remains available after the next action');
  await page.reload();await page.screenshot({path:'tests/tag-manager-3.10-export.png',fullPage:true});
  response=await context.request.get(base+'/export?scope=batch&batch=missing');check(response.status()===400,'unknown batch never downloads the full registry');
  check(errors.length===0,'no browser errors in batch and selection exports');
  console.log(`ALL ${checks} EXPORT BROWSER CHECKS PASSED`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
