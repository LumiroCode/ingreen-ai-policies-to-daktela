<?php

declare(strict_types=1);

/**
 * @var list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}> $attachments
 * @var string $accessToken
 * @var string|null $selectedAttachmentIndex
 * @var string $ticketId
 * @var string $ticketTitle
 * @var \Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData|null $extractedData
 */

$attachmentsCollapsed = $extractedData instanceof \Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
?>

<section class="panel attachment-panel<?= $attachmentsCollapsed ? ' collapsed' : '' ?>" aria-labelledby="attachments-heading">
    <div class="section-heading">
        <button
            class="panel-toggle"
            type="button"
            aria-expanded="<?= $attachmentsCollapsed ? 'false' : 'true' ?>"
            aria-controls="attachments-panel-content"
        >
            <span class="panel-chevron" aria-hidden="true"></span>
            <span class="section-title">
                <span id="attachments-heading" class="section-heading-title" role="heading" aria-level="2">Załączniki PDF</span>
                <span class="count-badge"><?= count($attachments) ?></span>
            </span>
        </button>
        <form class="attachment-refresh-form" method="get" data-loading-label="Odświeżam..." data-show-processing-message="false">
            <input type="hidden" name="ticket" value="<?= htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="title" value="<?= htmlspecialchars($ticketTitle, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="access_token" value="<?= htmlspecialchars($accessToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="refresh_attachments" value="1">
            <button class="button primary" type="submit">Odśwież</button>
        </form>
    </div>

    <div id="attachments-panel-content" class="panel-content"<?= $attachmentsCollapsed ? ' hidden' : '' ?>>
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
    </div>
</section>
