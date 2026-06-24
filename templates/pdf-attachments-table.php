<?php

declare(strict_types=1);

/**
 * @var list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}> $attachments
 * @var string $accessToken
 * @var string|null $selectedAttachmentIndex
 * @var string $ticketId
 * @var string $ticketTitle
 */

?>

<section class="panel attachment-panel" aria-labelledby="attachments-heading">
    <div class="section-heading">
        <h2 id="attachments-heading">Załączniki PDF</h2>
        <span class="count-badge"><?= count($attachments) ?></span>
    </div>

    <?php if ($attachments === []): ?>
        <p class="empty-state">Brak załączników PDF.</p>
    <?php endif; ?>

    <?php if ($attachments !== []): ?>
        <div class="attachment-list">
            <?php foreach ($attachments as $index => $attachment): ?>
                <?php
                    $attachmentTitle = $attachment['title'] ?? basename($attachment['file']);
                    $isSelected = (string) $index === (string) $selectedAttachmentIndex;
                ?>
                <form
                    class="attachment-row attachment-read-form<?= $isSelected ? ' selected' : '' ?>"
                    method="get"
                    data-loading-label="Odczytuję..."
                >
                    <input type="hidden" name="ticket" value="<?= htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="title" value="<?= htmlspecialchars($ticketTitle, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="attachment" value="<?= $index ?>">
                    <input type="hidden" name="access_token" value="<?= htmlspecialchars($accessToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="attachment-index"><?= $index + 1 ?></div>
                    <div class="attachment-title">
                        <?= htmlspecialchars($attachmentTitle, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <button class="button secondary" type="submit">Odczytaj</button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
