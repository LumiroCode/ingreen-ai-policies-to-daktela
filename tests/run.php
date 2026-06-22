<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
use Ingreen\DaktelaPolicy\PolicyStore;
use Ingreen\DaktelaPolicy\WebhookApp;

require dirname(__DIR__) . '/vendor/autoload.php';

final class FakeDaktela
{
    /** @var list<array{method:string,url:string,headers:array<string,string>}> */
    public array $requests = [];

    /**
     * @param array<string, array{status:int,headers:array<string,string>,body:string}|callable(string,string,array<string,string>,?string): array{status:int,headers:array<string,string>,body:string}> $routes
     */
    public function __construct(private readonly array $routes)
    {
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function __invoke(string $method, string $url, array $headers, ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $route = $this->routes[$path] ?? ['status' => 404, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'];

        return is_callable($route) ? $route($method, $url, $headers, $body) : $route;
    }
}

final class NullLogger extends AppLogger
{
    public function info(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
    }

    public function error(string $message, array $context = []): void
    {
    }
}

/**
 * @param callable(): void $test
 */
function test(string $name, callable $test): void
{
    try {
        $test();
        echo ".";
    } catch (Throwable $exception) {
        echo "\nFAIL: {$name}\n";
        echo $exception::class . ': ' . $exception->getMessage() . "\n";
        exit(1);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new RuntimeException($message !== '' ? $message : 'Expected true.');
    }
}

/**
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function jsonResponse(array $payload, int $status = 200): array
{
    return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
}

/**
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function pdfResponse(string $body = "%PDF-1.4\nbody"): array
{
    return ['status' => 200, 'headers' => ['Content-Type' => 'application/pdf'], 'body' => $body];
}

function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/daktela-policy-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);

    return $dir;
}

function app(FakeDaktela $fake, string $dir): WebhookApp
{
    $config = new AppConfig('https://daktela.example', 'api-token', 'secret', $dir . '/var', $dir . '/cache', $dir . '/policies', 1_000_000);

    return new WebhookApp(
        $config,
        new DaktelaClient($config->daktelaBaseUrl, $config->daktelaApiToken, $fake),
        new PolicyStore($config->policyTempDir),
        new NullLogger()
    );
}

test('webhook rejects missing shared secret', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle('POST', [], '{"entityType":"ticket","entityId":"1"}');

    assertSameValue(401, $response['status']);
    assertSameValue('unauthorized', $response['body']['error']['code']);
});

test('webhook rejects unsupported entity type', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"car","entityId":"1"}');

    assertSameValue(400, $response['status']);
    assertSameValue('unsupported_entity_type', $response['body']['error']['code']);
});

test('ticket without attachments returns 404', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123', 'has_attachment' => false]]),
    ]);
    $response = app($fake, tempDir())->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"ticket","entityId":"123"}');

    assertSameValue(404, $response['status']);
    assertSameValue('ticket_has_no_attachment', $response['body']['error']['code']);
});

test('selector prefers PDF metadata and policy filename', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/readme.txt', 'title' => 'readme.txt', 'type' => 'text/plain'],
                    ['file' => '/files/scan.pdf', 'title' => 'scan.pdf'],
                    ['file' => '/files/policy.bin', 'title' => 'policy.pdf', 'type' => 'application/pdf'],
                ],
            ],
        ]),
        '/api/v6/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/policy.bin' => pdfResponse(),
    ]);

    $response = app($fake, tempDir())->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"ticket","entityId":"123"}');

    assertSameValue(200, $response['status']);
    assertSameValue('/files/policy.bin', $response['body']['result']['attachment']['file']);
});

test('storage sanitizes filename and skips existing files', function (): void {
    $store = new PolicyStore(tempDir());
    $attachment = ['file' => '/download/abc', 'title' => '../Policy 2026.pdf', 'type' => 'application/pdf'];

    $stored = $store->save('ticket', '12/34', $attachment, '%PDF-1.4');
    $again = $store->save('ticket', '12/34', $attachment, '%PDF-1.4 updated');

    assertSameValue('downloaded', $stored['status']);
    assertSameValue('already_exists', $again['status']);
    assertTrueValue(is_file($stored['path']));
    assertTrueValue(str_contains(basename($stored['path']), 'ticket_12_34_Policy_2026.pdf'));
});

test('downloader handles relative Daktela file path', function (): void {
    $fake = new FakeDaktela(['/attachments/policy.pdf' => pdfResponse()]);
    $client = new DaktelaClient('https://daktela.example', 'token', $fake);
    $file = $client->download('/attachments/policy.pdf', 1_000_000);

    assertSameValue("%PDF-1.4\nbody", $file['body']);
    assertSameValue('https://daktela.example/attachments/policy.pdf', $fake->requests[0]['url']);
    assertSameValue('token', $fake->requests[0]['headers']['X-AUTH-TOKEN-OPENAPI']);
});

test('daktela 401 maps to upstream auth error', function (): void {
    $fake = new FakeDaktela(['/api/v6/tickets/123' => jsonResponse([], 401)]);
    $response = app($fake, tempDir())->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"ticket","entityId":"123"}');

    assertSameValue(502, $response['status']);
    assertSameValue('daktela_auth_failed', $response['body']['error']['code']);
});

test('full mocked ticket download and duplicate idempotency', function (): void {
    $downloadCount = 0;
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
        '/api/v6/activities' => jsonResponse(['result' => ['data' => []]]),
        '/files/policy.pdf' => function () use (&$downloadCount): array {
            $downloadCount++;
            return pdfResponse();
        },
    ]);
    $app = app($fake, tempDir());

    $first = $app->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"ticket","entityId":"123"}');
    $second = $app->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"ticket","entityId":"123"}');

    assertSameValue(200, $first['status']);
    assertSameValue('downloaded', $first['body']['result']['status']);
    assertSameValue(200, $second['status']);
    assertSameValue('already_exists', $second['body']['result']['status']);
    assertSameValue(1, $downloadCount);
});

test('app config loads from PHP config files', function (): void {
    $dir = tempDir();
    $appConfig = $dir . '/app.php';
    $credentials = $dir . '/daktela-credentails.php';

    file_put_contents($appConfig, <<<'PHP'
<?php
return [
    'daktelaBaseUrl' => 'https://daktela.example/',
    'webhookSharedSecret' => 'secret',
    'varDir' => '/tmp/app-var',
    'cacheDir' => '/tmp/cache',
    'policyTempDir' => '/tmp/policies',
    'maxDownloadBytes' => '12345',
];
PHP);
    file_put_contents($credentials, <<<'PHP'
<?php
$daktelaAccessToken = 'token-from-file';
return $daktelaAccessToken;
PHP);

    $config = AppConfig::fromFiles($appConfig, $credentials);

    assertSameValue('https://daktela.example', $config->daktelaBaseUrl);
    assertSameValue('token-from-file', $config->daktelaApiToken);
    assertSameValue('secret', $config->webhookSharedSecret);
    assertSameValue('/tmp/app-var', $config->varDir);
    assertSameValue('/tmp/cache', $config->cacheDir);
    assertSameValue('/tmp/policies', $config->policyTempDir);
    assertSameValue(12345, $config->maxDownloadBytes);
});

test('app config requires credentials file', function (): void {
    $dir = tempDir();
    $appConfig = $dir . '/app.php';

    file_put_contents($appConfig, <<<'PHP'
<?php
return [
    'daktelaBaseUrl' => 'https://daktela.example',
    'webhookSharedSecret' => 'secret',
    'varDir' => '/tmp/app-var',
    'cacheDir' => '/tmp/cache',
    'policyTempDir' => '/tmp/policies',
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

echo "\nAll tests passed.\n";
