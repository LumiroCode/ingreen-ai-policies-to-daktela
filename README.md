# Daktela Policy Attachment Downloader

Small plain PHP app that accepts a Daktela ticket id from the `ticket` query parameter, lists related PDF attachments, and downloads a selected attachment from Daktela only when the user clicks `odczytaj`.

## Requirements

- PHP 8.2+
- Composer
- PHP extensions: `curl`, `json`

## Setup

```bash
composer dump-autoload
cp config/app.example.php config/app.php
cp credentials/daktela-credentails.example.php credentials/daktela-credentails.php
cp credentials/claude-api-key.example.php credentials/claude-api-key.php
```

Edit `config/app.php`:

```php
<?php

return [
    'daktelaBaseUrl' => 'https://your-daktela-instance.example',
    'varDir' => __DIR__ . '/../var',
    'cacheDir' => __DIR__ . '/../var/cache',
    'maxDownloadBytes' => 25_000_000,
    'allowedUtilityOrigin' => 'https://ingreen.daktela.com',
    'utilitySecretKey' => 'use-a-long-random-shared-secret',
];
```

Put the Daktela access token in `credentials/daktela-credentails.php`:

```php
<?php

$daktelaAccessToken = 'your-token';

return $daktelaAccessToken;
```

Put the Claude API key in `credentials/claude-api-key.php`:

```php
<?php

$claudeApiKey = 'your-claude-api-key';

return $claudeApiKey;
```

The app does not read environment variables for its own runtime configuration. Runtime configuration comes from `config/app.php`; the Daktela access token comes from `credentials/daktela-credentails.php`; the Claude API key comes from `credentials/claude-api-key.php`.

## Run Locally

```bash
php -S 127.0.0.1:8080 -t public
```

Smoke test:

```bash
curl 'http://127.0.0.1:8080?ticket=123'
```

The ticket URL returns an HTML table with PDF attachments. The `odczytaj` button requests the selected attachment and returns it inline as `application/pdf`.

## Daktela Utility Access Restriction

Set `allowedUtilityOrigin` to the Daktela origin that is allowed to load this app and `utilitySecretKey` to a long random shared secret:

```php
'allowedUtilityOrigin' => 'https://ingreen.daktela.com',
'utilitySecretKey' => 'use-a-long-random-shared-secret',
```

Then include the key in the initial iframe URL:

```html
<iframe src="https://your-app.example/?ticket=123&utility_key=use-a-long-random-shared-secret"></iframe>
```

When configured, the app:

- rejects initial browser requests unless their referrer origin is `https://ingreen.daktela.com` and `utility_key` matches `utilitySecretKey`;
- sends `Content-Security-Policy: frame-ancestors https://ingreen.daktela.com` so browsers will not embed it on other sites;
- signs the rendered `odczytaj` actions with a short-lived ticket-specific `access_token`, so follow-up PDF requests from inside the utility do not expose the shared `utility_key`.

Treat `utilitySecretKey` as a password. Generate a high-entropy value, keep the app on HTTPS, and rotate the key if the iframe URL is exposed outside Daktela.

## Runtime Files

The app owns a local `var/` directory:

- `var/YYYY-MM-DD/YYYY-MM-DD.log` stores JSON-line application logs.
- `var/YYYY-MM-DD/YYYY-MM-DD.errors.log` stores PHP engine errors.
- `var/cache/` is reserved for cache data.

Only `.gitkeep` files are committed from `var/`; runtime contents are ignored.

## Tests

```bash
composer test
```

## Policy Data Extraction

The first extraction proof of concept is in `src/PolicyExtraction`. It exposes a small `PolicyDataExtractor` interface that accepts a local PDF path and returns car make, car model, value, and Claude's raw response.

```php
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\AnthropicClaudeMessagesClient;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;

$extractor = new ClaudePolicyDataExtractor(AnthropicClaudeMessagesClient::fromApiKey($config->claudeApiKey));
$data = $extractor->extract('/path/to/policy.pdf');
```

## Code Structure

The app is intentionally small:

- `public/index.php` wires configuration, logging, runtime directories, and the app.
- `src/WebhookApp.php` handles the ticket query parameter and the ticket policy download workflow.
- `src/TicketPdfAttachments.php` resolves PDF attachments related to a Daktela ticket.
- `src/PolicyExtraction` contains the PDF-to-policy-data extraction interface and Claude implementation.
- `src/Daktela/DaktelaClient.php` performs authenticated Daktela JSON and file requests.
- `templates/pdf-attachments-table.php` renders the attachment list.
- `src/Config`, `src/Logging`, and `src/Support` contain config loading, daily logs, and directory/error helpers.

## Current Scope

V1 supports downloading policy attachments for tickets only. Add other Daktela entity types inside `WebhookApp` when their attachment shape is known.

The local OpenAPI document exposes `has_attachment` and attachment metadata shapes, but not a dedicated attachment download endpoint. For that reason, the Daktela-specific discovery logic is kept in `WebhookApp` and can be replaced once the exact endpoint is known.
