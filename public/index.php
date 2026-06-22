<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Application\PolicyDownloadService;
use Ingreen\DaktelaPolicy\Attachment\AttachmentSelector;
use Ingreen\DaktelaPolicy\Attachment\FileDownloader;
use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\CurlHttpClient;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Entity\AttachmentResolverRegistry;
use Ingreen\DaktelaPolicy\Entity\TicketAttachmentResolver;
use Ingreen\DaktelaPolicy\Http\Response;
use Ingreen\DaktelaPolicy\Http\WebhookController;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Logging\DailyLogPaths;
use Ingreen\DaktelaPolicy\Storage\LocalPolicyStorage;
use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\Support\DirectoryPreparer;

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
    $daktelaClient = new DaktelaClient($config->daktelaBaseUrl, $config->daktelaApiToken, new CurlHttpClient());
    $ticketResolver = new TicketAttachmentResolver($daktelaClient, $logger);
    $service = new PolicyDownloadService(
        new AttachmentResolverRegistry(['ticket' => $ticketResolver]),
        new AttachmentSelector(),
        new FileDownloader($daktelaClient, $config->maxDownloadBytes),
        new LocalPolicyStorage($config->policyTempDir),
        $logger
    );

    $controller = new WebhookController($config->webhookSharedSecret, $service, $logger);
    $controller->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', getallheaders() ?: [], file_get_contents('php://input') ?: '')->send();
} catch (AppException $exception) {
    (new Response($exception->statusCode(), [
        'error' => [
            'code' => $exception->errorCode(),
            'message' => $exception->getMessage(),
            'details' => $exception->details(),
        ],
    ]))->send();
} catch (Throwable $exception) {
    $logger->exception($exception);
    (new Response(500, [
        'error' => [
            'code' => 'internal_error',
            'message' => 'Internal server error.',
        ],
    ]))->send();
}
