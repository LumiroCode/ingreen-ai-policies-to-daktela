## Overview
Kiedy polisa jest odczytana i zatwierdzona, jej dane powinny trafić do odpowiednich wpisów CRM. Product Owner wyszczególnił trzy działania, które należy wykonać:
- tworzył się/ aktualizował CRM Record tą polisą, który będzie podpięty do ticketu z tym numerem rejestracyjnym pojazdu na wzór tego https://ingreen.daktela.com/crm/records/type_68227cae0ac91290315441/update/record_691b427bdab5e761907999#attachments
- tworzył się/aktualizował CRM record pojazd z danymi pojazdu https://ingreen.daktela.com/crm/records/type_68227f433880e473202607/update/record_6a3b67f9f200d797425479
- aktualizowały się wszystkie dane w tickecie, w którym otworzono zakładkę z niniejszą aplikacją. Wszystkie jakie możemy z polisy pobrać i odszukać odwiednie pola w formularzu ticketu

## Szczegóły
Ależy najpierw wpisać odczytane dane do ticketa - w ten sposób tworzymy bazę pod uzupełnienie danych w CRM.

### Ticket
Należy zaktualizować wszystkie pola w tickecie nad którym pracujemy, które możemy odczytać z polisy. Pola są opisane w pliku docs/Dodawanie danych polisy/Ticket.md

### Wpis CRM - polisy
Należy wziąć wpisy CRM PRZYPISANE DO TICKETA i wybrać z nich pasujące po numerze rejestracyjnym pojazdu albo po numerze VIN (dopasowanie albo tego albo tego włącza wpis CRM do puli roboczej).
Poniżej opisane są przypadki które należy obsłużyć:
- Jeżeli nie ma pasującego wpisu CRM dla polisy, należy go utworzyć.
- Jeżeli jest 1 pasujący wpis CRM dla polisy, należy go zaktualizować.
- Jeżeli jest więcej niż 1 pasujący wpis CRM dla polisy, wyświetlić użytkownikowi komunikat o błędzie wyjaśniającym, że znaleziono więcej niż 1 pasujący wpis CRM dla polisy i poprosić o usunięcie zbędnych wpisów CRM polis z numerem rejestracyjnym pojazdu lub numerem VIN, aby pozostał tylko jeden wpis CRM dla polisy, oraz poinformować że po usunięciu zbędnych wpisów CRM polisy będzie można spróbować ponownie.

W dopasowanym ticketcie należy zaktualizować wszystkie pola, które możemy odczytać z polisy.
Przy braku dopasowania wpisu CRM dla polisy, należy go utworzyć i przypisać do ticketa.

Pola, które należy wypełnić w dopasowanym wpisie CRM dla polisy, są opisane w pliku docs/Dodawanie danych polisy/Wpis CRM - polisy.md


### Wpis CRM - pojazdy
Należy wziąć wpisy CRM PRZYPISANE DO TICKETA i wybrać z nich pasujące po numerze rejestracyjnym pojazdu albo po numerze VIN (dopasowanie albo tego albo tego włącza wpis CRM do puli roboczej).
Poniżej opisane są przypadki które należy obsłużyć:
- Jeżeli nie ma pasującego wpisu CRM dla pojazdu, należy go utworzyć.
- Jeżeli jest 1 pasujący wpis CRM dla pojazdu, należy go zaktualizować.
- Jeżeli jest więcej niż 1 pasujący wpis CRM dla pojazdu, wyświetlić użytkownikowi komunikat o błędzie wyjaśniającym, że znaleziono więcej niż 1 pasujący wpis CRM dla pojazdu i poprosić o usunięcie zbędnych wpisów CRM pojazdów z numerem rejestracyjnym pojazdu lub numerem VIN, aby pozostał tylko jeden wpis CRM dla pojazdu, oraz poinformować że po usunięciu zbędnych wpisów CRM pojazdu będzie można spróbować ponownie.

W dopasowanym ticketcie należy zaktualizować wszystkie pola, które możemy odczytać z polisy.
Przy braku dopasowania wpisu CRM dla polisy, należy go utworzyć i przypisać do ticketa.

Pola, które należy wypełnić w dopasowanym wpisie CRM dla polisy, są opisane w pliku docs/Dodawanie danych polisy/Wpis CRM - pojazdy.md
