<?php

declare(strict_types=1);

require_once __DIR__ . '/../Utils/Runner.php';
require_once __DIR__ . '/../Utils/Assertions.php';
require_once __DIR__ . '/../Utils/Helpers.php';
require_once __DIR__ . '/../Fakes/FakeDaktela.php';
require_once __DIR__ . '/../Fakes/NullLogger.php';
require_once __DIR__ . '/../Fakes/FakePolicyDataExtractor.php';
require_once __DIR__ . '/../Fakes/FakeClaudeMessagesClient.php';

use Ingreen\DaktelaPolicy\WebhookApp;
use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaModule;
use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaTicketPolicyDataMapper;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataParser;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;

test('downloader handles relative Daktela file path', function (): void {
    $fake = new FakeDaktela(['/attachments/policy.pdf' => pdfResponse()]);
    $client = new DaktelaModule('https://daktela.example', 'token', $fake);
    $file = $client->download('/attachments/policy.pdf', 1_000_000);

    assertSameValue("%PDF-1.4\nbody", $file['body']);
    assertSameValue('https://daktela.example/attachments/policy.pdf', $fake->requests[0]['url']);
    assertSameValue('token', $fake->requests[0]['headers']['X-AUTH-TOKEN-OPENAPI']);
});


test('Daktela module gets ticket by name through its facade', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $ticket = $module->getTicketByName('123');

    assertSameValue('123', $ticket['result']['name']);
    assertSameValue('GET', $fake->requests[0]['method']);
    assertSameValue('https://daktela.example/api/v6/tickets/123', $fake->requests[0]['url']);
    assertSameValue('application/json', $fake->requests[0]['headers']['Accept']);
    assertSameValue('module-token', $fake->requests[0]['headers']['X-AUTH-TOKEN-OPENAPI']);
});


test('Daktela module URL-encodes ticket name', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/ABC%2F123' => jsonResponse(['result' => ['name' => 'ABC/123']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $ticket = $module->getTicketByName('ABC/123');

    assertSameValue('ABC/123', $ticket['result']['name']);
    assertSameValue('https://daktela.example/api/v6/tickets/ABC%2F123', $fake->requests[0]['url']);
});


test('Daktela ticket policy data mapper maps non-empty policy values to custom fields', function (): void {
    $data = ExtractedPolicyData::fromFields([
        'marka' => ' Tesla ',
        'model' => '',
        'vin' => null,
        'nr_polisy' => 'POL-123',
    ], '{}');

    $payload = (new DaktelaTicketPolicyDataMapper())->toTicketPayload($data);

    assertSameValue(['marka' => 'Tesla', 'nr_polisy' => 'POL-123'], $payload['customFields']);
    assertArrayMissingKey('model', $payload['customFields']);
    assertArrayMissingKey('vin', $payload['customFields']);
});


test('Daktela module updates ticket policy data through form-encoded custom fields', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/ABC%2F123.json' => jsonResponse(['result' => ['name' => 'ABC/123']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $response = $module->updateTicketPolicyData('ABC/123', ExtractedPolicyData::fromFields([
        'marka' => 'Tesla',
        'model' => '',
        'nr_polisy' => 'POL-123',
    ], '{}'));
    parse_str((string) $fake->requests[0]['body'], $body);

    assertSameValue('ABC/123', $response['result']['name']);
    assertSameValue('PUT', $fake->requests[0]['method']);
    assertSameValue('https://daktela.example/api/v6/tickets/ABC%2F123.json', $fake->requests[0]['url']);
    assertSameValue('application/json', $fake->requests[0]['headers']['Accept']);
    assertSameValue('application/x-www-form-urlencoded', $fake->requests[0]['headers']['Content-Type']);
    assertSameValue('module-token', $fake->requests[0]['headers']['X-AUTH-TOKEN-OPENAPI']);
    assertSameValue('Tesla', $body['customFields']['marka']);
    assertSameValue('POL-123', $body['customFields']['nr_polisy']);
    assertArrayMissingKey('model', $body['customFields']);
});


test('Daktela ticket policy values provider normalizes custom fields', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([
            'result' => [
                'name' => '123',
                'customFields' => [
                    'marka' => [' Tesla ', 'Model S'],
                    'model' => ' 3 ',
                    'rocznik' => [2026],
                    'przebieg' => [],
                    'vin' => [null, ['nested'], ''],
                    'nr_polisy' => ['POL-123'],
                    'unknown_field' => ['ignored'],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);
    $provider = new \Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaTicketPolicyValuesProvider($module);

    $values = $provider->valuesForTicket('123');

    assertSameValue('Tesla, Model S', $values['marka']);
    assertSameValue('3', $values['model']);
    assertSameValue('2026', $values['rocznik']);
    assertSameValue('POL-123', $values['nr_polisy']);
    assertArrayMissingKey('przebieg', $values);
    assertArrayMissingKey('vin', $values);
    assertArrayMissingKey('unknown_field', $values);
});


test('Daktela module gets CRM records linked to ticket id', function (): void {
    $firstPageRecords = array_map(
        static fn (int $index): array => ['name' => 'record_' . $index, 'ticket' => ['name' => 'ABC/123']],
        range(1, 100)
    );
    $fake = new FakeDaktela([
        '/api/v6/crmRecords' => function (string $method, string $url) use ($firstPageRecords): array {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            return jsonResponse([
                'result' => [
                    'data' => ((int) $query['page']) === 1
                        ? $firstPageRecords
                        : [
                            ['name' => 'record_policy', 'ticket' => ['name' => 'ABC/123']],
                            ['name' => 'record_vehicle', 'ticket' => ['name' => 'ABC/123']],
                        ],
                ],
            ]);
        },
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $records = $module->getCrmRecordsByTicketId('ABC/123');
    parse_str(parse_url($fake->requests[0]['url'], PHP_URL_QUERY) ?: '', $query);
    parse_str(parse_url($fake->requests[1]['url'], PHP_URL_QUERY) ?: '', $secondPageQuery);

    assertSameValue(102, count($records));
    assertSameValue(['record_1', 'record_policy', 'record_vehicle'], array_map(
        static fn (array $record): string => $record['name'],
        [$records[0], $records[100], $records[101]]
    ));
    assertSameValue('GET', $fake->requests[0]['method']);
    assertSameValue('https://daktela.example/api/v6/crmRecords?page=1&pageSize=100&filter%5Bfield%5D=ticket.name&filter%5Boperator%5D=eq&filter%5Bvalue%5D=ABC%2F123', $fake->requests[0]['url']);
    assertSameValue('ticket.name', $query['filter']['field']);
    assertSameValue('eq', $query['filter']['operator']);
    assertSameValue('ABC/123', $query['filter']['value']);
    assertSameValue('1', $query['page']);
    assertSameValue('100', $query['pageSize']);
    assertSameValue('2', $secondPageQuery['page']);
    assertSameValue('100', $secondPageQuery['pageSize']);
    assertSameValue('application/json', $fake->requests[0]['headers']['Accept']);
    assertSameValue('module-token', $fake->requests[0]['headers']['X-AUTH-TOKEN-OPENAPI']);
});


test('Daktela module finds policy CRM record identifiers by registration number or VIN', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_registration',
                        'title' => 'Polisy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => ' wx12345 ',
                            'vin' => 'OTHER',
                        ],
                    ],
                    [
                        'name' => 'record_vin',
                        'title' => 'Polisy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => 'OTHER',
                            'vin' => ' tmb123 ',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle',
                        'title' => 'Pojazdy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'TMB123',
                        ],
                    ],
                    [
                        'name' => 'record_without_custom_fields',
                        'title' => 'Polisy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [],
                    ],
                    [
                        'name' => 'record_array_wrapped_fields',
                        'title' => 'Polisy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => ['WX12345'],
                            'vin' => ['TMB123'],
                        ],
                    ],
                    [
                        'name' => 'record_type_title',
                        'title' => 'Dowolny tytuł',
                        'type' => ['title' => 'Polisy'],
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'OTHER',
                        ],
                    ],
                    [
                        'name' => 'record_nested_non_value_field',
                        'title' => 'Polisy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => [['WX12345']],
                            'vin' => ['value' => 'TMB123'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $recordIdentifiers = $module->findPolicyCrmRecordIdentifiers('ABC/123', 'WX12345', 'TMB123');
    parse_str(parse_url($fake->requests[0]['url'], PHP_URL_QUERY) ?: '', $query);

    assertSameValue(['record_registration', 'record_vin', 'record_array_wrapped_fields', 'record_type_title'], $recordIdentifiers);
    assertSameValue('ticket.name', $query['filter']['field']);
    assertSameValue('eq', $query['filter']['operator']);
    assertSameValue('ABC/123', $query['filter']['value']);
});


test('Daktela module finds vehicle CRM record identifiers by registration number or VIN', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_policy',
                        'title' => 'Polisy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'TMB123',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_registration',
                        'title' => 'Pojazdy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => ' wx12345 ',
                            'vin' => 'OTHER',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_vin',
                        'title' => 'Pojazdy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => 'OTHER',
                            'vin' => ' tmb123 ',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_array_wrapped_fields',
                        'title' => 'Pojazdy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => ['WX12345'],
                            'vin' => ['TMB123'],
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_type_title',
                        'title' => 'Dowolny tytuł',
                        'type' => ['title' => 'Pojazdy'],
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => 'OTHER',
                            'vin' => 'TMB123',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_nested_non_value_field',
                        'title' => 'Pojazdy',
                        'ticket' => ['name' => 'ABC/123'],
                        'customFields' => [
                            'nr_rejestracyjny' => [['WX12345']],
                            'vin' => ['value' => 'TMB123'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $recordIdentifiers = $module->findVehicleCrmRecordIdentifiers('ABC/123', 'WX12345', 'TMB123');
    parse_str(parse_url($fake->requests[0]['url'], PHP_URL_QUERY) ?: '', $query);

    assertSameValue([
        'record_vehicle_registration',
        'record_vehicle_vin',
        'record_vehicle_array_wrapped_fields',
        'record_vehicle_type_title',
    ], $recordIdentifiers);
    assertSameValue('ticket.name', $query['filter']['field']);
    assertSameValue('eq', $query['filter']['operator']);
    assertSameValue('ABC/123', $query['filter']['value']);
});


test('Daktela module returns empty policy CRM record identifier list when nothing matches', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_policy',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WA98765',
                            'vin' => 'OTHER',
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $recordIdentifiers = $module->findPolicyCrmRecordIdentifiers('123', 'WX12345', 'TMB123');

    assertSameValue([], $recordIdentifiers);
});


test('Daktela module returns empty vehicle CRM record identifier list when nothing matches', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_vehicle',
                        'title' => 'Pojazdy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WA98765',
                            'vin' => 'OTHER',
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $recordIdentifiers = $module->findVehicleCrmRecordIdentifiers('123', 'WX12345', 'TMB123');

    assertSameValue([], $recordIdentifiers);
});


test('Daktela module rejects empty policy CRM lookup values', function (): void {
    $fake = new FakeDaktela([]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->findPolicyCrmRecordIdentifiers('123', ' ', 'TMB123');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(400, $exception->statusCode());
        assertSameValue('invalid_policy_crm_lookup_arguments', $exception->errorCode());
        assertSameValue('registrationNumber', $exception->details()['field']);
        assertSameValue(0, count($fake->requests));
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module rejects empty vehicle CRM lookup values', function (): void {
    $fake = new FakeDaktela([]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->findVehicleCrmRecordIdentifiers('123', ' ', 'TMB123');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(400, $exception->statusCode());
        assertSameValue('invalid_vehicle_crm_lookup_arguments', $exception->errorCode());
        assertSameValue('registrationNumber', $exception->details()['field']);
        assertSameValue(0, count($fake->requests));
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module rejects empty policy CRM lookup VIN', function (): void {
    $fake = new FakeDaktela([]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->findPolicyCrmRecordIdentifiers('123', 'WX12345', ' ');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(400, $exception->statusCode());
        assertSameValue('invalid_policy_crm_lookup_arguments', $exception->errorCode());
        assertSameValue('vin', $exception->details()['field']);
        assertSameValue(0, count($fake->requests));
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module rejects empty vehicle CRM lookup VIN', function (): void {
    $fake = new FakeDaktela([]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->findVehicleCrmRecordIdentifiers('123', 'WX12345', ' ');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(400, $exception->statusCode());
        assertSameValue('invalid_vehicle_crm_lookup_arguments', $exception->errorCode());
        assertSameValue('vin', $exception->details()['field']);
        assertSameValue(0, count($fake->requests));
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module rejects matched policy CRM record without writable identifier', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'TMB123',
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->findPolicyCrmRecordIdentifiers('123', 'WX12345', 'TMB123');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('invalid_daktela_response', $exception->errorCode());
        assertSameValue('/api/v6/crmRecords', $exception->details()['path']);
        assertSameValue('123', $exception->details()['ticketId']);
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module rejects matched vehicle CRM record without writable identifier', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'title' => 'Pojazdy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'TMB123',
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->findVehicleCrmRecordIdentifiers('123', 'WX12345', 'TMB123');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('invalid_daktela_response', $exception->errorCode());
        assertSameValue('/api/v6/crmRecords', $exception->details()['path']);
        assertSameValue('123', $exception->details()['ticketId']);
        assertSameValue('Pojazdy', $exception->details()['recordTitle']);
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module rejects malformed CRM record list', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/crmRecords' => jsonResponse(['result' => ['data' => null]]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->getCrmRecordsByTicketId('123');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('invalid_daktela_response', $exception->errorCode());
        assertSameValue('/api/v6/crmRecords', $exception->details()['path']);
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module gets normalized ticket PDF attachments', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/ABC%2F123' => jsonResponse([
            'result' => [
                'name' => 'ABC/123',
                'title' => 'Policy ticket',
                'has_attachment' => true,
                'attachments' => [
                    ['file' => '/files/ticket.pdf', 'title' => 'ticket.pdf'],
                ],
            ],
        ]),
        '/api/v6/tickets/ABC%2F123/activities' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'activity-1',
                        'attachments' => [
                            ['file' => '/files/activity.pdf', 'title' => 'activity.pdf'],
                        ],
                        'item' => [
                            'attachments' => [
                                ['file' => '/files/item.pdf', 'title' => 'item.pdf'],
                            ],
                            'inlineAttachments' => [
                                ['file' => '/files/inline.pdf', 'title' => 'inline.pdf'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $attachments = $module->getTicketPdfAttachments('ABC/123');
    parse_str(parse_url($fake->requests[1]['url'], PHP_URL_QUERY) ?: '', $activityQuery);

    assertSameValue('Policy ticket', $attachments['title']);
    assertSameValue(true, $attachments['hasAttachment']);
    assertSameValue('https://daktela.example/api/v6/tickets/ABC%2F123', $fake->requests[0]['url']);
    assertSameValue('https://daktela.example/api/v6/tickets/ABC%2F123/activities?pageSize=100&sort%5B0%5D%5Bfield%5D=time&sort%5B0%5D%5Bdir%5D=desc', $fake->requests[1]['url']);
    assertSameValue('100', $activityQuery['pageSize']);
    assertSameValue('time', $activityQuery['sort'][0]['field']);
    assertSameValue('desc', $activityQuery['sort'][0]['dir']);
    assertSameValue(['ticket', 'activity.attachments', 'activity.item.attachments', 'activity.item.inlineAttachments'], array_map(
        static fn (array $candidate): string => $candidate['source'],
        $attachments['attachments']
    ));
    assertSameValue('ticket.pdf', $attachments['attachments'][0]['title']);
    assertSameValue('/files/ticket.pdf', $attachments['attachments'][0]['file']);
    assertSameValue('https://daktela.example/files/ticket.pdf?download=0', $attachments['attachments'][0]['previewUrl']);
    assertSameValue('activity.pdf', $attachments['attachments'][1]['title']);
});


test('Daktela module maps auth failure to AppException', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([], 401),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->getTicketByName('123');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('daktela_auth_failed', $exception->errorCode());
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module maps non-success response to AppException', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse([], 500),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->getTicketByName('123');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('daktela_request_failed', $exception->errorCode());
        assertSameValue('/api/v6/tickets/123', $exception->details()['path']);
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module rejects invalid JSON response', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{invalid'],
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->getTicketByName('123');
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('invalid_daktela_response', $exception->errorCode());
        assertSameValue('/api/v6/tickets/123', $exception->details()['path']);
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module creates policy CRM record when no matching record exists', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => jsonResponse([
            'result' => [
                'name' => '123',
                'user' => ['name' => 'agent_1'],
                'contact' => ['name' => 'contact_1'],
                'account' => ['name' => 'account_1'],
                'customFields' => [
                    'pochodzenie_polisy' => ['Dealer'],
                ],
            ],
        ]),
        '/api/v6/crmRecords' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/crmRecords.json' => jsonResponse(['result' => ['name' => 'record_created']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $response = $module->saveConfirmedPolicyData('123', ExtractedPolicyData::fromFields([
        'nr_rejestracyjny' => ' WX12345 ',
        'vin' => ' TMB123 ',
        'marka' => 'Tesla',
        'model' => 'Model 3',
        'nr_polisy' => 'POL-123',
        'towarzystwo_ubezpieczeniowe' => 'PZU',
        'data_konca_polisy' => '2027-01-31',
    ], '{}'));
    parse_str((string) $fake->requests[2]['body'], $body);

    assertSameValue('record_created', $response['result']['name']);
    assertSameValue('PUT', $fake->requests[0]['method']);
    assertSameValue('https://daktela.example/api/v6/tickets/123.json', $fake->requests[0]['url']);
    assertSameValue('GET', $fake->requests[1]['method']);
    assertSameValue('https://daktela.example/api/v6/crmRecords?page=1&pageSize=100&filter%5Bfield%5D=ticket.name&filter%5Boperator%5D=eq&filter%5Bvalue%5D=123', $fake->requests[1]['url']);
    assertSameValue('POST', $fake->requests[2]['method']);
    assertSameValue('https://daktela.example/api/v6/crmRecords.json', $fake->requests[2]['url']);
    assertSameValue('application/x-www-form-urlencoded', $fake->requests[2]['headers']['Content-Type']);
    assertSameValue('Polisy', $body['title']);
    assertSameValue('type_68227cae0ac91290315441', $body['type']['name']);
    assertSameValue('Polisy', $body['type']['title']);
    assertSameValue('OPEN', $body['stage']);
    assertSameValue('', $body['description']);
    assertSameValue('agent_1', $body['user']['name']);
    assertSameValue('contact_1', $body['contact']['name']);
    assertSameValue('account_1', $body['account']['name']);
    assertSameValue('123', $body['ticket']['name']);
    assertSameValue('WX12345', $body['customFields']['nr_rejestracyjny']);
    assertSameValue('TMB123', $body['customFields']['vin']);
    assertSameValue('Tesla', $body['customFields']['marka']);
    assertSameValue('POL-123', $body['customFields']['nr_polisy']);
    assertSameValue('PZU', $body['customFields']['towarzystwo_ubezpieczeniowe']);
    assertSameValue('2027-01-31', $body['customFields']['data_konca_polisy']);
    assertSameValue('Dealer', $body['customFields']['pochodzenie_polisy']);
    assertArrayMissingKey('status', $body);
});


test('Daktela module updates policy CRM record when exactly one matching record exists', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/ABC%2F123.json' => jsonResponse([
            'result' => [
                'name' => 'ABC/123',
                'user' => ['name' => 'agent_1'],
                'contact' => ['name' => 'contact_1'],
                'customFields' => [
                    'pochodzenie_polisy' => 'Online',
                ],
            ],
        ]),
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_policy',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'OTHER',
                        ],
                    ],
                ],
            ],
        ]),
        '/api/v6/crmRecords/record_policy.json' => jsonResponse(['result' => ['name' => 'record_policy']]),
        '/api/v6/crmRecords.json' => jsonResponse(['result' => ['name' => 'record_vehicle_created']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $response = $module->saveConfirmedPolicyData('ABC/123', ExtractedPolicyData::fromFields([
        'nr_rejestracyjny' => 'WX12345',
        'vin' => 'FORMVIN123',
        'nr_polisy' => 'POL-456',
    ], '{}'));
    parse_str((string) $fake->requests[2]['body'], $body);

    assertSameValue('record_policy', $response['result']['name']);
    assertSameValue('PUT', $fake->requests[2]['method']);
    assertSameValue('https://daktela.example/api/v6/crmRecords/record_policy.json', $fake->requests[2]['url']);
    assertSameValue('ABC/123', $body['ticket']['name']);
    assertSameValue('agent_1', $body['user']['name']);
    assertSameValue('contact_1', $body['contact']['name']);
    assertSameValue('FORMVIN123', $body['customFields']['vin']);
    assertSameValue('POL-456', $body['customFields']['nr_polisy']);
    assertSameValue('Online', $body['customFields']['pochodzenie_polisy']);
});


test('Daktela module aborts policy CRM save when more than one matching record exists', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => jsonResponse(['result' => ['name' => '123']]),
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123']]),
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_registration',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'OTHER',
                        ],
                    ],
                    [
                        'name' => 'record_vin',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'OTHER',
                            'vin' => 'TMB123',
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->saveConfirmedPolicyData('123', ExtractedPolicyData::fromFields([
            'nr_rejestracyjny' => 'WX12345',
            'vin' => 'TMB123',
        ], '{}'));
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(409, $exception->statusCode());
        assertSameValue('multiple_policy_crm_records_found', $exception->errorCode());
        assertSameValue(['record_registration', 'record_vin'], $exception->details()['recordIdentifiers']);
        assertSameValue(['PUT', 'GET', 'GET'], array_map(
            static fn (array $request): string => $request['method'],
            $fake->requests
        ));
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela module creates vehicle CRM record when no matching vehicle record exists', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => jsonResponse([
            'result' => [
                'name' => '123',
                'user' => ['name' => 'agent_1'],
                'contact' => ['name' => 'contact_1'],
                'account' => ['name' => 'account_1'],
                'customFields' => [
                    'pochodzenie_polisy' => 'Salon',
                ],
            ],
        ]),
        '/api/v6/crmRecords' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/crmRecords.json' => function (string $method, string $url, array $headers, ?string $body): array {
            parse_str((string) $body, $payload);

            return jsonResponse([
                'result' => [
                    'name' => $payload['title'] === 'Pojazdy' ? 'record_vehicle_created' : 'record_policy_created',
                ],
            ]);
        },
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $response = $module->saveConfirmedPolicyData('123', ExtractedPolicyData::fromFields([
        'nr_rejestracyjny' => 'WX12345',
        'vin' => 'TMB123',
        'marka' => 'Tesla',
        'model' => 'Model 3',
        'wersja' => 'Long Range',
        'forma_wlasnosci' => 'Leasing',
        'rocznik' => '2026',
        'przebieg' => '1234',
        'data_pierwszej_rejestracji' => '2026-01-02',
        'wartosc_pojazdu_brutto' => '200000 PLN',
        'wspolposiadacz' => 'tak',
        'imie_wspolposiadacza' => 'Jan',
        'nazwisko_wspolposiadacza' => 'Kowalski',
        'pesel_wspolposiadacza' => '12345678901',
        'adres_wspolposiadacza' => 'Warszawa',
        'nr_polisy' => 'POL-123',
    ], '{}'));
    parse_str((string) $fake->requests[4]['body'], $body);

    assertSameValue('record_policy_created', $response['result']['name']);
    assertSameValue('POST', $fake->requests[4]['method']);
    assertSameValue('https://daktela.example/api/v6/crmRecords.json', $fake->requests[4]['url']);
    assertSameValue('Pojazdy', $body['title']);
    assertSameValue('type_68227f433880e473202607', $body['type']['name']);
    assertSameValue('Pojazdy', $body['type']['title']);
    assertSameValue('OPEN', $body['stage']);
    assertSameValue('', $body['description']);
    assertSameValue('agent_1', $body['user']['name']);
    assertSameValue('contact_1', $body['contact']['name']);
    assertSameValue('account_1', $body['account']['name']);
    assertSameValue('123', $body['ticket']['name']);
    assertSameValue('WX12345', $body['customFields']['nr_rejestracyjny']);
    assertSameValue('TMB123', $body['customFields']['vin']);
    assertSameValue('Tesla', $body['customFields']['marka']);
    assertSameValue('Long Range', $body['customFields']['wersja']);
    assertSameValue('Leasing', $body['customFields']['forma_wlasnosci']);
    assertSameValue('Jan', $body['customFields']['imie_wspolposiadacza']);
    assertArrayMissingKey('nr_polisy', $body['customFields']);
    assertArrayMissingKey('status', $body);
});


test('Daktela module updates vehicle CRM record when exactly one matching vehicle record exists', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => jsonResponse(['result' => ['name' => '123']]),
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123']]),
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_policy',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'TMB123',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle',
                        'title' => 'Pojazdy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'OTHER',
                            'vin' => 'TMB123',
                        ],
                    ],
                ],
            ],
        ]),
        '/api/v6/crmRecords/record_policy.json' => jsonResponse(['result' => ['name' => 'record_policy']]),
        '/api/v6/crmRecords/record_vehicle.json' => jsonResponse(['result' => ['name' => 'record_vehicle']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $module->saveConfirmedPolicyData('123', ExtractedPolicyData::fromFields([
        'nr_rejestracyjny' => 'WX12345',
        'vin' => 'TMB123',
        'marka' => 'Skoda',
    ], '{}'));
    parse_str((string) $fake->requests[5]['body'], $body);

    assertSameValue('PUT', $fake->requests[5]['method']);
    assertSameValue('https://daktela.example/api/v6/crmRecords/record_vehicle.json', $fake->requests[5]['url']);
    assertSameValue('Pojazdy', $body['title']);
    assertSameValue('Skoda', $body['customFields']['marka']);
    assertSameValue('TMB123', $body['customFields']['vin']);
});


test('Daktela module aborts vehicle CRM save when more than one matching vehicle record exists', function (): void {
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => jsonResponse(['result' => ['name' => '123']]),
        '/api/v6/tickets/123' => jsonResponse(['result' => ['name' => '123']]),
        '/api/v6/crmRecords' => jsonResponse([
            'result' => [
                'data' => [
                    [
                        'name' => 'record_policy',
                        'title' => 'Polisy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'TMB123',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_registration',
                        'title' => 'Pojazdy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'WX12345',
                            'vin' => 'OTHER',
                        ],
                    ],
                    [
                        'name' => 'record_vehicle_vin',
                        'title' => 'Pojazdy',
                        'customFields' => [
                            'nr_rejestracyjny' => 'OTHER',
                            'vin' => 'TMB123',
                        ],
                    ],
                ],
            ],
        ]),
        '/api/v6/crmRecords/record_policy.json' => jsonResponse(['result' => ['name' => 'record_policy']]),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->saveConfirmedPolicyData('123', ExtractedPolicyData::fromFields([
            'nr_rejestracyjny' => 'WX12345',
            'vin' => 'TMB123',
        ], '{}'));
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(409, $exception->statusCode());
        assertSameValue('multiple_vehicle_crm_records_found', $exception->errorCode());
        assertSameValue(['record_vehicle_registration', 'record_vehicle_vin'], $exception->details()['recordIdentifiers']);
        assertSameValue(['PUT', 'GET', 'GET', 'PUT', 'GET'], array_map(
            static fn (array $request): string => $request['method'],
            $fake->requests
        ));
        return;
    }

    throw new RuntimeException('Expected exception.');
});
