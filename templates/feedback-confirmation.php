<?php

declare(strict_types=1);

/**
 * @var \Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData|null $extractedData
 * @var string $accessToken
 * @var string|null $selectedAttachmentIndex
 * @var array<string,bool> $selectedLockedFields
 * @var string $ticketId
 * @var string $ticketTitle
 */

$policyRows = [];

if ($extractedData instanceof \Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData) {
    $policyRows = [
        'car_make' => ['label' => 'Marka', 'value' => $extractedData->carMake],
        'car_model' => ['label' => 'Model', 'value' => $extractedData->carModel],
        'value' => ['label' => 'Wartość', 'value' => $extractedData->value],
    ];
}

$allLocked = $policyRows !== [] && count(array_intersect_key($selectedLockedFields, $policyRows)) === count($policyRows);
?>

<?php if ($policyRows !== []): ?>
<section class="panel review-panel" aria-labelledby="review-heading">
    <div class="section-heading">
        <h2 id="review-heading">Dane polisy</h2>
        <span class="count-badge"><?= count($policyRows) ?></span>
    </div>

    <form class="policy-review-form" method="get" novalidate>
        <input type="hidden" name="ticket" value="<?= htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="title" value="<?= htmlspecialchars($ticketTitle, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="attachment" value="<?= htmlspecialchars((string) $selectedAttachmentIndex, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="access_token" value="<?= htmlspecialchars($accessToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="policy-fields">
            <?php foreach ($policyRows as $key => $row): ?>
                <?php
                    $locked = $selectedLockedFields[$key] ?? false;
                    $fieldId = 'policy-data-' . $key;
                    $lockId = 'policy-lock-' . $key;
                ?>
                <div class="policy-field<?= $locked ? ' locked' : '' ?>">
                    <div class="field-topline">
                        <label for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <label class="lock-control" for="<?= htmlspecialchars($lockId, ENT_QUOTES, 'UTF-8') ?>">
                            <input
                                id="<?= htmlspecialchars($lockId, ENT_QUOTES, 'UTF-8') ?>"
                                class="policy-review-lock"
                                type="checkbox"
                                name="policy_locked[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                                value="1"
                                <?= $locked ? 'checked' : '' ?>
                            >
                            <span>poprawne?</span>
                        </label>
                    </div>
                    <input
                        id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
                        class="policy-input"
                        type="text"
                        name="policy_data[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                        value="<?= htmlspecialchars((string) ($row['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        <?= $locked ? 'readonly' : '' ?>
                    >
                </div>
            <?php endforeach; ?>
        </div>

        <div class="policy-review-feedback status-message error" hidden></div>

        <div class="action-bar">
            <button
                class="button primary"
                type="submit"
                name="confirmation"
                value="yes"
                <?= $allLocked ? '' : 'disabled' ?>
            >Zapisz</button>
            <button
                class="button secondary"
                type="submit"
                name="confirmation"
                value="no"
                <?= $allLocked ? 'disabled' : '' ?>
            >Odczytaj ponownie</button>
        </div>
    </form>
</section>
<?php endif; ?>
