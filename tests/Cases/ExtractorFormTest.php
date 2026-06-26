<?php

declare(strict_types=1);

require_once __DIR__ . '/../Utils/Runner.php';
require_once __DIR__ . '/../Utils/Assertions.php';
require_once __DIR__ . '/../Utils/Helpers.php';
require_once __DIR__ . '/../Fakes/FakeDaktela.php';
require_once __DIR__ . '/../Fakes/NullLogger.php';
require_once __DIR__ . '/../Fakes/FakePolicyDataExtractor.php';
require_once __DIR__ . '/../Fakes/FakeClaudeMessagesClient.php';

use Ingreen\DaktelaPolicy\WebhookApp;
use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaModule;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataCache;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataParser;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;
use Ingreen\DaktelaPolicy\Support\AppException;

test('policy confirmation form shows ticket custom field value without replacing LLM value', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'title' => 'Policy ticket',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
                'customFields' => [
                    'marka' => ['SYSTEM & <Tesla>'],
                    'model' => [],
                    'wartosc_pojazdu_brutto' => [''],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $extractor = new FakePolicyDataExtractor(\Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData::fromFields([
        'marka' => 'LLM Tesla',
        'model' => 'Model 3',
        'wartosc_pojazdu_brutto' => '204000 PLN',
    ], '{"marka":"LLM Tesla"}'));
    $app = app($fake, $dir, extractor: $extractor);

    $download = signedEntryRequest($app, '123', '0');

    assertSameValue(200, $download['status']);
    assertTrueValue(str_contains($download['body'], 'value="LLM Tesla"'));
    assertTrueValue(str_contains($download['body'], 'W systemie:'));
    assertTrueValue(str_contains($download['body'], 'SYSTEM &amp; &lt;Tesla&gt;'));
    assertTrueValue(str_contains($download['body'], 'data-policy-apply-value="SYSTEM &amp; &lt;Tesla&gt;"'));
    assertTrueValue(str_contains($download['body'], 'class="button secondary policy-apply-system-value"'));
    assertSameValue(
        count(\Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData::FIELDS),
        substr_count($download['body'], 'class="policy-input-action policy-restore-ai-value"')
    );
    assertSameValue(
        count(\Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData::FIELDS),
        substr_count($download['body'], 'class="policy-input-action policy-clear-value"')
    );
    assertTrueValue(str_contains($download['body'], 'data-policy-ai-value="LLM Tesla"'));
    assertTrueValue(str_contains($download['body'], '>AI</button>'));
    assertTrueValue(str_contains($download['body'], '>&times;</button>'));
    assertTrueValue(str_contains($download['body'], 'name="ticket" value="123"'));
    assertTrueValue(str_contains($download['body'], 'name="title" value="Policy ticket"'));
    assertTrueValue(str_contains($download['body'], 'name="attachment" value="0"'));
    assertTrueValue(str_contains($download['body'], 'name="access_token"'));
    assertTrueValue(!str_contains($download['body'], 'data-policy-apply-value="Model 3"'));
    assertTrueValue(!str_contains($download['body'], 'data-policy-apply-value="204000 PLN"'));
});


test('confirmed policy data is saved to Daktela ticket before confirmed cache', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
                'customFields' => [],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/tickets/123.json' => jsonResponse(['result' => ['name' => '123']]),
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $app = app($fake, $dir, writeTicketPolicyData: true);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $confirmed = $app->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'yes',
        policyData: [
            'stan_pojazdu' => 'Nowy',
            'marka' => 'Manualna marka',
            'model' => '',
            'nr_polisy' => 'POL-123',
        ],
        policyLocked: \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm::allLockedFields()
    );

    $putRequest = null;
    foreach ($fake->requests as $request) {
        if ($request['method'] === 'PUT') {
            $putRequest = $request;
            break;
        }
    }

    if ($putRequest === null) {
        throw new RuntimeException('Expected Daktela ticket update request.');
    }

    parse_str((string) $putRequest['body'], $body);
    $cache = new PolicyDataCache($dir . '/var');
    $attachment = ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'];

    assertSameValue(200, $extracted['status']);
    assertSameValue(200, $confirmed['status']);
    assertTrueValue(str_contains($confirmed['body'], 'Zaakceptowane wartości zostały zapisane do ticketa w Daktela.'));
    assertSameValue('https://daktela.example/api/v6/tickets/123.json', $putRequest['url']);
    assertSameValue('Manualna marka', $body['customFields']['marka']);
    assertSameValue('POL-123', $body['customFields']['nr_polisy']);
    assertArrayMissingKey('model', $body['customFields']);
    assertSameValue('Manualna marka', $cache->confirmed('123', $attachment)?->field('marka'));
    assertSameValue(null, $cache->pending('123', $attachment));
});


test('Daktela ticket update failure preserves confirmation form and does not save confirmed cache', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
                'customFields' => [],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/tickets/123.json' => jsonResponse(['error' => [['message' => 'failed']]], 500),
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $app = app($fake, $dir, writeTicketPolicyData: true);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $failed = $app->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'yes',
        policyData: [
            'marka' => 'Manualna marka',
            'nr_polisy' => 'POL-123',
        ],
        policyLocked: \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm::allLockedFields()
    );

    $cache = new PolicyDataCache($dir . '/var');
    $attachment = ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'];

    assertSameValue(200, $extracted['status']);
    assertSameValue(502, $failed['status']);
    assertTrueValue(str_contains($failed['body'], 'Nie udało się zapisać danych polisy do ticketa w Daktela.'));
    assertTrueValue(str_contains($failed['body'], 'value="Manualna marka"'));
    assertTrueValue(str_contains($failed['body'], 'value="POL-123"'));
    assertSameValue(null, $cache->confirmed('123', $attachment));
    assertSameValue('Skoda', $cache->pending('123', $attachment)?->field('marka'));
});


test('confirmed policy data updates matching CRM record using form registration and VIN', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
                'customFields' => [
                    'nr_rejestracyjny' => 'STALE-REG',
                    'vin' => 'STALE-VIN',
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/tickets/123.json' => jsonResponse([
            'result' => [
                'name' => '123',
                'user' => ['name' => 'agent_1'],
                'contact' => ['name' => 'contact_1'],
            ],
        ]),
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_form_match',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'FORM-REG',
                            'vin' => 'FORM-VIN',
                        ],
                    ],
                ],
            ],
        ]),
        '/api/v6/crmRecords/record_form_match.json' => jsonResponse(['result' => ['name' => 'record_form_match']]),
        '/api/v6/crmRecords.json' => jsonResponse(['result' => ['name' => 'record_vehicle_created']]),
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $app = app($fake, $dir, writeConfirmedPolicyData: true);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $confirmed = $app->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'yes',
        policyData: [
            'nr_rejestracyjny' => 'FORM-REG',
            'vin' => 'FORM-VIN',
            'marka' => 'Manualna marka',
            'model' => 'Manualny model',
            'nr_polisy' => 'POL-123',
        ],
        policyLocked: \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm::allLockedFields()
    );

    $crmUpdateRequest = null;
    foreach ($fake->requests as $request) {
        if ($request['method'] === 'PUT' && str_contains($request['url'], '/api/v6/crmRecords/record_form_match.json')) {
            $crmUpdateRequest = $request;
            break;
        }
    }

    if ($crmUpdateRequest === null) {
        throw new RuntimeException('Expected Daktela policy CRM update request.');
    }

    parse_str((string) $crmUpdateRequest['body'], $body);
    $cache = new PolicyDataCache($dir . '/var');
    $attachment = ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'];

    assertSameValue(200, $extracted['status']);
    assertSameValue(200, $confirmed['status']);
    assertTrueValue(str_contains($confirmed['body'], 'Zaakceptowane wartości zostały zapisane do ticketa oraz rekordów CRM polisy i pojazdu w Daktela.'));
    assertSameValue('FORM-REG', $body['customFields']['nr_rejestracyjny']);
    assertSameValue('FORM-VIN', $body['customFields']['vin']);
    assertSameValue('Manualna marka', $body['customFields']['marka']);
    assertSameValue('POL-123', $body['customFields']['nr_polisy']);
    assertSameValue('FORM-REG', $cache->confirmed('123', $attachment)?->field('nr_rejestracyjny'));
});


test('duplicate policy CRM records abort confirmation and do not save confirmed cache', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
                'customFields' => [],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/tickets/123.json' => jsonResponse(['result' => ['name' => '123']]),
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_registration',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'FORM-REG',
                            'vin' => 'OTHER',
                        ],
                    ],
                    [
                        'name' => 'record_vin',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'OTHER',
                            'vin' => 'FORM-VIN',
                        ],
                    ],
                ],
            ],
        ]),
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $app = app($fake, $dir, writeConfirmedPolicyData: true);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $failed = $app->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'yes',
        policyData: [
            'nr_rejestracyjny' => 'FORM-REG',
            'vin' => 'FORM-VIN',
            'marka' => 'Manualna marka',
            'nr_polisy' => 'POL-123',
        ],
        policyLocked: \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm::allLockedFields()
    );

    $crmWriteRequests = array_values(array_filter(
        $fake->requests,
        static fn (array $request): bool => $request['method'] !== 'GET'
            && str_contains($request['url'], '/api/v6/crmRecords')
    ));
    $cache = new PolicyDataCache($dir . '/var');
    $attachment = ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'];

    assertSameValue(200, $extracted['status']);
    assertSameValue(409, $failed['status']);
    assertTrueValue(str_contains($failed['body'], 'Dane dla rekordu CRM polisy nie zostały zapisane.'));
    assertTrueValue(str_contains($failed['body'], 'Znaleziono więcej niż jeden pasujący rekord CRM polisy'));
    assertTrueValue(str_contains($failed['body'], 'value="Manualna marka"'));
    assertTrueValue(str_contains($failed['body'], 'value="POL-123"'));
    assertSameValue(0, count($crmWriteRequests));
    assertSameValue(null, $cache->confirmed('123', $attachment));
    assertSameValue('Skoda', $cache->pending('123', $attachment)?->field('marka'));
});


test('duplicate vehicle CRM records abort confirmation and do not save confirmed cache', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
                'customFields' => [],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/tickets/123.json' => jsonResponse(['result' => ['name' => '123']]),
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_policy',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'FORM-REG',
                            'vin' => 'FORM-VIN',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_registration',
                        'title' => 'Pojazdy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'FORM-REG',
                            'vin' => 'OTHER',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_vin',
                        'title' => 'Pojazdy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'OTHER',
                            'vin' => 'FORM-VIN',
                        ],
                    ],
                ],
            ],
        ]),
        '/api/v6/crmRecords/record_policy.json' => jsonResponse(['result' => ['name' => 'record_policy']]),
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $app = app($fake, $dir, writeConfirmedPolicyData: true);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $failed = $app->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'yes',
        policyData: [
            'nr_rejestracyjny' => 'FORM-REG',
            'vin' => 'FORM-VIN',
            'marka' => 'Manualna marka',
            'nr_polisy' => 'POL-123',
        ],
        policyLocked: \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm::allLockedFields()
    );

    $vehicleWriteRequests = array_values(array_filter(
        $fake->requests,
        static fn (array $request): bool => $request['method'] !== 'GET'
            && str_contains($request['url'], '/api/v6/crmRecords/record_vehicle')
    ));
    $cache = new PolicyDataCache($dir . '/var');
    $attachment = ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'];

    assertSameValue(200, $extracted['status']);
    assertSameValue(409, $failed['status']);
    assertTrueValue(str_contains($failed['body'], 'Dane dla rekordu CRM pojazdu nie zostały zapisane.'));
    assertTrueValue(str_contains($failed['body'], 'Znaleziono więcej niż jeden pasujący rekord CRM pojazdu'));
    assertTrueValue(str_contains($failed['body'], 'value="Manualna marka"'));
    assertTrueValue(str_contains($failed['body'], 'value="POL-123"'));
    assertSameValue(0, count($vehicleWriteRequests));
    assertSameValue(null, $cache->confirmed('123', $attachment));
    assertSameValue('Skoda', $cache->pending('123', $attachment)?->field('marka'));
});


test('confirmed policy data loaded from cache is locked by default', function (): void {
    $dir = tempDir();
    $downloadCount = 0;
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/scan.pdf' => function () use (&$downloadCount): array {
            $downloadCount++;
            return pdfResponse();
        },
    ]);
    $app = app($fake, $dir);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $confirmed = $app->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'yes',
        policyData: [
            'marka' => 'Skoda',
            'model' => 'Octavia',
            'wartosc_pojazdu_brutto' => '50 000 CZK',
        ],
        policyLocked: \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm::allLockedFields()
    );
    $cached = $app->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(200, $extracted['status']);
    assertSameValue(200, $confirmed['status']);
    assertSameValue(200, $cached['status']);
    assertSameValue(1, $downloadCount, 'Expected cached policy read not to download the attachment again.');
    assertTrueValue(
        str_contains($cached['body'], 'Polisa została już kiedyś odczytana i zatwierdzona - wczytano zapisane dane.'),
        'Expected policy data to be loaded from cache.'
    );
    assertPolicyFieldLocked($cached['body'], 'marka');
    assertPolicyFieldLocked($cached['body'], 'model');
    assertPolicyFieldLocked($cached['body'], 'wartosc_pojazdu_brutto');
    assertTrueValue(
        preg_match('/class="policy-review-lock-all"[^>]*\bchecked\b/s', $cached['body']) === 1,
        'Expected master policy lock checkbox to be checked for cached locked policy data.'
    );
    assertTrueValue(str_contains($cached['body'], 'name="confirmation"'));
    assertTrueValue(str_contains($cached['body'], 'value="yes"'));
    assertTrueValue(
        preg_match('/name="confirmation"[^>]*value="no"[^>]*\bdisabled\b/s', $cached['body']) === 1,
        'Expected retry button to be disabled for cached locked policy data.'
    );
});


test('pending policy data loaded from cache on read click', function (): void {
    $dir = tempDir();
    $downloadCount = 0;
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/scan.pdf' => function () use (&$downloadCount): array {
            $downloadCount++;
            return pdfResponse();
        },
    ]);
    $extractor = new FakePolicyDataExtractor(new \Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData('Toyota', 'Yaris', '10 000 EUR', '{"car_make":"Toyota","car_model":"Yaris","value":"10 000 EUR"}'));
    $app = app($fake, $dir, extractor: $extractor);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $cached = $app->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(200, $extracted['status']);
    assertSameValue(200, $cached['status']);
    assertSameValue(1, $downloadCount, 'Expected pending policy read not to download the attachment again.');
    assertSameValue(1, count($extractor->paths), 'Expected pending policy read not to run extraction again.');
    assertTrueValue(
        str_contains($cached['body'], 'Wczytano dane z poprzedniego odczytu polisy przez AI.'),
        'Expected policy data to be loaded from pending cache.'
    );
    assertTrueValue(str_contains($cached['body'], 'value="Toyota"'));
    assertTrueValue(str_contains($cached['body'], 'value="Yaris"'));
    assertTrueValue(str_contains($cached['body'], 'value="10 000 EUR"'));
});


test('policy reread after incorrectness claim ignores pending cache', function (): void {
    $dir = tempDir();
    $downloadCount = 0;
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/scan.pdf' => function () use (&$downloadCount): array {
            $downloadCount++;
            return pdfResponse();
        },
    ]);
    $extractor = new FakePolicyDataExtractor();
    $app = app($fake, $dir, extractor: $extractor);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $reread = $app->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'no',
        policyData: [
            'marka' => 'Skoda',
            'model' => 'Octavia',
            'wartosc_pojazdu_brutto' => '50 000 CZK',
        ],
        policyLocked: [
            'marka' => '1',
        ]
    );

    assertSameValue(200, $extracted['status']);
    assertSameValue(200, $reread['status']);
    assertSameValue(2, $downloadCount, 'Expected incorrectness claim to download the attachment again.');
    assertSameValue(2, count($extractor->paths), 'Expected incorrectness claim to run extraction again.');
    assertTrueValue(str_contains($reread['body'], 'Dane polisy zostały odczytane przez AI.'));
});


test('policy reread error preserves submitted policy form state', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/policy.pdf', 'title' => 'policy.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/policy.pdf' => pdfResponse(),
    ]);

    $response = app($fake, tempDir(), extractor: new FakePolicyDataExtractor(exception: new AppException(502, 'policy_extraction_parse_failed', 'Claude did not return valid policy extraction JSON.')))
        ->handle(
            '123',
            '0',
            daktelaAccessToken('123'),
            confirmation: 'no',
            policyData: [
                'marka' => 'Manualna marka',
                'model' => 'Manualny model',
                'wartosc_pojazdu_brutto' => '123 456 PLN',
            ],
            policyLocked: [
                'marka' => '1',
            ]
        );

    assertSameValue(502, $response['status']);
    assertTrueValue(str_contains($response['body'], 'Claude zwrócił odpowiedź w nieoczekiwanym formacie.'));
    assertTrueValue(str_contains($response['body'], 'class="attachment-row attachment-read-form selected"'));
    assertTrueValue(str_contains($response['body'], 'policy_pdf=1'));
    assertTrueValue(str_contains($response['body'], 'Dane polisy'));
    assertTrueValue(str_contains($response['body'], 'value="Manualna marka"'));
    assertTrueValue(str_contains($response['body'], 'value="Manualny model"'));
    assertTrueValue(str_contains($response['body'], 'value="123 456 PLN"'));
    assertPolicyFieldLocked($response['body'], 'marka');
});
