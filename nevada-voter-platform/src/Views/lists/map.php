<div class="page-head"><h1><?= e($list['name']) ?>: map</h1>
  <div class="actions">
    <a class="btn" href="/lists/<?= (int) $list['id'] ?>">Back to list</a>
    <a class="btn" href="/lists/<?= (int) $list['id'] ?>/turfs">Turfs</a>
    <?php if (App\Core\Auth::hasRole('organizer')): ?>
    <button class="btn primary" id="draw-start" type="button">Draw a turf</button>
    <button class="btn" id="draw-finish" type="button" hidden>Finish polygon</button>
    <button class="btn" id="draw-cancel" type="button" hidden>Cancel</button>
    <?php endif; ?>
  </div>
</div>
<p class="small muted" id="map-status">Loading households…</p>
<div id="map" class="map" data-list="<?= (int) $list['id'] ?>" data-style="<?= e($mapStyle) ?>" data-geojson="/api/lists/<?= (int) $list['id'] ?>/geojson"></div>
<div id="draw-form" class="card" hidden>
  <label>Turf name <input id="turf-name" placeholder="e.g. Walk 1 — north of Sahara"></label>
  <label>Script <select id="turf-script"><option value="">Default</option><?php foreach ($scripts as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
  <button class="btn primary" id="turf-save" type="button">Create turf from polygon</button>
</div>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css">
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
<script src="/assets/map.js" defer></script>
