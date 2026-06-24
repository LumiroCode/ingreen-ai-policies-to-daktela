<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Polisa z PDF</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
    <main class="app-shell">
        <header class="app-header">
            <div>
                <p class="app-kicker">Daktela</p>
                <h1>Polisa z PDF</h1>
            </div>
            <div class="ticket-pill">#<?= htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8') ?></div>
        </header>

        <?php require __DIR__ . '/feedback-message.php'; ?>

        <div id="processing-message" class="status-message processing" hidden>
            <span class="spinner" aria-hidden="true"></span>
            <span>Trwa odczyt danych z polisy.</span>
        </div>

        <?php
            require __DIR__ . '/pdf-attachments-table.php';
            require __DIR__ . '/feedback-confirmation.php';
        ?>
    </main>
</body>
</html>
