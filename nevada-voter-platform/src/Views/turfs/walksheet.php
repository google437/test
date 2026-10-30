<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title><?= e($title) ?></title>
<style>
body{font:11pt/1.3 system-ui,sans-serif;margin:1.2cm;color:#111}h1{font-size:15pt;margin:0 0 .2em}h2{font-size:11pt;margin:.8em 0 .3em}table{width:100%;border-collapse:collapse;font-size:9.5pt}th,td{border:1px solid #999;padding:3px 5px;vertical-align:top;text-align:left}th{background:#eee}
.hh{background:#f6f6f6;font-weight:600}.box{display:inline-block;width:11px;height:11px;border:1px solid #333;margin-right:3px;vertical-align:middle}.small{font-size:8.5pt;color:#444}.script{border:1px solid #bbb;padding:6px 8px;white-space:pre-wrap;background:#fafafa}
@media print{.noprint{display:none}}@page{margin:1.2cm}
</style></head><body>
<div class="noprint"><button id="print-btn">Print / Save as PDF</button> <a href="/turfs/<?= (int) $turf['id'] ?>">Back</a></div>
<h1><?= e($turf['name']) ?></h1>
<p class="small">List: <?= e($turf['list_name']) ?> · <?= (int) $turf['door_count'] ?> doors, <?= (int) $turf['voter_count'] ?> voters · Canvasser: <?= e($turf['assignee'] ?: '____________') ?> · Date: ____________</p>
<p class="small">Election-use only. Do not copy or share this sheet. Return it to the campaign office when finished.</p>
<?php if ($script): ?><h2>Script: <?= e($script['name']) ?></h2><div class="script"><?= e($script['body']) ?></div><?php endif; ?>
<h2>Walk list</h2>
<table><thead><tr><th style="width:4%">#</th><th style="width:30%">Address / voter</th><th style="width:7%">Party</th><th style="width:5%">Age</th><th style="width:22%">Result</th><th>Survey / notes</th></tr></thead><tbody>
<?php foreach ($walk as $h): ?>
<tr class="hh"><td><?= (int) $h['walk_order'] ?></td><td colspan="5"><?= e((string) $h['address_line']) ?>, <?= e((string) $h['city']) ?> <?= e((string) $h['zip']) ?></td></tr>
<?php foreach ($h['voters'] as $v): ?>
<tr><td></td><td><?= e($v['name']) ?><?= $v['unit'] ? ' (unit ' . e($v['unit']) . ')' : '' ?><?= $v['voted'] ? ' <b>VOTED</b>' : '' ?></td><td><?= e((string) $v['party']) ?></td><td><?= e((string) $v['age']) ?></td>
<td><span class="box"></span>Contacted <span class="box"></span>Not home<br><span class="box"></span>Refused <span class="box"></span>Moved</td>
<td><?php foreach ($questions as $q): ?><div class="small"><?= e(mb_strimwidth($q['question'], 0, 40, '…')) ?>: ________</div><?php endforeach; ?></td></tr>
<?php endforeach; endforeach; ?>
</tbody></table>
<script src="/assets/print.js"></script></body></html>
