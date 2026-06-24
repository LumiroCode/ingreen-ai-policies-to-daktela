<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Support\AppException;

final class WebhookAccessGuard
{
    private const ACCESS_TOKEN_TTL_SECONDS = 900;
    private const DAKTELA_TAB_DT_FORMAT = 'YmdHis';

    public function __construct(private readonly AppConfig $config)
    {
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
        if ($accessToken !== null && $this->isValidAccessToken($ticketId, $accessToken)) {
            return;
        }

        if ($this->isValidEntryRequest($ticketId, $daktelaTabDt, $daktelaTabSig, $referrer, $requestHeaders)) {
            return;
        }

        throw new AppException(403, 'forbidden_utility_access', 'Access denied.',);
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
            (($n + 7919) * ($mi + 37))
            + (($s + 17) * 131071)
            + ($h * 65537)
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

    private function isFreshDaktelaTabDt(string $dt): bool
    {
        if (!$this->isValidDaktelaTabDt($dt)) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $requestSecond = (int) substr($dt, 12, 2);

        for ($hourOffset = 0; $hourOffset <= 2; $hourOffset++) {
            $candidate = $now->modify('+' . $hourOffset . ' hours');

            if ($candidate->format('YmdHi') === substr($dt, 0, 12)
                && abs((int) $candidate->format('s') - $requestSecond) <= 1
            ) {
                return true;
            }
        }

        return false;
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
