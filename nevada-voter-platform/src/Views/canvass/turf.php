<div id="canvass" data-turf="<?= (int) $turf['id'] ?>" data-api="/api/turfs/<?= (int) $turf['id'] ?>" data-style="<?= e($mapStyle) ?>">
  <div class="cv-head"><span id="cv-progress" class="small muted">Loading turf…</span><button class="btn small" id="cv-map-toggle" type="button">Map</button></div>
  <div id="cv-map" class="map small" hidden></div>
  <div id="cv-list"></div>
  <div id="cv-sheet" class="sheet" hidden>
    <div class="sheet-head"><strong id="cv-hh-addr"></strong><button class="linklike" id="cv-close" type="button">Close</button></div>
    <div id="cv-voters"></div>
    <details class="script"><summary>Script</summary><pre id="cv-script"></pre></details>
  </div>
</div>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css"><script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
