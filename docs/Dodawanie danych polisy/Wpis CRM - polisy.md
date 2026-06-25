## Dane
Dane bierzemy z polisy i z ticketa. Zanim wrzucimy dane do rekordu CRM, zaktualizowany zostanie ticket, więc dane odczytane i z ticketa powinny się zgadzać.

## Pola
- title: zawsze "Polisy"
- user: właściciel ticketa
- stage: "Otwórz"
- contact: link do kontaktu do klienta znajdującego się już w ticketcie źródłowym
- account: link do konta klienta znajdującego się już w ticketcie źródłowym
- ticket: link do ticketu źródłowego
- status: "Żaden"
- description: ""

- Dane Pojazdu
    - marka: marka pojazdu
    - model: model pojazdu
    - nr_rejestracyjny: numer rejestracyjny pojazdu

- Dane ubezpieczenia
    - towarzystwo_ubezpieczeniowe: nazwa towarzystwa ubezpieczeniowego z listy
    - nr_polisy: numer polisy
    - cena_pakietu: Cena pakietu pierwszy rok (z ticketu)
    - cena_wznowienia: ? (z ticketu) / alternatywna dla ceny pakietu pierwszy rok
    - pc_cena: ? (z ticketu) / alternatywna dla ceny pakietu pierwszy rok
    - ac_cena: ? (z ticketu) / alternatywna dla ceny pakietu pierwszy rok
    - cena_nnw: ? (z ticketu) / alternatywna dla ceny pakietu pierwszy rok
    - cena_assistance: ? (z ticketu) / alternatywna dla ceny pakietu pierwszy rok
    - gap_cena: ? (z ticketu) / alternatywna dla ceny pakietu pierwszy rok
    - cena_przedluzonej_gwarancji: ? (z ticketu) / alternatywna dla ceny pakietu pierwszy rok
    - pochodzenie_polisy: ? (z ticketu)
    - rodzaj_polisy: Wybór z listy OC, OC/AC, OC/AC/NNW, OC/AC/NNW/Assistance, AC, NNW, Assistance, GAP, Przedłużona Gwarancja
    - data_konca_polisy: data końca polisy
    - data_sprzedazy_lubezpieczenia: Data sprzedaży ubezpieczenia pierwszy rok / LUB
    - data_sprzedazy_wznowienia - zaktualizować z polisy

- Załączniki: PDF z polisą
