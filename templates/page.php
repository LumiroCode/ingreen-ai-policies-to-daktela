<?php

declare(strict_types=1);

/**
 * @var string $ticketId
 * @var array{type:string,text:string}|null $message
 */

?>

<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Polisa z PDF</title>
    <style>
        <?php readfile(dirname(__DIR__) . '/public/assets/app.css'); ?>
    </style>
    <script>
        <?php readfile(dirname(__DIR__) . '/public/assets/app.js'); ?>
    </script>
</head>
<body>
    <div class="toast-stack" aria-live="polite" aria-atomic="true">
        <?php require __DIR__ . '/feedback-message.php'; ?>

        <div id="processing-message" class="toast processing" hidden>
            <span class="spinner" aria-hidden="true"></span>
            <span class="toast-text">Trwa odczyt danych z polisy.</span>
            <button class="toast-close" type="button" aria-label="Zamknij komunikat">x</button>
        </div>
    </div>
    <main class="app-shell">
        <header class="app-header">
            <div>
                <p class="app-kicker">Polisa z PDF</p>
            </div>
            <div class="ticket-pill">#<?= htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8') ?></div>
        </header>

        <?php
            require __DIR__ . '/pdf-attachments-table.php';
            require __DIR__ . '/feedback-confirmation.php';
        ?>
    </main>
</body>
</html>
