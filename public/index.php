<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaModule;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaTabSignatureVerifier;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\AnthropicClaudeMessagesClient;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;
use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\Support\DirectoryPreparer;
use Ingreen\DaktelaPolicy\TicketPdfAttachments;
use Ingreen\DaktelaPolicy\WebhookApp;

require dirname(__DIR__) . '/vendor/autoload.php';

$logger = new AppLogger();

try {
    $config = AppConfig::fromFiles();
    $dailyLogPaths = DailyLogPaths::forToday($config->varDir);
    (new DirectoryPreparer())->ensureAll([
        $config->varDir,
        $dailyLogPaths->directory(),
        $config->cacheDir,
    ]);
    ini_set('log_errors', '1');
    ini_set('error_log', $dailyLogPaths->errorsFile());
    $logger = new AppLogger($dailyLogPaths->logsFile());
    $daktela = new DaktelaModule($config->daktelaBaseUrl, $config->daktelaApiToken, logger: $logger);
    $daktelaTabSignatureVerifier = new DaktelaTabSignatureVerifier();
    $app = new WebhookApp(
        $config,
        $daktelaTabSignatureVerifier,
        new TicketPdfAttachments($daktela, $logger, $config->cacheDir),
        new ClaudePolicyDataExtractor(AnthropicClaudeMessagesClient::fromApiKey($config->claudeApiKey)),
        $logger
    );

    sendResponse($app->handle(
        queryStringParam('ticket'),
        queryStringParam('attachment'),
        queryStringParam('access_token'),
        $_SERVER['HTTP_REFERER'] ?? null,
        requestHeaders(),
        queryStringParam('dt'),
        queryStringParam('sig'),
        queryStringParam('confirmation'),
        queryArrayParam('policy_data'),
        queryArrayParam('policy_locked'),
        queryStringParam('title'),
        queryBoolParam('refresh_attachments'),
        queryBoolParam('policy_pdf')
    ));
} catch (AppException $exception) {
    sendJson(['status' => $exception->statusCode(), 'body' => [
        'error' => [
            'code' => $exception->errorCode(),
            'message' => $exception->getMessage(),
            'details' => $exception->details(),
        ],
    ]]);
} catch (Throwable $exception) {
    $logger->exception($exception);
    sendJson(['status' => 500, 'body' => [
        'error' => [
            'code' => 'internal_error',
            'message' => 'Internal server error.',
        ],
    ]]);
}

function queryStringParam(string $name): ?string
{
    $value = $_GET[$name] ?? null;

    return is_string($value) ? $value : null;
}

function queryBoolParam(string $name): bool
{
    $value = $_GET[$name] ?? null;

    return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes'], true);
}

/**
 * @return array<string,string>|null
 */
function queryArrayParam(string $name): ?array
{
    $value = $_GET[$name] ?? null;

    if (!is_array($value)) {
        return null;
    }

    $strings = [];

    foreach ($value as $key => $item) {
        if (is_string($key) && is_string($item)) {
            $strings[$key] = $item;
        }
    }

    return $strings;
}

/**
 * @return array<string,string>
 */
function requestHeaders(): array
{
    $headers = [];

    foreach ([
        'Referer' => 'HTTP_REFERER',
        'Sec-Fetch-Dest' => 'HTTP_SEC_FETCH_DEST',
        'Sec-Fetch-Mode' => 'HTTP_SEC_FETCH_MODE',
        'Sec-Fetch-Site' => 'HTTP_SEC_FETCH_SITE',
    ] as $name => $serverKey) {
        $value = $_SERVER[$serverKey] ?? null;

        if (is_string($value)) {
            $headers[$name] = $value;
        }
    }

    return $headers;
}

/**
 * @param array{status:int,headers:array<string,string>,body:string} $response
 */
function sendResponse(array $response): void
{
    http_response_code($response['status']);

    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }

    echo $response['body'];
}

/**
 * @param array{status:int,body:array<string,mixed>} $response
 */
function sendJson(array $response): void
{
    http_response_code($response['status']);
    header('Content-Type: application/json');
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
