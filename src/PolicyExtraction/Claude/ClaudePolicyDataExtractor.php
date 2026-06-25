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
Odczytaj dane pojazdu z załączonej polisy.

W swoim wewnętrznym procesie myślowym najpierw wypisz wszystkie znalezione w dokumencie pary klucz-wartość, które mogą dotyczyć pojazdu lub polisy, a następnie zwaliduj każdą wybraną wartość w oparciu o treść załączonej polisy. Nie zwracaj tej analizy w odpowiedzi końcowej.

Nie wymyślaj danych. Każde pole może mieć wartość null, jeśli nie da się go jednoznacznie potwierdzić w polisie.

Zwróć wyłącznie poprawny JSON z dokładnie tymi kluczami:
{
  "stan_pojazdu": "Nowy" | "Używany" | "Nieznany" | null,
  "marka": string | null,
  "model": string | null,
  "wersja": string | null,
  "vin": string | null,
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
  "planowana_data_rejestracji": string | null
}

Reguły normalizacji:
- rocznik zwróć jako rok produkcji pojazdu.
- przebieg zwróć w kilometrach.
- wartosc_pojazdu_brutto i wartosc_pojazdu_netto zwróć w PLN, jeśli są dostępne.
- pojemnosc_silnika zwróć w cm3.
- data_pierwszej_rejestracji dotyczy tylko pojazdów używanych; dla pojazdów nowych zwróć null, chyba że polisa jednoznacznie podaje tę datę.
- planowana_data_rejestracji dotyczy tylko pojazdów nowych; dla pojazdów używanych zwróć null.
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
            'marka' => ['type' => ['string', 'null']],
            'model' => ['type' => ['string', 'null']],
            'wersja' => ['type' => ['string', 'null']],
            'vin' => ['type' => ['string', 'null']],
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
        ],
        'required' => [
            'stan_pojazdu',
            'marka',
            'model',
            'wersja',
            'vin',
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
