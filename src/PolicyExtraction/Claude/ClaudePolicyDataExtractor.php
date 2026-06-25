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

Nie wymyślaj danych. Każde pole może mieć wartość null, jeśli nie da się go jednoznacznie potwierdzić w polisie.

Zwróć wyłącznie poprawny JSON z dokładnie tymi kluczami:
{
  "stan_pojazdu": "Nowy" | "Używany" | "Nieznany" | null,
  "nr_rejestracyjny": string | null,
  "marka": string | null,
  "model": string | null,
  "wersja": string | null,
  "vin": string | null,
  "forma_wlasnosci": "Własny" | "Leasing" | "Bank" | "Wynajem" | null,
  "rocznik": string | null,
  "przebieg": string | null,
  "wartosc_pojazdu_brutto": string | null,
  "wartosc_pojazdu_netto": string | null,
  "kategoria_pojazdu": "Osobowy (Kat. M1)" | "Ciężarowy - LCV (DMC do 3500kg) Kat. N1" | "Motocykle i inne pojazdy (kat.L)" | null,
  "sposob_korzystania": "Standardowy" | "Taxi" | null,
  "typ_silnika": "Benzynowy" | "CNG/LPG" | "Diesel" | "Elektryczny" | "Hybryda" | null,
  "pojemnosc_silnika": string | null,
  "data_nabycia": string | null,
  "data_pierwszej_rejestracji": string | null,
  "planowana_data_rejestracji": string | null,
  "wspolposiadacz": "tak" | "nie" | null,
  "imie_wspolposiadacza": string | null,
  "nazwisko_wspolposiadacza": string | null,
  "pesel_wspolposiadacza": string | null,
  "adres_wspolposiadacza": string | null,
  "pakiet_ubezpieczeniowy": "tak" | "nie" | null,
  "rodzaj_assistance": "minimalny" | "Polska" | "Europa (500-700km)" | "Europa (1000km)" | "Europa (+1500km)" | null,
  "towarzystwo_ubezpieczeniowe": "Alianz" | "Aviva" | "AXA" | "Balcia" | "Benefia" | "Compensa" | "Concordia" | "Defend" | "Ergo Hestia" | "Ergo Hestia - Pakiet Dealerski" | "Ergo Hestia - Pakiet Dealerski polisa za 1zł" | "Euroins" | "Generali" | "Gothaer" | "HDI" | "Inne" | "Interrisk" | "Liberty Ubezpieczenia" | "Link4" | "Met Life" | "MTU" | "NN Życie" | "Open Life" | "PKO Ubezpieczenia" | "Polisa - Życie" | "Polskie Towarzystwo Reasekuracji" | "Proama" | "PTU" | "PZM" | "PZU" | "PZU - pakiet dealerski SIGMA" | "RESO Europa" | "Saltus" | "Signal Iduna" | "TU Europa" | "TUW" | "TUZ" | "Uniqa" | "Vienna Life" | "Warta" | "Wefox" | "Wiener" | null,
  "nr_polisy": string | null,
  "kategoria_tu": "Partner InGreen" | "Asap" | "Wiktoria" | null,
  "data_konca_polisy": string | null,
  "cena_pakietu": string | null,
  "cena_wznowienia": string | null,
  "pc_cena": string | null,
  "ac_cena": string | null,
  "cena_nnw": string | null,
  "cena_assistance": string | null,
  "gap_cena": string | null,
  "cena_przedluzonej_gwarancji": string | null,
  "pochodzenie_polisy": string | null,
  "rodzaj_polisy": "OC" | "OC/AC" | "OC/AC/NNW" | "OC/AC/NNW/Assistance" | "AC" | "NNW" | "Assistance" | "GAP" | "Przedłużona Gwarancja" | null,
  "data_sprzedazy_lubezpieczenia": string | null,
  "data_sprzedazy_wznowienia": string | null
}

Reguły normalizacji:
- rocznik zwróć jako rok produkcji pojazdu.
- przebieg zwróć w kilometrach.
- wartosc_pojazdu_brutto i wartosc_pojazdu_netto zwróć w PLN, jeśli są dostępne.
- forma_wlasnosci zwróć tylko wtedy, gdy polisa jednoznacznie wskazuje jedną z wartości: "Własny", "Leasing", "Bank", "Wynajem".
- pojemnosc_silnika zwróć w cm3.
- data_pierwszej_rejestracji dotyczy tylko pojazdów używanych; dla pojazdów nowych zwróć null, chyba że polisa jednoznacznie podaje tę datę.
- planowana_data_rejestracji dotyczy tylko pojazdów nowych; dla pojazdów używanych zwróć null.
- wspolposiadacz zwróć jako "tak", jeśli polisa wskazuje więcej niż jednego właściciela, współwłaściciela lub współposiadacza pojazdu; zwróć "nie", jeśli polisa jednoznacznie wskazuje tylko jednego właściciela/posiadacza. Jeśli wspolposiadacz to "tak", uzupełnij dane współposiadacza na podstawie polisy.
- pakiet_ubezpieczeniowy zwróć jako "tak", jeśli dokument dotyczy całego pakietu ubezpieczeniowego; zwróć "nie", jeśli dotyczy tylko pojedynczego produktu AC, NNW, Assistance, GAP albo Przedłużona Gwarancja.
- rodzaj_assistance zwróć tylko wtedy, gdy zakres assistance da się jednoznacznie dopasować do jednej z podanych wartości.
- nr_polisy zwróć jako numer polisy.
- ceny zwróć z walutą, jeśli jest dostępna.
- cena_pakietu dotyczy ceny pakietu za pierwszy rok.
- cena_wznowienia, pc_cena, ac_cena, cena_nnw, cena_assistance, gap_cena i cena_przedluzonej_gwarancji zwróć tylko wtedy, gdy dokument jednoznacznie wskazuje odpowiednią składkę/cenę.
- rodzaj_polisy zwróć tylko wtedy, gdy zakres polisy da się jednoznacznie dopasować do jednej z podanych wartości.
- data_sprzedazy_lubezpieczenia dotyczy sprzedaży ubezpieczenia za pierwszy rok, a data_sprzedazy_wznowienia sprzedaży wznowienia.
- Jeśli dokument podaje "/", "-", "brak", puste pole albo wartość niejednoznaczną, zwróć null.
PROMPT;

    private const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'stan_pojazdu' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['Nowy', 'Używany', 'Nieznany']],
                    ['type' => 'null'],
                ],
            ],
            'nr_rejestracyjny' => ['type' => ['string', 'null']],
            'marka' => ['type' => ['string', 'null']],
            'model' => ['type' => ['string', 'null']],
            'wersja' => ['type' => ['string', 'null']],
            'vin' => ['type' => ['string', 'null']],
            'forma_wlasnosci' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['Własny', 'Leasing', 'Bank', 'Wynajem']],
                    ['type' => 'null'],
                ],
            ],
            'rocznik' => ['type' => ['string', 'null']],
            'przebieg' => ['type' => ['string', 'null']],
            'wartosc_pojazdu_brutto' => ['type' => ['string', 'null']],
            'wartosc_pojazdu_netto' => ['type' => ['string', 'null']],
            'kategoria_pojazdu' => [
                'anyOf' => [
                    [
                        'type' => 'string',
                        'enum' => [
                            'Osobowy (Kat. M1)',
                            'Ciężarowy - LCV (DMC do 3500kg) Kat. N1',
                            'Motocykle i inne pojazdy (kat.L)',
                        ],
                    ],
                    ['type' => 'null'],
                ],
            ],
            'sposob_korzystania' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['Standardowy', 'Taxi']],
                    ['type' => 'null'],
                ],
            ],
            'typ_silnika' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['Benzynowy', 'CNG/LPG', 'Diesel', 'Elektryczny', 'Hybryda']],
                    ['type' => 'null'],
                ],
            ],
            'pojemnosc_silnika' => ['type' => ['string', 'null']],
            'data_nabycia' => ['type' => ['string', 'null']],
            'data_pierwszej_rejestracji' => ['type' => ['string', 'null']],
            'planowana_data_rejestracji' => ['type' => ['string', 'null']],
            'wspolposiadacz' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['tak', 'nie']],
                    ['type' => 'null'],
                ],
            ],
            'imie_wspolposiadacza' => ['type' => ['string', 'null']],
            'nazwisko_wspolposiadacza' => ['type' => ['string', 'null']],
            'pesel_wspolposiadacza' => ['type' => ['string', 'null']],
            'adres_wspolposiadacza' => ['type' => ['string', 'null']],
            'pakiet_ubezpieczeniowy' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['tak', 'nie']],
                    ['type' => 'null'],
                ],
            ],
            'rodzaj_assistance' => [
                'anyOf' => [
                    [
                        'type' => 'string',
                        'enum' => [
                            'minimalny',
                            'Polska',
                            'Europa (500-700km)',
                            'Europa (1000km)',
                            'Europa (+1500km)',
                        ],
                    ],
                    ['type' => 'null'],
                ],
            ],
            'towarzystwo_ubezpieczeniowe' => [
                'anyOf' => [
                    [
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
                        ],
                    ],
                    ['type' => 'null'],
                ],
            ],
            'nr_polisy' => ['type' => ['string', 'null']],
            'kategoria_tu' => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['Partner InGreen', 'Asap', 'Wiktoria']],
                    ['type' => 'null'],
                ],
            ],
            'data_konca_polisy' => ['type' => ['string', 'null']],
            'cena_pakietu' => ['type' => ['string', 'null']],
            'cena_wznowienia' => ['type' => ['string', 'null']],
            'pc_cena' => ['type' => ['string', 'null']],
            'ac_cena' => ['type' => ['string', 'null']],
            'cena_nnw' => ['type' => ['string', 'null']],
            'cena_assistance' => ['type' => ['string', 'null']],
            'gap_cena' => ['type' => ['string', 'null']],
            'cena_przedluzonej_gwarancji' => ['type' => ['string', 'null']],
            'pochodzenie_polisy' => ['type' => ['string', 'null']],
            'rodzaj_polisy' => [
                'anyOf' => [
                    [
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
                        ],
                    ],
                    ['type' => 'null'],
                ],
            ],
            'data_sprzedazy_lubezpieczenia' => ['type' => ['string', 'null']],
            'data_sprzedazy_wznowienia' => ['type' => ['string', 'null']],
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
            'pc_cena',
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
