/* OGO Tag Manager 3.7.0 — delegated controls survive partial page refreshes. */
(function () {
 'use strict';
 if (!window.OGO_TM) return;
 const cfg = window.OGO_TM;
 const inlineActions = new Set(['ogo_tag_manager_review','ogo_tag_manager_external_target','ogo_tag_manager_suggested_group','ogo_tag_manager_batch_queue','ogo_tag_manager_pair','ogo_tag_manager_verify']);
 let busy = false, stop = false, preview = null;
 const $ = (s, root = document) => root.querySelector(s);
 const $$ = (s, root = document) => Array.from(root.querySelectorAll(s));
 const node = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; };
 function notice(text, error) {
  let box = $('#ogo-live-status');
  if (!box) { box = node('div', '', 'notice inline'); box.id = 'ogo-live-status'; box.setAttribute('role','status'); $('.wrap').prepend(box); }
  box.className = 'notice inline ' + (error ? 'notice-error' : 'notice-success'); box.replaceChildren(node('p',text));
 }
 async function request(body) {
  const res = await fetch(cfg.ajax,{method:'POST',credentials:'same-origin',body});
  const text = await res.text(); let data;
  try { data = JSON.parse(text); } catch (_) {
   const doc = new DOMParser().parseFromString(text,'text/html');
   const message = doc.querySelector('.wp-die-message');
   throw Error(message ? message.textContent.trim() : 'Сервер повернув неочікувану відповідь. Стан операції перевірте перед повтором.');
  }
  if (!res.ok || !data.success) throw Error(typeof data.data === 'string' ? data.data : (data.data && data.data.message) || 'Помилка запиту.');
  return data.data;
 }
 function api(op, values) {
  const body = new FormData(); body.set('action','ogo_tm_workbench');body.set('nonce',cfg.nonce);body.set('op',op);
  Object.entries(values || {}).forEach(([key,value]) => body.set(key,value)); return request(body);
 }
 function identity(e) {
  if (e.id) return '#' + e.id;
  const group = e.closest('[data-ogo-group]'), job = e.closest('[data-batch]'), row = e.closest('[data-ogo-row]');
  let path = group ? 'g:' + group.dataset.ogoGroup : job ? 'j:' + job.dataset.batch : 'page';
  if (row) path += ':r:' + row.dataset.ogoRow;
  if (e.matches('details')) path += ':d:' + $$( 'details',group || job || $('.wrap')).indexOf(e);
  else path += ':' + (e.name || e.className) + ':' + (e.type === 'checkbox' ? e.value : '');
  return path;
 }
 function snapshot(origin) {
  const states = new Map();
  $$('details, select, input[type="search"], input[type="checkbox"], .ogo-smart-target').forEach(e => {
   if(e.matches('details')) states.set(identity(e),{open:e.open});
   else if(e.matches('.ogo-smart-target')) states.set(identity(e),{html:$('.ogo-smart-results',e)?.innerHTML || '',value:$('.ogo-smart-select',e)?.value || '',q:$('.ogo-smart-query',e)?.value || ''});
   else states.set(identity(e),{value:e.value,checked:e.checked});
  });
  let anchor = origin?.closest('[data-ogo-group], [data-ogo-row], [data-batch]');
  if(!anchor)anchor=$$('[data-ogo-group], [data-batch], [data-ogo-row]').filter(e=>e.getBoundingClientRect().bottom>80).sort((a,b)=>Math.abs(a.getBoundingClientRect().top-80)-Math.abs(b.getBoundingClientRect().top-80))[0];
  return {states,anchor:anchor ? identity(anchor) : '',top:anchor?.getBoundingClientRect().top,y:window.scrollY};
 }
 function restore(state) {
  $$('details, select, input[type="search"], input[type="checkbox"], .ogo-smart-target').forEach(e => {
   const saved = state.states.get(identity(e));if(!saved)return;
   if(e.matches('details'))e.open=saved.open;
   else if(e.matches('.ogo-smart-target')) {
    const results=$('.ogo-smart-results',e);if(results&&saved.html){results.innerHTML=saved.html;e.dataset.loaded='1';}
    const search=$('.ogo-smart-query',e);if(search)search.value=saved.q;
    const select=$('.ogo-smart-select',e);if(select){select.value=saved.value;syncTarget(e);}
   } else {e.value=saved.value;if(e.type==='checkbox')e.checked=saved.checked;}
  });
  const anchor = $$('[data-ogo-group], [data-ogo-row], [data-batch]').find(e=>identity(e)===state.anchor);
  if(anchor&&Number.isFinite(state.top))window.scrollBy(0,anchor.getBoundingClientRect().top-state.top);
  else window.scrollTo(0,state.y);
 }
 async function refresh(origin, state) {
  state=state||snapshot(origin);
  const url=new URL(location.href);['updated','batch_added','batch_skipped','verified','batch_verified','batch_failed'].forEach(k=>url.searchParams.delete(k));
  const activeGroup=origin?.closest('[data-ogo-group]');if(activeGroup)url.searchParams.set('keep_group',activeGroup.dataset.ogoGroup);
  const res=await fetch(url,{credentials:'same-origin',cache:'no-store'});
  if(!res.ok)throw Error('Не вдалося оновити список. Виконана дія могла зберегтися; оновіть сторінку.');
  const doc=new DOMParser().parseFromString(await res.text(),'text/html');
  const next=doc.querySelector('#wpbody-content > .wrap')||doc.querySelector('.wrap');
  if(!next||!next.querySelector('.nav-tab-wrapper'))throw Error('Рішення могло зберегтися, але список не оновлено. Перезавантажте сторінку.');
  next.querySelectorAll('script').forEach(e=>e.remove());
  $('.wrap').replaceWith(next);restore(state);persist();
 }
 function setBusy(value) {
  busy=value;document.body.classList.toggle('ogo-working',value);
  $$('.ogo-stop').forEach(e=>{e.hidden=!value;});
  $$('.ogo-toolbar button:not(.ogo-stop), #ogo-slug-apply').forEach(e=>e.disabled=value);
 }
 document.addEventListener('submit',async function(event){
  const form=event.target, action=form.elements.action?.value;
  if(!inlineActions.has(action))return;
  event.preventDefault();if(busy){notice('Дочекайтеся поточної операції.',true);return;}
  const state=snapshot(form);const body=new FormData(form);
  body.set('return_filter',new URL(location.href).searchParams.get('filter')||'all');
  setBusy(true);
  try {await request(body);await refresh(form,state);notice('Рішення збережено.');}
  catch(e){notice(e.message,true);}finally{setBusy(false);}
 });
 function syncTarget(box){
  const value=$('.ogo-smart-select',box)?.value||'',form=$('.ogo-smart-form',box);
  if(!form)return;$('.ogo-smart-target-id',form).value=value;form.style.display=value?'':'none';
 }
 async function loadTargets(box,force){
  if(!box||box.dataset.loading==='1'||!force&&box.dataset.loaded==='1')return;
  box.dataset.loading='1';const results=$('.ogo-smart-results',box),form=$('.ogo-smart-form',box);
  results.textContent='Шукаємо в базі…';form.style.display='none';
  const body=new FormData();body.set('action','ogo_tag_find_targets');body.set('nonce',cfg.findNonce);body.set('q',$('.ogo-smart-query',box).value);
  box.dataset.ids.split(',').forEach(id=>body.append('ids[]',id));
  try{
   const items=await request(body);results.replaceChildren();
   if(!items.length)results.textContent='Схожих тегів не знайдено. Спробуйте пошук за назвою.';
   else{
    const select=node('select',undefined,'ogo-smart-select');select.append(new Option('— Оберіть перевірену ціль —',''));
    items.forEach(x=>{const option=new Option(x.name+' (#'+x.id+', '+x.count+' статей, /'+x.slug+'/) — '+x.reason+(x.blocked?' [У ЧЕРЗІ]':''),x.blocked?'':String(x.id));option.disabled=x.blocked;select.append(option);});results.append(select);
   }
   box.dataset.loaded='1';
  }catch(e){results.textContent=e.message;}finally{delete box.dataset.loading;}
 }
 document.addEventListener('toggle',event=>{
  const detail=event.target;
  if(detail.matches('details.ogo-group-details')&&detail.open)loadTargets($('.ogo-smart-target',detail));
  persist();
 },true);
 document.addEventListener('change',e=>{if(e.target.matches('.ogo-smart-select'))syncTarget(e.target.closest('.ogo-smart-target'));persist();});
 document.addEventListener('keydown',e=>{if(e.target.matches('.ogo-smart-query')&&e.key==='Enter'){e.preventDefault();loadTargets(e.target.closest('.ogo-smart-target'),true);}});
 function showPreview(plan){
  preview=plan;const host=$('#ogo-slug-preview');host.replaceChildren(node('h3','Попередній перегляд пакета'));
  const list=node('ul');let count=0;
  plan.items.forEach(item=>{
   const li=node('li',item.name+' (#'+item.id+'): '+item.old_slug+' → '+item.desired);
   if(item.state==='prepared'){count++;li.append(node('span',' · готово до зміни'));}
   else {li.append(node('strong',' · '+(item.errors||[]).join(' ')));if(item.conflict){const a=node('a',' Перевірити дублікати');const url=new URL(location.href);url.searchParams.set('tab','duplicates');url.searchParams.delete('paged');a.href=url.href;li.append(a);}}
   list.append(li);
  });host.append(list);
  if(count){const label=node('label',undefined,'ogo-confirm');const check=node('input');check.type='checkbox';check.id='ogo-slug-confirm';label.append(check,document.createTextNode(' Підтверджую зміну '+count+' slug за показаним планом. Після зміни імпортую CSV у Redirection.'));host.append(label);const button=node('button','Змінити перевірені slug','button button-primary');button.type='button';button.id='ogo-slug-apply';host.append(button);}
  else host.append(node('p','Немає готових змін. Виправте конфлікти й перевірте знову.'));
  host.append(node('div','','ogo-progress'));const stopButton=node('button','Зупинити після поточного','button ogo-stop');stopButton.type='button';stopButton.hidden=true;host.append(stopButton);
 }
 async function run(items,task,progress,onResult){
  stop=false;setBusy(true);let done=0,failed=0;
  try{
   for(const item of items){if(stop)break;
    progress.textContent='Опрацьовано '+done+' із '+items.length+'; помилок '+failed+'.';
    try{const result=await task(item);if(result.ok===false)failed++;if(onResult)onResult(item,result);}
    catch(e){failed++;if(onResult)onResult(item,{ok:false,result:e.message});}
    done++;
   }
   progress.textContent=(stop?'Зупинено. ':'Завершено. ')+'Опрацьовано '+done+' із '+items.length+'; помилок '+failed+'.';
   return {done,failed,stopped:stop};
  }finally{setBusy(false);}
 }
 document.addEventListener('click',async function(event){
  const b=event.target.closest('button');if(!b)return;
  if(b.matches('.ogo-stop')){stop=true;b.textContent='Зупиняємо після поточної операції…';return;}
  if(b.matches('.ogo-smart-search')){loadTargets(b.closest('.ogo-smart-target'),true);return;}
  if(b.id==='ogo-select-groups'||b.id==='ogo-clear-groups'){$$('input[name="group_keys[]"]').forEach(e=>e.checked=b.id==='ogo-select-groups');return;}
  if(b.dataset.ogoSlugSelect){$$('.ogo-slug-choice:not(:disabled)').forEach(e=>e.checked=b.dataset.ogoSlugSelect==='all');return;}
  const managed=b.id==='ogo-slug-prepare'||b.id==='ogo-slug-apply'||b.hasAttribute('data-ogo-resume')||b.hasAttribute('data-ogo-undo')||b.hasAttribute('data-ogo-verify')||b.hasAttribute('data-ogo-check-job');
  if(!managed)return;if(busy){notice('Дочекайтеся поточної операції.',true);return;}
  try{
   if(b.id==='ogo-slug-prepare'){
    const items=$$('.ogo-slug-choice:checked').map(e=>({id:Number(e.value),slug:$('.ogo-slug-input',e.closest('tr')).value}));
    if(!items.length)throw Error('Оберіть теги для перевірки.');setBusy(true);try{showPreview(await api('prepare',{items:JSON.stringify(items)}));}finally{setBusy(false);}
   }else if(b.hasAttribute('data-ogo-resume')){
    const job=b.closest('[data-batch]');const items=$$('tr[data-state="prepared"]',job).map(row=>({id:Number(row.dataset.jobId),name:row.dataset.name,old_slug:$$('td',row)[1].textContent,desired:row.dataset.desired,state:'prepared'}));
    showPreview({batch:b.dataset.ogoResume,items});$('#ogo-slug-preview').scrollIntoView({block:'nearest'});
   }else if(b.id==='ogo-slug-apply'){
    if(!$('#ogo-slug-confirm')?.checked)throw Error('Підтвердіть показаний план прапорцем.');
    const plan=preview,items=plan.items.filter(x=>x.state==='prepared'),progress=$('.ogo-progress',$('#ogo-slug-preview'));
    const result=await run(items,item=>api('apply',{batch:plan.batch,id:item.id,confirmed:'1'}),progress,(item,result)=>{
     const status=$('[data-ogo-row="'+item.id+'"] .ogo-slug-status');if(status)status.textContent=result.state==='applied'?'Slug змінено':result.result||'Потрібна увага';
    });
    await refresh(b);notice((result.stopped?'Пакет зупинено. ':'Пакет опрацьовано. ')+'Оброблено '+result.done+'; помилок '+result.failed+'. CSV і продовження — у пакетах змін slug.');preview=null;
   }else if(b.hasAttribute('data-ogo-undo')){
    if(!window.confirm('Відкотити slug? Якщо CSV уже імпортовано, окремо приберіть або змініть відповідний редирект у Redirection.'))return;
    setBusy(true);try{const r=await api('undo',{batch:b.closest('[data-batch]').dataset.batch,id:b.dataset.ogoUndo,confirmed:'1'});await refresh(b);notice(r.state==='undone'?'Slug відновлено. Перевірте відповідне правило Redirection.':'Потрібна ручна перевірка.',r.state!=='undone');}finally{setBusy(false);}
   }else if(b.hasAttribute('data-ogo-check-job')){
    const job=b.closest('[data-batch]'),ids=$$('tr[data-state="applied"]',job).map(r=>r.dataset.jobId);
    await run(ids,id=>api('url_check',{batch:job.dataset.batch,id}),$('.ogo-progress',job),(id,r)=>{const cell=$('[data-job-id="'+id+'"] .ogo-job-status',job);let out=$('.ogo-check-result',cell);if(!out){out=node('div','','ogo-check-result');cell.append(out);}out.textContent=r.result;});
   }else if(b.hasAttribute('data-ogo-verify')){
    let ids;
    if(b.dataset.ogoVerify==='selected')ids=$$('.ogo-verify-choice:checked').map(e=>e.value);
    else {setBusy(true);try{ids=(await api('verify_list')).ids;}finally{setBusy(false);}}
    if(!ids.length)throw Error('Немає перенесень для перевірки. Оберіть записи у таблиці.');
    const progress=$('.ogo-progress',b.closest('.ogo-toolbar'));
    await run(ids,id=>api('verify',{id}),progress,(id,r)=>{const row=$('[data-ogo-row="'+id+'"]');if(row){let out=$('.ogo-check-result',row);if(!out){out=node('div','','ogo-check-result');row.lastElementChild.prepend(out);}out.textContent=r.result;}});
   }
  }catch(e){notice(e.message,true);setBusy(false);}
 });
 // Save UI state for full-page actions and navigation, using stable group/row identifiers.
 const storageKey='ogo-tm-v37:'+location.pathname+':'+(new URL(location.href).searchParams.get('tab')||'workflow')+':'+(new URL(location.href).searchParams.get('paged')||'1')+':'+(new URL(location.href).searchParams.get('filter')||'all');
 function persist(){try{const s=snapshot(document.activeElement);sessionStorage.setItem(storageKey,JSON.stringify({states:Array.from(s.states),anchor:s.anchor,top:s.top,y:s.y}));}catch(_){} }
 window.addEventListener('pagehide',persist);
 let scheduled=false;window.addEventListener('scroll',()=>{if(!scheduled){scheduled=true;requestAnimationFrame(()=>{persist();scheduled=false;});}},{passive:true});
 try{const saved=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(saved){saved.states=new Map(saved.states);restore(saved);}}catch(_){}
 $$('details.ogo-group-details[open]').forEach(e=>loadTargets($('.ogo-smart-target',e)));
})();
