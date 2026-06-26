<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaTabSignatureVerifier;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaModule;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaTicketPolicyValuesProvider;
use Ingreen\DaktelaPolicy\TicketPdfAttachments;
use Ingreen\DaktelaPolicy\WebhookAccessGuard;
use Ingreen\DaktelaPolicy\WebhookApp;

function jsonResponse(array $payload, int $status = 200): array
{
    return [
        'status' => $status,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($payload, JSON_THROW_ON_ERROR),
    ];
}

function pdfResponse(string $body = "%PDF-1.4\nbody"): array
{
    return [
        'status' => 200,
        'headers' => ['Content-Type' => 'application/pdf'],
        'body' => $body,
    ];
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

    return new WebhookApp(
        $config,
        tabSignatureVerifier(),
        new TicketPdfAttachments($daktela, $logger, $config->cacheDir),
        $extractor ?? new FakePolicyDataExtractor(),
        $logger,
        new DaktelaTicketPolicyValuesProvider($daktela, $logger)
    );
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

function daktelaFrameHeaders(): array
{
    return [
        'Referer' => 'https://ingreen.daktela.com/',
        'Sec-Fetch-Dest' => 'iframe',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Site' => 'cross-site',
    ];
}

function daktelaFrameHeadersWith(array $headers): array
{
    return array_merge(daktelaFrameHeaders(), $headers);
}

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

function signedEntryRequest(WebhookApp $app, string $ticketId, ?string $attachmentIndex = null): array
{
    $tab = daktelaTabParams($ticketId);

    return $app->handle($ticketId, $attachmentIndex, null, null, [], $tab['dt'], $tab['sig']);
}

function errorBody(array $response): array
{
    $payload = json_decode($response['body'], true);

    if (!is_array($payload)) {
        throw new RuntimeException('Expected JSON error response.');
    }

    return $payload;
}
