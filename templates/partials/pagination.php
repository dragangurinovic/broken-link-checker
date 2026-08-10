<?php if (isset($totalPages) && $totalPages > 1): ?>
<nav class="pagination">
    <?php
    $currentPg = $currentPg ?? 1;
    $baseUrl = $paginationUrl ?? '?';
    $range = 2;
    ?>

    <?php if ($currentPg > 1): ?>
        <a href="<?= $baseUrl ?>&pg=<?= $currentPg - 1 ?>" class="page-link">&laquo; Prev</a>
    <?php endif; ?>

    <?php for ($i = max(1, $currentPg - $range); $i <= min($totalPages, $currentPg + $range); $i++): ?>
        <a href="<?= $baseUrl ?>&pg=<?= $i ?>" class="page-link <?= $i === $currentPg ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($currentPg < $totalPages): ?>
        <a href="<?= $baseUrl ?>&pg=<?= $currentPg + 1 ?>" class="page-link">Next &raquo;</a>
    <?php endif; ?>
</nav>
<?php endif; ?>
