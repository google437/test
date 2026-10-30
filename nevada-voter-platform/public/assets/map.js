// MapLibre map for a list (households as points, turfs as polygons, polygon drawing) and for a single turf.
(function () {
  const el = document.getElementById('map');
  if (!el || typeof maplibregl === 'undefined') return;
  const map = new maplibregl.Map({ container: 'map', style: el.dataset.style, center: [-115.14, 36.17], zoom: 10, attributionControl: true });
  map.addControl(new maplibregl.NavigationControl());
  const status = document.getElementById('map-status');

  function fit(features) {
    const b = new maplibregl.LngLatBounds();
    let n = 0;
    for (const f of features) { if (f.geometry?.type === 'Point') { b.extend(f.geometry.coordinates); n++; } }
    if (n) map.fitBounds(b, { padding: 40, maxZoom: 15 });
  }

  function addLayers(points, turfs) {
    map.addSource('hh', { type: 'geojson', data: points });
    map.addLayer({ id: 'hh', type: 'circle', source: 'hh', paint: { 'circle-radius': ['interpolate', ['linear'], ['zoom'], 10, 2, 15, 6], 'circle-color': '#2d6cb5', 'circle-stroke-color': '#fff', 'circle-stroke-width': 0.7 } });
    map.addSource('turfs', { type: 'geojson', data: { type: 'FeatureCollection', features: turfs || [] } });
    map.addLayer({ id: 'turfs-fill', type: 'fill', source: 'turfs', paint: { 'fill-color': '#f6ad55', 'fill-opacity': 0.18 } });
    map.addLayer({ id: 'turfs-line', type: 'line', source: 'turfs', paint: { 'line-color': '#dd6b20', 'line-width': 2 } });
    map.addLayer({ id: 'turfs-label', type: 'symbol', source: 'turfs', layout: { 'text-field': ['get', 'name'], 'text-size': 12 }, paint: { 'text-halo-color': '#fff', 'text-halo-width': 1.5 } });
    map.on('click', 'hh', e => {
      const p = e.features[0].properties;
      new maplibregl.Popup().setLngLat(e.features[0].geometry.coordinates).setHTML(`<strong>${p.address || ''}</strong><br>${p.voters} voter(s)`).addTo(map);
    });
    map.on('mouseenter', 'hh', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'hh', () => map.getCanvas().style.cursor = '');
  }

  map.on('load', async () => {
    if (el.dataset.geojson) {
      const r = await nvvpFetch(el.dataset.geojson);
      const j = await r.json();
      addLayers({ type: 'FeatureCollection', features: j.features }, j.turfs);
      fit(j.features);
      if (status) status.textContent = `${j.features.length.toLocaleString()} households on the map` + (j.missing_geocode ? ` · ${j.missing_geocode.toLocaleString()} not geocoded yet (run bin/ingest geocode)` : '');
      setupDraw();
    } else if (el.dataset.turf) {
      const r = await nvvpFetch(el.dataset.turf);
      const j = await r.json();
      const feats = j.households.filter(h => h.lat && h.lng).map(h => ({ type: 'Feature', geometry: { type: 'Point', coordinates: [+h.lng, +h.lat] }, properties: { address: h.address_line, voters: h.voters.length } }));
      addLayers({ type: 'FeatureCollection', features: feats }, j.turf.geom ? [{ type: 'Feature', geometry: j.turf.geom, properties: { name: j.turf.name } }] : []);
      fit(feats);
    }
  });

  // Simple polygon drawing: click to add vertices, "Finish" (or double-click) to close.
  function setupDraw() {
    const start = document.getElementById('draw-start'), finish = document.getElementById('draw-finish'), cancel = document.getElementById('draw-cancel'), form = document.getElementById('draw-form');
    if (!start) return;
    let pts = [], drawing = false, geom = null;
    map.addSource('draw', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addLayer({ id: 'draw-line', type: 'line', source: 'draw', paint: { 'line-color': '#e53e3e', 'line-width': 2, 'line-dasharray': [2, 1] } });
    map.addLayer({ id: 'draw-fill', type: 'fill', source: 'draw', paint: { 'fill-color': '#e53e3e', 'fill-opacity': 0.1 } });
    map.addLayer({ id: 'draw-pts', type: 'circle', source: 'draw', paint: { 'circle-radius': 4, 'circle-color': '#e53e3e' }, filter: ['==', '$type', 'Point'] });
    function render(closed) {
      const feats = pts.map(p => ({ type: 'Feature', geometry: { type: 'Point', coordinates: p }, properties: {} }));
      if (pts.length > 1) feats.push({ type: 'Feature', geometry: closed ? { type: 'Polygon', coordinates: [[...pts, pts[0]]] } : { type: 'LineString', coordinates: pts }, properties: {} });
      map.getSource('draw').setData({ type: 'FeatureCollection', features: feats });
    }
    function reset() { pts = []; drawing = false; geom = null; render(false); map.getCanvas().style.cursor = ''; finish.hidden = cancel.hidden = true; start.hidden = false; form.hidden = true; }
    start.onclick = () => { reset(); drawing = true; start.hidden = true; finish.hidden = cancel.hidden = false; map.getCanvas().style.cursor = 'crosshair'; if (status) status.textContent = 'Click around the area to draw a turf, then press Finish.'; };
    cancel.onclick = reset;
    map.on('click', e => { if (!drawing) return; pts.push([e.lngLat.lng, e.lngLat.lat]); render(false); });
    map.doubleClickZoom.disable();
    const done = () => { if (!drawing || pts.length < 3) return; drawing = false; geom = { type: 'Polygon', coordinates: [[...pts, pts[0]]] }; render(true); map.getCanvas().style.cursor = ''; finish.hidden = true; form.hidden = false; };
    finish.onclick = done;
    map.on('dblclick', done);
    document.getElementById('turf-save').onclick = async () => {
      if (!geom) return;
      const r = await nvvpFetch(`/api/lists/${el.dataset.list}/turfs/polygon`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: document.getElementById('turf-name').value, script_id: document.getElementById('turf-script').value || null, geometry: geom }) });
      const j = await r.json();
      if (j.id) {
        const src = map.getSource('turfs'); const d = src._data; d.features.push({ type: 'Feature', geometry: geom, properties: { id: j.id, name: j.name, doors: j.door_count } }); src.setData(d);
        if (status) status.innerHTML = `Turf "<b>${j.name}</b>" created with ${j.door_count} doors and ${j.voter_count} voters. <a href="/turfs/${j.id}">Open it</a>.`;
        reset();
      } else { alert(j.error || 'Could not create the turf.'); }
    };
  }
})();
