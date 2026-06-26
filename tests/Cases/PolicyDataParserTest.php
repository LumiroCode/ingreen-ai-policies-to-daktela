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
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataParser;
use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudePolicyDataExtractor;

test('policy data parser maps Claude JSON response to extracted policy data', function (): void {
    $data = (new \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataResponseParser())->parse('```json
{"stan_pojazdu":"Używany","nr_rejestracyjny":"WX12345","marka":"Toyota","model":"Corolla","wersja":"Comfort","vin":"JT123","forma_wlasnosci":"Leasing","rocznik":"2022","przebieg":"12000","wartosc_pojazdu_brutto":"123 000 PLN","wartosc_pojazdu_netto":null,"kategoria_pojazdu":"Osobowy (Kat. M1)","sposob_korzystania":"Standardowy","typ_silnika":"Hybryda","pojemnosc_silnika":"1798","data_nabycia":"2024-01-01","data_pierwszej_rejestracji":"2022-03-01","planowana_data_rejestracji":null,"wspolposiadacz":"tak","imie_wspolposiadacza":"Jan","nazwisko_wspolposiadacza":"Kowalski","pesel_wspolposiadacza":"80010112345","adres_wspolposiadacza":"ul. Prosta 1, Warszawa","pakiet_ubezpieczeniowy":"tak","rodzaj_assistance":"Polska","towarzystwo_ubezpieczeniowe":"PZU","nr_polisy":"POL-123","data_konca_polisy":"2025-03-01","cena_pakietu":"3200 PLN","cena_wznowienia":"3300 PLN","oc_cena":"100 PLN","ac_cena":"2100 PLN","cena_nnw":"50 PLN","cena_assistance":"80 PLN","gap_cena":"900 PLN","cena_przedluzonej_gwarancji":"1200 PLN","rodzaj_polisy":"OC/AC/NNW/Assistance","data_sprzedazy_lubezpieczenia":"2024-03-01","data_sprzedazy_wznowienia":"2025-02-20"}
```');

    assertSameValue('Toyota', $data->carMake);
    assertSameValue('Corolla', $data->carModel);
    assertSameValue('123 000 PLN', $data->value);
    assertSameValue('Używany', $data->field('stan_pojazdu'));
    assertSameValue('WX12345', $data->field('nr_rejestracyjny'));
    assertSameValue('JT123', $data->field('vin'));
    assertSameValue('Leasing', $data->field('forma_wlasnosci'));
    assertSameValue('Hybryda', $data->field('typ_silnika'));
    assertSameValue('tak', $data->field('wspolposiadacz'));
    assertSameValue('Jan', $data->field('imie_wspolposiadacza'));
    assertSameValue('Kowalski', $data->field('nazwisko_wspolposiadacza'));
    assertSameValue('80010112345', $data->field('pesel_wspolposiadacza'));
    assertSameValue('ul. Prosta 1, Warszawa', $data->field('adres_wspolposiadacza'));
    assertSameValue('tak', $data->field('pakiet_ubezpieczeniowy'));
    assertSameValue('PZU', $data->field('towarzystwo_ubezpieczeniowe'));
    assertSameValue('POL-123', $data->field('nr_polisy'));
    assertSameValue('3200 PLN', $data->field('cena_pakietu'));
    assertSameValue('3300 PLN', $data->field('cena_wznowienia'));
    assertSameValue('100 PLN', $data->field('oc_cena'));
    assertSameValue('2100 PLN', $data->field('ac_cena'));
    assertSameValue('50 PLN', $data->field('cena_nnw'));
    assertSameValue('80 PLN', $data->field('cena_assistance'));
    assertSameValue('900 PLN', $data->field('gap_cena'));
    assertSameValue('1200 PLN', $data->field('cena_przedluzonej_gwarancji'));
    assertSameValue('OC/AC/NNW/Assistance', $data->field('rodzaj_polisy'));
    assertSameValue('2025-02-20', $data->field('data_sprzedazy_wznowienia'));
});


test('policy data parser extracts JSON object from descriptive Claude response', function (): void {
    $data = (new \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataResponseParser())->parse('Znalazłem dane pojazdu. Odpowiedź końcowa: {"marka":"Skoda","model":"Octavia","vin":"TMB123"} Dziękuję.');

    assertSameValue('Skoda', $data->field('marka'));
    assertSameValue('Octavia', $data->field('model'));
    assertSameValue('TMB123', $data->field('vin'));
});


test('policy data parser falls back to key value lines', function (): void {
    $data = (new \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataResponseParser())->parse('
Stan pojazdu: Nowy
Numer rejestracyjny pojazdu: WE123AB
Marka pojazdu: Tesla
Model pojazdu: 3
Numer VIN: /
Forma własności: Własny
Wartość pojazdu brutto: 204000 PLN
Typ silnika: Elektryczny
Współposiadacz: nie
Towarzystwo ubezpieczeniowe: Warta
Numer polisy: WAR-456
Data końca polisy: 2026-05-20
Cena pakietu za pierwszy rok: 2500 PLN
Cena wznowienia: 2600 PLN
AC cena: 1900 PLN
Cena NNW: 40 PLN
Cena assistance: 70 PLN
GAP cena: 800 PLN
Cena przedłużonej gwarancji: /
Rodzaj polisy: OC/AC
Data sprzedaży wznowienia: 2026-05-01
');

    assertSameValue('Nowy', $data->field('stan_pojazdu'));
    assertSameValue('WE123AB', $data->field('nr_rejestracyjny'));
    assertSameValue('Tesla', $data->field('marka'));
    assertSameValue('3', $data->field('model'));
    assertSameValue(null, $data->field('vin'));
    assertSameValue('Własny', $data->field('forma_wlasnosci'));
    assertSameValue('204000 PLN', $data->field('wartosc_pojazdu_brutto'));
    assertSameValue('Elektryczny', $data->field('typ_silnika'));
    assertSameValue('nie', $data->field('wspolposiadacz'));
    assertSameValue('Warta', $data->field('towarzystwo_ubezpieczeniowe'));
    assertSameValue('WAR-456', $data->field('nr_polisy'));
    assertSameValue('2026-05-20', $data->field('data_konca_polisy'));
    assertSameValue('2500 PLN', $data->field('cena_pakietu'));
    assertSameValue('2600 PLN', $data->field('cena_wznowienia'));
    assertSameValue('1900 PLN', $data->field('ac_cena'));
    assertSameValue('40 PLN', $data->field('cena_nnw'));
    assertSameValue('70 PLN', $data->field('cena_assistance'));
    assertSameValue('800 PLN', $data->field('gap_cena'));
    assertSameValue(null, $data->field('cena_przedluzonej_gwarancji'));
    assertSameValue('OC/AC', $data->field('rodzaj_polisy'));
    assertSameValue('2026-05-01', $data->field('data_sprzedazy_wznowienia'));
});


test('policy data parser ignores unrelated JSON before key value lines', function (): void {
    $data = (new \Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataResponseParser())->parse('
Najpierw notatka techniczna: {"status":"ok"}
Marka: Kia
Model: Niro
Wartość brutto: 150000 PLN
');

    assertSameValue('Kia', $data->field('marka'));
    assertSameValue('Niro', $data->field('model'));
    assertSameValue('150000 PLN', $data->field('wartosc_pojazdu_brutto'));
});

