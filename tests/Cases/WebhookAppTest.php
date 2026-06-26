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
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataParser;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;
use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\WebhookAccessGuard;

test('app rejects missing ticket query parameter', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle(null, null);
    $payload = errorBody($response);

    assertSameValue(400, $response['status']);
    assertSameValue('invalid_request', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});


test('app rejects empty ticket query parameter', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle('  ', null);
    $payload = errorBody($response);

    assertSameValue(400, $response['status']);
    assertSameValue('invalid_request', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});


test('app rejects ticket requests without Utility tab signature', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle('123', null);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});


test('access guard logs denied attempts with diagnostic reasons', function (): void {
    $logger = new NullLogger();
    $config = new AppConfig('https://daktela.example', 'api-token', null, tempDir() . '/var', tempDir() . '/cache', 1_000_000, 'https://ingreen.daktela.com');
    $guard = new WebhookAccessGuard($config, tabSignatureVerifier(), $logger);
    $tab = daktelaTabParams('123');

    try {
        $guard->assertAccessAllowed(
            '123',
            null,
            'https://evil.example/tickets/123',
            daktelaFrameHeadersWith(['Referer' => 'https://evil.example/tickets/123']),
            $tab['dt'],
            '00000-00000-00000'
        );
    } catch (AppException $exception) {
        assertSameValue(403, $exception->statusCode());
    }

    assertSameValue(1, count($logger->warnings));
    assertSameValue('Utility tab access denied.', $logger->warnings[0]['message']);

    $context = $logger->warnings[0]['context'];
    assertSameValue('123', $context['ticket']);
    assertSameValue('https://ingreen.daktela.com', $context['allowedOrigin']);
    assertTrueValue(in_array('missing_access_token', $context['denialReasons']['accessToken'], true));
    assertTrueValue(in_array('sig_mismatch', $context['denialReasons']['tabSignature'], true));
    assertTrueValue(in_array('referrer_not_allowed', $context['denialReasons']['tabSignature'], true));
    assertSameValue($tab['dt'], $context['attempt']['tabSignature']['dt']);
    assertSameValue(true, $context['attempt']['tabSignature']['sigPresent']);
    assertTrueValue(is_string($context['attempt']['tabSignature']['sigFingerprint']));
});


test('configured utility origin allows signed in-app attachment request', function (): void {
    $dir = tempDir();
    $tab = daktelaTabParams('123');
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
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $app = app($fake, $dir, 'https://ingreen.daktela.com');
    $list = $app->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders(), $tab['dt'], $tab['sig']);
    $download = $app->handle('123', '0', accessTokenFromHtml($list['body']), 'https://app.example/?ticket=123');

    assertSameValue(200, $download['status']);
    assertSameValue('text/html; charset=UTF-8', $download['headers']['Content-Type']);
    assertTrueValue(str_contains($download['body'], 'Dane polisy'));
    assertTrueValue(str_contains($download['body'], 'Dane pojazdu'));
    assertTrueValue(str_contains($download['body'], 'Towarzystwo ubezpieczeniowe'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[pakiet_ubezpieczeniowy]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[nr_polisy]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[cena_wznowienia]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[oc_cena]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[ac_cena]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[cena_nnw]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[cena_assistance]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[gap_cena]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[cena_przedluzonej_gwarancji]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[rodzaj_polisy]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[data_sprzedazy_wznowienia]"'));
    assertTrueValue(str_contains($download['body'], 'scan.pdf'));
    assertTrueValue(str_contains($download['body'], 'Marka'));
    assertTrueValue(str_contains($download['body'], 'Model'));
    assertTrueValue(str_contains($download['body'], 'Nr rejestracyjny'));
    assertTrueValue(str_contains($download['body'], 'Forma własności'));
    assertTrueValue(str_contains($download['body'], 'Współposiadacz'));
    assertTrueValue(str_contains($download['body'], 'Wartość'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[nr_rejestracyjny]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[marka]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[model]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[forma_wlasnosci]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[wartosc_pojazdu_brutto]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[wspolposiadacz]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[imie_wspolposiadacza]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[nazwisko_wspolposiadacza]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[pesel_wspolposiadacza]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[adres_wspolposiadacza]"'));
    assertTrueValue(str_contains($download['body'], 'value="Skoda"'));
    assertTrueValue(str_contains($download['body'], 'value="Octavia"'));
    assertTrueValue(str_contains($download['body'], 'value="50 000 CZK"'));
    assertTrueValue(str_contains($download['body'], 'class="policy-review-lock-all"'));
    assertTrueValue(str_contains($download['body'], 'class="policy-review-lock-group"'));
    assertTrueValue(str_contains($download['body'], 'wszystkie poprawne'));
    assertTrueValue(str_contains($download['body'], 'name="policy_locked[marka]"'));
    assertTrueValue(str_contains($download['body'], 'name="confirmation"'));
    assertTrueValue(str_contains($download['body'], 'value="yes"'));
    assertTrueValue(str_contains($download['body'], 'value="no"'));
    assertSameValue("%PDF-1.4\nbody", file_get_contents($dir . '/var/policies/files_scan.pdf'));
});


test('app config loads from PHP config files', function (): void {
    $dir = tempDir();
    $appConfig = $dir . '/app.php';
    $credentials = $dir . '/daktela-credentails.php';
    $claudeCredentials = $dir . '/claude-api-key.php';

    file_put_contents($appConfig, <<<'PHP'
<?php
return [
    'daktelaBaseUrl' => 'https://daktela.example/',
    'varDir' => '/tmp/app-var',
    'cacheDir' => '/tmp/cache',
    'maxDownloadBytes' => '12345',
];
PHP);
    file_put_contents($credentials, <<<'PHP'
<?php
$daktelaAccessToken = 'token-from-file';
return $daktelaAccessToken;
PHP);
    file_put_contents($claudeCredentials, <<<'PHP'
<?php
$claudeApiKey = 'claude-key-from-file';
return $claudeApiKey;
PHP);

    $config = AppConfig::fromFiles($appConfig, $credentials, $claudeCredentials);

    assertSameValue('https://daktela.example', $config->daktelaBaseUrl);
    assertSameValue('token-from-file', $config->daktelaApiToken);
    assertSameValue('claude-key-from-file', $config->claudeApiKey);
    assertSameValue('/tmp/app-var', $config->varDir);
    assertSameValue('/tmp/cache', $config->cacheDir);
    assertSameValue(12345, $config->maxDownloadBytes);
});


test('app config requires credentials file', function (): void {
    $dir = tempDir();
    $appConfig = $dir . '/app.php';

    file_put_contents($appConfig, <<<'PHP'
<?php
return [
    'daktelaBaseUrl' => 'https://daktela.example',
    'varDir' => '/tmp/app-var',
    'cacheDir' => '/tmp/cache',
];
PHP);

    try {
        AppConfig::fromFiles($appConfig, $dir . '/missing.php');
    } catch (Throwable $exception) {
        assertSameValue('missing_credentials_file', $exception->errorCode());
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('app logger writes JSON lines to configured log file', function (): void {
    $dir = tempDir();
    $paths = new DailyLogPaths($dir . '/var', new DateTimeImmutable('2026-06-22'));
    $logger = new AppLogger($paths->logsFile());

    $logger->info('Test log message.', ['requestId' => 'abc']);

    assertSameValue($dir . '/var/2026-06-22/2026-06-22.log', $paths->logsFile());
    assertSameValue($dir . '/var/2026-06-22/2026-06-22.errors.log', $paths->errorsFile());
    assertTrueValue(is_file($paths->logsFile()));

    $lines = file($paths->logsFile(), FILE_IGNORE_NEW_LINES);
    assertTrueValue(is_array($lines));

    $payload = json_decode($lines[0], true);
    assertSameValue('info', $payload['level']);
    assertSameValue('Test log message.', $payload['message']);
    assertSameValue('abc', $payload['context']['requestId']);
});
