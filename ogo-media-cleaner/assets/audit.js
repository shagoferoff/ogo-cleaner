(() => {
  'use strict';
  const start = document.getElementById('ogo-audit-start');
  const resume = document.getElementById('ogo-audit-scan');
  const stop = document.getElementById('ogo-audit-stop');
  const progress = document.getElementById('ogo-audit-progress');
  let busy = false, stopping = false, run = ogoAudit.run;
  stop.addEventListener('click', () => { stopping = true; });
  async function request(mode) {
    const body = new URLSearchParams({action:'ogo_mc_audit', nonce:ogoAudit.nonce, mode, run});
    const response = await fetch(ogoAudit.url, {method:'POST', body, credentials:'same-origin'});
    const result = await response.json();
    if (!result.success) throw new Error(typeof result.data === 'string' ? result.data : 'Помилка сервера.');
    const d = result.data;
    run = d.state.run;
    progress.textContent = `Перевірено матеріалів: ${d.state.scanned}. Груп URL: ${d.stats.totals.urls}; посилань: ${d.stats.totals.links}; матеріалів із посиланнями: ${d.stats.totals.materials}. ${d.state.complete ? 'Ревізію завершено.' : 'Сканування триває.'}`;
    return d;
  }
  async function scan(mode) {
    if (busy) return;
    if (mode === 'start' && run && !confirm('Почати нову ревізію? Попередні звіти збережуться. Статті не змінюються.')) return;
    busy = true; stopping = false; start.disabled = true; resume.disabled = true;
    try {
      let d = await request(mode);
      while (!d.state.complete && !stopping) d = await request('scan');
      const url = new URL(ogoAudit.reportUrl, window.location.href);
      url.searchParams.set('run', run);
      window.location.assign(url.toString());
    } catch (e) {
      progress.textContent = 'Ревізію зупинено: ' + e.message + ' Оновіть сторінку й натисніть «Продовжити поточну ревізію». Збережені результати залишаються.';
    } finally {
      busy = false; start.disabled = false; resume.disabled = !run;
    }
  }
  start.addEventListener('click', () => scan('start'));
  resume.addEventListener('click', () => scan('scan'));
})();
