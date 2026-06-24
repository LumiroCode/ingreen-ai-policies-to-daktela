<?php

declare(strict_types=1);

/**
 * @var array{type:string,text:string}|null $message
 */

?>

<?php
    $feedbackType = $message['type'] ?? 'processing';
    $feedbackText = $message['text'] ?? 'Trwa odczyt danych z polisy.';
    $isProcessing = $message === null;
?>

<style>
.feedback-message {
    margin-top: 12px;
    padding: 10px 12px;
    max-width: 560px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.feedback-message.success {
    background: #edf7ed;
    border: 1px solid #8bc58b;
    color: #1f5b1f;
}

.feedback-message.error {
    background: #fdecec;
    border: 1px solid #d98a8a;
    color: #7a1f1f;
}

.feedback-message.processing {
    align-items: center;
    background: #eef4ff;
    border: 1px solid #8aa9d9;
    color: #1f3f70;
    display: flex;
    gap: 8px;
}

.feedback-message[hidden] {
    display: none;
}
</style>

<div
    id="<?= $isProcessing ? 'processing-message' : 'feedback-message' ?>"
    class="feedback-message <?= htmlspecialchars($feedbackType, ENT_QUOTES, 'UTF-8') ?>"
    <?= $isProcessing ? 'hidden' : '' ?>
>
    <?php if ($isProcessing): ?>
        <span class="spinner" aria-hidden="true"></span>
    <?php endif; ?>
    <?= htmlspecialchars($feedbackText, ENT_QUOTES, 'UTF-8') ?>
</div>
