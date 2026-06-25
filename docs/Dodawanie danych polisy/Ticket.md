## Pola
Należy wypełnić z polisy pola w ticketcie z następujących kategorii:
- Dane pojazdu
- Ubezpieczenie
Pozostałe kategorie pól są na razie OUT OF SCOPE!

### Dane pojazdu
- stan_pojazdu: wybór z listy Nowy, Używany, Nieznany
- marka: marka pojazdu
- model: model pojazdu
- wersja: wersja wyposażenia pojazdu
- vin: numer VIN pojazdu
- rocznik: Rok produkcji pojazdu
- przebieg: przebieg pojazdu w km
- wartosc_pojazdu_brutto: wartość pojazdu w PLN
- wartosc_pojazdu_netto: wartość pojazdu w PLN
- kategoria_pojazdu: Osobowy (Kat. M1) / Ciężarowy - LCV (DMC do 3500kg) Kat. N1 / Motocykle i inne pojazdy (kat.L)
- sposob_korzystania: Standardowy / Taxi
- typ_silnika: Benzynowy / CNG/LPG / Diesel / Elektryczny / Hybryda
- pojemnosc_silnika: pojemność silnika w cm3
- data_nabycia: data nabycia pojazdu
- data_pierwszej_rejestracji: data pierwszej rejestracji pojazdu; tylko dla pojazdów używanych; LUB /
- planowana_data_rejestracji: planowana data rejestracji pojazdu; tylko dla pojazdów nowych

Poniższe tylko dla samochodów marki Tesla
- kolor: kolor pojazdu; Pearl White Multi-Coat / Diamond Black / Stealth Grey / Marin Blue / Quicksilver / Ultra Red
- hak_holowniczy: tak/nie
- kolor_wnetrza: kolor wnętrza pojazdu; All Black / Black & White
- pakiet_autopilot: Podstawowy / Rozszerzony / Pełny

### Ubezpieczenie
- pakiet_ubezpieczeniowy: czy to jest cały pakiet ubezpieczeniowy czy tylko AC, NNW, Assistance, GAP, Przedłużona Gwarancja; tak/nie
- rodzaj_assistance: minimalny / Polska / Europa (500-700km) / Europa ( 1000km) / Europa (+1500km)
- towarzystwo_ubezpieczeniowe: Alianz / Aviva / AXA / Balcia / Benefia / Compensa / Concordia / Defend / Ergo Hestia / Ergo Hestia - Pakiet Dealerski / Ergo Hestia - Pakiet Dealerski polisa za 1zł / Euroins / Generali / Gothaer / HDI / Inne / Interrisk / Liberty Ubezpieczenia / Link4 / Met Life / MTU / NN Życie / Open Life / PKO Ubezpieczenia / Polisa - Życie / Polskie Towarzystwo Reasekuracji / Proama / PTU / PZM / PZU / PZU - pakiet dealerski SIGMA / RESO Europa / Saltus / Signal Iduna / TU Europa / TUW / TUZ / Uniqa / Vienna Life / Warta / Wefox / Wiener
- kategoria_tu: kategoria towarzystwa ubezpieczeniowego; Partner InGreen / Asap / Wiktoria
- data_konca_polisy: data końca polisy
- cena_pakietu: Cena Pakietu pierwszy rok
- data_sprzedazy_lubezpieczenia: Data sprzedaży ubezpieczenia pierwszy rok
- cena_suma_skladek_online: NIE Z POLISY - POMIJAMY
- cena_suma_skladek_online_promo: NIE Z POLISY - POMIJAMY

### ~~Ubezpieczenia dodatkowe~~
#OUT-OF-SCOPE
- oc; tak / nie / do decyzji
- oc_cena
- ac; tak / nie / do decyzji
- ac_cena
- nnw; tak / nie / do decyzji
- nnw_cena
- assistance; tak / nie / do decyzji
- cena_assistance
- ochrona_utraty_znizki; tak / nie / do decyzji
- cena_ochrona_utraty_znizek
- stala_suma_ubezpieczenia; tak / nie / do decyzji
- cena_stala_suma
- ubezpieczenie_szyb; tak / nie / do decyzji
- cena_ubezpieczenie_szyb
- nastepstwa_nieszczesliwych_wypadkow: kwota wykupionego NNW, jest to coś innego niż stwierdzenie, czy NNW było kupione samodzielnie czy w ramach pakietu ubezpieczeniowego; tak / nie / do decyzji
- rodzaj_nnw: 20 tys / 30 tys / 40 tys / 50 tys / 60 tys / 70 tys / 80 tys / 90 tys / 100 tys
- cena_nnw: cena dodatkowych NNW
- zielona_karta: tak / nie / do decyzji
- cena_zielona_karta
- ochrona_prawna: tak / nie / do decyzji
- cena_ochrona_prawna
- wallbox
- cena_wallbox
- car_assistance_prestiz_plus: tak / nie / do decyzji
- cena_car_assistance_pp
- mediplan: tak / nie / do decyzji
- cena_mediplan
- bagaz: tak / nie / do decyzji
- cena_bagaz
- drugi_komplet_kol: tak / nie / do decyzji
- cena_drugi_komplet_kol
- ochrona_powlok_lakierniczych: tak / nie / do decyzji
- rodzaj_powlok: tekst; Label to "Suma powłok" ?

### ~~GAP~~
#OUT-OF-SCOPE
- rodzaj_gap: własne / z leasingiem
- gap_cena
- cena_miesieczna_gap
- marza_gap
- data_startu_gap
- data_konca_polisy_gap
- data_sprzedazy_gap
- czy_wartosc_pojazdu_na_polisie_ac_jest_wartoscia_netto_: Tak / 50% / Nie
- towarzystwo_ubezpieczeniowe_gap: Defend / Wagas
- okres_gap: 2 lata / 3 lata / 4 lata / 5 lat
- platnosc_gap: Płatność jednorazowa / Płatność roczna
- data_platnosci_gap: Data kiedy klient zapłacił za polisę, wg systemu TU
- data_1_raty_gap
- data_2_raty_gap
- data_3_raty_gap
- data_4_raty_gap
- data_5_raty_gap
- limit_odszkodowania_gap: 50 000 zł / 100 000 zł / 150 000 zł / 200 000 zł / 250 000 zł / 300 000 zł
- rodzaj_platnosci: Płatne przez klienta ( blik, karta, szybki przelew) / Przelew tradycyjny / Raty miesięczne PayU / Płatne przez dealera

### ~~Przedłużona Gwarancja~~
#OUT-OF-SCOPE
- cena_przedluzonej_gwarancji
- prowizja_gwarancja
- czy_pojazd_jest_na_gwarancji_producenta: Tak / Nie
- okres_gwarancji_producenta: 6 / 12 / 24 / 36 / 48 / 60 / 72 / 84
- przebieg_objety_gwarancja_producenta: 100 000 km / 150 000 km / 250 000 km / bez limitu
- data_rozpoczecia_gwarancji_producenta
- data_rozpoczecia_gwarancji_producenta_jest_taka_sama_jak_data_pi: Data rozpoczęcia gwarancji producenta jest taka sama jak data pierwszej rejestracji pojazdu; Tak / Nie
- przedluzona_gwarancja: Tak / Nie / Do decyzji
- gwarancja_cena
- data_sprzedazy_gwarancji
- towarzystwo_ubezpieczeniowe_gwarancja: Defend / Wagas
- data_konca_polisy_gwarancja
- cena_zakupu_na_jedno_zdarzenie: 5 000 zł na jedno zdarzenie, 10 000 zł na wszystkie zdarzenia / 10 000 zł na jedno zdarzenie, 20 000 zł na wszystkie zdarzenia / 10 000 zł na jedno zdarzenie, 30 000 zł na wszystkie zdarzenia / 10 000 zł na jedno zdarzenie, 40 000 zł na wszystkie zdarzenia / 15 000 zł na jedno zdarzenie, 30 000 zł na wszystkie zdarzenia / 20 000 zł na jedno zdarzenie, 40 000 zł na wszystkie zdarzenia / 20 000 zł na jedno zdarzenie, cena zakupu na wszystkie zdarzenia / 30 000 zł na jedno zdarzenie, cena zakupu na wszystkie zdarzenia / 40 000 zł na jedno zdarzenie, cena zakupu na wszystkie zdarzenia / 80 000 zł na jedno zdarzenie, cena zakupu na wszystkie zdarzenia / Cena zakupu na jedno zdarzenie, cena zakupu na wszystkie zdarzenia ?
- okres_ubezpieczenia_gwarancji: 6 msc / 1 rok / 2 lata / 3 lata
- roczny_przebieg_bez_limitu: tekst ?
- udzial_wlasny: bez udiału własnego / udział własny 750zł / udział własny 1 500zł
- forma_platnosci_gwarancja: Płatne przez klienta ( blik, karta, szybki przelew) / Przelew tradycyjny / Raty miesięczne PayU / Płatne przez dealera
- dodatkowe_opcje_ubezpieczenia: Zwrot kosztów wypożyczenia pojazdu / Komponenty LPG/CNG / Komponenty napędu hybrydowego (MHEV, HEV, PHEV)
- system_multimedialny: System multimedialny: 10 000 zł / System multimedialny: 20 000 zł / System multimedialny: 30 000 zł

### ~~Wynajem~~
#OUT-OF-SCOPE
- okres_finansowania_najmu
- wklad_wlasny_kwota_brutto_lub___wynajem
- jaka_maksymalna_rata_wynajem: 1 000 zł / 1 500 zł / 2 000 zł / 2 500 zł / 3 000 zł / 3 500 zł / 4 000 zł / 5 000 zł / 6 000 zł / powyżej 6 000 zł
- dofinansowaniem_naszeauto_wynajem: Tak / Nie
- nazwa_finansujacego: Arval / Masterlease
- data_konca_wynajmu
- regon_finansujacego
- prowizja_wynajem
- data_sprzedazy_wynajmu

Tylko jeżeli dofinansowaniem_naszeauto_wynajem = Tak, należy wypełnić poniższe pola:
- bedzie_zlomowal_stare_auto_wynajem: Tak / Nie
- czy_chcialby_przekazac_nam_proces_dotacji_wynajem: Tak / Nie
