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

$vehicleValueAmount = static function (string $value): ?float {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (!preg_match('/[-+]?\d(?:[\d\s.,\x{00A0}]*\d)?/u', $value, $matches)) {
        return null;
    }

    $amount = preg_replace('/[\s\x{00A0}]+/u', '', $matches[0]);

    if (!is_string($amount) || $amount === '') {
        return null;
    }

    $commaPosition = strrpos($amount, ',');
    $dotPosition = strrpos($amount, '.');
    $decimalSeparator = null;

    if ($commaPosition !== false && $dotPosition !== false) {
        $decimalSeparator = $commaPosition > $dotPosition ? ',' : '.';
    } elseif ($commaPosition !== false && strlen($amount) - $commaPosition <= 3) {
        $decimalSeparator = ',';
    } elseif ($dotPosition !== false && strlen($amount) - $dotPosition <= 3) {
        $decimalSeparator = '.';
    }

    if ($decimalSeparator !== null) {
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
        $amount = str_replace($thousandsSeparator, '', $amount);
        $amount = str_replace($decimalSeparator, '.', $amount);
    } else {
        $amount = str_replace([',', '.'], '', $amount);
    }

    if (!is_numeric($amount)) {
        return null;
    }

    return (float) $amount;
};

$formattedVehicleValue = static function (float $value, string $sourceValue): string {
    $value = round($value, 2);
    $formatted = number_format($value, ((int) round($value * 100)) % 100 === 0 ? 0 : 2, ',', ' ');

    return $formatted . (preg_match('/(?:PLN|zł)/iu', $sourceValue) ? ' PLN' : '');
};

$vehicleGrossValueFromNet = static function (?string $value) use ($vehicleValueAmount, $formattedVehicleValue): string {
    $value = trim((string) $value);
    $amount = $vehicleValueAmount($value);

    if ($amount === null) {
        return '';
    }

    return $formattedVehicleValue($amount * 1.23, $value);
};

$vehicleNetValueFromGross = static function (?string $value) use ($vehicleValueAmount, $formattedVehicleValue): string {
    $value = trim((string) $value);
    $amount = $vehicleValueAmount($value);

    if ($amount === null) {
        return '';
    }

    return $formattedVehicleValue($amount / 1.23, $value);
};
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
                                $descriptionId = 'policy-description-' . $key;
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
                                <?php if ($key === 'wartosc_pojazdu_brutto'): ?>
                                    <div
                                        id="<?= htmlspecialchars($descriptionId, ENT_QUOTES, 'UTF-8') ?>"
                                        class="field-description policy-gross-net-value"
                                        data-policy-gross-net-value
                                    >/ 1,23 = <span><?= htmlspecialchars($vehicleNetValueFromGross($row['value'] ?? null), ENT_QUOTES, 'UTF-8') ?></span></div>
                                <?php elseif ($key === 'wartosc_pojazdu_netto'): ?>
                                    <div
                                        id="<?= htmlspecialchars($descriptionId, ENT_QUOTES, 'UTF-8') ?>"
                                        class="field-description policy-net-gross-value"
                                        data-policy-net-gross-value
                                    >* 1,23 = <span><?= htmlspecialchars($vehicleGrossValueFromNet($row['value'] ?? null), ENT_QUOTES, 'UTF-8') ?></span></div>
                                <?php endif; ?>
                                <div class="policy-input-control">
                                    <input
                                        id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
                                        class="policy-input"
                                        type="text"
                                        name="policy_data[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                                        value="<?= htmlspecialchars((string) ($row['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        <?= in_array($key, ['wartosc_pojazdu_brutto', 'wartosc_pojazdu_netto'], true) ? 'aria-describedby="' . htmlspecialchars($descriptionId, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                                        <?= $locked ? 'readonly' : '' ?>
                                    >
                                    <div class="policy-input-actions">
                                        <button
                                            class="policy-input-action policy-restore-ai-value"
                                            type="button"
                                            data-policy-ai-value="<?= htmlspecialchars((string) ($row['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            title="Przywróć wartość odczytaną przez AI"
                                            aria-label="Przywróć wartość odczytaną przez AI dla pola <?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?>"
                                        >AI</button>
                                        <button
                                            class="policy-input-action policy-clear-value"
                                            type="button"
                                            title="Wyczyść pole"
                                            aria-label="Wyczyść pole <?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?>"
                                        >&times;</button>
                                    </div>
                                </div>
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
