(() => {
  'use strict';
  // Confirmation text is explicit for every operation; no code is constructed from it.
  const applyPrompts = {
    legacy_missing: "Замінити URL наявних статей і зняти архівні та відсутні посилання зі збереженням тексту й оформлення згідно з попереднім переглядом?",
    segodnya: "Зняти посилання на segodnya.ua та піддомени, зберігши текст, оформлення й абзаци?",
    miralinks_unwrap: "Зняти посилання за вибраною ревізією Міралінкс, зберігши текст та оформлення?",
    tsn: "Оновити target і rel посилань на tsn.ua та піддомени згідно з попереднім переглядом?",
    korrespondent: "Оновити target і rel посилань на korrespondent.net та піддомени згідно з попереднім переглядом?",
    source_batch_1: "Оновити target і rel посилань першої партії згідно з попереднім переглядом?",
    editorial_urls: "Замінити погоджені рубрики на теги за картою, включно зі старими рейтингами; зберегти активну рубрику top, текст і оформлення?",
    vybir_home: "Замінити посилання на головну «Вибір 2010» на тег «Рейтинги» згідно з попереднім переглядом?",
    woman_urls: "Замінити посилання Жіночого клубу: головну /woman на тег zhinochyj-klub, 8 рубрик на погоджені теги, статті на URL за ID; зберегти текст та оформлення?",
    dim_urls: "Замінити головну рубрики Дім та її сторінки /page:N на https://ogo.ua/tag/dim/, зберігаючи текст та оформлення?",
    nashregion_urls: "Замінити регіональні рубрики з /nashregion і без нього, включно зі сторінками /page:N, на теги за картою; головну проєкту на Новини Рівного, статті на URL за ID; прибрати #page-top зі збереженням тексту та оформлення?",
    pets_urls: "Замінити посилання рубрики ogo.ua/rubrics/view/pets на https://ogo.ua/tag/domashni-tvaryny/, зберігаючи текст та оформлення?",
    garden_urls: "Замінити посилання рубрики ogo.ua/rubrics/view/garden на https://ogo.ua/tag/dacha-sad-gorod/, зберігаючи текст та оформлення?",
    horoscope_urls: "Замінити посилання рубрики ogo.ua/rubrics/view/goroskop на https://ogo.ua/tag/goroskop/, зберігаючи текст та оформлення?",
    ustream: "Видалити впізнані плеєри Ustream, стандартні підписи та блоки, що стали порожніми, згідно з попереднім переглядом?",
    flash: "Відновити впізнані відео YouTube й видалити інші підтверджені Flash-вставки згідно з попереднім переглядом?",
    vk: "Обробити посилання ВК: зберегти текст джерел та імена, прибрати пункти ВК зі впізнаних списків і видалити впізнані застарілі абзаци згідно з попереднім переглядом?",
    legacy_urls: "Замінити старі внутрішні URL на актуальні адреси статей за збереженими ID WordPress, зберігаючи текст та оформлення?",
    day_urls: "Замінити адреси day.ogo.ua/articles/view/ на https://ogo.ua/articles/view/, зберігаючи текст?",
    day: "Видалити окремі рекомендації та підписи джерела day.ogo.ua, а в реченнях зняти посилання зі збереженням тексту згідно з попереднім переглядом?",
  };
  const defaultApplyPrompt = "Видалити окремі посилання разом із текстом у готових матеріалах?";
  let busy = false, stop = false;
  let miralinksRun = ogoMC.currentRun;
  const progress = document.getElementById('ogo-progress');
  const labels = {ready:'Готові',review:'Ручна перевірка',done:'Очищені',restored:'Відновлені',conflict:'Пропущені'};
  if(ogoMC.replacing) { labels.ready='Готові до заміни'; labels.done='URL замінено'; }
  if(ogoMC.vkMode) { labels.ready='Готові до обробки'; labels.done='Оброблено'; }
  if(ogoMC.unwrapping) { labels.ready='Готові до зняття'; labels.done='Посилання знято'; }
  if(ogoMC.updatingAttributes) { labels.ready='Готові до оновлення'; labels.done='Атрибути оновлено'; }
  document.getElementById('ogo-stop').addEventListener('click', () => { stop = true; });
  async function call(mode, ids, selected) {
    const body = new URLSearchParams({action:'ogo_mc',nonce:ogoMC.nonce,mode,run:ogoMC.run,project:ogoMC.project,backup:document.getElementById('ogo-backup')?.checked?'1':''});
    if (ogoMC.project==='miralinks_unwrap') {
      if (mode==='start') body.set('audit_run',ogoMC.auditRun);
      if (mode==='scan') body.set('run',miralinksRun);
    }
    if(selected) body.set('selected','1');
    if(ids) ids.forEach(id => body.append('ids[]',id));
    const response = await fetch(ogoMC.url,{method:'POST',body,credentials:'same-origin'});
    const result = await response.json();
    if(!result.success) throw new Error(typeof result.data==='string'?result.data:'Помилка сервера.');
    const d = result.data;
    if (mode==='start' && ogoMC.project==='miralinks_unwrap') miralinksRun=d.state.run;
    const scanProgress = ogoMC.archived ? '' : `Перевірено: ${d.state.scanned}. ${d.state.complete?'Сканування завершено.':'Сканування триває.'} `;
    progress.textContent = scanProgress + d.stats.map(s=>`${labels[s.status]||s.status}: ${s.materials}`).join('; ');
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
    const applyPrompt = applyPrompts[ogoMC.project] || defaultApplyPrompt;
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
