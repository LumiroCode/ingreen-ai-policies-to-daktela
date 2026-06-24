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
use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\TicketPdfAttachments;
use Ingreen\DaktelaPolicy\WebhookAccessGuard;
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
    /** @var list<array{message:string,context:array<string,mixed>}> */
    public array $warnings = [];

    public function info(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = ['message' => $message, 'context' => $context];
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

function assertArrayMissingKey(string $key, array $array, string $message = ''): void
{
    if (array_key_exists($key, $array)) {
        throw new RuntimeException($message !== '' ? $message : 'Expected missing key ' . $key . '.');
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
 * @return array<string,string>
 */
function daktelaFrameHeaders(): array
{
    return [
        'Referer' => 'https://ingreen.daktela.com/',
        'Sec-Fetch-Dest' => 'iframe',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Site' => 'cross-site',
    ];
}

/**
 * @return array<string,string>
 */
function daktelaFrameHeadersWith(array $headers): array
{
    return array_merge(daktelaFrameHeaders(), $headers);
}

/**
 * @return array{dt:string,sig:string}
 */
function daktelaTabParams(string $ticketId, int $secondOffset = 0): array
{
    $dt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify(($secondOffset >= 0 ? '+' : '') . $secondOffset . ' seconds')
        ->format('U');
    $config = new AppConfig('https://daktela.example', 'api-token', null, sys_get_temp_dir(), sys_get_temp_dir());
    $sig = (new WebhookAccessGuard($config))->makeDaktelaTabSig($dt, $ticketId);

    if ($sig === null) {
        throw new RuntimeException('Could not create Daktela tab signature.');
    }

    return ['dt' => $dt, 'sig' => $sig];
}

function daktelaAccessToken(string $ticketId): string
{
    $config = new AppConfig('https://daktela.example', 'api-token', null, sys_get_temp_dir(), sys_get_temp_dir());

    return (new WebhookAccessGuard($config))->accessTokenForTicket($ticketId);
}

/**
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function signedEntryRequest(WebhookApp $app, string $ticketId): array
{
    $tab = daktelaTabParams($ticketId);

    return $app->handle($ticketId, null, null, null, [], $tab['dt'], $tab['sig']);
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
    assertArrayMissingKey('details', $payload['error']);
});

test('app rejects empty ticket query parameter', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle('  ', null);
    $payload = errorBody($response);

    assertSameValue(400, $response['status']);
    assertSameValue('invalid_request', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});

test('app rejects ticket requests without Daktela tab signature', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle('123', null);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});

test('Daktela tab signature matches helper formula with seconds', function (): void {
    $config = new AppConfig('https://daktela.example', 'api-token', null, tempDir() . '/var', tempDir() . '/cache');
    $guard = new WebhookAccessGuard($config);

    assertSameValue('89666-30820-47545', $guard->makeDaktelaTabSig('1782315045', '123'));
});

test('access guard logs denied attempts with diagnostic reasons', function (): void {
    $logger = new NullLogger();
    $config = new AppConfig('https://daktela.example', 'api-token', null, tempDir() . '/var', tempDir() . '/cache', 1_000_000, 'https://ingreen.daktela.com');
    $guard = new WebhookAccessGuard($config, $logger);
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
    assertSameValue('Daktela tab access denied.', $logger->warnings[0]['message']);

    $context = $logger->warnings[0]['context'];
    assertSameValue('123', $context['ticket']);
    assertSameValue('https://ingreen.daktela.com', $context['allowedOrigin']);
    assertTrueValue(in_array('missing_access_token', $context['denialReasons']['accessToken'], true));
    assertTrueValue(in_array('sig_mismatch', $context['denialReasons']['daktelaTabSignature'], true));
    assertTrueValue(in_array('referrer_not_allowed', $context['denialReasons']['daktelaTabSignature'], true));
    assertSameValue($tab['dt'], $context['attempt']['daktelaTabSignature']['dt']);
    assertSameValue(true, $context['attempt']['daktelaTabSignature']['sigPresent']);
    assertTrueValue(is_string($context['attempt']['daktelaTabSignature']['sigFingerprint']));
});

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
    assertTrueValue(str_contains($response['body'], 'scan.pdf'));
    assertTrueValue(str_contains($response['body'], 'policy.pdf'));
    assertTrueValue(str_contains($response['body'], 'class="attachment-row attachment-read-form'));
    assertTrueValue(str_contains($response['body'], 'data-loading-label="Odczytuję..."'));
    assertTrueValue(str_contains($response['body'], 'id="processing-message"'));
    assertTrueValue(str_contains($response['body'], 'Trwa odczyt danych z polisy.'));
    assertTrueValue(str_contains(file_get_contents(dirname(__DIR__) . '/public/assets/app.js'), 'button.textContent = loadingLabel;'));
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

    $download = $app->handle('15242', '0', daktelaAccessToken('15242'));
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

    $response = signedEntryRequest(app($fake, tempDir()), '123');

    assertSameValue(200, $response['status']);
    assertTrueValue(str_contains($response['body'], 'nested-policy.pdf'));
    assertTrueValue(!str_contains($response['body'], 'item-readme.txt'));
});

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

test('configured utility origin rejects missing Daktela tab signature', function (): void {
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders());
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});

test('configured utility origin allows Daktela tab timestamp within skew', function (): void {
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

test('configured utility origin rejects stale Daktela tab timestamp', function (): void {
    $tab = daktelaTabParams('123', -6);
    $response = app(new FakeDaktela([]), tempDir(), 'https://ingreen.daktela.com')
        ->handle('123', null, null, 'https://ingreen.daktela.com/tickets/123', daktelaFrameHeaders(), $tab['dt'], $tab['sig']);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
});

test('configured utility origin rejects wrong Daktela tab signature', function (): void {
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
    assertTrueValue(str_contains($download['body'], 'Marka'));
    assertTrueValue(str_contains($download['body'], 'Model'));
    assertTrueValue(str_contains($download['body'], 'Wartość'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[car_make]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[car_model]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[value]"'));
    assertTrueValue(str_contains($download['body'], 'value="Skoda"'));
    assertTrueValue(str_contains($download['body'], 'value="Octavia"'));
    assertTrueValue(str_contains($download['body'], 'value="50 000 CZK"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_locked[car_make]"'));
    assertTrueValue(str_contains($download['body'], 'name="confirmation"'));
    assertTrueValue(str_contains($download['body'], 'value="yes"'));
    assertTrueValue(str_contains($download['body'], 'value="no"'));
    assertSameValue("%PDF-1.4\nbody", file_get_contents($dir . '/var/tmp/policies/files_scan.pdf'));
});

test('configured utility key still requires Daktela tab signature', function (): void {
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
    $response = signedEntryRequest(app($fake, tempDir()), '123');
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

    $list = signedEntryRequest($app, '123');

    assertSameValue(200, $list['status']);
    assertSameValue([], $downloads);
    assertTrueValue(!is_file($dir . '/var/tmp/policies/policy-456.pdf'));

    $download = $app->handle('123', '1', daktelaAccessToken('123'));

    assertSameValue(200, $download['status']);
    assertSameValue('text/html; charset=UTF-8', $download['headers']['Content-Type']);
    assertTrueValue(str_contains($download['body'], 'name="policy_data[car_make]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[car_model]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[value]"'));
    assertTrueValue(str_contains($download['body'], 'value="Skoda"'));
    assertTrueValue(str_contains($download['body'], 'value="Octavia"'));
    assertTrueValue(str_contains($download['body'], 'value="50 000 CZK"'));
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

    $response = app($fake, tempDir())->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(502, $response['status']);
    assertSameValue('text/html; charset=UTF-8', $response['headers']['Content-Type']);
    assertTrueValue(str_contains($response['body'], 'not-pdf.pdf'));
    assertTrueValue(str_contains($response['body'], 'Nie udało się pobrać pliku polisy z Dakteli.'));
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
