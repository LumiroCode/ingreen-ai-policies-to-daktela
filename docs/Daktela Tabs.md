Dodanie zakładki o takim adresie w ustawieniach:

```url
https://ingreen360.pl/daktela-test?ticket1={{name}}&ticket2={{ticket.name}}&title={{fn.urlencode(title)}}
```

skutkuje wywołaniem następującego adresu URL w ticketcie:
```url
https://ingreen360.pl/daktela-test?ticket1=11170&ticket2=&title=ZAMOWFILM%2BDamian%2BNojek%2B%257CTM3%257CRN127614471%257C1329688%252F2026%252F03%252FA%252F1%252FW
```

A więc użycie `{{name}}` daje nam rzetelny sposób na przekazanie numeru ticketu do naszej aplikacji
