# Daktela Policy Attachment Downloader

Small plain PHP webhook app that accepts a Daktela event payload, resolves a ticket policy PDF attachment, downloads it from Daktela, and stores it in a local flat temp directory.

## Requirements

- PHP 8.2+
- Composer
- PHP extensions: `curl`, `json`

## Setup

```bash
composer dump-autoload
cp config/app.example.php config/app.php
cp credentials/daktela-credentails.example.php credentials/daktela-credentails.php
```

Edit `config/app.php`:

```php
<?php

return [
    'daktelaBaseUrl' => 'https://your-daktela-instance.example',
    'webhookSharedSecret' => 'replace-me',
    'varDir' => __DIR__ . '/../var',
    'cacheDir' => __DIR__ . '/../var/cache',
    'policyTempDir' => __DIR__ . '/../var/cache/policies',
    'maxDownloadBytes' => 25_000_000,
];
```

Put the Daktela access token in `credentials/daktela-credentails.php`:

```php
<?php

$daktelaAccessToken = 'your-token';

return $daktelaAccessToken;
```

The app does not read environment variables. Runtime configuration comes from `config/app.php`; the Daktela access token comes from `credentials/daktela-credentails.php`.

## Run Locally

```bash
php -S 127.0.0.1:8080 -t public
```

Smoke test:

```bash
curl -X POST http://127.0.0.1:8080 \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Secret: replace-me' \
  -d '{"entityType":"ticket","entityId":"123"}'
```

Successful responses return `status` as `downloaded` or `already_exists` and include the local `path`.

## Runtime Files

The app owns a local `var/` directory:

- `var/YYYY-MM-DD/YYYY-MM-DD.log` stores JSON-line application logs.
- `var/YYYY-MM-DD/YYYY-MM-DD.errors.log` stores PHP engine errors.
- `var/cache/` is reserved for cache data.
- `var/cache/policies/` stores downloaded PDFs by default.

Only `.gitkeep` files are committed from `var/`; runtime contents are ignored.

## Tests

```bash
composer test
```

## Code Structure

The app is intentionally small:

- `public/index.php` wires configuration, logging, runtime directories, and the app.
- `src/WebhookApp.php` handles the webhook and the ticket policy download workflow.
- `src/Daktela/DaktelaClient.php` performs authenticated Daktela JSON and file requests.
- `src/PolicyStore.php` creates deterministic local PDF filenames and skips duplicates.
- `src/Config`, `src/Logging`, and `src/Support` contain config loading, daily logs, and directory/error helpers.

## Current Scope

V1 supports `ticket` only. Add other Daktela entity types inside `WebhookApp` when their attachment shape is known.

The local OpenAPI document exposes `has_attachment` and attachment metadata shapes, but not a dedicated attachment download endpoint. For that reason, the Daktela-specific discovery logic is kept in `WebhookApp` and can be replaced once the exact endpoint is known.
