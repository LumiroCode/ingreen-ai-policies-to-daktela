<?php

declare(strict_types=1);

/**
 * @var array{type:string,text:string}|null $message
 */

?>

<?php if ($message !== null && trim((string) ($message['text'] ?? '')) !== ''): ?>
    <?php
        $messageType = in_array($message['type'] ?? '', ['success', 'error'], true) ? $message['type'] : 'success';
    ?>
    <div class="status-message <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>
