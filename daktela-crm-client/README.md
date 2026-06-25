# Daktela CRM Client

Standalone package shell for searching and writing Daktela CRM records and updating Daktela tickets.

This package is intentionally separate from the parent app. It has its own Composer metadata, namespace, source tree, tests, and dependency list.

## Requirements

- PHP 8.2+
- Composer
- PHP extension: `json`

## Install

From this package directory:

```bash
composer install
composer test
```

The parent repository does not autoload this package. Add it to another project as a Composer package or path repository when integration is needed.

## Public Client Contract

The package exposes `Ingreen\DaktelaCrmClient\DaktelaCrmClientInterface`:

```php
use Ingreen\DaktelaCrmClient\Dto\CarRecordInput;
use Ingreen\DaktelaCrmClient\Dto\CrmRecord;
use Ingreen\DaktelaCrmClient\Dto\PolicyRecordInput;
use Ingreen\DaktelaCrmClient\Dto\TicketRecord;
use Ingreen\DaktelaCrmClient\Dto\TicketUpdateInput;

interface DaktelaCrmClientInterface
{
    public function findCarRecordByVinOrNumber(?string $vin, ?string $carNumber): ?CrmRecord;

    public function upsertCarRecord(CarRecordInput $input): CrmRecord;

    public function findPolicyRecordByCarNumber(string $carNumber): ?CrmRecord;

    public function upsertPolicyRecord(PolicyRecordInput $input): CrmRecord;

    public function updateTicketData(string $ticketName, TicketUpdateInput $input): TicketRecord;
}
```

The domain methods are not implemented yet. They validate basic required identifiers and then throw `NotImplementedException`.

## HTTP Shell

`Http\DaktelaHttpClient` centralizes Guzzle calls, JSON response handling, and the Daktela `X-AUTH-TOKEN-OPENAPI` header for:

- `GET /api/v6/crmRecords`
- `POST /api/v6/crmRecords`
- `PUT /api/v6/crmRecords/{name}`
- `PUT /api/v6/tickets/{name}`

The CRM `PUT` helper is present so the future `upsert*Record` implementation can perform true update-or-create behavior.

## Configuration

```php
use Ingreen\DaktelaCrmClient\Config\DaktelaClientConfig;

$config = new DaktelaClientConfig(
    baseUrl: 'https://your-daktela.example',
    apiToken: 'token',
    carRecordTypeName: 'CAR',
    policyRecordTypeName: 'POLICY',
    carVinFieldCode: 'vin',
    carNumberFieldCode: 'car_number',
    policyCarNumberFieldCode: 'policy_car_number',
    ticketCustomFieldCodes: ['processed' => 'cf_processed']
);
```

Result DTOs preserve raw Daktela payloads so later implementation can map additional fields without losing data.
