<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Application\PolicyDownloadService;
use Ingreen\DaktelaPolicy\Attachment\AttachmentMetadata;
use Ingreen\DaktelaPolicy\Attachment\AttachmentSelector;
use Ingreen\DaktelaPolicy\Attachment\DownloadedFile;
use Ingreen\DaktelaPolicy\Attachment\FileDownloader;
use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Daktela\HttpClientInterface;
use Ingreen\DaktelaPolicy\Daktela\HttpResponse;
use Ingreen\DaktelaPolicy\Entity\AttachmentResolverRegistry;
use Ingreen\DaktelaPolicy\Entity\TicketAttachmentResolver;
use Ingreen\DaktelaPolicy\Http\WebhookController;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
use Ingreen\DaktelaPolicy\Storage\LocalPolicyStorage;

require dirname(__DIR__) . '/vendor/autoload.php';

final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method:string,url:string,headers:array<string,string>}> */
    public array $requests = [];

    /**
     * @param array<string, HttpResponse|callable(string, string, array<string, string>): HttpResponse> $routes
     */
    public function __construct(private readonly array $routes)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        if (!isset($this->routes[$path])) {
            return new HttpResponse(404, ['Content-Type' => 'application/json'], '{}');
        }

        $route = $this->routes[$path];

        return is_callable($route) ? $route($method, $url, $headers) : $route;
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
        throw new RuntimeException($message !== '' ? $message : 'Values are not identical. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new RuntimeException($message !== '' ? $message : 'Expected true.');
    }
}

function jsonResponse(array $payload, int $status = 200): HttpResponse
{
    return new HttpResponse($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
}

function pdfResponse(string $body = "%PDF-1.4\nbody"): HttpResponse
{
    return new HttpResponse(200, ['Content-Type' => 'application/pdf'], $body);
}

function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/daktela-policy-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);

    return $dir;
}

function makeService(FakeHttpClient $http, string $dir): PolicyDownloadService
{
    $logger = new NullLogger();
    $client = new DaktelaClient('https://daktela.example', 'api-token', $http);

    return new PolicyDownloadService(
        new AttachmentResolverRegistry(['ticket' => new TicketAttachmentResolver($client, $logger)]),
        new AttachmentSelector(),
        new FileDownloader($client, 1_000_000),
        new LocalPolicyStorage($dir),
        $logger
    );
}

test('webhook rejects missing shared secret', function (): void {
    $http = new FakeHttpClient([]);
    $controller = new WebhookController('secret', makeService($http, tempDir()), new NullLogger());
    $response = $controller->handle('POST', [], '{"entityType":"ticket","entityId":"1"}');

    assertSameValue(401, $response->statusCode);
    assertSameValue('unauthorized', $response->body['error']['code']);
});

test('webhook rejects unsupported entity type', function (): void {
    $http = new FakeHttpClient([]);
    $controller = new WebhookController('secret', makeService($http, tempDir()), new NullLogger());
    $response = $controller->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"car","entityId":"1"}');

    assertSameValue(400, $response->statusCode);
    assertSameValue('unsupported_entity_type', $response->body['error']['code']);
});

test('ticket resolver handles has_attachment false', function (): void {
    $http = new FakeHttpClient([
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123', 'has_attachment' => false]]),
    ]);
    $resolver = new TicketAttachmentResolver(new DaktelaClient('https://daktela.example', 'token', $http), new NullLogger());

    try {
        $resolver->resolve('123');
    } catch (Throwable $exception) {
        assertSameValue('ticket_has_no_attachment', $exception->errorCode());
        return;
    }

    throw new RuntimeException('Expected exception.');
});

test('attachment selector prefers PDF metadata and policy filename', function (): void {
    $selector = new AttachmentSelector();
    $selected = $selector->selectPolicyPdf([
        new AttachmentMetadata('/files/readme.txt', 'readme.txt', 'text/plain'),
        new AttachmentMetadata('/files/scan.pdf', 'scan.pdf', null),
        new AttachmentMetadata('/files/policy.bin', 'policy.pdf', 'application/pdf'),
    ]);

    assertSameValue('/files/policy.bin', $selected->file);
});

test('storage sanitizes filename and skips existing files', function (): void {
    $dir = tempDir();
    $storage = new LocalPolicyStorage($dir);
    $attachment = new AttachmentMetadata('/download/abc', '../Policy 2026.pdf', 'application/pdf');

    $stored = $storage->store('ticket', '12/34', $attachment, new DownloadedFile('%PDF-1.4'));
    $again = $storage->store('ticket', '12/34', $attachment, new DownloadedFile('%PDF-1.4 updated'));

    assertSameValue('downloaded', $stored->status);
    assertSameValue('already_exists', $again->status);
    assertTrueValue(is_file($stored->path));
    assertTrueValue(str_contains(basename($stored->path), 'ticket_12_34_Policy_2026.pdf'));
});

test('downloader handles relative Daktela file path', function (): void {
    $http = new FakeHttpClient([
        '/attachments/policy.pdf' => pdfResponse(),
    ]);
    $downloader = new FileDownloader(new DaktelaClient('https://daktela.example', 'token', $http), 1_000_000);
    $file = $downloader->download(new AttachmentMetadata('/attachments/policy.pdf', 'policy.pdf', 'application/pdf'));

    assertSameValue("%PDF-1.4\nbody", $file->bytes);
    assertSameValue('https://daktela.example/attachments/policy.pdf', $http->requests[0]['url']);
    assertSameValue('token', $http->requests[0]['headers']['X-AUTH-TOKEN-OPENAPI']);
});

test('daktela 401 maps to upstream auth error', function (): void {
    $http = new FakeHttpClient([
        '/api/v6/tickets/123' => jsonResponse([], 401),
    ]);
    $controller = new WebhookController('secret', makeService($http, tempDir()), new NullLogger());
    $response = $controller->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"ticket","entityId":"123"}');

    assertSameValue(502, $response->statusCode);
    assertSameValue('daktela_auth_failed', $response->body['error']['code']);
});

test('full mocked ticket download and duplicate idempotency', function (): void {
    $downloadCount = 0;
    $http = new FakeHttpClient([
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
        '/files/policy.pdf' => function () use (&$downloadCount): HttpResponse {
            $downloadCount++;
            return pdfResponse();
        },
    ]);
    $controller = new WebhookController('secret', makeService($http, tempDir()), new NullLogger());

    $first = $controller->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"ticket","entityId":"123"}');
    $second = $controller->handle('POST', ['X-Webhook-Secret' => 'secret'], '{"entityType":"ticket","entityId":"123"}');

    assertSameValue(200, $first->statusCode);
    assertSameValue('downloaded', $first->body['result']['status']);
    assertSameValue(200, $second->statusCode);
    assertSameValue('already_exists', $second->body['result']['status']);
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
