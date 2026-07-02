<?php

declare(strict_types=1);

require_once __DIR__ . '/../Utils/Runner.php';
require_once __DIR__ . '/../Utils/Assertions.php';

use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaNumericValueNormalizer;

test('Daktela outbound value normalizer strips spaces from registration numbers without changing letters', function (): void {
    $normalizer = new DaktelaNumericValueNormalizer();

    assertSameValue('wx12345', $normalizer->normalizeForField('nr_rejestracyjny', ' wx 12345 '));
    assertSameValue('AbC-12', $normalizer->normalizeForField('nr_rejestracyjny', "  AbC - 12  "));
    assertSameValue('tMb123', $normalizer->normalizeForField('vin', ' tMb 123 '));
});

test('Daktela outbound value normalizer strips spaces and units from numeric fields', function (): void {
    $normalizer = new DaktelaNumericValueNormalizer();

    assertSameValue('123456', $normalizer->normalizeForField('cena_pakietu', '123 456 PLN'));
    assertSameValue('123456.78', $normalizer->normalizeForField('cena_wznowienia', '123 456,78 PLN'));
    assertSameValue('1798', $normalizer->normalizeForField('pojemnosc_silnika', '1 798 cm3'));
    assertSameValue('12000', $normalizer->normalizeForField('przebieg', '12 000 km'));
});
