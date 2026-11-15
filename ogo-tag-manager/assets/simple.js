(() => {
 'use strict';
 const el = id => document.getElementById(id);
 let busy = false, stop = false;
 const progress = message => { el('ogo-simple-progress').textContent = message; };
 async function api(op, data = {}) {
  let response;
  try { response = await fetch(OGO_SIMPLE.ajax, {method:'POST', credentials:'same-origin', body:new URLSearchParams({action:'ogo_tm_simple', nonce:OGO_SIMPLE.nonce, op, ...data})}); }
  catch (_) { throw Object.assign(new Error('З’єднання перервалося. Оновіть сторінку та перевірте журнал перед повторним запуском.'),{uncertain:true}); }
  let result;
  try { result = await response.json(); } catch (_) { throw Object.assign(new Error('Сервер не повернув результат. Перевірте журнал у розширеній панелі.'),{uncertain:true}); }
  if (!result.success) throw new Error(result.data?.message || 'Операцію не виконано.');
  return result.data;
 }
 function node(tag, text) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; return n; }
 function table(container, headers) {
  const t=node('table');t.className='widefat striped';const h=node('tr');
  headers.forEach(text=>h.append(node('th',text)));const head=node('thead');head.append(h);t.append(head);
  const body=node('tbody');t.append(body);el(container).replaceChildren(t);return body;
 }
 function choice(row, value) { const td=node('td'), c=node('input');c.type='checkbox';c.value=value;c.setAttribute('aria-label','Вибрати рядок');td.append(c);row.append(td); }
 function pairRow(body, item) {
  if ([...body.children].some(r=>r.dataset.source===String(item.source)&&r.dataset.target===String(item.target))) return;
  const r=node('tr');r.dataset.source=item.source;r.dataset.target=item.target;
  choice(r,item.source);r.append(node('td',`${item.name || 'Позначка'} #${item.source} → ${item.target_name || 'позначка'} #${item.target}`));
  r.append(node('td',String(item.count ?? 'Перевіримо перед запуском')));r.append(node('td',item.running?'Є часткове перенесення — виберіть для продовження.':'Очікує вибору'));
  if(!item.running)skipButton(r,item.source,item.target);body.append(r);
 }
 function skipButton(row,source,target){
  const b=node('button','Пропустити пару');b.type='button';b.className='button button-small';
  b.onclick=()=>task(async()=>{const r=await api('skip',{source,target});row.remove();progress(r.message);});row.lastElementChild.append(node('br'),b);
 }
 function selected(container) { return [...el(container).querySelectorAll('tbody tr')].filter(r=>r.querySelector('input[type=checkbox]:checked')); }
 function status(row, text) { row.lastElementChild.textContent=text; }
 function done(row, text) { status(row,text);const c=row.querySelector('input[type=checkbox]');c.checked=false;c.dataset.done='1'; }
 function exportLink(info){
  if(!info)return;
  document.querySelectorAll('[data-ogo-export-latest]').forEach(a=>{a.href=info.csv;a.hidden=!info.count;a.textContent=`CSV останнього пакета (${info.count})`;});
  const box=el('ogo-export-current');
  box.replaceChildren(node('p',`Поточний пакет: ${info.count} нових активних редиректів.${info.excluded?` Неактивних / змінених: ${info.excluded}.`:''}`));
  if(info.count){const a=node('a',`Завантажити CSV цього пакета (${info.count})`);a.href=info.csv;a.className='button button-primary';box.append(a);}
 }
 async function exportRefresh(id){
  try{exportLink(await api('export_info',{export_batch:id}));}
  catch(_){el('ogo-export-current').textContent='Пакет збережено. Оновіть сторінку, щоб завантажити його CSV.';}
 }
 async function task(fn) {
  if (busy) return;busy=true;stop=false;
  const controls=[...document.querySelectorAll('.wrap button, .wrap input, .wrap select')];const disabled=new Map(controls.map(c=>[c,c.disabled]));controls.forEach(c=>c.disabled=true);
  el('ogo-simple-stop').hidden=false;el('ogo-simple-stop').disabled=false;
  try { await fn(); } catch(e) { progress(e.message); }
  finally { busy=false;controls.forEach(c=>c.disabled=disabled.get(c)||c.dataset.done==='1');el('ogo-simple-stop').hidden=true;el('ogo-pending-stop').hidden=true; }
 }
 el('ogo-simple-stop').onclick=()=>{stop=true;progress('Зупинка після поточного запиту…');};
 el('ogo-simple-pairs').onclick=()=>task(async()=>{
  const data=await api('pairs');const body=table('ogo-simple-pair-list',['Вибір','Джерело → множина','Статей джерела','Результат']);
  data.items.forEach(item=>pairRow(body,item));progress(`Знайдено пар: ${data.items.length}. Виберіть потрібні. Для інших пар скористайтеся ID.`);
 });
 el('ogo-simple-pair-add').onclick=()=>{
  const source=Number(el('ogo-simple-source').value),target=Number(el('ogo-simple-target').value);
  if(!Number.isInteger(source)||!Number.isInteger(target)||source<1||target<1||source===target){progress('Вкажіть два різні додатні ID.');return;}
  let body=el('ogo-simple-pair-list').querySelector('tbody');
  if(!body)body=table('ogo-simple-pair-list',['Вибір','Джерело → множина','Статей джерела','Результат']);pairRow(body,{source,target});
 };
 async function runMerge(plan,row,batch,touched){
  if(touched.has(plan.target)){
   const fresh=await api('preview',{source:plan.source,target:plan.target});
   if(fresh.source_hash!==plan.source_hash||fresh.target_slug!==plan.target_slug)throw new Error('Позначки змінилися після перевірки. Оновіть список.');
   plan=fresh;
  }
  while(!stop){
   const r=await api('merge',{source:plan.source,target:plan.target,hash:plan.hash,export_batch:batch.export_batch});
   status(row,r.message);exportLink(r.export);
   if(r.complete!==false){done(row,r.message);touched.add(plan.target);return true;}
   progress(`Перенесення #${plan.source} → #${plan.target}: ${r.moved} із ${r.total}.`);
  }return false;
 }
 el('ogo-simple-merge').onclick=()=>task(async()=>{
  const rows=selected('ogo-simple-pair-list');if(!rows.length){progress('Спочатку виберіть пари.');return;}
  const plans=[];
  for(const row of rows){
   if(stop)break;
   try{const plan=await api('preview',{source:row.dataset.source,target:row.dataset.target});plans.push({row,plan});status(row,`Додамо ${plan.added} зв’язків. ${plan.old_url} → ${plan.new_url}. ${plan.warnings.join(' ')}`);}
   catch(e){status(row,e.message);}
  }
  if(stop||!plans.length){progress('Підготовку завершено. Перегляньте результати рядків.');return;}
  // Show every pair and any SEO warnings before the first mutation.
  const summary=plans.map(({plan:p})=>`${p.old_url} → ${p.new_url}\nНових зв’язків: ${p.added}. ${p.warnings.join(' ')}`).join('\n\n');
  if(!window.confirm(`Об’єднати ${plans.length} пар і ввімкнути 301? Старі позначки залишаться.\n\n${summary}`)){progress('Зміни скасовано.');return;}
  const batch=await api('export_start',{kind:'merge'});exportLink(batch);
  let ok=0,failed=0;const touched=new Set();
  for(const {row,plan} of plans){
   if(stop)break;
   try{if(await runMerge(plan,row,batch,touched))ok++;}
   catch(e){status(row,e.message);failed++;if(e.uncertain)stop=true;}
   progress(`Виконано пар: ${ok}; помилок: ${failed}.`);
  }
  await exportRefresh(batch.export_batch);
  await pendingRefresh();
  progress(`${stop?'Зупинено. ':''}Виконано пар: ${ok}; помилок: ${failed}. Для масового видалення джерел перейдіть до розділу 3 → «Підготувати список видалення».`);
 });
 el('ogo-simple-slugs').onclick=()=>task(async()=>{
  const data=await api('slugs');const body=table('ogo-simple-slug-list',['Вибір','Позначка','Поточний slug','Новий slug','Результат']);
  data.items.forEach(item=>{
   const r=node('tr');r.dataset.id=item.id;r.dataset.old=item.old_slug;choice(r,item.id);
   r.append(node('td',`${item.name} #${item.id} (${item.count} статей)`));r.append(node('td',decodeURIComponent(item.old_slug)));
   const td=node('td'),input=node('input');input.type='text';input.value=item.slug;input.className='ogo-simple-desired';input.setAttribute('aria-label',`Новий slug для ${item.name}`);td.append(input);
   if(item.conflict)td.append(node('p',`Адреса зайнята: ${item.conflict.name} #${item.conflict.id}. Запропонуємо об’єднання з цією позначкою.`));
   r.append(td,node('td','Очікує вибору'));if(item.conflict)skipButton(r,item.id,item.conflict.id);body.append(r);
  });progress(`Знайдено кириличних slug: ${data.items.length}. Перегляньте пропозиції та виберіть потрібні або всі.`);
 });
 el('ogo-simple-select').onclick=()=>{const choices=[...el('ogo-simple-slug-list').querySelectorAll('input[type=checkbox]:not(:disabled)')];const checked=choices.some(c=>!c.checked);choices.forEach(c=>c.checked=checked);};
 el('ogo-simple-slug-run').onclick=()=>task(async()=>{
  const rows=selected('ogo-simple-slug-list');if(!rows.length){progress('Спочатку знайдіть і виберіть slug.');return;}
  const plans=[];
  for(const row of rows){
   if(stop)break;
   try{
    const plan=await api('slug_preview',{id:row.dataset.id,old_slug:row.dataset.old,slug:row.querySelector('.ogo-simple-desired').value.trim()});
    plans.push({row,plan});status(row,plan.action==='merge'?`Об’єднання: ${plan.name} #${plan.source} → ${plan.target_name} #${plan.target}. Додамо ${plan.added} зв’язків. ${plan.warnings.join(' ')}`:`Зміна slug: ${plan.old_slug} → ${plan.desired}`);
   }catch(e){status(row,e.message);}
  }
  if(stop||!plans.length){progress('Немає готових змін. Перегляньте пояснення рядків.');return;}
  const summary=plans.map(({plan:p})=>p.action==='merge'?`ОБ’ЄДНАННЯ: ${p.name} #${p.source} → ${p.target_name} #${p.target}; нових зв’язків: ${p.added}. ${p.warnings.join(' ')}`:`SLUG: ${p.name} #${p.id}: ${p.old_slug} → ${p.desired}`).join('\n\n');
  if(!window.confirm(`Застосувати ${plans.length} змін і створити 301? Джерела об’єднань залишаться до окремого видалення.\n\n${summary}`)){progress('Зміни скасовано.');return;}
  const batch=await api('export_start',{kind:'mixed'});exportLink(batch);let ok=0,failed=0;const touched=new Set();
  for(const {row,plan} of plans){
   if(stop)break;
   try{
    if(plan.action==='merge'){if(await runMerge(plan,row,batch,touched))ok++;}
    else{const r=await api('slug',{id:plan.id,old_slug:plan.old_slug,slug:plan.desired,export_batch:batch.export_batch});done(row,`Змінено, 301 активний: ${r.new_url}`);exportLink(r.export);ok++;}
   }catch(e){status(row,e.message);failed++;if(e.uncertain)stop=true;}
   progress(`Виконано змін: ${ok}; пропущено / помилок: ${failed}.`);
  }
  await exportRefresh(batch.export_batch);
  await pendingRefresh();
  progress(`${stop?'Зупинено. ':''}Виконано змін: ${ok}; пропущено / помилок: ${failed}. Нові правила — у розділі 4 «Неперевірені редиректи». Перенесені джерела можна видалити у розділі 3.`);
 });
 let deleteLoaded=false;
 async function scanDeletion() {
  deleteLoaded=false;
  const body=table('ogo-simple-delete-list',['Вибір','Джерело → ціль','Статей джерела','Контроль / результат']);
  let after=0,checked=0,eligible=0;
  do {
   if(stop)break;
   const data=await api('delete_scan',{after});
   for(const item of data.items){
    const row=node('tr');row.dataset.deleteId=item.id;row.dataset.token=item.token;
    choice(row,item.id);const c=row.querySelector('input');c.checked=item.eligible;c.disabled=!item.eligible;
    if(!item.eligible)c.dataset.done='1';else eligible++;
    row.append(node('td',`${item.name} #${item.id} → ${item.target_name} #${item.target_id}`),node('td',String(item.count)),node('td',item.reason));body.append(row);
   }
   checked+=data.checked;after=data.next;
   progress(`Перевірено перенесень: ${checked}. Готові до видалення: ${eligible}.`);
  } while(after!==null);
  deleteLoaded=!stop;
  progress(`${stop?'Перевірку зупинено. ':''}Готові до видалення: ${eligible}. Усі придатні джерела вибрані. Перегляньте список і натисніть «Видалити всі перевірені джерела…».`);
 }
 el('ogo-simple-delete-scan').onclick=()=>task(scanDeletion);
 el('ogo-simple-delete-run').onclick=()=>task(async()=>{
  if(!deleteLoaded)await scanDeletion();
  if(stop)return;
  const rows=selected('ogo-simple-delete-list').filter(row=>row.dataset.token&&row.querySelector('input').dataset.done!=='1');
  if(!rows.length){progress('Немає перевірених джерел для видалення. Перегляньте причини у списку.');return;}
  if(!window.confirm(`Видалити ${rows.length} старих позначок із підготовленого списку?\n\nСтатті, цільові позначки та 301 залишаться. Старі позначки й їхні метадані буде видалено назавжди; відкат перенесення стане недоступним.`)){progress('Видалення скасовано. Жодну позначку не видалено.');return;}
  rows.forEach(row=>row.querySelector('input').disabled=true);
  let ok=0,failed=0;
  for(const row of rows){
   if(stop)break;
   try{const r=await api('delete',{id:row.dataset.deleteId,token:row.dataset.token,confirmed:'1'});done(row,r.message);ok++;}
   catch(e){status(row,e.message);failed++;if(e.uncertain)stop=true;}
   progress(`Видалено джерел: ${ok}; пропущено / помилок: ${failed}.`);
  }
  // Newly created rows were not in task()'s initial control list.
  el('ogo-simple-delete-list').querySelectorAll('input').forEach(c=>c.disabled=c.dataset.done==='1');
  progress(`${stop?'Зупинено. ':''}Видалено джерел: ${ok}; пропущено / помилок: ${failed}. Статті та правила 301 збережені. Повторна підготовка списку покаже решту джерел.`);
 });
 el('ogo-export-selected').addEventListener('submit',e=>{
  if(!el('ogo-export-selected').querySelector('input[name="keys[]"]:checked')){e.preventDefault();progress('Виберіть рядки редиректів для експорту.');}
 });
 el('ogo-skipped-load').onclick=()=>task(async()=>{
  const data=await api('skipped');const body=table('ogo-skipped-list',['Позначка 1','Позначка 2','Дія']);
  for(const item of data.items){const row=node('tr'),td=node('td'),button=node('button','Повернути до підказок');button.type='button';button.className='button';row.append(node('td',`${item.name} #${item.source}`),node('td',`${item.target_name} #${item.target}`),td);td.append(button);body.append(row);
   button.onclick=()=>task(async()=>{const r=await api('skip',{source:item.source,target:item.target,restore:'1'});row.remove();progress(r.message);});
  }progress(`Пропущених пар: ${data.items.length}.`);
 });
 async function pendingRefresh(){
  const data=await api('pending_list');const body=table('ogo-pending-list',['Вибір','Старий URL','Ціль','Перевірка']);
  for(const item of data.items){const row=node('tr');row.dataset.pendingKey=item.key;choice(row,item.key);row.querySelector('input').name='keys[]';row.append(node('td',decodeURIComponent(item.source)),node('td',item.target),node('td',item.result));body.append(row);}
  if(!data.items.length){const row=node('tr'),td=node('td','Неперевірених редиректів немає.');td.colSpan=4;row.append(td);body.append(row);}
  el('ogo-pending-count').textContent=`Неперевірених: ${data.items.length}`;
  el('ogo-pending-csv').textContent=`Експортувати неперевірені в CSV (${data.items.length})`;el('ogo-pending-csv').hidden=!data.items.length;
  el('ogo-export-selected').querySelector('[data-ogo-pending-selected]').hidden=!data.items.length;
 }
 el('ogo-pending-refresh').onclick=()=>task(pendingRefresh);
 el('ogo-pending-group-save').onclick=()=>task(async()=>{const r=await api('pending_group',{group:el('ogo-pending-group').value});await pendingRefresh();progress(r.message);});
 el('ogo-pending-check').onclick=()=>task(async()=>{
  const all=[...el('ogo-pending-list').querySelectorAll('tr[data-pending-key]')],chosen=all.filter(row=>row.querySelector('input:checked')),rows=chosen.length?chosen:all;
  if(!rows.length){el('ogo-pending-progress').textContent='Немає неперевірених редиректів.';return;}
  el('ogo-pending-stop').hidden=false;el('ogo-pending-stop').disabled=false;
  let ok=0,failed=0;
  for(const row of rows){
   if(stop)break;
   try{const r=await api('pending_verify',{key:row.dataset.pendingKey});if(r.ok){row.remove();ok++;}else{status(row,r.result);failed++;}}
   catch(e){status(row,e.message);failed++;if(e.uncertain)stop=true;}
   el('ogo-pending-progress').textContent=`Перевірено в Redirection: ${ok}; залишено для виправлення: ${failed}.`;progress(el('ogo-pending-progress').textContent);
  }
  await pendingRefresh();el('ogo-pending-progress').textContent=`${stop?'Зупинено. ':''}Перевірено й приховано: ${ok}; не пройшли: ${failed}.`;
 });
 el('ogo-pending-stop').onclick=()=>{stop=true;el('ogo-pending-progress').textContent='Зупинка після поточної перевірки…';};
 window.addEventListener('beforeunload',e=>{if(busy){e.preventDefault();e.returnValue='';}});
})();
