<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
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
    $daktela = new DaktelaClient($config->daktelaBaseUrl, $config->daktelaApiToken);
    $app = new WebhookApp(
        $config,
        $daktela,
        new TicketPdfAttachments($daktela, $logger),
        $logger
    );

    sendResponse($app->handle(
        queryStringParam('ticket'),
        queryStringParam('attachment'),
        queryStringParam('access_token'),
        $_SERVER['HTTP_REFERER'] ?? null
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
