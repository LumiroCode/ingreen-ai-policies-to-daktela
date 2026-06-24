<?php

declare(strict_types=1);

/**
 * @var array{type:string,text:string}|null $message
 */

?>

<?php if ($message !== null && trim((string) ($message['text'] ?? '')) !== ''): ?>
    <?php
        $messageType = in_array($message['type'] ?? '', ['success', 'error'], true) ? $message['type'] : 'success';
        $autoClose = $messageType !== 'error';
    ?>
    <div
        class="toast <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>"
        <?= $autoClose ? 'data-autoclose="15000"' : '' ?>
    >
        <span class="toast-text"><?= htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8') ?></span>
        <button class="toast-close" type="button" aria-label="Zamknij komunikat">x</button>
    </div>
<?php endif; ?>
