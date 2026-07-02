## Instrukcja obsługi:
1. Wgrać polisę w PDF jako załącznik do ticketa - program odczytuje tylko pliki PDF już załączone do ticketa i nie daje możliwości wgrania nowego pliku (mogę to dodać jeżeli będzie taka potrzeba)
2. Otworzyć zakładkę "Dane polisy z PDF" i wybrać z listy plik PDF który ma zostać odczytany przez AI naciskając przycisk "Odczytaj" przy nazwie interesującego nas pliku. Jeżeli pliku nie ma na liście, ale jest w ticketcie - klikamy przycisk Odśwież. Jeżeli plik nadal nie pojawi się na liście - proszę o zgłoszenie mi błędu.
3. Pojawi się na górze zakładki informacja o trwającym odczycie, a gdy ten się zakończy - o sukcesie lub błędzie.
    - Jeżeli chcemy wybrać inny plik do oczytu, należy rozwinąć kartę "Załączniki PDF" aby ponownie wyświetlić listę plików do wybrania i kliknąć w przycisk "Odczytaj" przy nowo wybranym pliku.
4. Otworzy się formularz potwierdzania danych. Pola zostaną automatycznie wypełnione danymi odczytanymi przez AI, lub poprzednio zaakceptowanymi jeżeli dany plik został już odczytany i zaakceptowany.
    - Dla Waszej wygody wybrany plik PDF otworzy się też w karcie "Podgląd PDF" - jeżeli ekran jest szeroki, to po prawej stronie, jeżeli nie - to pod formularzem potwierdzania danych. Można w nim przewijać plik PDF i porównywać dane z formularza z tymi w pliku. Polecam używanie kółeczka myszki na trzy sposoby. Jeżeli ktoś jeszcze nie zna tych skrótów, to leci to w ten sposób (działa wszędzie na komputerze z Windowsem):
        - kółkiem myszki przewijamy w pionie, standard;
        - przytrzymując shift przewijamy w poziomie;
        - przytrzymując ctrl przybliżamy i oddalamy widok.
5. Sprawdzamy wartość każdego z pól i kiedy stwierdzimy, że się zgadza z tym co chcemy zapisać w Dakteli, klikamy przy nim checkbox "poprawne"
    - pola można edytować ręcznie, aby zmienić wartości na takie, jakie chcemy zapisać, jeżeli nie pasują nam te z odczytu przez AI
    - kliknięcie checkboxa "poprawne" przy polu BLOKUJE jego edycję, aby przypadkiem nie zmienić wartości, którą chcemy zapisać w Dakteli. Żeby edytować pole należy odznaczyć checkbox "poprawne"
    - przycisk "AI" w polu formularza nadpisuje jego treść tym, co AI odczytało z pliku PDF, jeżeli chcemy przywrócić wartość odczytaną przez AI
    - przycisk "⨯" w polu formularza czyści jego zawartość, jeżeli chcemy pozostawić to pole puste
    - Jeżeli jakaś wartość istnieje w systemie Dakteli: pod polem formularza pojawia się napis "W systemie: <wartość>" oraz przycisk "← użyj z systemu". Przycisk wypełnia zawartość pola formularza tym, co jest zapisane w Dakteli, wyświetloną obok.
    - pola pozostawione jako puste nie zmienią danych zapisanych już w Dakteli
    - pola niepuste zostaną zapisane w Dakteli
6. Kiedy przejrzymy wszystkie pola i zaznaczymy checkboxy przy tych, które są poprawne, klikamy przycisk "Zapisz". UWAGA: program wymusza zaznaczenie checkboxów "poprawne" przy wszystkich polach posiadających jakiś tekst. Jeżeli danego pola nie chcemy zmieniać, należy je wyczyścić. Taki mechanizm ma za zadanie chronić przed przypadkowym nadpisaniem pól w ticketcie błędnymi danymi.
7. Po kliknięciu "Zapisz" program zapisze w Dakteli wszystkie pola, które posiadają jakąś wartość:
- wprowadzi je do ticketa i rekordów CRM pojazdu i polisy, oraz dołączy przetworzony plik PDF do wpisu CRM dla polisy
- jeżeli rekord CRM dla pojazdu lub polisy nie istnieje, program utworzy go automatycznie
- jeżeli w ticketcie już istnieje rekord CRM z pasującym numerem rejestracyjnym lub numerem VIN, program zaktualizuje je o nowe dane i dołączy następny plik polisy, o ile ma on inną nazwę niż pliki już dołączone do rekordu CRM polisy
- jeżeli w ticketcie już istnieje więcej niż jeden rekord CRM z pasującym numerem rejestracyjnym lub numerem VIN, program PRZERWIE PRACĘ i wyświetli komunikat z prośbą o usunięcie nadmiarowych rekordów CRM, aby pozostał tylko jeden rekord pojazdu i jeden rekord polisy dla danego numeru rejestracyjnego/VIN. W takim przypadku należy usunąć nadmiarowe rekordy CRM i ponownie kliknąć przycisk "Zapisz" w formularzu potwierdzania danych.

## Uwagi końcowe:
- można użyć formularza do wygodnego wpisania wszystkich tych danych, nawet jeżeli nie pochodziły one z odczytu AI lub nie są zawarte w polisie, należy jednak mieć na uwadze, że przy ponownej próbie odczytu tego pliku, to ZATWIERDZONE dane zostaną wczytane do formularza, a nie dane odczytane z polisy. Proponuję więc wpisywać w ten sposób tylko te dane, które mają związek z odczytanym plikiem PDF
- można odczytywać i zapisywać po kolei więcej niż jeden plik PDF dla tego samego ticketa - tym bardziej zachęcam do ręcznego wpisywania do formularza tylko danych dotyczących odczytanego pliku PDF, aby nie wprowadzać niepotrzebnego zamieszania. Nie chcemy przecież, żeby jeden plik np. OC nadpisywał dane pliku dotyczącego innego produktu, np. GAPa, bo potem przy zmianie np. tylko GAPa, stare dane GAP będą dalej przypisane do pliku OC
