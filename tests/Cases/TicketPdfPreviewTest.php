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

test('daktela 401 maps to upstream auth error', function (): void {
    $fake = new FakeDaktela(['/api/v6/tickets/123' => jsonResponse([], 401)]);
    $response = signedEntryRequest(app($fake, tempDir()), '123');
    $payload = errorBody($response);

    assertSameValue(502, $response['status']);
    assertSameValue('daktela_auth_failed', $payload['error']['code']);
});


test('policy PDF preview is served from local policy storage', function (): void {
    $dir = tempDir();
    $downloads = 0;
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['id' => 'policy-123', 'file' => '/files/policy.pdf', 'title' => 'policy.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/policy.pdf' => function () use (&$downloads): array {
            $downloads++;
            return pdfResponse("%PDF-1.4\npreview");
        },
    ]);
    $app = app($fake, $dir);

    $preview = $app->handle('123', '0', daktelaAccessToken('123'), servePolicyPdf: true);
    $cachedPreview = $app->handle('123', '0', daktelaAccessToken('123'), servePolicyPdf: true);

    assertSameValue(200, $preview['status']);
    assertSameValue('application/pdf', $preview['headers']['Content-Type']);
    assertTrueValue(str_starts_with($preview['headers']['Content-Disposition'], 'inline;'));
    assertSameValue("%PDF-1.4\npreview", $preview['body']);
    assertSameValue("%PDF-1.4\npreview", $cachedPreview['body']);
    assertSameValue("%PDF-1.4\npreview", file_get_contents($dir . '/var/policies/policy-123.pdf'));
    assertSameValue(1, $downloads);
});


test('selected PDF attachment is stored only after clicking read', function (): void {
    $dir = tempDir();
    $downloads = [];
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/first.pdf', 'title' => 'first.pdf', 'type' => 'application/pdf'],
                    ['id' => 'policy-456', 'file' => '/files/second.pdf', 'title' => 'second.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/first.pdf' => function () use (&$downloads): array {
            $downloads[] = 'first';
            return pdfResponse("%PDF-1.4\nfirst");
        },
        '/files/second.pdf' => function () use (&$downloads): array {
            $downloads[] = 'second';
            return pdfResponse("%PDF-1.4\nsecond");
        },
    ]);
    $app = app($fake, $dir);

    $list = signedEntryRequest($app, '123');

    assertSameValue(200, $list['status']);
    assertSameValue([], $downloads);
    assertTrueValue(!str_contains($list['body'], 'policy_pdf=1'));
    assertTrueValue(!str_contains($list['body'], 'Otwórz w nowym oknie'));
    assertTrueValue(str_contains($list['body'], 'Brak pliku PDF do podglądu.'));
    assertTrueValue(!is_file($dir . '/var/policies/policy-456.pdf'));

    $download = $app->handle('123', '1', daktelaAccessToken('123'));

    assertSameValue(200, $download['status']);
    assertSameValue('text/html; charset=UTF-8', $download['headers']['Content-Type']);
    assertTrueValue(str_contains($download['body'], 'name="policy_data[marka]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[model]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[wartosc_pojazdu_brutto]"'));
    assertTrueValue(str_contains($download['body'], 'value="Skoda"'));
    assertTrueValue(str_contains($download['body'], 'value="Octavia"'));
    assertTrueValue(str_contains($download['body'], 'value="50 000 CZK"'));
    assertTrueValue(str_contains($download['body'], 'second.pdf'));
    assertTrueValue(str_contains($download['body'], 'src="?ticket=123&amp;attachment=1&amp;access_token='));
    assertTrueValue(str_contains($download['body'], 'href="https://daktela.example/files/second.pdf?download=0"'));
    assertTrueValue(str_contains($download['body'], 'target="_blank"'));
    assertTrueValue(str_contains($download['body'], 'Otwórz w nowym oknie'));
    assertTrueValue(str_contains($download['body'], '&amp;policy_pdf=1"'));
    assertSameValue("%PDF-1.4\nsecond", file_get_contents($dir . '/var/policies/policy-456.pdf'));
    assertSameValue(['second'], $downloads);
});


test('selected PDF attachment storage error renders message under table', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/not-pdf.pdf', 'title' => 'not-pdf.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/not-pdf.pdf' => ['status' => 500, 'headers' => ['Content-Type' => 'text/plain'], 'body' => 'download failed'],
    ]);

    $response = app($fake, tempDir())->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(502, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], 'not-pdf.pdf'));
    assertTrueValue(str_contains($response['body'], 'System źródłowy odmówił pobrania wybranego pliku polisy lub zwrócił błąd dla tego załącznika.'));
});


test('selected PDF attachment auth error renders dedicated message under table', function (): void {
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
        '/files/policy.pdf' => ['status' => 401, 'headers' => ['Content-Type' => 'text/plain'], 'body' => 'unauthorized'],
    ]);

    $response = app($fake, tempDir())->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(502, $response['status']);
    assertTrueValue(str_contains($response['body'], 'Daktela odrzuciła uwierzytelnienie API podczas pobierania pliku polisy.'));
});


test('selected PDF attachment upstream HTTP error renders dedicated message under table', function (): void {
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
        '/files/policy.pdf' => function (): array {
            throw new AppException(502, 'upstream_http_error', 'Daktela HTTP request failed.', ['error' => 'timeout']);
        },
    ]);

    $response = app($fake, tempDir())->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(502, $response['status']);
    assertTrueValue(str_contains($response['body'], 'Nie udało się połączyć z systemem źródłowym podczas pobierania pliku polisy.'));
});


test('selected PDF attachment extraction error renders message under table', function (): void {
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

    $response = app($fake, tempDir(), extractor: new FakePolicyDataExtractor(exception: new RuntimeException('Claude unavailable')))
        ->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(500, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], 'policy.pdf'));
    assertTrueValue(str_contains($response['body'], 'Wystąpił nieoczekiwany błąd podczas odczytu danych z polisy.'));
});


test('selected PDF attachment parse error preserves selected attachment and preview', function (): void {
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
        ->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(502, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], 'Claude zwrócił odpowiedź w nieoczekiwanym formacie.'));
    assertTrueValue(str_contains($response['body'], 'class="attachment-row attachment-read-form selected"'));
    assertTrueValue(str_contains($response['body'], 'Podgląd PDF'));
    assertTrueValue(str_contains($response['body'], 'policy_pdf=1'));
});


test('selected PDF attachment Claude extraction error renders Claude message under table', function (): void {
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

    $response = app($fake, tempDir(), extractor: new FakePolicyDataExtractor(exception: new AppException(502, 'claude_policy_extraction_failed', 'Claude policy extraction request failed.')))
        ->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(502, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], 'policy.pdf'));
    assertTrueValue(str_contains($response['body'], 'Nie udało się odczytać danych z polisy przez Claude.'));
});


test('selected PDF attachment Claude extraction error renders Anthropic error message under table', function (): void {
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
    $anthropicMessage = 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.';
    $detailsMessage = "Anthropic Bad Request Exception\n" . json_encode([
        'status' => 400,
        'body' => [
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => $anthropicMessage,
            ],
            'request_id' => 'req_011CcL5WpcWQ6WgdAo5D7RYD',
        ],
    ], JSON_THROW_ON_ERROR);

    $response = app($fake, tempDir(), extractor: new FakePolicyDataExtractor(exception: new AppException(502, 'claude_policy_extraction_failed', 'Claude policy extraction request failed.', [
        'message' => $detailsMessage,
    ])))->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(502, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], 'policy.pdf'));
    assertTrueValue(str_contains($response['body'], 'Your credit balance is too low to access the Anthropic API.'));
    assertTrueValue(!str_contains($response['body'], 'Nie udało się odczytać danych z polisy przez Claude.'));
});
