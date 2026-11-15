(() => {
 'use strict';
 const el = id => document.getElementById(id);
 let busy = false, stop = false;
 const progress = message => { el('ogo-simple-progress').textContent = message; };
 async function api(op, data = {}) {
  let response;
  try { response = await fetch(OGO_SIMPLE.ajax, {method:'POST', credentials:'same-origin', body:new URLSearchParams({action:'ogo_tm_simple', nonce:OGO_SIMPLE.nonce, op, ...data})}); }
  catch (_) { throw new Error('З’єднання перервалося. Оновіть сторінку та перевірте журнал перед повторним запуском.'); }
  let result;
  try { result = await response.json(); } catch (_) { throw new Error('Сервер не повернув результат. Перевірте журнал у розширеній панелі.'); }
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
  r.append(node('td',String(item.count ?? 'Перевіримо перед запуском')));r.append(node('td','Очікує вибору'));body.append(r);
 }
 function selected(container) { return [...el(container).querySelectorAll('tbody tr')].filter(r=>r.querySelector('input[type=checkbox]:checked')); }
 function status(row, text) { row.lastElementChild.textContent=text; }
 function done(row, text) { status(row,text);const c=row.querySelector('input[type=checkbox]');c.checked=false;c.dataset.done='1'; }
 async function task(fn) {
  if (busy) return;busy=true;stop=false;
  const controls=[...document.querySelectorAll('.wrap button, .wrap input')];controls.forEach(c=>c.disabled=true);
  el('ogo-simple-stop').hidden=false;el('ogo-simple-stop').disabled=false;
  try { await fn(); } catch(e) { progress(e.message); }
  finally { busy=false;controls.forEach(c=>c.disabled=c.dataset.done==='1');el('ogo-simple-stop').hidden=true; }
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
  let ok=0,failed=0;
  for(const {row,plan} of plans){
   if(stop)break;
   try{const r=await api('merge',{source:plan.source,target:plan.target,hash:plan.hash});done(row,r.message);ok++;}
   catch(e){status(row,e.message);failed++;}
   progress(`Виконано пар: ${ok}; помилок: ${failed}.`);
  }
  progress(`${stop?'Зупинено. ':''}Виконано пар: ${ok}; помилок: ${failed}. Оновіть сторінку для списку редиректів. Перевірте статті перед видаленням старих позначок.`);
 });
 el('ogo-simple-slugs').onclick=()=>task(async()=>{
  const data=await api('slugs');const body=table('ogo-simple-slug-list',['Вибір','Позначка','Поточний slug','Новий slug','Результат']);
  data.items.forEach(item=>{
   const r=node('tr');r.dataset.id=item.id;r.dataset.old=item.old_slug;choice(r,item.id);
   r.append(node('td',`${item.name} #${item.id} (${item.count} статей)`));r.append(node('td',decodeURIComponent(item.old_slug)));
   const td=node('td'),input=node('input');input.type='text';input.value=item.slug;input.className='ogo-simple-desired';input.setAttribute('aria-label',`Новий slug для ${item.name}`);td.append(input);r.append(td,node('td','Очікує вибору'));body.append(r);
  });progress(`Знайдено кириличних slug: ${data.items.length}. Перегляньте пропозиції та виберіть потрібні або всі.`);
 });
 el('ogo-simple-select').onclick=()=>{const choices=[...el('ogo-simple-slug-list').querySelectorAll('input[type=checkbox]:not(:disabled)')];const checked=choices.some(c=>!c.checked);choices.forEach(c=>c.checked=checked);};
 el('ogo-simple-slug-run').onclick=()=>task(async()=>{
  const rows=selected('ogo-simple-slug-list');if(!rows.length){progress('Спочатку знайдіть і виберіть slug.');return;}
  if(!window.confirm(`Змінити ${rows.length} slug та ввімкнути автоматичні 301? Зайняті slug буде пропущено.`)){progress('Зміни скасовано.');return;}
  let ok=0,failed=0;
  for(const row of rows){
   if(stop)break;
   try{const r=await api('slug',{id:row.dataset.id,old_slug:row.dataset.old,slug:row.querySelector('.ogo-simple-desired').value.trim()});done(row,`Змінено, 301 активний: ${r.new_url}`);ok++;}
   catch(e){status(row,e.message);failed++;}
   progress(`Змінено slug: ${ok}; пропущено / помилок: ${failed}.`);
  }
  progress(`${stop?'Зупинено. ':''}Змінено slug: ${ok}; пропущено / помилок: ${failed}. Оновіть сторінку для списку редиректів. Відкат доступний у розширеній панелі → Перевірка slug.`);
 });
 window.addEventListener('beforeunload',e=>{if(busy){e.preventDefault();e.returnValue='';}});
})();
