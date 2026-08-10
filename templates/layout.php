<?php
$currentPage = $_GET['page'] ?? 'dashboard';
$pageTitle = $pageTitle ?? 'Broken Link Checker';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Broken Link Checker</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <?php if (isset($extraCss)): ?>
        <?php foreach ((array)$extraCss as $css): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/partials/header.php'; ?>

        <div class="app-body">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>

            <main class="main-content">
                <?php include __DIR__ . '/partials/flash-messages.php'; ?>
                <?= $content ?? '' ?>
            </main>
        </div>
    </div>

    <div id="toast-container"></div>

    <script src="assets/js/app.js"></script>
    <?php if (isset($extraJs)): ?>
        <?php foreach ((array)$extraJs as $js): ?>
            <script src="<?= htmlspecialchars($js) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
