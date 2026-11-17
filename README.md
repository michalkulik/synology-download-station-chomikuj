# Chomikuj.pl dla Synology Download Station

Wtyczka do **Synology Download Station** (DSM 7.x), która pozwala przeszukiwać
zasoby **chomikuj.pl** i pobierać pliki bezpośrednio na NAS.

Składa się z dwóch modułów, dokładnie tak jak oficjalne wtyczki NoPremium /
Rapideo / TwojLimit:

| plik | rola | miejsce w DSM |
| --- | --- | --- |
| `chomikuj.host` | obsługa pobierania (logowanie, generowanie linku) | Ustawienia → **Hosting plików** |
| `chomikuj-search.dlm` | wyszukiwarka plików | Ustawienia → **Wyszukiwanie (BT Search)** |

## Funkcje

- wyszukiwanie plików w chomikuj.pl bezpośrednio z Download Station
  (działa **bez konta** — używa publicznej wyszukiwarki serwisu),
- pobieranie przez konto Chomikuj (login i hasło wpisywane w Download Station,
  tak jak przy NoPremium),
- rozpoznawanie linków do plików chomikuj.pl wklejonych ręcznie,
- obsługa błędów: brak transferu, plik usunięty, błędne hasło,
- automatyczne odświeżanie linku pobierania, gdy ten wygaśnie w kolejce.

## Wymagania

- Synology DSM 7.x z Download Station (testowano na DSM 7.2.2),
- konto chomikuj.pl,
- **transfer na koncie Chomikuj** — pobieranie plików innych użytkowników zużywa
  transfer (darmowe konto: 50 MB tygodniowo; większe pliki wymagają
  abonamentu/dodatkowego transferu). Bez transferu Download Station pokaże błąd
  „wymagane konto premium”.

## Instalacja

1. Pobierz `chomikuj.host` i `chomikuj-search.dlm` z sekcji **Releases**
   (albo zbuduj samodzielnie: `./build.sh`).
2. W Download Station: **Ustawienia → Hosting plików → Dodaj** i wskaż
   `chomikuj.host`.
3. Na liście pojawi się **Chomikuj.pl** — kliknij **Edytuj**, wpisz login i
   hasło do chomikuj.pl i zatwierdź. Download Station sprawdzi dane
   (komunikat o błędzie oznacza złe hasło lub brak połączenia).
4. W Download Station: **Ustawienia → Wyszukiwanie (BT Search) → Dodaj** i
   wskaż `chomikuj-search.dlm`.
5. Gotowe. W zakładce wyszukiwania wybierz źródło **Chomikuj.pl**, wpisz frazę
   i dodaj wybrane wyniki do pobierania.

Linki można też wklejać ręcznie, np.
`https://chomikuj.pl/<chomik>/<folder>/<plik>,123456789.mp4`.

## Jak to działa

Pobieranie korzysta z mobilnego API Chomikuj (`mobile.chomikuj.pl/api/v3`) —
tego samego, którego używa aplikacja Android:

1. `POST /api/v3/account/login` → `ApiKey` (klucz jest cache'owany w
   `/tmp/chomikuj_synology_apikey.json`, więc logowanie nie powtarza się przy
   każdym pobraniu; po restarcie DSM klucz jest odtwarzany z danych konta),
2. `GET /api/v3/files/download?fileId=...` → podpisany, czasowy adres pliku,
3. Download Station pobiera plik z tego adresu (bez cookies i bez logowania).

Wyszukiwanie używa publicznego formularza `https://chomikuj.pl/action/SearchFiles`
i parsuje listę wyników (tytuł, rozmiar, kategoria, link).

## Ograniczenia

- **Transfer**: to nie jest obejście limitów Chomikuj. Pobieranie odbywa się w
  ramach transferu przypisanego do konta. Plik większy niż dostępny transfer
  kończy się błędem „wymagane konto premium”.
- **Brak obsługi folderów**: wtyczka pobiera pojedyncze pliki (tak jak wyniki
  wyszukiwania). Wklejenie adresu folderu nie rozwinie go na listę plików.
- **API nieoficjalne**: mobilne API jest udokumentowane tylko przez
  inżynierię wsteczną. Chomikuj może je zmienić; wtedy wtyczka wymaga
  aktualizacji. Ustawiany jest User-Agent aplikacji Android, bo API odrzuca
  inne klienty („Not supported client version”).
- **Cloudflare**: przy nietypowym ruchu możliwa jest weryfikacja Cloudflare —
  wtyczka zwraca wtedy błąd „spróbuj później”.
- **Regulamin**: automatyczne pobieranie i korzystanie z niepublicznego API
  może naruszać regulamin chomikuj.pl. Używaj na własne ryzyko, najlepiej do
  własnych plików i legalnych treści.

## Diagnostyka

Log wtyczki: `/tmp/chomikuj_synology.log`. Cache klucza API:
`/tmp/chomikuj_synology_apikey.json` (prawa `0600`). Oba pliki znikają po
restarcie DSM.

Aby wymusić ponowne logowanie, usuń plik cache i ponownie zapisz konto w
ustawieniach Download Station.

## Budowanie

```sh
./build.sh
```

Wynik w `dist/`:

- `chomikuj.host` — archiwum tar.gz z `INFO` i `chomikuj.php`,
- `chomikuj-search.dlm` — archiwum tar.gz z `INFO` i `search.php`.

## Testy

`tests/search_test.php` sprawdza parser wyszukiwarki na zapisanym HTML
(wymaga tylko PHP CLI):

```sh
php tests/search_test.php tests/fixtures/search.html
```

`tests/host_test.php` loguje się do API i generuje link do pliku — wymaga PHP z
rozszerzeniem cURL oraz danych w zmiennych środowiskowych:

```sh
CHOMIKUJ_USER=twoj_login CHOMIKUJ_PASS=twoje_haslo \
  php tests/host_test.php 'https://chomikuj.pl/<chomik>/<folder>/<plik>,123456789.mp4'
```

## Licencja

MIT — patrz [LICENSE](LICENSE). Projekt nieoficjalny, niepowiązany z
Chomikuj.pl ani Synology.