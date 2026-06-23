<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudeMessagesClient;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataResponseParser;
use Ingreen\DaktelaPolicy\TicketPdfAttachments;
use Ingreen\DaktelaPolicy\WebhookApp;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\TextBlockParam;

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

final class FakeClaudeMessagesClient implements ClaudeMessagesClient
{
    /** @var list<array{model:string,maxTokens:int,messages:list<array{role:string,content:list<object>}>>> */
    public array $requests = [];

    public function __construct(private readonly string $response)
    {
    }

    public function createMessage(string $model, int $maxTokens, array $messages): string
    {
        $this->requests[] = ['model' => $model, 'maxTokens' => $maxTokens, 'messages' => $messages];

        return $this->response;
    }
}

final class FakePolicyDataExtractor implements PolicyDataExtractor
{
    /** @var list<string> */
    public array $paths = [];

    public function __construct(
        private readonly ?ExtractedPolicyData $response = null,
        private readonly ?Throwable $exception = null
    ) {
    }

    public function extract(string $pdfPath): ExtractedPolicyData
    {
        $this->paths[] = $pdfPath;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response ?? new ExtractedPolicyData('Skoda', 'Octavia', '50 000 CZK', '{"car_make":"Skoda","car_model":"Octavia","value":"50 000 CZK"}');
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

function app(FakeDaktela $fake, string $dir, ?string $allowedUtilityOrigin = null, ?string $utilitySecretKey = null, ?PolicyDataExtractor $extractor = null): WebhookApp
{
    $config = new AppConfig('https://daktela.example', 'api-token', null, $dir . '/var', $dir . '/cache', 1_000_000, $allowedUtilityOrigin, $utilitySecretKey);

    $logger = new NullLogger();
    $daktela = new DaktelaClient($config->daktelaBaseUrl, $config->daktelaApiToken, $fake);

    return new WebhookApp($config, $daktela, new TicketPdfAttachments($daktela, $logger), $extractor ?? new FakePolicyDataExtractor(), $logger);
}

function accessTokenFromHtml(string $html): string
{
    if (preg_match('/name="access_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Expected rendered access token.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

/**
 * @param array{status:int,headers:array<string,string>,body:string} $response
 * @return array<string,mixed>
 */
function errorBody(array $response): array
{
    $payload = json_decode($response['body'], true);

    if (!is_array($payload)) {
        throw new RuntimeException('Expected JSON error response.');
    }

    return $payload;
}

test('app rejects missing ticket query parameter', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle(null, null);
    $payload = errorBody($response);

    assertSameValue(400, $response['status']);
    assertSameValue('invalid_request', $payload['error']['code']);
    assertSameValue('ticket', $payload['error']['details']['field']);
});

test('app rejects empty ticket query parameter', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle('  ', null);
    $payload = errorBody($response);

    assertSameValue(400, $response['status']);
    assertSameValue('invalid_request', $payload['error']['code']);
    assertSameValue('ticket', $payload['error']['details']['field']);
});

test('ticket without attachments returns 404', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123', 'has_attachment' => false]]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
    ]);
    $response = app($fake, tempDir())->handle('123', null);
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

    $response = app($fake, tempDir())->handle('123', null);

    assertSameValue(200, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], '<td>scan.pdf</td>'));
    assertTrueValue(str_contains($response['body'], '<td>policy.pdf</td>'));
    assertTrueValue(!str_contains($response['body'], 'readme.txt'));
    assertSameValue(0, $downloadCount);
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

    $response = app($fake, tempDir())->handle('123', null);
    $requestPaths = array_map(
        static fn (array $request): string => parse_url($request['url'], PHP_URL_PATH) ?: '',
        $fake->requests
    );

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], '<td>activity-first.pdf</td>'));
    assertTrueValue(str_contains($response['body'], '<td>activity-second.pdf</td>'));
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

    $response = app($fake, tempDir())->handle('15242', null);

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], '<td>przykladowa_polisa_ubezpieczenia_samochodu.pdf</td>'));
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

    $download = $app->handle('15242', '0');
    $request = $fake->requests[2];
    parse_str(parse_url($request['url'], PHP_URL_QUERY) ?: '', $query);

    assertSameValue(200, $download['status']);
    assertSameValue('https://daktela.example/file/download.php?mapper=activitiesEmailFiles&name=35869&iconHash=Polisa_904001145228.pdf&download=1', $request['url']);
    assertSameValue('activitiesEmailFiles', $query['mapper']);
    assertSameValue('35869', $query['name']);
    assertSameValue('Polisa_904001145228.pdf', $query['iconHash']);
    assertSameValue('1', $query['download']);
    assertSameValue("%PDF-1.4\nmapped", file_get_contents($dir . '/var/tmp/policies/35869.pdf'));
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

    $download = $app->handle('15242', '0');
    $request = $fake->requests[2];

    assertSameValue(200, $download['status']);
    assertSameValue('https://daktela.example/file/download.php?mapper=activitiesComment&name=2023&iconHash=Faktura+FV+9_4_2026.pdf&download=1', $request['url']);
    assertSameValue("%PDF-1.4\ncomment", file_get_contents($dir . '/var/tmp/policies/2023.pdf'));
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

    $response = app($fake, tempDir())->handle('123', null);

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], '<td>nested-policy.pdf</td>'));
    assertTrueValue(!str_contains($response['body'], 'item-readme.txt'));
});

test('configured utility origin rejects direct browser entry requests', function (): void {
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com')->handle('123', null);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
    assertSameValue('https://ingreen.daktela.com', $payload['error']['details']['allowedOrigin']);
});

test('configured utility origin allows Daktela referrer and sets frame policy', function (): void {
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
        ->handle('123', null, null, null, 'https://ingreen.daktela.com/tickets/123');

    assertSameValue(200, $response['status']);
    assertSameValue('frame-ancestors https://ingreen.daktela.com', $response['headers']['Content-Security-Policy']);
    assertTrueValue(str_contains($response['body'], 'name="access_token"'));
});

test('configured utility origin allows signed in-app attachment request', function (): void {
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
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $app = app($fake, $dir, 'https://ingreen.daktela.com');
    $list = $app->handle('123', null, null, null, 'https://ingreen.daktela.com/tickets/123');
    $download = $app->handle('123', '0', accessTokenFromHtml($list['body']), null, 'https://app.example/?ticket=123');

    assertSameValue(200, $download['status']);
    assertSameValue('text/html; charset=UTF-8', $download['headers']['Content-Type']);
    assertTrueValue(str_contains($download['body'], '&quot;car_make&quot;:&quot;Skoda&quot;'));
    assertTrueValue(str_contains($download['body'], '&quot;car_model&quot;:&quot;Octavia&quot;'));
    assertTrueValue(str_contains($download['body'], '&quot;value&quot;:&quot;50 000 CZK&quot;'));
    assertSameValue("%PDF-1.4\nbody", file_get_contents($dir . '/var/tmp/policies/files_scan.pdf'));
});

test('configured utility key rejects entry requests without the key', function (): void {
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com', 'shared-secret')
        ->handle('123', null, null, null, 'https://ingreen.daktela.com/tickets/123');
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
    assertSameValue(true, $payload['error']['details']['requiresUtilityKey']);
});

test('configured utility key rejects entry requests with the wrong key', function (): void {
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com', 'shared-secret')
        ->handle('123', null, null, 'wrong-secret', 'https://ingreen.daktela.com/tickets/123');
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
});

test('configured utility key allows entry requests with the right key', function (): void {
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
        ->handle('123', null, null, 'shared-secret', 'https://ingreen.daktela.com/tickets/123');

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], 'name="access_token"'));
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
    $response = app($fake, tempDir())->handle('123', null);
    $payload = errorBody($response);

    assertSameValue(502, $response['status']);
    assertSameValue('daktela_auth_failed', $payload['error']['code']);
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

    $list = $app->handle('123', null);

    assertSameValue(200, $list['status']);
    assertSameValue([], $downloads);
    assertTrueValue(!is_file($dir . '/var/tmp/policies/policy-456.pdf'));

    $download = $app->handle('123', '1');

    assertSameValue(200, $download['status']);
    assertSameValue('text/html; charset=UTF-8', $download['headers']['Content-Type']);
    assertTrueValue(str_contains($download['body'], '&quot;car_make&quot;:&quot;Skoda&quot;'));
    assertTrueValue(str_contains($download['body'], '&quot;car_model&quot;:&quot;Octavia&quot;'));
    assertTrueValue(str_contains($download['body'], '&quot;value&quot;:&quot;50 000 CZK&quot;'));
    assertSameValue("%PDF-1.4\nsecond", file_get_contents($dir . '/var/tmp/policies/policy-456.pdf'));
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

    $response = app($fake, tempDir())->handle('123', '0');

    assertSameValue(502, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], '<td>not-pdf.pdf</td>'));
    assertTrueValue(str_contains($response['body'], 'Nie udało się przetworzyć pliku polisy:'));
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
        ->handle('123', '0');

    assertSameValue(500, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], '<td>policy.pdf</td>'));
    assertTrueValue(str_contains($response['body'], 'Nie udało się przetworzyć pliku polisy: Claude unavailable'));
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

test('policy data parser maps Claude JSON response to extracted policy data', function (): void {
    $data = (new PolicyDataResponseParser())->parse('```json
{"car_make":"Toyota","car_model":"Corolla","value":"123 000 PLN"}
```');

    assertSameValue('Toyota', $data->carMake);
    assertSameValue('Corolla', $data->carModel);
    assertSameValue('123 000 PLN', $data->value);
});

test('Claude policy extractor sends PDF document and prompt to Claude client', function (): void {
    $dir = tempDir();
    $pdfPath = $dir . '/policy.pdf';
    file_put_contents($pdfPath, "%PDF-1.4\npolicy");

    $client = new FakeClaudeMessagesClient('{"car_make":"Skoda","car_model":"Octavia","value":"50 000 CZK"}');
    $extractor = new ClaudePolicyDataExtractor($client, new PolicyDataResponseParser(), 'claude-test-model', 256);

    $data = $extractor->extract($pdfPath);

    assertSameValue('Skoda', $data->carMake);
    assertSameValue('Octavia', $data->carModel);
    assertSameValue('50 000 CZK', $data->value);
    assertSameValue('claude-test-model', $client->requests[0]['model']);
    assertSameValue(256, $client->requests[0]['maxTokens']);
    assertSameValue('user', $client->requests[0]['messages'][0]['role']);
    assertTrueValue($client->requests[0]['messages'][0]['content'][0] instanceof DocumentBlockParam);
    assertTrueValue($client->requests[0]['messages'][0]['content'][1] instanceof TextBlockParam);
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'car make'));
});

echo "\nAll tests passed.\n";
