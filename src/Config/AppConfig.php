<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Config;

use Ingreen\DaktelaPolicy\Support\AppException;

final class AppConfig
{
    public const DEFAULT_MAX_DOWNLOAD_BYTES = 25_000_000;
    public const DEFAULT_APP_CONFIG_PATH = __DIR__ . '/../../config/app.php';
    public const DEFAULT_CREDENTIALS_PATH = __DIR__ . '/../../credentials/daktela-credentails.php';

    public function __construct(
        public readonly string $daktelaBaseUrl,
        public readonly string $daktelaApiToken,
        public readonly string $varDir,
        public readonly string $cacheDir,
        public readonly string $policyTempDir,
        public readonly int $maxDownloadBytes = self::DEFAULT_MAX_DOWNLOAD_BYTES
    ) {
    }

    public static function fromFiles(
        string $appConfigPath = self::DEFAULT_APP_CONFIG_PATH,
        string $credentialsPath = self::DEFAULT_CREDENTIALS_PATH
    ): self
    {
        $settings = self::loadAppConfig($appConfigPath);

        return new self(
            rtrim(self::requiredString($settings, 'daktelaBaseUrl', $appConfigPath), '/'),
            self::tokenFromCredentialsFile($credentialsPath),
            self::requiredString($settings, 'varDir', $appConfigPath),
            self::requiredString($settings, 'cacheDir', $appConfigPath),
            self::requiredString($settings, 'policyTempDir', $appConfigPath),
            isset($settings['maxDownloadBytes'])
                ? self::positiveInt($settings['maxDownloadBytes'], 'maxDownloadBytes', $appConfigPath)
                : self::DEFAULT_MAX_DOWNLOAD_BYTES
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadAppConfig(string $path): array
    {
        if (!is_file($path)) {
            throw new AppException(500, 'missing_configuration', 'Required app config file is missing.', [
                'path' => $path,
            ]);
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new AppException(500, 'invalid_configuration', 'App config file must return an array.', [
                'path' => $path,
            ]);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function requiredString(array $settings, string $name, string $path): string
    {
        $value = $settings[$name] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new AppException(500, 'missing_configuration', 'Required config value is missing.', [
                'path' => $path,
                'name' => $name,
            ]);
        }

        return trim($value);
    }

    private static function tokenFromCredentialsFile(string $path): string
    {
        if (!is_file($path)) {
            throw new AppException(500, 'missing_credentials_file', 'Daktela credentials file is missing.', [
                'path' => $path,
            ]);
        }

        $daktelaAccessToken = null;
        $credentials = require $path;

        if (is_string($credentials) && trim($credentials) !== '') {
            return trim($credentials);
        }

        if (is_array($credentials) && isset($credentials['accessToken']) && is_string($credentials['accessToken']) && trim($credentials['accessToken']) !== '') {
            return trim($credentials['accessToken']);
        }

        if (is_string($daktelaAccessToken) && trim($daktelaAccessToken) !== '') {
            return trim($daktelaAccessToken);
        }

        throw new AppException(500, 'invalid_credentials_file', 'Daktela credentials file must define a non-empty access token.', [
            'path' => $path,
            'expectedVariable' => '$daktelaAccessToken',
        ]);
    }

    private static function positiveInt(mixed $value, string $name, string $path): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (!is_string($value) || !ctype_digit($value) || (int) $value <= 0) {
            throw new AppException(500, 'invalid_configuration', 'Config value must be a positive integer.', [
                'path' => $path,
                'name' => $name,
            ]);
        }

        return (int) $value;
    }
}
