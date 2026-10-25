(() => {
  'use strict';
  let busy = false, stop = false;
  const progress = document.getElementById('ogo-progress');
  const labels = {ready:'Готові',review:'Ручна перевірка',done:'Очищені',restored:'Відновлені',conflict:'Пропущені'};
  if(ogoMC.replacing) { labels.ready='Готові до заміни'; labels.done='URL замінено'; }
  if(ogoMC.vkMode) { labels.ready='Готові до обробки'; labels.done='Оброблено'; }
  if(ogoMC.unwrapping) { labels.ready='Готові до зняття'; labels.done='Посилання знято'; }
  document.getElementById('ogo-stop').addEventListener('click', () => { stop = true; });
  async function call(mode, ids, selected) {
    const body = new URLSearchParams({action:'ogo_mc',nonce:ogoMC.nonce,mode,run:ogoMC.run,project:ogoMC.project,backup:document.getElementById('ogo-backup').checked?'1':''});
    if(selected) body.set('selected','1');
    if(ids) ids.forEach(id => body.append('ids[]',id));
    const response = await fetch(ogoMC.url,{method:'POST',body,credentials:'same-origin'});
    const result = await response.json();
    if(!result.success) throw new Error(typeof result.data==='string'?result.data:'Помилка сервера.');
    const d = result.data;
    progress.textContent = `Перевірено: ${d.state.scanned}. ${d.state.complete?'Сканування завершено.':'Сканування триває.'} ` + d.stats.map(s=>`${labels[s.status]||s.status}: ${s.materials}`).join('; ');
    return d;
  }
  document.querySelectorAll('[data-mode]').forEach(button=>button.addEventListener('click',async()=>{
    if(busy) return;
    let mode=button.dataset.mode;
    const selected=button.dataset.selected==='1';
    const ids=selected?Array.from(document.querySelectorAll('.ogo-row:checked')).map(c=>c.value):null;
    if(selected && !ids.length) { alert('Виберіть матеріали у звіті.'); return; }
    if(mode==='apply' && ogoMC.run!==ogoMC.currentRun) { alert('Відкрийте звіт останнього сканування вибраного проєкту для очищення.'); return; }
    if(mode==='apply' && !document.getElementById('ogo-backup').checked) { alert('Спочатку зробіть резервну копію бази й позначте підтвердження.'); return; }
    if(mode==='start' && ogoMC.hasRun && !confirm('Почати новий звіт? Старий журнал і відкат збережуться. Очищення працюватиме для нового запуску.')) return;
    const applyPrompt=ogoMC.project==='editorial_urls'?'Замінити погоджені рубрики на теги за картою, включно зі старими рейтингами; зберегти активну рубрику top, текст і оформлення?':ogoMC.project==='woman_urls'?'Замінити посилання Жіночого клубу: головну /woman на тег zhinochyj-klub, 8 рубрик на погоджені теги, статті на URL за ID; зберегти текст та оформлення?':ogoMC.project==='dim_urls'?'Замінити головну рубрики Дім та її сторінки /page:N на https://ogo.ua/tag/dim/, зберігаючи текст та оформлення?':ogoMC.project==='nashregion_urls'?'Замінити регіональні рубрики з /nashregion і без нього, включно зі сторінками /page:N, на теги за картою; головну проєкту на Новини Рівного, статті на URL за ID; прибрати #page-top зі збереженням тексту та оформлення?':ogoMC.project==='pets_urls'?'Замінити посилання рубрики ogo.ua/rubrics/view/pets на https://ogo.ua/tag/domashni-tvaryny/, зберігаючи текст та оформлення?':ogoMC.project==='garden_urls'?'Замінити посилання рубрики ogo.ua/rubrics/view/garden на https://ogo.ua/tag/dacha-sad-gorod/, зберігаючи текст та оформлення?':ogoMC.project==='horoscope_urls'?'Замінити посилання рубрики ogo.ua/rubrics/view/goroskop на https://ogo.ua/tag/goroskop/, зберігаючи текст та оформлення?':ogoMC.project==='ustream'?'Видалити впізнані плеєри Ustream, стандартні підписи та блоки, що стали порожніми, згідно з попереднім переглядом?':(ogoMC.vkMode?'Обробити посилання ВК: зберегти текст джерел та імена, прибрати пункти ВК зі впізнаних списків і видалити впізнані застарілі абзаци згідно з попереднім переглядом?':(ogoMC.unwrapping?(ogoMC.project==='media_unwrap'?'Зняти решту посилань media.ogo.ua, зберігши весь текст і абзаци? Окреме очищення запрошень до галерей потрібно виконати перед цією операцією.':'Зняти посилання catalog.ogo.ua, зберігши весь текст і абзаци?'):(ogoMC.project==='legacy_urls'?'Замінити старі внутрішні URL на актуальні адреси статей за збереженими ID WordPress, зберігаючи текст та оформлення?':(ogoMC.project==='dossier_urls'?'Замінити старі посилання на досьє ОГО на підтверджені сторінки Рівнян, зберігаючи текст та оформлення?':(ogoMC.replacing?'Замінити адреси day.ogo.ua/articles/view/ на https://ogo.ua/articles/view/, зберігаючи текст?':(ogoMC.project==='media'?'Видалити окремі посилання та впізнані абзаци-запрошення до фото- і відеогалерей Медіа разом із їхнім текстом згідно з попереднім переглядом?':(ogoMC.project==='day'?'Видалити окремі рекомендації та підписи джерела day.ogo.ua, а в реченнях зняти посилання зі збереженням тексту згідно з попереднім переглядом?':'Видалити окремі посилання разом із текстом у готових матеріалах?')))))));
    if((mode==='apply'||mode==='restore') && !confirm(mode==='apply'?applyPrompt:'Відновити оригінали? Матеріали з подальшими правками буде пропущено.')) return;
    busy=true; stop=false;
    document.querySelectorAll('[data-mode]').forEach(b=>b.disabled=true);
    try {
      let d;
      if(selected) {
        for(let i=0;i<ids.length && !stop;i+=20) d=await call(mode,ids.slice(i,i+20),true);
      } else d=await call(mode,ids,false);
      if(mode==='start') mode='scan';
      if(mode==='scan') while(!d.state.complete && !stop) d=await call('scan');
      if((mode==='apply'||mode==='restore') && !selected) while(d.remaining>0 && !stop) d=await call(mode);
      progress.textContent += ' Операцію завершено. Оновлення звіту…';
      const query=new URLSearchParams({page:'ogo-media-cleaner',project:ogoMC.project});
      if(mode==='restore') query.set('run',ogoMC.run);
      window.location.assign(window.location.pathname+'?'+query.toString());
    } catch(e) { progress.textContent='Зупинено: '+e.message+' Оновіть сторінку для актуального журналу. Не запускайте очищення повторно, доки не перевірите звіт.'; }
    finally { busy=false; document.querySelectorAll('[data-mode]').forEach(b=>b.disabled=false); }
  }));
})();
