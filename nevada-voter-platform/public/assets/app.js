// Shared desktop behaviour: list-builder live counts, precinct filtering, survey answer options.
(function () {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
  window.nvvpFetch = (url, opts = {}) => fetch(url, Object.assign({ credentials: 'same-origin' }, opts, {
    headers: Object.assign({ 'X-CSRF-Token': csrf, 'Accept': 'application/json' }, opts.headers || {})
  }));

  // Inline handlers are blocked by the Content-Security-Policy, so these are wired here.
  document.querySelectorAll('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form && el.form.submit()));
  document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));

  const form = document.getElementById('list-form');
  if (!form) return;

  function filters() {
    const fd = new FormData(form);
    const out = {};
    for (const [k, v] of fd.entries()) {
      if (k === '_csrf' || k === 'name' || k === 'freeze' || v === '') continue;
      const key = k.replace(/\[\]$/, '');
      if (k.endsWith('[]')) { (out[key] = out[key] || []).push(v); } else { out[key] = v; }
    }
    if (fd.get('chase_mode')) out.chase = true;
    return out;
  }

  let timer = null;
  async function refresh() {
    const v = document.getElementById('cnt-voters'), d = document.getElementById('cnt-doors');
    v.textContent = '…'; d.textContent = '…';
    try {
      const r = await nvvpFetch('/api/lists/count', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(filters()) });
      const j = await r.json();
      v.textContent = (j.voters ?? 0).toLocaleString(); d.textContent = (j.doors ?? 0).toLocaleString();
    } catch (e) { v.textContent = '?'; d.textContent = '?'; }
  }
  form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refresh, 350); });
  form.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(refresh, 150); });
  refresh();

  // Precinct choices follow the selected districts.
  const dsel = document.getElementById('f-districts'), psel = document.getElementById('f-precincts');
  if (dsel && psel) {
    dsel.addEventListener('change', async () => {
      const ids = [...dsel.selectedOptions].map(o => o.value).join(',');
      const r = await nvvpFetch('/api/precincts?districts=' + ids);
      const rows = await r.json();
      const keep = new Set([...psel.selectedOptions].map(o => o.value));
      psel.innerHTML = '';
      for (const p of rows) { const o = new Option(p.number, p.id, false, keep.has(String(p.id))); psel.add(o); }
    });
  }

  // Survey answers follow the chosen question.
  const q = document.getElementById('f-survey-q'), a = document.getElementById('f-survey-a');
  if (q && a) {
    q.addEventListener('change', () => {
      const opts = JSON.parse(q.selectedOptions[0]?.dataset.options || '[]');
      a.innerHTML = '';
      for (const o of opts) a.add(new Option(o, o));
    });
  }
})();
