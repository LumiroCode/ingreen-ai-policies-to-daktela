<?php

declare(strict_types=1);
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\TextBlockParam;

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
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataParser;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;

test('Claude policy extractor sends PDF document and prompt to Claude client', function (): void {
    $dir = tempDir();
    $pdfPath = $dir . '/policy.pdf';
    file_put_contents($pdfPath, "%PDF-1.4\npolicy");

    $client = new FakeClaudeMessagesClient('{"car_make":"Skoda","car_model":"Octavia","value":"50 000 CZK"}');
    $extractor = new ClaudePolicyDataExtractor($client, new \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataResponseParser(), 'claude-test-model', 256);

    $data = $extractor->extract($pdfPath);

    assertSameValue('Skoda', $data->carMake);
    assertSameValue('Octavia', $data->carModel);
    assertSameValue('50 000 CZK', $data->value);
    assertSameValue('claude-test-model', $client->requests[0]['model']);
    assertSameValue(256, $client->requests[0]['maxTokens']);
    assertSameValue('user', $client->requests[0]['messages'][0]['role']);
    assertTrueValue($client->requests[0]['messages'][0]['content'][0] instanceof DocumentBlockParam);
    assertTrueValue($client->requests[0]['messages'][0]['content'][1] instanceof TextBlockParam);
    assertSameValue(null, $client->requests[0]['thinking']);
    assertSameValue('json_schema', $client->requests[0]['outputConfig']['format']['type']);
    $schema = $client->requests[0]['outputConfig']['format']['schema'];
    assertSameValue(false, $schema['additionalProperties']);
    assertSameValue('string', $schema['properties']['nr_rejestracyjny']['type']);
    assertSameValue('string', $schema['properties']['marka']['type']);
    assertSameValue(['Własny', 'Leasing', 'Bank', 'Wynajem', ''], $schema['properties']['forma_wlasnosci']['enum']);
    assertSameValue(['Standardowy', 'Taxi', ''], $schema['properties']['sposob_korzystania']['enum']);
    assertSameValue(['Nowy', 'Używany', 'Nieznany', ''], $schema['properties']['stan_pojazdu']['enum']);
    assertSameValue(['tak', 'nie', ''], $schema['properties']['pakiet_ubezpieczeniowy']['enum']);
    assertSameValue(['tak', 'nie', ''], $schema['properties']['wspolposiadacz']['enum']);
    assertTrueValue(in_array('PZU', $schema['properties']['towarzystwo_ubezpieczeniowe']['enum'], true));
    assertSameValue('string', $schema['properties']['nr_polisy']['type']);
    assertSameValue('string', $schema['properties']['cena_wznowienia']['type']);
    assertSameValue(['OC', 'OC/AC', 'OC/AC/NNW', 'OC/AC/NNW/Assistance', 'AC', 'NNW', 'Assistance', 'GAP', 'Przedłużona Gwarancja', ''], $schema['properties']['rodzaj_polisy']['enum']);
    $unionCount = static function (mixed $value) use (&$unionCount): int {
        if (!is_array($value)) {
            return 0;
        }

        $count = array_key_exists('anyOf', $value) || (isset($value['type']) && is_array($value['type'])) ? 1 : 0;

        foreach ($value as $child) {
            $count += $unionCount($child);
        }

        return $count;
    };
    assertSameValue(0, $unionCount($schema));
    assertTrueValue(in_array('nr_rejestracyjny', $schema['required'], true));
    assertTrueValue(in_array('forma_wlasnosci', $schema['required'], true));
    assertTrueValue(in_array('planowana_data_rejestracji', $schema['required'], true));
    assertTrueValue(in_array('wspolposiadacz', $schema['required'], true));
    assertTrueValue(in_array('pesel_wspolposiadacza', $schema['required'], true));
    assertTrueValue(in_array('nr_polisy', $schema['required'], true));
    assertTrueValue(in_array('rodzaj_polisy', $schema['required'], true));
    assertTrueValue(in_array('data_sprzedazy_lubezpieczenia', $schema['required'], true));
    assertTrueValue(in_array('data_sprzedazy_wznowienia', $schema['required'], true));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'numer rejestracyjny'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'stan pojazdu'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'forma wlasnosci'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'wspolposiadacz'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'towarzystwo ubezpieczeniowe'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'nr polisy'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'rodzaj polisy'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'Nie wymyślaj danych'));
    assertTrueValue(str_contains($client->requests[0]['messages'][0]['content'][1]->text, 'pustego stringa ""'));
});
