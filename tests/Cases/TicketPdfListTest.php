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

test('ticket without attachments returns 404', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123', 'has_attachment' => false]]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
    ]);
    $response = signedEntryRequest(app($fake, tempDir()), '123');
    $payload = errorBody($response);

    assertSameValue(404, $response['status']);
    assertSameValue('ticket_has_no_attachment', $payload['error']['code']);
});


test('ticket PDF list renders table and does not download files', function (): void {
    $downloadCount = 0;
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'title' => 'ZAMOWFILM Damian Nojek |TM3|RN127614471',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/readme.txt', 'title' => 'readme.txt', 'type' => 'text/plain'],
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf'],
                    ['file' => '/files/policy.bin', 'title' => 'policy.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/scan.pdf' => function () use (&$downloadCount): array {
            $downloadCount++;
            return pdfResponse();
        },
        '/files/policy.bin' => function () use (&$downloadCount): array {
            $downloadCount++;
            return pdfResponse();
        },
    ]);

    $response = signedEntryRequest(app($fake, tempDir()), '123');

    assertSameValue(200, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], '<h1 class="ticket-title">ZAMOWFILM Damian Nojek |TM3|RN127614471</h1>'));
    assertTrueValue(str_contains($response['body'], '<p class="ticket-debug">Ticket #123</p>'));
    assertTrueValue(str_contains($response['body'], 'name="title" value="ZAMOWFILM Damian Nojek |TM3|RN127614471"'));
    assertTrueValue(str_contains($response['body'], 'scan.pdf'));
    assertTrueValue(str_contains($response['body'], 'policy.pdf'));
    assertTrueValue(str_contains($response['body'], 'class="attachment-row attachment-read-form'));
    assertTrueValue(str_contains($response['body'], 'data-loading-label="Odczytuję..."'));
    assertTrueValue(str_contains($response['body'], 'name="refresh_attachments" value="1"'));
    assertTrueValue(str_contains($response['body'], 'data-loading-label="Odświeżam..."'));
    assertTrueValue(str_contains($response['body'], '>Odśwież<'));
    assertTrueValue(str_contains($response['body'], 'id="processing-message"'));
    assertTrueValue(str_contains($response['body'], 'Trwa odczyt danych z polisy.'));
    assertTrueValue(str_contains(file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.js'), 'button.textContent = loadingLabel;'));
    assertTrueValue(str_contains(file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.js'), 'attachmentActionButtons.forEach'));
    assertTrueValue(!str_contains($response['body'], 'readme.txt'));
    assertSameValue(0, $downloadCount);
});


test('ticket PDF list uses cached Daktela attachment list for one day', function (): void {
    $dir = tempDir();
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
    ]);
    $app = app($fake, $dir);

    $first = signedEntryRequest($app, '123');
    $second = $app->handle('123', null, daktelaAccessToken('123'));
    $requestPaths = array_map(
        static fn (array $request): string => parse_url($request['url'], PHP_URL_PATH) ?: '',
        $fake->requests
    );

    assertSameValue(200, $first['status']);
    assertSameValue(200, $second['status']);
    assertSameValue(1, count(array_filter($requestPaths, static fn (string $path): bool => $path === '/api/v6/tickets/123')));
    assertSameValue(1, count(array_filter($requestPaths, static fn (string $path): bool => $path === '/api/v6/tickets/123/activities')));
    assertTrueValue(str_contains($second['body'], 'scan.pdf'));
});


test('ticket PDF list refresh button bypasses cached Daktela attachment list', function (): void {
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
    $cached = $app->handle('123', null, daktelaAccessToken('123'));
    $refreshed = $app->handle('123', null, daktelaAccessToken('123'), forceAttachmentRefresh: true);

    assertSameValue(200, $first['status']);
    assertSameValue(200, $cached['status']);
    assertSameValue(200, $refreshed['status']);
    assertSameValue(2, $ticketCalls);
    assertTrueValue(str_contains($cached['body'], 'scan-1.pdf'));
    assertTrueValue(str_contains($refreshed['body'], 'scan-2.pdf'));
});


test('ticket PDF list includes PDFs from ticket activities attachments', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => false,
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'activity-1',
                        'attachments' => [
                            ['file' => '/files/readme.txt', 'title' => 'readme.txt', 'type' => 'text/plain'],
                            ['file' => '/files/activity-first.pdf', 'title' => 'activity-first.pdf'],
                        ],
                    ],
                    [
                        'name' => 'activity-2',
                        'attachments' => [
                            ['file' => '/files/activity-second.bin', 'title' => 'activity-second.pdf', 'type' => 'application/pdf'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $response = signedEntryRequest(app($fake, tempDir()), '123');
    $requestPaths = array_map(
        static fn (array $request): string => parse_url($request['url'], PHP_URL_PATH) ?: '',
        $fake->requests
    );

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], 'activity-first.pdf'));
    assertTrueValue(str_contains($response['body'], 'activity-second.pdf'));
    assertTrueValue(!str_contains($response['body'], 'readme.txt'));
    assertTrueValue(in_array('/api/v6/tickets/123/activities', $requestPaths, true));
    assertTrueValue(!in_array('/api/v6/activities', $requestPaths, true));
});


test('ticket PDF list includes activity attachment with numeric file id', function (): void {
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
                                'file' => 2029,
                                'activity' => null,
                                'inline' => 0,
                                'cid' => '',
                                'title' => 'przykladowa_polisa_ubezpieczenia_samochodu.pdf',
                                'type' => 'application/pdf',
                                'size' => 49800,
                                'time' => '2026-06-23 11:21:48',
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $response = signedEntryRequest(app($fake, tempDir()), '15242');

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], 'przykladowa_polisa_ubezpieczenia_samochodu.pdf'));
});


test('ticket PDF list includes PDFs from nested activity item attachments', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => false,
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'activity-1',
                        'item' => [
                            'name' => 'email-1',
                            'attachments' => [
                                ['file' => '/files/item-readme.txt', 'title' => 'item-readme.txt', 'type' => 'text/plain'],
                                ['file' => 2030, 'title' => 'nested-policy.pdf', 'type' => 'application/pdf'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $response = signedEntryRequest(app($fake, tempDir()), '123');

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], 'nested-policy.pdf'));
    assertTrueValue(!str_contains($response['body'], 'item-readme.txt'));
});
