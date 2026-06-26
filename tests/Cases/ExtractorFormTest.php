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
    assertTrueValue(!str_contains($download['body'], 'data-policy-apply-value="Model 3"'));
    assertTrueValue(!str_contains($download['body'], 'data-policy-apply-value="204000 PLN"'));
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
