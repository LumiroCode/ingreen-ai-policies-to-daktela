<?php

declare(strict_types=1);

require_once __DIR__ . '/../Utils/Runner.php';
require_once __DIR__ . '/../Utils/Assertions.php';
require_once __DIR__ . '/../Utils/Helpers.php';
require_once __DIR__ . '/../Fakes/FakeDaktela.php';
require_once __DIR__ . '/../Fakes/NullLogger.php';
require_once __DIR__ . '/../Fakes/FakePolicyDataExtractor.php';
require_once __DIR__ . '/../Fakes/FakeClaudeMessagesClient.php';

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm;
use Ingreen\DaktelaPolicy\WebhookAccessGuard;

test('Utility tab signature matches helper formula with seconds', function (): void {
    $config = new AppConfig('https://daktela.example', 'api-token', null, tempDir() . '/var', tempDir() . '/cache');
    $guard = new WebhookAccessGuard($config, tabSignatureVerifier());

    assertSameValue('89666-30820-47545', $guard->makeUtilityTabSig('1782315045', '123'));
});


test('stale ticket PDF attachment cache is refreshed after one day', function (): void {
    $dir = tempDir();
    $ticketCalls = 0;
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => function () use (&$ticketCalls): array {
            $ticketCalls++;

            return jsonResponse([
                'result' => [
                    'name' => '123',
                    'has_attachment' => true,
                    'attachments' => [
                        ['file' => '/files/scan-' . $ticketCalls . '.pdf', 'title' => 'scan-' . $ticketCalls . '.pdf', 'type' => 'application/pdf'],
                    ],
                ],
            ]);
        },
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
    ]);
    $app = app($fake, $dir);

    $first = signedEntryRequest($app, '123');
    $cacheFiles = glob($dir . '/cache/ticket-attachments/*.json') ?: [];

    assertSameValue(200, $first['status']);
    assertSameValue(1, count($cacheFiles));

    touch($cacheFiles[0], time() - 86401);

    $second = $app->handle('123', null, daktelaAccessToken('123'));

    assertSameValue(200, $second['status']);
    assertSameValue(2, $ticketCalls);
    assertTrueValue(str_contains($second['body'], 'scan-2.pdf'));
});


test('cached extensionless Daktela download URLs are normalized before download', function (): void {
    $dir = tempDir();
    $cacheDir = $dir . '/cache/ticket-attachments';
    mkdir($cacheDir, 0775, true);
    file_put_contents($cacheDir . '/' . hash('sha256', '15242') . '.json', json_encode([
        'title' => '[TEST] Ticket',
        'attachments' => [
            [
                'file' => '/file/download?mapper=activitiesComment&name=2051&iconHash=polisa_odnowieniowa_17559119.pdf&download=1',
                'title' => 'polisa_odnowieniowa_17559119.pdf',
                'type' => 'application/pdf',
                'source' => 'activity.attachments',
                'id' => '2051',
                'previewUrl' => 'https://daktela.example/file/download?mapper=activitiesComment&name=2051&iconHash=polisa_odnowieniowa_17559119.pdf&download=0',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $fake = new FakeDaktela([
        '/file/download.php' => pdfResponse("%PDF-1.4\ncached"),
    ]);
    $app = app($fake, $dir);

    $response = $app->handle('15242', '0', daktelaAccessToken('15242'));
    $request = $fake->requests[0];

    assertSameValue(200, $response['status']);
    assertSameValue('https://daktela.example/file/download.php?mapper=activitiesComment&name=2051&iconHash=polisa_odnowieniowa_17559119.pdf&download=1', $request['url']);
    assertTrueValue(str_contains(
        $response['body'],
        'href="https://daktela.example/file/download.php?mapper=activitiesComment&amp;name=2051&amp;iconHash=polisa_odnowieniowa_17559119.pdf&amp;download=0"'
    ));
});


test('cached Daktela file model mapper URLs are normalized before download', function (): void {
    $dir = tempDir();
    $cacheDir = $dir . '/cache/ticket-attachments';
    mkdir($cacheDir, 0775, true);
    file_put_contents($cacheDir . '/' . hash('sha256', '14599') . '.json', json_encode([
        'title' => 'Michał Krzemiński | Tesla Model 3',
        'attachments' => [
            [
                'file' => '/file/download.php?mapper=activitiesEmailFiles&name=36251&iconHash=Polisa_912001340319+podpisana.pdf&download=1',
                'title' => 'Polisa_912001340319 podpisana.pdf',
                'type' => 'application/pdf',
                'source' => 'activity.item.attachments',
                'id' => '36251',
                'previewUrl' => 'https://daktela.example/file/download.php?mapper=activitiesEmailFiles&name=36251&iconHash=Polisa_912001340319+podpisana.pdf&download=0',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $fake = new FakeDaktela([
        '/file/download.php' => pdfResponse("%PDF-1.4\ncached"),
    ]);
    $app = app($fake, $dir);

    $response = $app->handle('14599', '0', daktelaAccessToken('14599'));
    $request = $fake->requests[0];

    assertSameValue(200, $response['status']);
    assertSameValue('https://daktela.example/file/download.php?mapper=activitiesEmail&name=36251&iconHash=Polisa_912001340319+podpisana.pdf&download=1', $request['url']);
    assertTrueValue(str_contains(
        $response['body'],
        'href="https://daktela.example/file/download.php?mapper=activitiesEmail&amp;name=36251&amp;iconHash=Polisa_912001340319+podpisana.pdf&amp;download=0"'
    ));
});


test('selected email activity attachment downloads through Daktela file mapper', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/api/v6/tickets/15242' => jsonResponse([
            'result' => [
                'name' => '15242',
                'has_attachment' => false,
            ],
        ]),
        '/api/v6/tickets/15242/activities' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'activity_6a3a502c8ea50690005376',
                        'attachments' => [
                            [
                                'file' => 35869,
                                'title' => 'Polisa_904001145228.pdf',
                                'type' => 'application/pdf',
                                '_sys' => ['model' => 'activitiesEmailFiles'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        '/file/download.php' => pdfResponse("%PDF-1.4\nmapped"),
    ]);
    $app = app($fake, $dir);

    $download = $app->handle('15242', '0', daktelaAccessToken('15242'));
    $request = $fake->requests[2];
    parse_str(parse_url($request['url'], PHP_URL_QUERY) ?: '', $query);

    assertSameValue(200, $download['status']);
    assertSameValue('https://daktela.example/file/download.php?mapper=activitiesEmail&name=35869&iconHash=Polisa_904001145228.pdf&download=1', $request['url']);
    assertSameValue('activitiesEmail', $query['mapper']);
    assertSameValue('35869', $query['name']);
    assertSameValue('Polisa_904001145228.pdf', $query['iconHash']);
    assertSameValue('1', $query['download']);
    assertTrueValue(str_contains(
        $download['body'],
        'src="?ticket=15242&amp;attachment=0&amp;access_token='
    ));
    assertTrueValue(str_contains(
        $download['body'],
        'href="https://daktela.example/file/download.php?mapper=activitiesEmail&amp;name=35869&amp;iconHash=Polisa_904001145228.pdf&amp;download=0"'
    ));
    assertTrueValue(str_contains(
        $download['body'],
        '&amp;policy_pdf=1"'
    ));
    assertSameValue("%PDF-1.4\nmapped", file_get_contents($dir . '/var/policies/35869.pdf'));
});


test('selected activity comment attachment downloads through activities comment mapper', function (): void {
    $dir = tempDir();
    $fake = new FakeDaktela([
        '/api/v6/tickets/15242' => jsonResponse([
            'result' => [
                'name' => '15242',
                'has_attachment' => false,
            ],
        ]),
        '/api/v6/tickets/15242/activities' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'activity_6a3a502c8ea50690005376',
                        'attachments' => [
                            [
                                'file' => 2023,
                                'title' => 'Faktura FV 9_4_2026.pdf',
                                'type' => 'application/pdf',
                                '_sys' => ['model' => 'activities\\Attachments'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        '/file/download.php' => pdfResponse("%PDF-1.4\ncomment"),
    ]);
    $app = app($fake, $dir);

    $download = $app->handle('15242', '0', daktelaAccessToken('15242'));
    $request = $fake->requests[2];

    assertSameValue(200, $download['status']);
    assertSameValue('https://daktela.example/file/download.php?mapper=activitiesComment&name=2023&iconHash=Faktura+FV+9_4_2026.pdf&download=1', $request['url']);
    assertSameValue("%PDF-1.4\ncomment", file_get_contents($dir . '/var/policies/2023.pdf'));
});


test('policy confirmation storage error preserves submitted policy form state', function (): void {
    $dir = tempDir();
    mkdir($dir . '/var', 0775, true);
    file_put_contents($dir . '/var/policy-data', 'not a directory');

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
    ]);

    $response = app($fake, $dir)->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'yes',
        policyData: [
            'stan_pojazdu' => 'Nowy',
            'marka' => 'Manualna marka',
            'model' => 'Manualny model',
            'wartosc_pojazdu_brutto' => '123 456 PLN',
        ],
        policyLocked: PolicyConfirmationForm::allLockedFields()
    );

    assertSameValue(500, $response['status']);
    assertTrueValue(str_contains($response['body'], 'Nie udało się zapisać potwierdzonych danych polisy.'));
    assertTrueValue(str_contains($response['body'], 'class="attachment-row attachment-read-form selected"'));
    assertTrueValue(str_contains($response['body'], 'policy_pdf=1'));
    assertTrueValue(str_contains($response['body'], 'value="Manualna marka"'));
    assertTrueValue(str_contains($response['body'], 'value="Manualny model"'));
    assertTrueValue(str_contains($response['body'], 'value="123 456 PLN"'));
    assertPolicyFieldLocked($response['body'], 'marka');
});


test('policy confirmation requires locks only for non-empty values', function (): void {
    $form = PolicyConfirmationForm::fromRequest(
        [
            'marka' => 'Manualna marka',
            'model' => '',
            'nr_polisy' => '   ',
        ],
        [
            'marka' => '1',
        ]
    );

    assertSameValue(null, $form->validationMessage('yes'));
    assertSameValue(
        'Nie można zgłosić niepoprawnych danych, gdy wszystkie niepuste pola są oznaczone jako poprawne.',
        $form->validationMessage('no')
    );
});


test('policy confirmation rejects unlocked non-empty values', function (): void {
    $form = PolicyConfirmationForm::fromRequest(
        [
            'marka' => 'Manualna marka',
            'model' => '',
        ],
        []
    );

    assertSameValue(
        'Aby potwierdzić poprawność danych, zaznacz wszystkie niepuste pola jako poprawne.',
        $form->validationMessage('yes')
    );
    assertSameValue(null, $form->validationMessage('no'));
});
