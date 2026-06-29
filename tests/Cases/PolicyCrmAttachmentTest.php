<?php

declare(strict_types=1);

require_once __DIR__ . '/../Utils/Runner.php';
require_once __DIR__ . '/../Utils/Assertions.php';
require_once __DIR__ . '/../Utils/Helpers.php';
require_once __DIR__ . '/../Fakes/FakeDaktela.php';
require_once __DIR__ . '/../Fakes/NullLogger.php';

use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaModule;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataCache;

test('Daktela module uploads and attaches policy PDF before saving vehicle record', function (): void {
    $pdf = testPolicyPdf('policy document.pdf');
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => jsonResponse([
            'result' => [
                'name' => '123',
                'user' => ['name' => 'agent_1'],
                'customFields' => ['pochodzenie_polisy' => 'Dealer'],
            ],
        ]),
        '/api/v6/crmRecords' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/crmRecords.json' => function (string $method, string $url, array $headers, mixed $body): array {
            parse_str((string) $body, $payload);

            return jsonResponse(['result' => [
                'name' => ($payload['title'] ?? null) === 'Pojazdy' ? 'record_vehicle' : 'record_policy',
            ]]);
        },
        '/api/v6/crmRecords/record_policy/attachments.json' => function (string $method, string $url, array $headers, mixed $body): array {
            if ($method === 'GET') {
                return jsonResponse(['result' => ['data' => []]]);
            }

            return jsonResponse(['result' => ['file' => 'attachment_1']], 201);
        },
        '/file/upload.php' => [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode('temporary-policy.pdf', JSON_THROW_ON_ERROR),
        ],
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $module->saveConfirmedPolicyData('123', ExtractedPolicyData::fromFields([
        'nr_rejestracyjny' => 'WX12345',
        'vin' => 'TMB123',
    ], '{}'), $pdf);

    $upload = array_values(array_filter(
        $fake->requests,
        static fn (array $request): bool => parse_url($request['url'], PHP_URL_PATH) === '/file/upload.php'
    ));
    $attachmentWrites = array_values(array_filter(
        $fake->requests,
        static fn (array $request): bool => $request['method'] === 'POST'
            && parse_url($request['url'], PHP_URL_PATH) === '/api/v6/crmRecords/record_policy/attachments.json'
    ));

    assertSameValue(1, count($upload));
    parse_str(parse_url($upload[0]['url'], PHP_URL_QUERY) ?: '', $uploadQuery);
    assertSameValue('save', $uploadQuery['type']);
    assertSameValue('module-token', $uploadQuery['accessToken']);
    assertTrueValue(is_array($upload[0]['body']));
    assertTrueValue($upload[0]['body']['files'] instanceof CURLFile);
    assertSameValue($pdf->path, $upload[0]['body']['files']->getFilename());
    assertSameValue('policy document.pdf', $upload[0]['body']['files']->getPostFilename());
    assertSameValue('application/pdf', $upload[0]['body']['files']->getMimeType());

    assertSameValue(1, count($attachmentWrites));
    parse_str((string) $attachmentWrites[0]['body'], $attachmentPayload);
    assertSameValue('temporary-policy.pdf', $attachmentPayload['file']['name']);
    assertSameValue('policy document.pdf', $attachmentPayload['file']['title']);

    $attachmentWriteIndex = array_search($attachmentWrites[0], $fake->requests, true);
    $vehicleWriteIndex = null;
    foreach ($fake->requests as $index => $request) {
        if ($request['method'] !== 'POST' || parse_url($request['url'], PHP_URL_PATH) !== '/api/v6/crmRecords.json') {
            continue;
        }

        parse_str((string) $request['body'], $payload);

        if (($payload['title'] ?? null) === 'Pojazdy') {
            $vehicleWriteIndex = $index;
        }
    }

    assertTrueValue(is_int($attachmentWriteIndex) && is_int($vehicleWriteIndex) && $attachmentWriteIndex < $vehicleWriteIndex);
});


test('Daktela module skips upload when policy attachment filename and size already match', function (): void {
    $pdf = testPolicyPdf('same.pdf');
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => jsonResponse(['result' => [
            'name' => '123',
            'user' => ['name' => 'agent_1'],
            'customFields' => ['pochodzenie_polisy' => 'Dealer'],
        ]]),
        '/api/v6/crmRecords' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/crmRecords.json' => jsonResponse(['result' => ['name' => 'record_policy']]),
        '/api/v6/crmRecords/record_policy/attachments.json' => existingTestPolicyAttachmentResponse('same.pdf'),
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    $module->saveConfirmedPolicyData('123', ExtractedPolicyData::fromFields([
        'nr_rejestracyjny' => 'WX12345',
        'vin' => 'TMB123',
    ], '{}'), $pdf);

    assertSameValue(0, count(array_filter(
        $fake->requests,
        static fn (array $request): bool => parse_url($request['url'], PHP_URL_PATH) === '/file/upload.php'
    )));
});


test('policy CRM attachment lookup follows pagination before detecting duplicate', function (): void {
    $pdf = testPolicyPdf('second-page.pdf');
    $fake = new FakeDaktela([
        '/api/v6/crmRecords/record_policy/attachments.json' => function (string $method, string $url) use ($pdf): array {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $page = (int) ($query['page'] ?? 0);

            if ($page === 1) {
                return jsonResponse(['result' => ['data' => array_fill(0, 100, [
                    'title' => 'other.pdf',
                    'size' => 1,
                ])]]);
            }

            return jsonResponse(['result' => ['data' => [[
                'title' => $pdf->title,
                'size' => $pdf->size,
            ]]]]);
        },
    ]);
    $service = new \Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService(
        'https://daktela.example',
        'module-token',
        $fake
    );
    $handler = new \Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\HasPolicyCrmAttachment($service);

    assertSameValue(true, $handler->execute('record_policy', $pdf));
    assertSameValue(2, count($fake->requests));
    assertTrueValue(str_contains($fake->requests[0]['url'], 'page=1'));
    assertTrueValue(str_contains($fake->requests[1]['url'], 'page=2'));
});


test('Daktela upload rejects malformed JSON string response', function (): void {
    $pdf = testPolicyPdf();
    $fake = new FakeDaktela([
        '/file/upload.php' => [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode('Upload failed: invalid file', JSON_THROW_ON_ERROR),
        ],
    ]);
    $service = new \Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService(
        'https://daktela.example',
        'module-token',
        $fake
    );

    try {
        (new \Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\UploadPolicyPdf($service))->execute($pdf);
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('invalid_daktela_attachment_upload_response', $exception->errorCode());
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('Daktela upload reports upstream upload failure', function (): void {
    $pdf = testPolicyPdf();
    $fake = new FakeDaktela([
        '/file/upload.php' => jsonResponse(['error' => 'failed'], 500),
    ]);
    $service = new \Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService(
        'https://daktela.example',
        'module-token',
        $fake
    );

    try {
        (new \Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\UploadPolicyPdf($service))->execute($pdf);
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue(502, $exception->statusCode());
        assertSameValue('daktela_policy_attachment_upload_failed', $exception->errorCode());
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('policy attachment failure stops vehicle save', function (): void {
    $pdf = testPolicyPdf();
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => jsonResponse(['result' => [
            'name' => '123',
            'user' => ['name' => 'agent_1'],
            'customFields' => ['pochodzenie_polisy' => 'Dealer'],
        ]]),
        '/api/v6/crmRecords' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/crmRecords.json' => jsonResponse(['result' => ['name' => 'record_policy']]),
        '/api/v6/crmRecords/record_policy/attachments.json' => function (string $method): array {
            return $method === 'GET'
                ? jsonResponse(['result' => ['data' => []]])
                : jsonResponse(['error' => [['message' => 'failed']]], 500);
        },
        '/file/upload.php' => [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode('temporary-policy.pdf', JSON_THROW_ON_ERROR),
        ],
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);

    try {
        $module->saveConfirmedPolicyData('123', ExtractedPolicyData::fromFields([
            'nr_rejestracyjny' => 'WX12345',
            'vin' => 'TMB123',
        ], '{}'), $pdf);
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue('daktela_policy_attachment_save_failed', $exception->errorCode());

        $vehicleWrites = array_filter($fake->requests, static function (array $request): bool {
            if ($request['method'] !== 'POST' || parse_url($request['url'], PHP_URL_PATH) !== '/api/v6/crmRecords.json') {
                return false;
            }

            parse_str((string) $request['body'], $payload);

            return ($payload['title'] ?? null) === 'Pojazdy';
        });
        assertSameValue(0, count($vehicleWrites));
        return;
    }

    throw new RuntimeException('Expected exception.');
});


test('retry after attachment failure reuses created policy record and completes attachment', function (): void {
    $pdf = testPolicyPdf();
    $attempt = 0;
    $attachmentWrites = 0;
    $fake = new FakeDaktela([
        '/api/v6/tickets/123.json' => function () use (&$attempt): array {
            $attempt++;

            return jsonResponse(['result' => [
                'name' => '123',
                'user' => ['name' => 'agent_1'],
                'customFields' => ['pochodzenie_polisy' => 'Dealer'],
            ]]);
        },
        '/api/v6/crmRecords' => function () use (&$attempt): array {
            return jsonResponse(['result' => ['data' => $attempt === 1 ? [] : [[
                'name' => 'record_policy',
                'title' => 'Polisy',
                'customFields' => [
                    'nr_rejestracyjny' => 'WX12345',
                    'vin' => 'TMB123',
                ],
            ]]]]);
        },
        '/api/v6/crmRecords.json' => function (string $method, string $url, array $headers, mixed $body): array {
            parse_str((string) $body, $payload);

            return jsonResponse(['result' => [
                'name' => ($payload['title'] ?? null) === 'Pojazdy' ? 'record_vehicle' : 'record_policy',
            ]]);
        },
        '/api/v6/crmRecords/record_policy.json' => jsonResponse(['result' => ['name' => 'record_policy']]),
        '/api/v6/crmRecords/record_policy/attachments.json' => function (string $method) use (&$attachmentWrites): array {
            if ($method === 'GET') {
                return jsonResponse(['result' => ['data' => []]]);
            }

            $attachmentWrites++;

            return $attachmentWrites === 1
                ? jsonResponse(['error' => [['message' => 'failed']]], 500)
                : jsonResponse(['result' => ['file' => 'attachment_1']], 201);
        },
        '/file/upload.php' => [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode('temporary-policy.pdf', JSON_THROW_ON_ERROR),
        ],
    ]);
    $module = new DaktelaModule('https://daktela.example', 'module-token', $fake);
    $data = ExtractedPolicyData::fromFields([
        'nr_rejestracyjny' => 'WX12345',
        'vin' => 'TMB123',
    ], '{}');

    try {
        $module->saveConfirmedPolicyData('123', $data, $pdf);
    } catch (\Ingreen\DaktelaPolicy\Support\AppException $exception) {
        assertSameValue('daktela_policy_attachment_save_failed', $exception->errorCode());
    }

    $module->saveConfirmedPolicyData('123', $data, $pdf);

    $policyCreates = array_filter($fake->requests, static function (array $request): bool {
        if ($request['method'] !== 'POST' || parse_url($request['url'], PHP_URL_PATH) !== '/api/v6/crmRecords.json') {
            return false;
        }

        parse_str((string) $request['body'], $payload);

        return ($payload['title'] ?? null) === 'Polisy';
    });
    assertSameValue(1, count($policyCreates));
    assertSameValue(2, $attachmentWrites);
});


test('attachment failure leaves confirmation cache uncommitted', function (): void {
    $dir = tempDir();
    $attachment = ['file' => '/files/scan.pdf', 'title' => 'scan.pdf', 'type' => 'application/pdf'];
    $fake = new FakeDaktela([
        '/api/v6/tickets/123' => jsonResponse(['result' => [
            'name' => '123',
            'has_attachment' => true,
            'attachments' => [$attachment],
            'customFields' => [],
        ]]),
        '/api/v6/tickets/123/activities' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/tickets/123.json' => jsonResponse(['result' => [
            'name' => '123',
            'user' => ['name' => 'agent_1'],
            'customFields' => ['pochodzenie_polisy' => 'Dealer'],
        ]]),
        '/api/v6/crmRecords' => jsonResponse(['result' => ['data' => []]]),
        '/api/v6/crmRecords.json' => jsonResponse(['result' => ['name' => 'record_policy']]),
        '/api/v6/crmRecords/record_policy/attachments.json' => function (string $method): array {
            return $method === 'GET'
                ? jsonResponse(['result' => ['data' => []]])
                : jsonResponse(['error' => [['message' => 'failed']]], 500);
        },
        '/file/upload.php' => [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode('temporary-policy.pdf', JSON_THROW_ON_ERROR),
        ],
        '/files/scan.pdf' => pdfResponse(),
    ]);
    $app = app($fake, $dir, writeConfirmedPolicyData: true);

    $app->handle('123', '0', daktelaAccessToken('123'));
    $failed = $app->handle(
        '123',
        '0',
        daktelaAccessToken('123'),
        confirmation: 'yes',
        policyData: [
            'nr_rejestracyjny' => 'WX12345',
            'vin' => 'TMB123',
            'nr_polisy' => 'POL-123',
        ],
        policyLocked: PolicyConfirmationForm::allLockedFields()
    );
    $cache = new PolicyDataCache($dir . '/var');

    assertSameValue(502, $failed['status']);
    assertTrueValue(str_contains($failed['body'], 'Nie udało się dołączyć pliku polisy'));
    assertSameValue(null, $cache->confirmed('123', $attachment));
    assertSameValue('Skoda', $cache->pending('123', $attachment)?->field('marka'));
});
