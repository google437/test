// Offline-capable canvass app: caches the turf in IndexedDB, queues results, syncs when online.
(function () {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
  const api = (url, opts = {}) => fetch(url, Object.assign({ credentials: 'same-origin' }, opts, { headers: Object.assign({ 'X-CSRF-Token': csrf, 'Accept': 'application/json' }, opts.headers || {}) }));
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});

  // ---- tiny IndexedDB wrapper ----
  const DB = { name: 'nvvp-canvass', v: 1, db: null };
  function open() {
    return new Promise((res, rej) => {
      if (DB.db) return res(DB.db);
      const r = indexedDB.open(DB.name, DB.v);
      r.onupgradeneeded = () => { const d = r.result; if (!d.objectStoreNames.contains('turfs')) d.createObjectStore('turfs'); if (!d.objectStoreNames.contains('queue')) d.createObjectStore('queue', { keyPath: 'client_id' }); if (!d.objectStoreNames.contains('done')) d.createObjectStore('done'); };
      r.onsuccess = () => { DB.db = r.result; res(DB.db); };
      r.onerror = () => rej(r.error);
    });
  }
  async function idb(store, mode, fn) { const d = await open(); return new Promise((res, rej) => { const tx = d.transaction(store, mode); const s = tx.objectStore(store); const out = fn(s); tx.oncomplete = () => res(out && out.result !== undefined ? out.result : out); tx.onerror = () => rej(tx.error); }); }
  const put = (store, key, val) => idb(store, 'readwrite', s => key === undefined ? s.put(val) : s.put(val, key));
  const get = (store, key) => idb(store, 'readonly', s => s.get(key));
  const all = store => idb(store, 'readonly', s => s.getAll());
  const del = (store, key) => idb(store, 'readwrite', s => s.delete(key));

  // ---- connectivity + sync ----
  const net = document.getElementById('net');
  function setNet() { if (net) { net.classList.toggle('off', !navigator.onLine); net.title = navigator.onLine ? 'Online' : 'Offline: results are queued'; } }
  window.addEventListener('online', () => { setNet(); sync(); });
  window.addEventListener('offline', setNet);
  setNet();

  let syncing = false;
  async function sync() {
    if (syncing || !navigator.onLine) return;
    const q = await all('queue');
    const qs = document.getElementById('queue-status');
    if (!q.length) { if (qs) qs.textContent = 'All results synced.'; return; }
    syncing = true;
    if (qs) qs.textContent = `Syncing ${q.length} result(s)…`;
    try {
      const r = await api('/api/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ attempts: q }) });
      if (r.status === 401) { if (qs) qs.textContent = 'Sign in again to sync queued results.'; return; }
      const j = await r.json();
      for (const id of j.synced || []) await del('queue', id);
      for (const f of j.failed || []) if (f.reason === 'rejected') await del('queue', f.client_id);
      if (qs) qs.textContent = (j.synced || []).length ? `Synced ${j.synced.length} result(s).` : '';
    } catch (e) { if (qs) qs.textContent = 'Could not sync yet; will retry.'; }
    finally { syncing = false; }
  }
  sync();
  setInterval(sync, 60000);

  // ---- turf screen ----
  const root = document.getElementById('canvass');
  if (!root) return;
  const turfId = root.dataset.turf, listEl = document.getElementById('cv-list'), progress = document.getElementById('cv-progress');
  const sheet = document.getElementById('cv-sheet'), votersEl = document.getElementById('cv-voters');
  let data = null, map = null;

  async function load() {
    try {
      const r = await api(root.dataset.api);
      if (!r.ok) throw new Error('offline');
      data = await r.json();
      await put('turfs', turfId, data);
    } catch (e) {
      data = await get('turfs', turfId);
      if (!data) { progress.textContent = 'This turf is not saved on this device. Open it once while online.'; return; }
    }
    renderList();
  }

  async function localResults() {
    const out = {};
    for (const q of await all('queue')) if (String(q.turf_id) === String(turfId)) out[q.voter_id] = q.result;
    for (const d of await all('done')) if (d.turf && String(d.turf) === String(turfId)) out[d.voter] = d.result;
    return out;
  }

  async function renderList() {
    const local = await localResults();
    let done = 0, total = 0;
    listEl.innerHTML = '';
    data.households.forEach((h, i) => {
      const results = h.voters.map(v => local[v.id] || v.last_result);
      const n = results.filter(Boolean).length; total += h.voters.length; done += n;
      const row = document.createElement('div'); row.className = 'hh-row';
      row.innerHTML = `<span class="dot ${n === h.voters.length ? 'done' : n ? 'partial' : ''}"></span><div class="addr"><strong>${h.walk_order}. ${esc(h.address_line || '')}</strong><small>${h.voters.map(v => esc(v.name) + (v.voted ? ' (voted)' : '')).join(' · ')}</small></div><span class="muted small">${h.voters.length}</span>`;
      row.onclick = () => openHousehold(i);
      listEl.appendChild(row);
    });
    progress.textContent = `${data.turf.name}: ${done}/${total} voters recorded · ${data.households.length} doors`;
  }

  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function openHousehold(i) {
    const h = data.households[i];
    const local = await localResults();
    document.getElementById('cv-hh-addr').textContent = `${h.address_line || ''}${h.city ? ', ' + h.city : ''}`;
    document.getElementById('cv-script').textContent = data.script?.body || 'No script configured.';
    votersEl.innerHTML = '';
    for (const v of h.voters) {
      const card = document.createElement('div'); card.className = 'cv-voter';
      const prior = local[v.id] || v.last_result;
      card.innerHTML = `<h3>${esc(v.name)} <span class="muted small">${esc(v.party || '')}${v.age ? ', ' + v.age : ''}${v.unit ? ', unit ' + esc(v.unit) : ''}${v.voted ? ' · already voted' : ''}</span></h3>
        <div class="muted small">${prior ? 'Last: ' + esc(prior.replace('_', ' ')) : 'Not yet contacted'}</div>
        <div class="cv-results">${data.results.map(r => `<button type="button" data-r="${r}">${r.replace('_', ' ')}</button>`).join('')}</div>
        <div class="cv-survey" hidden>${data.questions.map(q => `<div class="cv-q"><label>${esc(q.question)}${q.qtype === 'text' ? `<input data-q="${q.id}">` : `<select data-q="${q.id}"><option value="">—</option>${q.options.map(o => `<option>${esc(o)}</option>`).join('')}</select>`}</label></div>`).join('')}
        <textarea rows="2" placeholder="Notes"></textarea></div>
        <button type="button" class="btn primary cv-save" hidden>Save</button> <span class="cv-saved"></span>`;
      let result = null;
      card.querySelectorAll('.cv-results button').forEach(b => b.onclick = () => {
        card.querySelectorAll('.cv-results button').forEach(x => x.classList.remove('sel')); b.classList.add('sel'); result = b.dataset.r;
        card.querySelector('.cv-survey').hidden = result !== 'contacted'; card.querySelector('.cv-save').hidden = false;
        if (result !== 'contacted') save();
      });
      card.querySelector('.cv-save').onclick = save;
      async function save() {
        const answers = {};
        card.querySelectorAll('[data-q]').forEach(el => { if (el.value) answers[el.dataset.q] = el.value; });
        const item = { client_id: `${turfId}-${v.id}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`, voter_id: v.id, turf_id: data.turf.id, list_id: data.turf.list_id, channel: 'door', result, notes: card.querySelector('textarea').value, answers, contacted_at: new Date().toISOString() };
        if (navigator.geolocation) { try { const pos = await new Promise((res, rej) => navigator.geolocation.getCurrentPosition(res, rej, { timeout: 1500, maximumAge: 60000 })); item.lat = pos.coords.latitude; item.lng = pos.coords.longitude; } catch (e) { } }
        await put('queue', undefined, item);
        await put('done', `${turfId}-${v.id}`, { turf: turfId, voter: v.id, result });
        card.querySelector('.cv-saved').textContent = navigator.onLine ? 'Saved' : 'Saved offline';
        card.querySelector('.cv-save').hidden = true;
        sync();
        renderList();
      }
      votersEl.appendChild(card);
    }
    sheet.hidden = false;
  }
  document.getElementById('cv-close').onclick = () => { sheet.hidden = true; };

  document.getElementById('cv-map-toggle').onclick = () => {
    const m = document.getElementById('cv-map'); m.hidden = !m.hidden;
    if (!m.hidden && !map && typeof maplibregl !== 'undefined' && data) {
      map = new maplibregl.Map({ container: 'cv-map', style: root.dataset.style, center: [-115.14, 36.17], zoom: 12 });
      map.addControl(new maplibregl.GeolocateControl({ trackUserLocation: true }));
      map.on('load', () => {
        const feats = data.households.filter(h => h.lat && h.lng).map((h, i) => ({ type: 'Feature', geometry: { type: 'Point', coordinates: [+h.lng, +h.lat] }, properties: { i: data.households.indexOf(h), label: String(h.walk_order) } }));
        map.addSource('hh', { type: 'geojson', data: { type: 'FeatureCollection', features: feats } });
        map.addLayer({ id: 'hh', type: 'circle', source: 'hh', paint: { 'circle-radius': 9, 'circle-color': '#2d6cb5', 'circle-stroke-color': '#fff', 'circle-stroke-width': 1 } });
        map.addLayer({ id: 'hh-l', type: 'symbol', source: 'hh', layout: { 'text-field': ['get', 'label'], 'text-size': 10 }, paint: { 'text-color': '#fff' } });
        map.on('click', 'hh', e => openHousehold(e.features[0].properties.i));
        const b = new maplibregl.LngLatBounds(); feats.forEach(f => b.extend(f.geometry.coordinates)); if (feats.length) map.fitBounds(b, { padding: 30, maxZoom: 16 });
      });
    }
  };
  load();
})();
