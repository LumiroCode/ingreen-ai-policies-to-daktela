<?php

declare(strict_types=1);

/**
 * @var \Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData|null $extractedData
 * @var string $accessToken
 * @var string|null $selectedAttachmentIndex
 * @var array<string,bool> $selectedLockedFields
 * @var string $ticketId
 * @var string $ticketTitle
 * @var array<string,string> $ticketPolicyValues
 * @var list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}> $attachments
 */

$policyRows = [];
$policyGroups = [];

if ($extractedData instanceof \Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData) {
    foreach (\Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData::FIELDS as $field) {
        $policyRows[$field] = [
            'label' => \Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData::LABELS[$field],
            'value' => $extractedData->field($field),
        ];
    }

    $vehicleRows = array_intersect_key(
        $policyRows,
        array_fill_keys(\Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData::VEHICLE_FIELDS, true)
    );
    $insuranceRows = array_intersect_key(
        $policyRows,
        array_fill_keys(\Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData::POLICY_FIELDS, true)
    );

    $policyGroups = [
        [
            'label' => 'Dane pojazdu',
            'rows' => $vehicleRows,
        ],
        [
            'label' => 'Dane polisy',
            'rows' => $insuranceRows,
        ],
    ];
}

$allLocked = $policyRows !== [] && count(array_intersect_key($selectedLockedFields, $policyRows)) === count($policyRows);
$selectedAttachment = $selectedAttachmentIndex !== null && ctype_digit($selectedAttachmentIndex)
    ? ($attachments[(int) $selectedAttachmentIndex] ?? null)
    : null;
$selectedAttachmentTitle = is_array($selectedAttachment)
    ? (string) ($selectedAttachment['title'] ?? basename($selectedAttachment['file']))
    : null;
?>

<?php if ($policyRows !== []): ?>
<section class="panel review-panel" aria-labelledby="review-heading">
    <div class="section-heading">
        <button
            class="panel-toggle"
            type="button"
            aria-expanded="true"
            aria-controls="review-panel-content"
        >
            <span class="panel-chevron" aria-hidden="true"></span>
            <span class="review-title">
                <span id="review-heading" class="section-heading-title" role="heading" aria-level="2">Dane polisy</span>
                <?php if ($selectedAttachmentTitle !== null && trim($selectedAttachmentTitle) !== ''): ?>
                    <span><?= htmlspecialchars($selectedAttachmentTitle, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </span>
        </button>
        <span class="count-badge"><?= count($policyRows) ?></span>
    </div>

    <form id="review-panel-content" class="policy-review-form panel-content" method="get" novalidate>
        <input type="hidden" name="ticket" value="<?= htmlspecialchars($ticketId, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="title" value="<?= htmlspecialchars($ticketTitle, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="attachment" value="<?= htmlspecialchars((string) $selectedAttachmentIndex, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="access_token" value="<?= htmlspecialchars($accessToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="policy-fields">
            <label class="lock-control lock-control-all" for="policy-lock-all">
                <input
                    id="policy-lock-all"
                    class="policy-review-lock-all"
                    type="checkbox"
                    <?= $allLocked ? 'checked' : '' ?>
                >
                <span>zachowaj wszystkie</span>
            </label>

            <?php foreach ($policyGroups as $groupIndex => $group): ?>
                <fieldset class="policy-field-group">
                    <?php
                        $groupRows = is_array($group['rows']) ? $group['rows'] : [];
                        $groupLocked = $groupRows !== [] && count(array_intersect_key($selectedLockedFields, $groupRows)) === count($groupRows);
                        $groupLockId = 'policy-lock-group-' . $groupIndex;
                    ?>
                    <legend>
                        <span><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <label class="lock-control lock-control-group" for="<?= htmlspecialchars($groupLockId, ENT_QUOTES, 'UTF-8') ?>">
                            <input
                                id="<?= htmlspecialchars($groupLockId, ENT_QUOTES, 'UTF-8') ?>"
                                class="policy-review-lock-group"
                                type="checkbox"
                                <?= $groupLocked ? 'checked' : '' ?>
                            >
                            <span>zachowaj grupę</span>
                        </label>
                    </legend>

                    <div class="policy-field-group-fields">
                        <?php foreach ($groupRows as $key => $row): ?>
                            <?php
                                $locked = $selectedLockedFields[$key] ?? false;
                                $fieldId = 'policy-data-' . $key;
                                $lockId = 'policy-lock-' . $key;
                                $ticketValue = $ticketPolicyValues[$key] ?? null;
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
                                        <span>zachowaj</span>
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
                                <?php if ($ticketValue !== null): ?>
                                    <div class="policy-system-value">
                                        <span class="policy-system-value-text">
                                            W systemie:
                                            <span><?= htmlspecialchars($ticketValue, ENT_QUOTES, 'UTF-8') ?></span>
                                        </span>
                                        <button
                                            class="button secondary policy-apply-system-value"
                                            type="button"
                                            data-policy-apply-value="<?= htmlspecialchars($ticketValue, ENT_QUOTES, 'UTF-8') ?>"
                                        >Użyj</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
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
                title="<?= $allLocked ? '' : 'Oznacz wszystkie pola jako do zachowania, aby móc zapisać dane polisy.' ?>"
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
