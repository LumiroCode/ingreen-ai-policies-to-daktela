<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaModule;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaTabSignatureVerifier;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudeMessagesClient;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm;
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
    /** @var list<array{model:string,maxTokens:int,messages:list<array{role:string,content:list<object>}>,thinking:array<string,mixed>|null,outputConfig:array<string,mixed>|null}> */
    public array $requests = [];

    public function __construct(private readonly string $response)
    {
    }

    public function createMessage(
        string $model,
        int $maxTokens,
        array $messages,
        ?array $thinking = null,
        ?array $outputConfig = null
    ): string {
        $this->requests[] = [
            'model' => $model,
            'maxTokens' => $maxTokens,
            'messages' => $messages,
            'thinking' => $thinking,
            'outputConfig' => $outputConfig,
        ];

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
    $daktela = new DaktelaModule($config->daktelaBaseUrl, $config->daktelaApiToken, $fake, $logger);

    return new WebhookApp($config, tabSignatureVerifier(), new TicketPdfAttachments($daktela, $logger, $config->cacheDir), $extractor ?? new FakePolicyDataExtractor(), $logger);
}

function tabSignatureVerifier(): DaktelaTabSignatureVerifier
{
    return new DaktelaTabSignatureVerifier();
}

function accessTokenFromHtml(string $html): string
{
    if (preg_match('/name="access_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Expected rendered access token.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function assertPolicyFieldLocked(string $html, string $field): void
{
    $fieldPattern = preg_quote($field, '/');

    assertTrueValue(
        preg_match('/name="policy_locked\[' . $fieldPattern . '\]"[^>]*\bchecked\b/s', $html) === 1,
        'Expected policy lock checkbox to be checked for ' . $field . '.'
    );
    assertTrueValue(
        preg_match('/name="policy_data\[' . $fieldPattern . '\]"[^>]*\breadonly\b/s', $html) === 1,
        'Expected policy data input to be readonly for ' . $field . '.'
    );
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
    $sig = (new WebhookAccessGuard($config, tabSignatureVerifier()))->makeUtilityTabSig($dt, $ticketId);

    if ($sig === null) {
        throw new RuntimeException('Could not create Utility tab signature.');
    }

    return ['dt' => $dt, 'sig' => $sig];
}

function daktelaAccessToken(string $ticketId): string
{
    $config = new AppConfig('https://daktela.example', 'api-token', null, sys_get_temp_dir(), sys_get_temp_dir());

    return (new WebhookAccessGuard($config, tabSignatureVerifier()))->accessTokenForTicket($ticketId);
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

test('app rejects ticket requests without Utility tab signature', function (): void {
    $response = app(new FakeDaktela([]), tempDir())->handle('123', null);
    $payload = errorBody($response);

    assertSameValue(403, $response['status']);
    assertSameValue('forbidden_utility_access', $payload['error']['code']);
    assertArrayMissingKey('details', $payload['error']);
});

test('Utility tab signature matches helper formula with seconds', function (): void {
    $config = new AppConfig('https://daktela.example', 'api-token', null, tempDir() . '/var', tempDir() . '/cache');
    $guard = new WebhookAccessGuard($config, tabSignatureVerifier());

    assertSameValue('89666-30820-47545', $guard->makeUtilityTabSig('1782315045', '123'));
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
    assertTrueValue(str_contains(file_get_contents(dirname(__DIR__) . '/public/assets/app.js'), 'button.textContent = loadingLabel;'));
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
    assertTrueValue(str_contains(
        $download['body'],
        'src="?ticket=15242&amp;attachment=0&amp;access_token='
    ));
    assertTrueValue(str_contains(
        $download['body'],
        'href="https://daktela.example/file/download.php?mapper=activitiesEmailFiles&amp;name=35869&amp;iconHash=Polisa_904001145228.pdf&amp;download=0"'
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
    assertTrueValue(str_contains($download['body'], 'name="policy_data[pc_cena]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[ac_cena]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[cena_nnw]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[cena_assistance]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[gap_cena]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[cena_przedluzonej_gwarancji]"'));
    assertTrueValue(str_contains($download['body'], 'name="policy_data[pochodzenie_polisy]"'));
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
    assertTrueValue(str_contains($download['body'], 'zachowaj wszystkie'));
    assertTrueValue(str_contains($download['body'], 'name="policy_locked[marka]"'));
    assertTrueValue(str_contains($download['body'], 'name="confirmation"'));
    assertTrueValue(str_contains($download['body'], 'value="yes"'));
    assertTrueValue(str_contains($download['body'], 'value="no"'));
    assertSameValue("%PDF-1.4\nbody", file_get_contents($dir . '/var/policies/files_scan.pdf'));
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
        policyLocked: PolicyConfirmationForm::allLockedFields()
    );
    $cached = $app->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(200, $extracted['status']);
    assertSameValue(200, $confirmed['status']);
    assertSameValue(200, $cached['status']);
    assertSameValue(1, $downloadCount, 'Expected cached policy read not to download the attachment again.');
    assertTrueValue(
        str_contains($cached['body'], 'Polisa została już kiedyś odczytana - wczytano zapisane dane.'),
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
    $extractor = new FakePolicyDataExtractor(new ExtractedPolicyData('Toyota', 'Yaris', '10 000 EUR', '{"car_make":"Toyota","car_model":"Yaris","value":"10 000 EUR"}'));
    $app = app($fake, $dir, extractor: $extractor);

    $extracted = $app->handle('123', '0', daktelaAccessToken('123'));
    $cached = $app->handle('123', '0', daktelaAccessToken('123'));

    assertSameValue(200, $extracted['status']);
    assertSameValue(200, $cached['status']);
    assertSameValue(1, $downloadCount, 'Expected pending policy read not to download the attachment again.');
    assertSameValue(1, count($extractor->paths), 'Expected pending policy read not to run extraction again.');
    assertTrueValue(
        str_contains($cached['body'], 'Wczytano dane z poprzedniego odczytu polisy.'),
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

test('downloader handles relative Daktela file path', function (): void {
    $fake = new FakeDaktela(['/attachments/policy.pdf' => pdfResponse()]);
    $client = new DaktelaModule('https://daktela.example', 'token', $fake);
    $file = $client->download('/attachments/policy.pdf', 1_000_000);

    assertSameValue("%PDF-1.4\nbody", $file['body']);
    assertSameValue('https://daktela.example/attachments/policy.pdf', $fake->requests[0]['url']);
    assertSameValue('token', $fake->requests[0]['headers']['X-AUTH-TOKEN-OPENAPI']);
});

test('Daktela module gets ticket by name through its facade', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $ticket = $module->getTicketByName('123');

    assertSameValue('123', $ticket['result']['name']);
    assertSameValue('GET', $fake->requests[0]['method']);
    assertSameValue('https://daktela.example/api/v6/tickets/123', $fake->requests[0]['url']);
    assertSameValue('application/json', $fake->requests[0]['headers']['Accept']);
    assertSameValue('module-token', $fake->requests[0]['headers']['X-AUTH-TOKEN-OPENAPI']);
});

test('Daktela module URL-encodes ticket name', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/ABC%2F123' => jsonResponse(['result' => ['name' => 'ABC/123']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $ticket = $module->getTicketByName('ABC/123');

    assertSameValue('ABC/123', $ticket['result']['name']);
    assertSameValue('https://daktela.example/api/v6/tickets/ABC%2F123', $fake->requests[0]['url']);
});

test('Daktela module gets normalized ticket PDF attachments', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/ABC%2F123' => jsonResponse([
            'result' => [
                'name' => 'ABC/123',
                'title' => 'Policy ticket',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/ticket.pdf', 'title' => 'ticket.pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/ABC%2F123/activities' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'activity-1',
                        'attachments' => [
                            ['file' => '/files/activity.pdf', 'title' => 'activity.pdf'],
                        ],
                        'item' => [
                            'attachments' => [
                                ['file' => '/files/item.pdf', 'title' => 'item.pdf'],
                            ],
                            'inlineAttachments' => [
                                ['file' => '/files/inline.pdf', 'title' => 'inline.pdf'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $attachments = $module->getTicketPdfAttachments('ABC/123');
    parse_str(parse_url($fake->requests[1]['url'], PHP_URL_QUERY) ?: '', $activityQuery);

    assertSameValue('Policy ticket', $attachments['title']);
    assertSameValue(true, $attachments['hasAttachment']);
    assertSameValue('https://daktela.example/api/v6/tickets/ABC%2F123', $fake->requests[0]['url']);
    assertSameValue('https://daktela.example/api/v6/tickets/ABC%2F123/activities?pageSize=100&sort%5B0%5D%5Bfield%5D=time&sort%5B0%5D%5Bdir%5D=desc', $fake->requests[1]['url']);
    assertSameValue('100', $activityQuery['pageSize']);
    assertSameValue('time', $activityQuery['sort'][0]['field']);
    assertSameValue('desc', $activityQuery['sort'][0]['dir']);
    assertSameValue(['ticket', 'activity.attachments', 'activity.item.attachments', 'activity.item.inlineAttachments'], array_map(
        static fn (array $candidate): string => $candidate['source'],
        $attachments['attachments']
    ));
    assertSameValue('ticket.pdf', $attachments['attachments'][0]['title']);
    assertSameValue('/files/ticket.pdf', $attachments['attachments'][0]['file']);
    assertSameValue('https://daktela.example/files/ticket.pdf?download=0', $attachments['attachments'][0]['previewUrl']);
    assertSameValue('activity.pdf', $attachments['attachments'][1]['title']);
});

test('Daktela module maps auth failure to AppException', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([], 401),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->getTicketByName('123');
    } catch (AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('daktela_auth_failed', $exception->errorCode());
        return;
    }

    throw new RuntimeException('Expected exception.');
});

test('Daktela module maps non-success response to AppException', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([], 500),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->getTicketByName('123');
    } catch (AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('daktela_request_failed', $exception->errorCode());
        assertSameValue('/api/v6/tickets/123', $exception->details()['path']);
        return;
    }

    throw new RuntimeException('Expected exception.');
});

test('Daktela module rejects invalid JSON response', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{invalid'],
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->getTicketByName('123');
    } catch (AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('invalid_daktela_response', $exception->errorCode());
        assertSameValue('/api/v6/tickets/123', $exception->details()['path']);
        return;
    }

    throw new RuntimeException('Expected exception.');
});

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
{"stan_pojazdu":"Używany","nr_rejestracyjny":"WX12345","marka":"Toyota","model":"Corolla","wersja":"Comfort","vin":"JT123","forma_wlasnosci":"Leasing","rocznik":"2022","przebieg":"12000","wartosc_pojazdu_brutto":"123 000 PLN","wartosc_pojazdu_netto":null,"kategoria_pojazdu":"Osobowy (Kat. M1)","sposob_korzystania":"Standardowy","typ_silnika":"Hybryda","pojemnosc_silnika":"1798","data_nabycia":"2024-01-01","data_pierwszej_rejestracji":"2022-03-01","planowana_data_rejestracji":null,"wspolposiadacz":"tak","imie_wspolposiadacza":"Jan","nazwisko_wspolposiadacza":"Kowalski","pesel_wspolposiadacza":"80010112345","adres_wspolposiadacza":"ul. Prosta 1, Warszawa","pakiet_ubezpieczeniowy":"tak","rodzaj_assistance":"Polska","towarzystwo_ubezpieczeniowe":"PZU","nr_polisy":"POL-123","kategoria_tu":"Partner InGreen","data_konca_polisy":"2025-03-01","cena_pakietu":"3200 PLN","cena_wznowienia":"3300 PLN","pc_cena":"100 PLN","ac_cena":"2100 PLN","cena_nnw":"50 PLN","cena_assistance":"80 PLN","gap_cena":"900 PLN","cena_przedluzonej_gwarancji":"1200 PLN","pochodzenie_polisy":"Dealer","rodzaj_polisy":"OC/AC/NNW/Assistance","data_sprzedazy_lubezpieczenia":"2024-03-01","data_sprzedazy_wznowienia":"2025-02-20"}
```');

    assertSameValue('Toyota', $data->carMake);
    assertSameValue('Corolla', $data->carModel);
    assertSameValue('123 000 PLN', $data->value);
    assertSameValue('Używany', $data->field('stan_pojazdu'));
    assertSameValue('WX12345', $data->field('nr_rejestracyjny'));
    assertSameValue('JT123', $data->field('vin'));
    assertSameValue('Leasing', $data->field('forma_wlasnosci'));
    assertSameValue('Hybryda', $data->field('typ_silnika'));
    assertSameValue('tak', $data->field('wspolposiadacz'));
    assertSameValue('Jan', $data->field('imie_wspolposiadacza'));
    assertSameValue('Kowalski', $data->field('nazwisko_wspolposiadacza'));
    assertSameValue('80010112345', $data->field('pesel_wspolposiadacza'));
    assertSameValue('ul. Prosta 1, Warszawa', $data->field('adres_wspolposiadacza'));
    assertSameValue('tak', $data->field('pakiet_ubezpieczeniowy'));
    assertSameValue('PZU', $data->field('towarzystwo_ubezpieczeniowe'));
    assertSameValue('POL-123', $data->field('nr_polisy'));
    assertSameValue('3200 PLN', $data->field('cena_pakietu'));
    assertSameValue('3300 PLN', $data->field('cena_wznowienia'));
    assertSameValue('100 PLN', $data->field('pc_cena'));
    assertSameValue('2100 PLN', $data->field('ac_cena'));
    assertSameValue('50 PLN', $data->field('cena_nnw'));
    assertSameValue('80 PLN', $data->field('cena_assistance'));
    assertSameValue('900 PLN', $data->field('gap_cena'));
    assertSameValue('1200 PLN', $data->field('cena_przedluzonej_gwarancji'));
    assertSameValue('Dealer', $data->field('pochodzenie_polisy'));
    assertSameValue('OC/AC/NNW/Assistance', $data->field('rodzaj_polisy'));
    assertSameValue('2025-02-20', $data->field('data_sprzedazy_wznowienia'));
});

test('policy data parser extracts JSON object from descriptive Claude response', function (): void {
    $data = (new PolicyDataResponseParser())->parse('Znalazłem dane pojazdu. Odpowiedź końcowa: {"marka":"Skoda","model":"Octavia","vin":"TMB123"} Dziękuję.');

    assertSameValue('Skoda', $data->field('marka'));
    assertSameValue('Octavia', $data->field('model'));
    assertSameValue('TMB123', $data->field('vin'));
});

test('policy data parser falls back to key value lines', function (): void {
    $data = (new PolicyDataResponseParser())->parse('
Stan pojazdu: Nowy
Numer rejestracyjny pojazdu: WE123AB
Marka pojazdu: Tesla
Model pojazdu: 3
Numer VIN: /
Forma własności: Własny
Wartość pojazdu brutto: 204000 PLN
Typ silnika: Elektryczny
Współposiadacz: nie
Towarzystwo ubezpieczeniowe: Warta
Numer polisy: WAR-456
Data końca polisy: 2026-05-20
Cena pakietu za pierwszy rok: 2500 PLN
Cena wznowienia: 2600 PLN
AC cena: 1900 PLN
Cena NNW: 40 PLN
Cena assistance: 70 PLN
GAP cena: 800 PLN
Cena przedłużonej gwarancji: /
Pochodzenie polisy: Wznowienie
Rodzaj polisy: OC/AC
Data sprzedaży wznowienia: 2026-05-01
');

    assertSameValue('Nowy', $data->field('stan_pojazdu'));
    assertSameValue('WE123AB', $data->field('nr_rejestracyjny'));
    assertSameValue('Tesla', $data->field('marka'));
    assertSameValue('3', $data->field('model'));
    assertSameValue(null, $data->field('vin'));
    assertSameValue('Własny', $data->field('forma_wlasnosci'));
    assertSameValue('204000 PLN', $data->field('wartosc_pojazdu_brutto'));
    assertSameValue('Elektryczny', $data->field('typ_silnika'));
    assertSameValue('nie', $data->field('wspolposiadacz'));
    assertSameValue('Warta', $data->field('towarzystwo_ubezpieczeniowe'));
    assertSameValue('WAR-456', $data->field('nr_polisy'));
    assertSameValue('2026-05-20', $data->field('data_konca_polisy'));
    assertSameValue('2500 PLN', $data->field('cena_pakietu'));
    assertSameValue('2600 PLN', $data->field('cena_wznowienia'));
    assertSameValue('1900 PLN', $data->field('ac_cena'));
    assertSameValue('40 PLN', $data->field('cena_nnw'));
    assertSameValue('70 PLN', $data->field('cena_assistance'));
    assertSameValue('800 PLN', $data->field('gap_cena'));
    assertSameValue(null, $data->field('cena_przedluzonej_gwarancji'));
    assertSameValue('Wznowienie', $data->field('pochodzenie_polisy'));
    assertSameValue('OC/AC', $data->field('rodzaj_polisy'));
    assertSameValue('2026-05-01', $data->field('data_sprzedazy_wznowienia'));
});

test('policy data parser ignores unrelated JSON before key value lines', function (): void {
    $data = (new PolicyDataResponseParser())->parse('
Najpierw notatka techniczna: {"status":"ok"}
Marka: Kia
Model: Niro
Wartość brutto: 150000 PLN
');

    assertSameValue('Kia', $data->field('marka'));
    assertSameValue('Niro', $data->field('model'));
    assertSameValue('150000 PLN', $data->field('wartosc_pojazdu_brutto'));
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
    assertSameValue(null, $client->requests[0]['thinking']);
    assertSameValue('json_schema', $client->requests[0]['outputConfig']['format']['type']);
    assertSameValue(false, $client->requests[0]['outputConfig']['format']['schema']['additionalProperties']);
    assertSameValue(['string', 'null'], $client->requests[0]['outputConfig']['format']['schema']['properties']['nr_rejestracyjny']['type']);
    assertSameValue(['string', 'null'], $client->requests[0]['outputConfig']['format']['schema']['properties']['marka']['type']);
    assertSameValue(['Własny', 'Leasing', 'Bank', 'Wynajem'], $client->requests[0]['outputConfig']['format']['schema']['properties']['forma_wlasnosci']['anyOf'][0]['enum']);
    assertSameValue(['Standardowy', 'Taxi'], $client->requests[0]['outputConfig']['format']['schema']['properties']['sposob_korzystania']['anyOf'][0]['enum']);
    assertSameValue('null', $client->requests[0]['outputConfig']['format']['schema']['properties']['sposob_korzystania']['anyOf'][1]['type']);
    assertSameValue(['Nowy', 'Używany', 'Nieznany'], $client->requests[0]['outputConfig']['format']['schema']['properties']['stan_pojazdu']['anyOf'][0]['enum']);
    assertSameValue('null', $client->requests[0]['outputConfig']['format']['schema']['properties']['stan_pojazdu']['anyOf'][1]['type']);
    assertSameValue(['tak', 'nie'], $client->requests[0]['outputConfig']['format']['schema']['properties']['pakiet_ubezpieczeniowy']['anyOf'][0]['enum']);
    assertSameValue(['tak', 'nie'], $client->requests[0]['outputConfig']['format']['schema']['properties']['wspolposiadacz']['anyOf'][0]['enum']);
    assertTrueValue(in_array('PZU', $client->requests[0]['outputConfig']['format']['schema']['properties']['towarzystwo_ubezpieczeniowe']['anyOf'][0]['enum'], true));
    assertSameValue(['string', 'null'], $client->requests[0]['outputConfig']['format']['schema']['properties']['nr_polisy']['type']);
    assertSameValue(['Partner InGreen', 'Asap', 'Wiktoria'], $client->requests[0]['outputConfig']['format']['schema']['properties']['kategoria_tu']['anyOf'][0]['enum']);
    assertSameValue(['string', 'null'], $client->requests[0]['outputConfig']['format']['schema']['properties']['cena_wznowienia']['type']);
    assertSameValue(['OC', 'OC/AC', 'OC/AC/NNW', 'OC/AC/NNW/Assistance', 'AC', 'NNW', 'Assistance', 'GAP', 'Przedłużona Gwarancja'], $client->requests[0]['outputConfig']['format']['schema']['properties']['rodzaj_polisy']['anyOf'][0]['enum']);
    assertTrueValue(in_array('nr_rejestracyjny', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(in_array('forma_wlasnosci', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(in_array('planowana_data_rejestracji', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(in_array('wspolposiadacz', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(in_array('pesel_wspolposiadacza', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(in_array('nr_polisy', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(in_array('rodzaj_polisy', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(in_array('data_sprzedazy_lubezpieczenia', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(in_array('data_sprzedazy_wznowienia', $client->requests[0]['outputConfig']['format']['schema']['required'], true));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'nr_rejestracyjny'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'stan_pojazdu'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'forma_wlasnosci'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'wspolposiadacz'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'towarzystwo_ubezpieczeniowe'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'nr_polisy'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'rodzaj_polisy'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'Nie wymyślaj danych'));
});

echo "\nAll tests passed.\n";
