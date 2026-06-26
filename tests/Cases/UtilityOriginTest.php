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

test('configured utility origin rejects direct browser entry requests', function (): void {
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com')->handle('123', null);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});


test('configured utility origin allows Daktela referrer and sets frame policy', function (): void {
    $tab = daktelaTabParams('123');
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
    ]);

    $response = app($fake, tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders(), $tab['dt'], $tab['sig']);

    assertSameValue(200, $response['status']);
    assertSameValue('frame-ancestors https://ingreen.daktela.com', $response['headers']['Content-Security-Policy']);
    assertTrueValue(str_contains($response['body'], 'name="access_token"'));
});


test('configured utility origin rejects missing Utility tab signature', function (): void {
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders());
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});


test('configured utility origin allows Utility tab timestamp within skew', function (): void {
    $tab = daktelaTabParams('123', -2);
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
    ]);

    $response = app($fake, tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders(), $tab['dt'], $tab['sig']);

    assertSameValue(200, $response['status']);
});


test('configured utility origin rejects stale Utility tab timestamp', function (): void {
    $tab = daktelaTabParams('123', -6);
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders(), $tab['dt'], $tab['sig']);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
});


test('configured utility origin rejects wrong Utility tab signature', function (): void {
    $tab = daktelaTabParams('123');
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders(), $tab['dt'], '00000-00000-00000');
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
});


test('configured utility origin rejects Daktela referrer without iframe navigation headers', function (): void {
    $tab = daktelaTabParams('123');
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', [], $tab['dt'], $tab['sig']);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
});


test('configured utility origin allows case-insensitive iframe navigation headers', function (): void {
    $tab = daktelaTabParams('123');
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
    ]);

    $response = app($fake, tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, ' https://ingreen.daktela.com/ ', daktelaFrameHeadersWith([
            'Sec-Fetch-Dest' => 'IFRAME',
            'Sec-Fetch-Mode' => 'NAVIGATE',
            'Sec-Fetch-Site' => 'CROSS-SITE',
        ]), $tab['dt'], $tab['sig']);

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], 'name="access_token"'));
});


test('configured utility origin rejects malformed origin comparisons', function (): void {
    $tab = daktelaTabParams('123');
    $response = app(new FakeDaktela([]), tempDir(), 'ingreen.daktela.com')
        ->handle('123', null, null, 'ingreen.daktela.com', daktelaFrameHeaders(), $tab['dt'], $tab['sig']);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
});


test('configured utility key still requires Utility tab signature', function (): void {
    $tab = daktelaTabParams('123');
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
    ]);

    $response = app($fake, tempDir(), 'https://ingreen.daktela.com', 'shared-secret')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders(), $tab['dt'], $tab['sig']);

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], 'name="access_token"'));
});

