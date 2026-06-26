<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction\Claude;

use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\Base64PDFSource;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\TextBlockParam;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataResponseParser;
use Ingreen\DaktelaPolicy\Support\AppException;

final class ClaudePolicyDataExtractor implements PolicyDataExtractor
{
    private const PROMPT = <<<'PROMPT'
Odczytaj dane pojazdu i dane polisy z załączonej polisy.

W swoim wewnętrznym procesie myślowym najpierw wypisz wszystkie znalezione w dokumencie pary klucz-wartość, które mogą dotyczyć pojazdu lub polisy, a następnie zwaliduj każdą wybraną wartość w oparciu o treść załączonej polisy. Nie zwracaj tej analizy w odpowiedzi końcowej.

Nie wymyślaj danych. Każde pole może mieć wartość pustego stringa "", jeśli nie da się go jednoznacznie potwierdzić w polisie.

Zwróć wyłącznie poprawny JSON z dokładnie tymi kluczami:
{
  "stan_pojazdu": "Nowy" | "Używany" | "Nieznany" | "",
  "nr_rejestracyjny": string,
  "marka": string,
  "model": string,
  "wersja": string,
  "vin": string,
  "forma_wlasnosci": "Własny" | "Leasing" | "Bank" | "Wynajem" | "",
  "rocznik": string,
  "przebieg": string,
  "wartosc_pojazdu_brutto": string,
  "wartosc_pojazdu_netto": string,
  "kategoria_pojazdu": "Osobowy (Kat. M1)" | "Ciężarowy - LCV (DMC do 3500kg) Kat. N1" | "Motocykle i inne pojazdy (kat.L)" | "",
  "sposob_korzystania": "Standardowy" | "Taxi" | "",
  "typ_silnika": "Benzynowy" | "CNG/LPG" | "Diesel" | "Elektryczny" | "Hybryda" | "",
  "pojemnosc_silnika": string,
  "data_nabycia": string,
  "data_pierwszej_rejestracji": string,
  "planowana_data_rejestracji": string,
  "wspolposiadacz": "tak" | "nie" | "",
  "imie_wspolposiadacza": string,
  "nazwisko_wspolposiadacza": string,
  "pesel_wspolposiadacza": string,
  "adres_wspolposiadacza": string,
  "pakiet_ubezpieczeniowy": "tak" | "nie" | "",
  "rodzaj_assistance": "minimalny" | "Polska" | "Europa (500-700km)" | "Europa (1000km)" | "Europa (+1500km)" | "",
  "towarzystwo_ubezpieczeniowe": "Alianz" | "Aviva" | "AXA" | "Balcia" | "Benefia" | "Compensa" | "Concordia" | "Defend" | "Ergo Hestia" | "Ergo Hestia - Pakiet Dealerski" | "Ergo Hestia - Pakiet Dealerski polisa za 1zł" | "Euroins" | "Generali" | "Gothaer" | "HDI" | "Inne" | "Interrisk" | "Liberty Ubezpieczenia" | "Link4" | "Met Life" | "MTU" | "NN Życie" | "Open Life" | "PKO Ubezpieczenia" | "Polisa - Życie" | "Polskie Towarzystwo Reasekuracji" | "Proama" | "PTU" | "PZM" | "PZU" | "PZU - pakiet dealerski SIGMA" | "RESO Europa" | "Saltus" | "Signal Iduna" | "TU Europa" | "TUW" | "TUZ" | "Uniqa" | "Vienna Life" | "Warta" | "Wefox" | "Wiener" | "",
  "nr_polisy": string,
  "kategoria_tu": "Partner InGreen" | "Asap" | "Wiktoria" | "",
  "data_konca_polisy": string,
  "cena_pakietu": string,
  "cena_wznowienia": string,
  "oc_cena": string,
  "ac_cena": string,
  "cena_nnw": string,
  "cena_assistance": string,
  "gap_cena": string,
  "cena_przedluzonej_gwarancji": string,
  "pochodzenie_polisy": string,
  "rodzaj_polisy": "OC" | "OC/AC" | "OC/AC/NNW" | "OC/AC/NNW/Assistance" | "AC" | "NNW" | "Assistance" | "GAP" | "Przedłużona Gwarancja" | "",
  "data_sprzedazy_lubezpieczenia": string,
  "data_sprzedazy_wznowienia": string
}

Reguły normalizacji:
- rocznik zwróć jako rok produkcji pojazdu.
- przebieg zwróć w kilometrach.
- wartosc_pojazdu_brutto i wartosc_pojazdu_netto zwróć w PLN, jeśli są dostępne.
- forma_wlasnosci zwróć tylko wtedy, gdy polisa jednoznacznie wskazuje jedną z wartości: "Własny", "Leasing", "Bank", "Wynajem" lub synonim którejś z nich.
- pojemnosc_silnika zwróć w cm3.
- data_pierwszej_rejestracji dotyczy tylko pojazdów **używanych**; dla pojazdów nowych zwróć "", chyba że polisa jednoznacznie podaje tę datę.
- planowana_data_rejestracji dotyczy tylko pojazdów **nowych**; dla pojazdów używanych zwróć "".
- wspolposiadacz zwróć jako "tak", jeśli polisa wskazuje więcej niż jednego właściciela, współwłaściciela lub współposiadacza pojazdu; zwróć "nie", jeśli polisa jednoznacznie wskazuje tylko jednego właściciela/posiadacza. Jeśli wspolposiadacz to "tak", uzupełnij dane współposiadacza na podstawie polisy.
- pakiet_ubezpieczeniowy zwróć jako "tak", jeśli dokument dotyczy całego pakietu ubezpieczeniowego; zwróć "nie", jeśli dotyczy tylko listy produktów jak AC, NNW, Assistance, GAP albo Przedłużona Gwarancja.
- rodzaj_assistance zwróć tylko wtedy, gdy zakres assistance da się jednoznacznie dopasować do jednej z podanych wartości.
- nr_polisy zwróć jako numer polisy.
- ceny zwróć z walutą, jeśli jest dostępna.
- cena_pakietu dotyczy ceny pakietu za pierwszy rok.
- cena_wznowienia, oc_cena, ac_cena, cena_nnw, cena_assistance, gap_cena i cena_przedluzonej_gwarancji zwróć tylko wtedy, gdy dokument jednoznacznie wskazuje odpowiednią składkę/cenę.
- rodzaj_polisy zwróć tylko wtedy, gdy zakres polisy da się jednoznacznie dopasować do jednej z podanych wartości.
- data_sprzedazy_lubezpieczenia dotyczy sprzedaży ubezpieczenia za pierwszy rok, a data_sprzedazy_wznowienia sprzedaży wznowienia.
- Jeśli dokument podaje "/", "-", "brak", puste pole albo wartość niejednoznaczną, zwróć "".
PROMPT;

    private const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'stan_pojazdu' => ['type' => 'string', 'enum' => ['Nowy', 'Używany', 'Nieznany', '']],
            'nr_rejestracyjny' => ['type' => 'string'],
            'marka' => ['type' => 'string'],
            'model' => ['type' => 'string'],
            'wersja' => ['type' => 'string'],
            'vin' => ['type' => 'string'],
            'forma_wlasnosci' => ['type' => 'string', 'enum' => ['Własny', 'Leasing', 'Bank', 'Wynajem', '']],
            'rocznik' => ['type' => 'string'],
            'przebieg' => ['type' => 'string'],
            'wartosc_pojazdu_brutto' => ['type' => 'string'],
            'wartosc_pojazdu_netto' => ['type' => 'string'],
            'kategoria_pojazdu' => [
                'type' => 'string',
                'enum' => [
                    'Osobowy (Kat. M1)',
                    'Ciężarowy - LCV (DMC do 3500kg) Kat. N1',
                    'Motocykle i inne pojazdy (kat.L)',
                    '',
                ],
            ],
            'sposob_korzystania' => ['type' => 'string', 'enum' => ['Standardowy', 'Taxi', '']],
            'typ_silnika' => ['type' => 'string', 'enum' => ['Benzynowy', 'CNG/LPG', 'Diesel', 'Elektryczny', 'Hybryda', '']],
            'pojemnosc_silnika' => ['type' => 'string'],
            'data_nabycia' => ['type' => 'string'],
            'data_pierwszej_rejestracji' => ['type' => 'string'],
            'planowana_data_rejestracji' => ['type' => 'string'],
            'wspolposiadacz' => ['type' => 'string', 'enum' => ['tak', 'nie', '']],
            'imie_wspolposiadacza' => ['type' => 'string'],
            'nazwisko_wspolposiadacza' => ['type' => 'string'],
            'pesel_wspolposiadacza' => ['type' => 'string'],
            'adres_wspolposiadacza' => ['type' => 'string'],
            'pakiet_ubezpieczeniowy' => ['type' => 'string', 'enum' => ['tak', 'nie', '']],
            'rodzaj_assistance' => [
                'type' => 'string',
                'enum' => [
                    'minimalny',
                    'Polska',
                    'Europa (500-700km)',
                    'Europa (1000km)',
                    'Europa (+1500km)',
                    '',
                ],
            ],
            'towarzystwo_ubezpieczeniowe' => [
                'type' => 'string',
                'enum' => [
                    'Alianz',
                    'Aviva',
                    'AXA',
                    'Balcia',
                    'Benefia',
                    'Compensa',
                    'Concordia',
                    'Defend',
                    'Ergo Hestia',
                    'Ergo Hestia - Pakiet Dealerski',
                    'Ergo Hestia - Pakiet Dealerski polisa za 1zł',
                    'Euroins',
                    'Generali',
                    'Gothaer',
                    'HDI',
                    'Inne',
                    'Interrisk',
                    'Liberty Ubezpieczenia',
                    'Link4',
                    'Met Life',
                    'MTU',
                    'NN Życie',
                    'Open Life',
                    'PKO Ubezpieczenia',
                    'Polisa - Życie',
                    'Polskie Towarzystwo Reasekuracji',
                    'Proama',
                    'PTU',
                    'PZM',
                    'PZU',
                    'PZU - pakiet dealerski SIGMA',
                    'RESO Europa',
                    'Saltus',
                    'Signal Iduna',
                    'TU Europa',
                    'TUW',
                    'TUZ',
                    'Uniqa',
                    'Vienna Life',
                    'Warta',
                    'Wefox',
                    'Wiener',
                    '',
                ],
            ],
            'nr_polisy' => ['type' => 'string'],
            'kategoria_tu' => ['type' => 'string', 'enum' => ['Partner InGreen', 'Asap', 'Wiktoria', '']],
            'data_konca_polisy' => ['type' => 'string'],
            'cena_pakietu' => ['type' => 'string'],
            'cena_wznowienia' => ['type' => 'string'],
            'oc_cena' => ['type' => 'string'],
            'ac_cena' => ['type' => 'string'],
            'cena_nnw' => ['type' => 'string'],
            'cena_assistance' => ['type' => 'string'],
            'gap_cena' => ['type' => 'string'],
            'cena_przedluzonej_gwarancji' => ['type' => 'string'],
            'pochodzenie_polisy' => ['type' => 'string'],
            'rodzaj_polisy' => [
                'type' => 'string',
                'enum' => [
                    'OC',
                    'OC/AC',
                    'OC/AC/NNW',
                    'OC/AC/NNW/Assistance',
                    'AC',
                    'NNW',
                    'Assistance',
                    'GAP',
                    'Przedłużona Gwarancja',
                    '',
                ],
            ],
            'data_sprzedazy_lubezpieczenia' => ['type' => 'string'],
            'data_sprzedazy_wznowienia' => ['type' => 'string'],
        ],
        'required' => [
            'stan_pojazdu',
            'nr_rejestracyjny',
            'marka',
            'model',
            'wersja',
            'vin',
            'forma_wlasnosci',
            'rocznik',
            'przebieg',
            'wartosc_pojazdu_brutto',
            'wartosc_pojazdu_netto',
            'kategoria_pojazdu',
            'sposob_korzystania',
            'typ_silnika',
            'pojemnosc_silnika',
            'data_nabycia',
            'data_pierwszej_rejestracji',
            'planowana_data_rejestracji',
            'wspolposiadacz',
            'imie_wspolposiadacza',
            'nazwisko_wspolposiadacza',
            'pesel_wspolposiadacza',
            'adres_wspolposiadacza',
            'pakiet_ubezpieczeniowy',
            'rodzaj_assistance',
            'towarzystwo_ubezpieczeniowe',
            'nr_polisy',
            'kategoria_tu',
            'data_konca_polisy',
            'cena_pakietu',
            'cena_wznowienia',
            'oc_cena',
            'ac_cena',
            'cena_nnw',
            'cena_assistance',
            'gap_cena',
            'cena_przedluzonej_gwarancji',
            'pochodzenie_polisy',
            'rodzaj_polisy',
            'data_sprzedazy_lubezpieczenia',
            'data_sprzedazy_wznowienia',
        ],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly ClaudeMessagesClient $client,
        private readonly PolicyDataResponseParser $parser = new PolicyDataResponseParser(),
        private readonly string $model = 'claude-sonnet-4-6',
        private readonly int $maxTokens = 4096
    ) {
    }

    public function extract(string $pdfPath): ExtractedPolicyData
    {
        $pdfData = $this->readPdf($pdfPath);

        try {
            $response = $this->client->createMessage(
                $this->model,
                $this->maxTokens,
                [[
                    'role' => 'user',
                    'content' => [
                        DocumentBlockParam::with(source: Base64PDFSource::with(data: base64_encode($pdfData))),
                        TextBlockParam::with(text: self::PROMPT),
                    ],
                ]],
                // ['type' => 'adaptive']
                null,
                [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => self::OUTPUT_SCHEMA,
                    ],
                ],
            );
        } catch (APIException $exception) {
            throw new AppException(502, 'claude_policy_extraction_failed', 'Claude policy extraction request failed.', [
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->parser->parse($response);
    }

    private function readPdf(string $pdfPath): string
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new AppException(400, 'policy_pdf_not_readable', 'Policy PDF file is not readable.', [
                'path' => $pdfPath,
            ]);
        }

        $data = file_get_contents($pdfPath);

        if ($data === false || $data === '') {
            throw new AppException(400, 'policy_pdf_not_readable', 'Policy PDF file is not readable.', [
                'path' => $pdfPath,
            ]);
        }

        return $data;
    }
}
