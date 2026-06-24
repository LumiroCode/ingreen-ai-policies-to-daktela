<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;

final class WebhookAccessGuard
{
    private const ACCESS_TOKEN_TTL_SECONDS = 900;
    private const DAKTELA_TAB_DT_FORMAT = 'YmdHis';
    private const DAKTELA_TAB_ALLOWED_SKEW_SECONDS = 5;

    public function __construct(
        private readonly AppConfig $config,
        private readonly ?AppLogger $logger = null
    ) {
    }

    /**
     * @param array<string,string> $requestHeaders
     */
    public function assertAccessAllowed(
        string $ticketId,
        ?string $accessToken,
        ?string $referrer,
        array $requestHeaders = [],
        ?string $daktelaTabDt = null,
        ?string $daktelaTabSig = null
    ): void
    {
        $accessTokenAttempt = $this->accessTokenAttemptDiagnostics($ticketId, $accessToken);

        if ($accessTokenAttempt['valid'] === true) {
            return;
        }

        $entryAttempt = $this->entryRequestAttemptDiagnostics($ticketId, $daktelaTabDt, $daktelaTabSig, $referrer, $requestHeaders);

        if ($entryAttempt['valid'] === true) {
            return;
        }

        $this->logDeniedAccess($ticketId, $accessToken, $accessTokenAttempt, $entryAttempt, $referrer, $requestHeaders, $daktelaTabDt, $daktelaTabSig);

        throw new AppException(403, 'forbidden_utility_access', 'Access denied.', [
            'allowedOrigin' => $this->config->allowedUtilityOrigin,
            'requiresDaktelaTabSignature' => true,
        ]);
    }

    public function makeDaktelaTabSig(string $dt, string $ticket): ?string
    {
        if (!$this->isValidDaktelaTabDt($dt)) {
            return null;
        }

        if (!preg_match('/^\d+$/', $ticket)) {
            return null;
        }

        $y = (int) substr($dt, 0, 4);
        $mo = (int) substr($dt, 4, 2);
        $d = (int) substr($dt, 6, 2);
        $h = (int) substr($dt, 8, 2);
        $mi = (int) substr($dt, 10, 2);
        $s = (int) substr($dt, 12, 2);
        $n = (int) $ticket;

        $p1 = intdiv(
            ($y * 131)
            + ($mo * 197)
            + ($d * 389)
            + ($h * 769)
            + ($mi * 1543)
            + ($s * 6151)
            + ($n * 3079)
            + 88237,
            3
        );

        $p2 = intdiv(
            (($n + 7919) * ($s + 37))
            + ($mi * 65537)
            + ($h * 8191)
            + 4289843,
            5
        );

        $p3 = intdiv(
            (($y + $mo + $d + $h + $mi + $s + $n + 104729) * 7919),
            7
        );

        return sprintf('%08d.%08d.%08d', $p1, $p2, $p3);
    }

    public function accessTokenForTicket(string $ticketId): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'ticket' => $ticketId,
            'expires' => time() + self::ACCESS_TOKEN_TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->accessTokenSignature($payload);
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    public function securityHeaders(array $headers): array
    {
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['Referrer-Policy'] = 'same-origin';

        if ($this->config->allowedUtilityOrigin !== null) {
            $headers['Content-Security-Policy'] = "frame-ancestors " . $this->config->allowedUtilityOrigin;
        }

        return $headers;
    }

    /**
     * @param array<string,string> $requestHeaders
     */
    private function isValidEntryRequest(
        string $ticketId,
        ?string $daktelaTabDt,
        ?string $daktelaTabSig,
        ?string $referrer,
        array $requestHeaders
    ): bool
    {
        if (!$this->isValidDaktelaTabRequest($ticketId, $daktelaTabDt, $daktelaTabSig)) {
            return false;
        }

        $referrer ??= $this->headerValue($requestHeaders, 'Referer');

        return $this->config->allowedUtilityOrigin === null
            || (
                $referrer !== null
                && $this->isAllowedReferrer($referrer)
                && $this->hasExpectedFrameNavigationHeaders($requestHeaders)
            );
    }

    /**
     * @param array<string,string> $requestHeaders
     * @return array{valid:bool,reasons:list<string>,checks:array<string,mixed>}
     */
    private function entryRequestAttemptDiagnostics(
        string $ticketId,
        ?string $daktelaTabDt,
        ?string $daktelaTabSig,
        ?string $referrer,
        array $requestHeaders
    ): array
    {
        $reasons = [];
        $checks = [
            'dtPresent' => $daktelaTabDt !== null,
            'sigPresent' => $daktelaTabSig !== null,
            'dtFormatValid' => false,
            'sigMatches' => false,
            'dtFresh' => false,
            'allowedOriginConfigured' => $this->config->allowedUtilityOrigin !== null,
            'referrerPresent' => false,
            'referrerAllowed' => $this->config->allowedUtilityOrigin === null,
            'frameNavigationHeadersValid' => $this->config->allowedUtilityOrigin === null,
        ];

        if ($daktelaTabDt === null) {
            $reasons[] = 'missing_dt';
        } else {
            $checks['dtFormatValid'] = $this->isValidDaktelaTabDt($daktelaTabDt);

            if ($checks['dtFormatValid'] !== true) {
                $reasons[] = 'invalid_dt_format';
            }
        }

        if ($daktelaTabSig === null) {
            $reasons[] = 'missing_sig';
        }

        if ($checks['dtFormatValid'] === true && $daktelaTabSig !== null) {
            $expected = $this->makeDaktelaTabSig((string) $daktelaTabDt, $ticketId);
            $checks['sigMatches'] = $expected !== null && hash_equals($expected, $daktelaTabSig);

            if ($checks['sigMatches'] !== true) {
                $reasons[] = 'sig_mismatch';
            }

            $checks['dtFresh'] = $this->isFreshDaktelaTabDt((string) $daktelaTabDt);

            if ($checks['dtFresh'] !== true) {
                $reasons[] = 'stale_dt';
            }
        }

        if ($this->config->allowedUtilityOrigin !== null) {
            $effectiveReferrer = $referrer ?? $this->headerValue($requestHeaders, 'Referer');
            $checks['referrerPresent'] = $effectiveReferrer !== null;

            if ($effectiveReferrer === null) {
                $reasons[] = 'missing_referrer';
            } else {
                $checks['referrerAllowed'] = $this->isAllowedReferrer($effectiveReferrer);

                if ($checks['referrerAllowed'] !== true) {
                    $reasons[] = 'referrer_not_allowed';
                }
            }

            $checks['frameNavigationHeadersValid'] = $this->hasExpectedFrameNavigationHeaders($requestHeaders);

            if ($checks['frameNavigationHeadersValid'] !== true) {
                $reasons[] = 'invalid_frame_navigation_headers';
            }
        }

        return [
            'valid' => $reasons === [],
            'reasons' => $reasons,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string,string> $requestHeaders
     */
    private function hasExpectedFrameNavigationHeaders(array $requestHeaders): bool
    {
        return strtolower($this->headerValue($requestHeaders, 'Sec-Fetch-Dest') ?? '') === 'iframe'
            && strtolower($this->headerValue($requestHeaders, 'Sec-Fetch-Mode') ?? '') === 'navigate'
            && strtolower($this->headerValue($requestHeaders, 'Sec-Fetch-Site') ?? '') === 'cross-site';
    }

    /**
     * @param array<string,string> $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (strtolower($headerName) === strtolower($name)) {
                return trim($value);
            }
        }

        return null;
    }

    private function isValidDaktelaTabRequest(string $ticketId, ?string $dt, ?string $sig): bool
    {
        if ($dt === null || $sig === null) {
            return false;
        }

        $expected = $this->makeDaktelaTabSig($dt, $ticketId);

        return $expected !== null
            && hash_equals($expected, $sig)
            && $this->isFreshDaktelaTabDt($dt);
    }

    /**
     * @return array{valid:bool,reasons:list<string>,checks:array<string,mixed>}
     */
    private function accessTokenAttemptDiagnostics(string $ticketId, ?string $token): array
    {
        if ($token === null || trim($token) === '') {
            return [
                'valid' => false,
                'reasons' => ['missing_access_token'],
                'checks' => [
                    'present' => false,
                ],
            ];
        }

        $parts = explode('.', $token, 2);
        $checks = [
            'present' => true,
            'hasPayloadAndSignature' => count($parts) === 2,
            'signatureMatches' => false,
            'payloadJsonValid' => false,
            'ticketMatches' => false,
            'expiresPresent' => false,
            'notExpired' => false,
        ];
        $reasons = [];

        if ($checks['hasPayloadAndSignature'] !== true) {
            $reasons[] = 'malformed_access_token';

            return [
                'valid' => false,
                'reasons' => $reasons,
                'checks' => $checks,
            ];
        }

        $checks['signatureMatches'] = hash_equals($this->accessTokenSignature($parts[0]), $parts[1]);

        if ($checks['signatureMatches'] !== true) {
            $reasons[] = 'access_token_signature_mismatch';
        }

        $payload = json_decode($this->base64UrlDecode($parts[0]), true);
        $checks['payloadJsonValid'] = is_array($payload);

        if (!is_array($payload)) {
            $reasons[] = 'invalid_access_token_payload';

            return [
                'valid' => false,
                'reasons' => $reasons,
                'checks' => $checks,
            ];
        }

        $checks['ticketMatches'] = ($payload['ticket'] ?? null) === $ticketId;
        $checks['expiresPresent'] = is_int($payload['expires'] ?? null);
        $checks['notExpired'] = $checks['expiresPresent'] === true && $payload['expires'] >= time();

        if ($checks['ticketMatches'] !== true) {
            $reasons[] = 'access_token_ticket_mismatch';
        }

        if ($checks['expiresPresent'] !== true) {
            $reasons[] = 'missing_access_token_expiry';
        } elseif ($checks['notExpired'] !== true) {
            $reasons[] = 'expired_access_token';
        }

        return [
            'valid' => $reasons === [],
            'reasons' => $reasons,
            'checks' => $checks,
        ];
    }

    /**
     * @param array{valid:bool,reasons:list<string>,checks:array<string,mixed>} $accessTokenAttempt
     * @param array{valid:bool,reasons:list<string>,checks:array<string,mixed>} $entryAttempt
     * @param array<string,string> $requestHeaders
     */
    private function logDeniedAccess(
        string $ticketId,
        ?string $accessToken,
        array $accessTokenAttempt,
        array $entryAttempt,
        ?string $referrer,
        array $requestHeaders,
        ?string $daktelaTabDt,
        ?string $daktelaTabSig
    ): void
    {
        $context = [
            'ticket' => $ticketId,
            'allowedOrigin' => $this->config->allowedUtilityOrigin,
            'attempt' => [
                'accessToken' => [
                    'present' => $accessTokenAttempt['checks']['present'] ?? false,
                    'fingerprint' => $this->fingerprintNullable($accessToken),
                    'reasons' => $accessTokenAttempt['reasons'],
                    'checks' => $accessTokenAttempt['checks'],
                ],
                'daktelaTabSignature' => [
                    'dt' => $daktelaTabDt,
                    'sigPresent' => $daktelaTabSig !== null,
                    'sigFingerprint' => $this->fingerprintNullable($daktelaTabSig),
                    'reasons' => $entryAttempt['reasons'],
                    'checks' => $entryAttempt['checks'],
                ],
                'referrerArgument' => $referrer,
                'headers' => $this->accessLogHeaders($requestHeaders),
            ],
            'denialReasons' => [
                'accessToken' => $accessTokenAttempt['reasons'],
                'daktelaTabSignature' => $entryAttempt['reasons'],
            ],
        ];

        if ($this->logger !== null) {
            $this->logger->warning('Daktela tab access denied.', $context);
            return;
        }

        error_log(json_encode([
            'time' => gmdate('c'),
            'level' => 'warning',
            'message' => 'Daktela tab access denied.',
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string|null>
     */
    private function accessLogHeaders(array $headers): array
    {
        return [
            'Referer' => $this->headerValue($headers, 'Referer'),
            'Sec-Fetch-Dest' => $this->headerValue($headers, 'Sec-Fetch-Dest'),
            'Sec-Fetch-Mode' => $this->headerValue($headers, 'Sec-Fetch-Mode'),
            'Sec-Fetch-Site' => $this->headerValue($headers, 'Sec-Fetch-Site'),
        ];
    }

    private function fingerprintNullable(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(hash('sha256', $value), 0, 16);
    }

    private function isFreshDaktelaTabDt(string $dt): bool
    {
        if (!$this->isValidDaktelaTabDt($dt)) {
            return false;
        }

        $requestTime = \DateTimeImmutable::createFromFormat('!' . self::DAKTELA_TAB_DT_FORMAT, $dt, new \DateTimeZone('UTC'));

        if (!$requestTime instanceof \DateTimeImmutable) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $age = abs($now->getTimestamp() - $requestTime->getTimestamp());

        return $age <= self::DAKTELA_TAB_ALLOWED_SKEW_SECONDS;
    }

    private function isValidDaktelaTabDt(string $dt): bool
    {
        if (!preg_match('/^\d{14}$/', $dt)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . self::DAKTELA_TAB_DT_FORMAT, $dt, new \DateTimeZone('UTC'));

        return $date instanceof \DateTimeImmutable
            && $date->format(self::DAKTELA_TAB_DT_FORMAT) === $dt;
    }

    private function isAllowedReferrer(string $referrer): bool
    {
        $allowed = parse_url($this->config->allowedUtilityOrigin ?? '');
        $actual = parse_url(trim($referrer));

        if (!is_array($allowed) || !is_array($actual)) {
            return false;
        }

        $allowedScheme = strtolower((string) ($allowed['scheme'] ?? ''));
        $allowedHost = strtolower((string) ($allowed['host'] ?? ''));
        $actualScheme = strtolower((string) ($actual['scheme'] ?? ''));
        $actualHost = strtolower((string) ($actual['host'] ?? ''));

        if ($allowedScheme === '' || $allowedHost === '' || $actualScheme === '' || $actualHost === '') {
            return false;
        }

        return $actualScheme === $allowedScheme
            && $actualHost === $allowedHost
            && (int) ($actual['port'] ?? self::defaultPort($actualScheme)) === (int) ($allowed['port'] ?? self::defaultPort($allowedScheme));
    }

    private static function defaultPort(string $scheme): int
    {
        return strtolower($scheme) === 'http' ? 80 : 443;
    }

    private function isValidAccessToken(string $ticketId, string $token): bool
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || !hash_equals($this->accessTokenSignature($parts[0]), $parts[1])) {
            return false;
        }

        $payload = json_decode($this->base64UrlDecode($parts[0]), true);

        return is_array($payload)
            && ($payload['ticket'] ?? null) === $ticketId
            && is_int($payload['expires'] ?? null)
            && $payload['expires'] >= time();
    }

    private function accessTokenSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->config->daktelaApiToken);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
