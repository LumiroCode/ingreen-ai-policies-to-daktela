<?php

declare(strict_types=1);

/**
 * @var string $ticketId
 * @var string $ticketTitle
 * @var string $accessToken
 * @var array{type:string,text:string}|null $message
 * @var list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}> $attachments
 * @var string|null $selectedAttachmentIndex
 */

$previewAttachmentIndex = null;
$previewAttachment = null;

if ($selectedAttachmentIndex !== null && ctype_digit($selectedAttachmentIndex)) {
    $previewAttachmentIndex = (int) $selectedAttachmentIndex;
    $previewAttachment = $attachments[$previewAttachmentIndex] ?? null;
}

$previewAttachmentTitle = is_array($previewAttachment)
    ? (string) ($previewAttachment['title'] ?? basename((string) $previewAttachment['file']))
    : null;

$previewUrl = is_array($previewAttachment)
    ? '?' . http_build_query([
        'ticket' => $ticketId,
        'attachment' => (string) $previewAttachmentIndex,
        'access_token' => $accessToken,
        'policy_pdf' => '1',
    ])
    : null;

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
        <div class="app-layout">
            <div class="app-flow">
                <header class="app-header">
                    <p class="app-kicker">Polisa z PDF</p>
                    <h1 class="ticket-title"><?= htmlspecialchars($ticketTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="ticket-debug">Ticket #<?= htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8') ?></p>
                </header>

                <?php
                    require __DIR__ . '/pdf-attachments-table.php';
                    require __DIR__ . '/feedback-confirmation.php';
                ?>
            </div>

            <aside class="panel pdf-preview-panel" aria-labelledby="pdf-preview-heading">
                <div class="section-heading">
                    <div class="review-title">
                        <h2 id="pdf-preview-heading">Podgląd PDF</h2>
                        <?php if ($previewAttachmentTitle !== null && trim($previewAttachmentTitle) !== ''): ?>
                            <p><?= htmlspecialchars($previewAttachmentTitle, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($previewUrl !== null): ?>
                    <iframe
                        class="pdf-preview-frame"
                        src="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>"
                        title="Podgląd PDF: <?= htmlspecialchars((string) $previewAttachmentTitle, ENT_QUOTES, 'UTF-8') ?>"
                    ></iframe>
                <?php else: ?>
                    <p class="empty-state">Brak pliku PDF do podglądu.</p>
                <?php endif; ?>
            </aside>
        </div>
    </main>
</body>
</html>
