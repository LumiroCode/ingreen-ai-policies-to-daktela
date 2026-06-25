## Dane
Dane bierzemy z polisy i z ticketa. Zanim wrzucimy dane do rekordu CRM, zaktualizowany zostanie ticket, więc dane odczytane i z ticketa powinny się zgadzać.

## Pola
- title: zawsze "Pojazdy"
- user: właściciel ticketa
- stage: "Otwórz"
- contact: link do kontaktu do klienta znajdującego się już w ticketcie źródłowym
- account: link do konta klienta znajdującego się już w ticketcie źródłowym
- ticket: link do ticketu źródłowego
- status: "Żaden"
- description: ""

- Dane pojazdu
    - nr_rejestracyjny: numer rejestracyjny pojazdu
    - marka: marka pojazdu
    - model: model pojazdu
    - wersja: wersja wyposażenia pojazdu
    - vin: numer VIN pojazdu
    - forma_wlasnosci: wybór z listy Własny, Leasing, Bank, Wynajem - Zapytać Tomka / sprawdzić czy info jest na polisie
    - rocznik: Rok produkcji pojazdu
    - przebieg: przebieg pojazdu w km
    - data_pierwszej_rejestracji: data pierwszej rejestracji pojazdu
    - wartosc_pojazdu_brutto: wartość pojazdu w PLN
    - wspolposiadacz: tak/nie - wziąć z polisy; jeżeli pojazd ma więcej niż jednego współwłaściciela; usupełnić wtedy też dane współwłaściciela(i). Wymaga wypełnienia następujących pól:
        - imie_wspolposiadacza: imię współwłaściciela
        - nazwisko_wspolposiadacza: nazwisko współwłaściciela
        - pesel_wspolposiadacza: PESEL współwłaściciela
        - adres_wspolposiadacza: adres zamieszkania współwłaściciela
