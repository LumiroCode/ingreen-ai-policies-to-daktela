<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
use Ingreen\DaktelaPolicy\PolicyStore;
use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\Support\DirectoryPreparer;
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
        $config->policyTempDir,
    ]);
    ini_set('log_errors', '1');
    ini_set('error_log', $dailyLogPaths->errorsFile());
    $logger = new AppLogger($dailyLogPaths->logsFile());
    $app = new WebhookApp(
        $config,
        new DaktelaClient($config->daktelaBaseUrl, $config->daktelaApiToken),
        new PolicyStore($config->policyTempDir),
        $logger
    );

    sendJson($app->handle($_GET['ticket'] ?? null));
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

/**
 * @param array{status:int,body:array<string,mixed>} $response
 */
function sendJson(array $response): void
{
    http_response_code($response['status']);
    header('Content-Type: application/json');
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
