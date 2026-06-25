<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ingreen\DaktelaCrmClient\Config\DaktelaClientConfig;
use Ingreen\DaktelaCrmClient\DaktelaCrmClient;
use Ingreen\DaktelaCrmClient\Dto\CarRecordInput;
use Ingreen\DaktelaCrmClient\Dto\CrmRecord;
use Ingreen\DaktelaCrmClient\Dto\PolicyRecordInput;
use Ingreen\DaktelaCrmClient\Dto\TicketRecord;
use Ingreen\DaktelaCrmClient\Dto\TicketUpdateInput;
use Ingreen\DaktelaCrmClient\Exception\DaktelaCrmClientException;
use Ingreen\DaktelaCrmClient\Exception\DaktelaHttpException;
use Ingreen\DaktelaCrmClient\Exception\NotImplementedException;
use Ingreen\DaktelaCrmClient\Http\DaktelaHttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param callable(): void $test
 */
function test(string $name, callable $test): void
{
    try {
        $test();
        echo '.';
    } catch (Throwable $exception) {
        echo "\nFAIL: {$name}\n";
        echo $exception::class . ': ' . $exception->getMessage() . "\n";
        exit(1);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new RuntimeException($message !== '' ? $message : 'Expected true.');
    }
}

/**
 * @param class-string<Throwable> $class
 * @param callable(): void $callback
 */
function assertThrows(string $class, callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return $exception;
        }

        throw new RuntimeException('Expected ' . $class . ', got ' . $exception::class);
    }

    throw new RuntimeException('Expected exception ' . $class . '.');
}

function config(): DaktelaClientConfig
{
    return new DaktelaClientConfig(
        'https://daktela.example',
        'api-token',
        'CAR',
        'POLICY',
        'vin',
        'car_number',
        'policy_car_number',
        ['processed' => 'cf_processed']
    );
}

function domainClient(): DaktelaCrmClient
{
    return new DaktelaCrmClient(new DaktelaHttpClient(config(), new Client([
        'handler' => HandlerStack::create(new MockHandler()),
        'base_uri' => 'https://daktela.example/',
        'http_errors' => false,
    ])));
}

test('config and client can be instantiated', function (): void {
    $config = config();
    $client = domainClient();

    assertSameValue('https://daktela.example', $config->baseUrl);
    assertTrueValue($client instanceof DaktelaCrmClient);
});

test('input DTOs validate required business identifiers', function (): void {
    $car = new CarRecordInput(null, 'Skoda Octavia', 'VIN123', null, 'Description', ['color' => 'green']);
    $policy = new PolicyRecordInput('crm-1', 'Policy 123', '1AB2345', 'POL123');
    $ticketUpdate = new TicketUpdateInput(stage: 'OPEN', priority: 'HIGH', customFields: ['cf_policy' => 'POL123']);

    assertSameValue('VIN123', $car->vin);
    assertSameValue('crm-1', $policy->name);
    assertSameValue('HIGH', $ticketUpdate->priority);

    assertThrows(InvalidArgumentException::class, static fn (): CarRecordInput => new CarRecordInput(null, 'Car', null, ''));
    assertThrows(InvalidArgumentException::class, static fn (): PolicyRecordInput => new PolicyRecordInput(null, 'Policy', ''));
});

test('result DTOs preserve raw Daktela payload', function (): void {
    $crm = CrmRecord::fromDaktelaPayload([
        'name' => 'crm-1',
        'title' => 'Car',
        'type' => ['name' => 'CAR'],
        'stage' => 'OPEN',
        'customFields' => ['vin' => 'VIN123'],
    ]);
    $ticket = TicketRecord::fromDaktelaPayload([
        'name' => 123,
        'title' => 'Ticket',
        'category' => ['name' => 'Claims'],
        'stage' => 'OPEN',
        'priority' => 'MEDIUM',
        'customFields' => ['cf' => 'value'],
    ]);

    assertSameValue('crm-1', $crm->name);
    assertSameValue('CAR', $crm->type);
    assertSameValue('VIN123', $crm->raw['customFields']['vin']);
    assertSameValue(123, $ticket->name);
    assertSameValue('Claims', $ticket->category);
});

test('public domain methods fail explicitly as not implemented', function (): void {
    $client = domainClient();

    assertThrows(NotImplementedException::class, static fn (): ?CrmRecord => $client->findCarRecordByVinOrNumber('VIN123', null));
    assertThrows(NotImplementedException::class, static fn (): CrmRecord => $client->upsertCarRecord(new CarRecordInput(null, 'Car', 'VIN123', null)));
    assertThrows(NotImplementedException::class, static fn (): ?CrmRecord => $client->findPolicyRecordByCarNumber('1AB2345'));
    assertThrows(NotImplementedException::class, static fn (): CrmRecord => $client->upsertPolicyRecord(new PolicyRecordInput(null, 'Policy', '1AB2345')));
    assertThrows(NotImplementedException::class, static fn (): TicketRecord => $client->updateTicketData('123', new TicketUpdateInput(stage: 'OPEN')));
});

test('lookup placeholders validate blank search inputs before not implemented failure', function (): void {
    $client = domainClient();

    assertThrows(InvalidArgumentException::class, static fn (): ?CrmRecord => $client->findCarRecordByVinOrNumber(null, ''));
    assertThrows(InvalidArgumentException::class, static fn (): ?CrmRecord => $client->findPolicyRecordByCarNumber(''));
    assertThrows(InvalidArgumentException::class, static fn (): TicketRecord => $client->updateTicketData('', new TicketUpdateInput()));
});

test('http client sends Daktela auth header and returns response result', function (): void {
    $history = [];
    $handler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'error' => [],
            'result' => ['data' => [['name' => 'crm-1']]],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($history));
    $guzzle = new Client([
        'handler' => $stack,
        'base_uri' => 'https://daktela.example/',
        'http_errors' => false,
    ]);

    $client = new DaktelaHttpClient(config(), $guzzle);
    $result = $client->getCrmRecords(['pageSize' => 1]);

    assertSameValue('crm-1', $result['data'][0]['name']);
    assertSameValue('api-token', $history[0]['request']->getHeaderLine('X-AUTH-TOKEN-OPENAPI'));
    assertSameValue('/api/v6/crmRecords', $history[0]['request']->getUri()->getPath());
    assertSameValue('pageSize=1', $history[0]['request']->getUri()->getQuery());
});

test('http client reports invalid JSON and non-success status', function (): void {
    $invalidJsonClient = new DaktelaHttpClient(config(), new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], 'not-json'),
        ])),
        'base_uri' => 'https://daktela.example/',
        'http_errors' => false,
    ]));
    $failingClient = new DaktelaHttpClient(config(), new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(403, ['Content-Type' => 'application/json'], '{"error":["forbidden"]}'),
        ])),
        'base_uri' => 'https://daktela.example/',
        'http_errors' => false,
    ]));

    assertThrows(DaktelaCrmClientException::class, static fn (): array|null => $invalidJsonClient->getCrmRecords());
    $exception = assertThrows(DaktelaHttpException::class, static fn (): array|null => $failingClient->getCrmRecords());
    assertSameValue(403, $exception->statusCode);
});

echo "\nOK\n";
