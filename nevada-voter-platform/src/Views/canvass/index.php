<h1>My turfs</h1>
<p class="small muted">Open a turf once while online; it is saved on this device so you can keep knocking without a signal. Results sync automatically when you are back online. <span id="queue-status"></span></p>
<ul class="cards">
<?php foreach ($mine as $t): ?><li class="card"><a class="big-link" href="/canvass/turf/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a><div class="small muted"><?= e($t['list_name']) ?> · <?= (int) $t['door_count'] ?> doors · <?= (int) $t['attempts'] ?> attempts · <?= e(result_label($t['status'])) ?></div></li><?php endforeach; ?>
<?php if (!$mine): ?><li class="card muted">No turfs assigned to you yet.</li><?php endif; ?>
</ul>
<?php if ($unassigned): ?><h2>Unassigned turfs</h2><ul class="cards">
<?php foreach ($unassigned as $t): ?><li class="card"><a class="big-link" href="/canvass/turf/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a><div class="small muted"><?= e($t['list_name']) ?> · <?= (int) $t['door_count'] ?> doors</div></li><?php endforeach; ?>
</ul><?php endif; ?>
