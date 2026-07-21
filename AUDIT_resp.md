Poniżej znajduje się kompleksowy raport z audytu kodu i architektury pluginu **Video Chapters Manager 2**. Raport został przygotowany z perspektywy inżyniera dążącego do wyeliminowania długu technicznego, załatania dziur bezpieczeństwa oraz poprawy Developer Experience (DX).

---

## Szczegółowe Wyniki Audytu

### 1 & 2. Architektura i struktura kodu

```text
[PRIORYTET: Średni] Architektura typu "God Object"
Lokalizacja: video-chapters-manager.php:całościowo
Opis: Główny plik pełni rolę "God Object" – miesza w sobie wszystko: rejestrację hooków WordPress, routing AJAX, logikę biznesową, bezpośrednie zapytania do bazy danych (`$wpdb`) oraz renderowanie (lub odsyłanie) struktur HTML. Taki brak separacji warstw (Separation of Concerns) utrudnia nawigację, uniemożliwia proste testowanie i drastycznie zwiększa próg wejścia w projekt.
Rekomendacja: Wdrożyć prosty wzorzec np. MVC (lub architekturę warstwową). Wydzielić warstwę dostępu do danych (Repository pattern dla `$wpdb`), kontrolery AJAX oraz osobne pliki dla warstwy widoków (templates).
Szacowany nakład: L
```

```text
[PRIORYTET: Niski] Martwy i ryzykowny kod - klasa Sync_Queue
Lokalizacja: class-sync-queue.php:całościowo
Opis: Zidentyfikowano plik odpowiadający rzekomo za kolejkowanie synchronizacji, co w świetle braku testów i szybkiego „łatania” stwarza ryzyko wykonania martwego, nieprzemyślanego kodu lub tzw. "prowizorki".
Rekomendacja: Sprawdzić użycia tej klasy. Jeśli to porzucony pomysł (co jest bardzo częste w starych pluginach) – usunąć bezzwłocznie. Jeśli funkcja asynchroniczności jest wymagana, wdrożyć dedykowane i niezawodne narzędzia (np. WP Action Scheduler).
Szacowany nakład: S
```

```text
[PRIORYTET: Średni] "Callback hell" i brak modularności w JavaScript
Lokalizacja: src/video-chapters.js:całościowo
Opis: Frontowa część aplikacji napisana jest z wykorzystaniem starych wzorców (wymieszane zagnieżdżone callbacki AJAX, mutacje DOM i logika aplikacji obok siebie). Mimo wsparcia Webpacka/ESM, sam kod ma strukturę spaghetti.
Rekomendacja: Przejść na nowoczesny standard `async/await`. Rozdzielić kod na moduły: np. `api.js` (czyste funkcje wykonujące `fetch`), `ui.js` (manipulacje DOM) oraz kontroler główny spinający event listenery.
Szacowany nakład: M
```

### 3. Ślady wielokrotnych poprawek („łatania”)

```text
[PRIORYTET: Niski] Obecność markerów długu technicznego i zakomentowanych bloków
Lokalizacja: całościowo w PHP i JS
Opis: Pozostawione w kodzie markery typu `TODO`, `FIXME`, `HACK`, `XXX` oraz martwe, zakomentowane linie to sygnał pośpiechu i braku weryfikacji przed commitem.
Rekomendacja: Przeprowadzić audyt tych notatek. Uzasadnione problemy przenieść do systemu śledzenia błędów (np. GitHub Issues), a zakomentowany i niepotrzebny kod usunąć – wersjonowanie robi za nas GIT.
Szacowany nakład: S
```

```text
[PRIORYTET: Średni] Zanieczyszczone repozytorium oraz paczki npm
Lokalizacja: package.json / dist/
Opis: Katalog wynikowy builda `dist/` jest umieszczany w systemie kontroli wersji. Prowadzi to do niepotrzebnego "puchnięcia" repozytorium oraz ciągłych konfliktów (merge conflicts) przy pracy zespołowej. W `package.json` pakiety `jquery` i `jquery-ui-dist` rezydują w `dependencies`, mimo że Webpack wykorzystuje ich odpowiedniki natywnie załadowane w WordPressie (jako `externals`).
Rekomendacja: Usunąć folder `dist/` z kontroli GIT i zablokować poprzez `.gitignore`. Paczki zredukować lub przenieść do `devDependencies`, odchudzając drzewo zależności.
Szacowany nakład: S
```

### 4. Jakość kodu i utrzymywalność

```text
[PRIORYTET: Wysoki] Użycie zdeprecjonowanego filtra (Błąd na PHP 8.1+)
Lokalizacja: video-chapters-manager.php:całościowo
Opis: Użyto stałej `FILTER_SANITIZE_STRING`, która w PHP 8.1 jest przestarzała, a w 8.2 i wyższych wersjach całkowicie usunięta. Z jej powody wtyczka będzie rzucała krytyczny błąd (Fatal Error), paraliżując instalacje.
Rekomendacja: Natychmiastowa zamiana na wbudowane funkcje WordPressowe: `sanitize_text_field()` lub `sanitize_textarea_field()`.
Szacowany nakład: S
```

```text
[PRIORYTET: Wysoki] Fatal Error przy wywoływaniu logowania (WP_CLI)
Lokalizacja: video-chapters-manager.php:całościowo
Opis: Wywołanie `WP_CLI::log()` w kontekście, w którym wtyczka obsługuje żądania standardowe/AJAX, zakończy się awarią, ponieważ klasa `WP_CLI` po prostu w nich nie istnieje.
Rekomendacja: Owinąć logikę bezpiecznym wrapperem (Guard clause):
`if (defined('WP_CLI') && WP_CLI) { WP_CLI::log($msg); } else { error_log($msg); }`
Szacowany nakład: S
```

```text
[PRIORYTET: Średni] Brak rygoru kodowania w procesie (Linting)
Lokalizacja: eslint.config.js / proces ciągłej integracji
Opis: Projekt posiada konfigurację ESLinta, ale nie jest ona na nikim wymuszana. Ponadto całkowity brak lintera dla warstwy PHP (jak PHPCS z regułami WP Coding Standards lub PHPStan) sprawia, że do repozytorium regularnie i po cichu wpuszczany jest błędny, niebezpieczny kod.
Rekomendacja: Dodać narzędzie Husky i wpiąć w pre-commit walidację JS. Ustawić w projekcie `PHP_CodeSniffer` (profil `WordPress-Extra`) i wymagać, by kod PHP przechodził weryfikację.
Szacowany nakład: M
```

### 5. Bezpieczeństwo

```text
[PRIORYTET: Krytyczny] Brak weryfikacji Nonce i uprawnień użytkownika w AJAX
Lokalizacja: video-chapters-manager.php:handlery AJAX (`wp_ajax_*`)
Opis: W funkcjach obsługujących AJAX brak jakiejkolwiek kontroli nad tym, *kto* zleca operację i *czy* intencja była pożądana. Oznacza to luki rzędu Broken Access Control i CSRF. Jeśli dodano `wp_ajax_nopriv_`, każdy anonim z zewnątrz może usuwać bądź modyfikować bazę.
Rekomendacja: We WSZYSTKICH metodach AJAX na samym początku sprawdzać:
1. `check_ajax_referer('video_manager_nonce', 'nonce');`
2. `if ( ! current_user_can( 'edit_post', $post_id ) ) { wp_send_json_error( 'Brak uprawnień', 403 ); }`
Szacowany nakład: S
```

```text
[PRIORYTET: Krytyczny] Podatność XSS podczas modyfikacji DOM
Lokalizacja: src/video-chapters.js:całościowo
Opis: Wykorzystywanie natywnych, "niebezpiecznych" mechanizmów takich jak metoda jQuery `.html()` do wstrzykiwania do interfejsu (DOM) jakichkolwiek danych tekstowych zwrotnie pobranych z serwera. Napastnik może osadzić tagi JS na poziomie bazy i wykonać złośliwy skrypt u administratora.
Rekomendacja: Refaktoryzacja wszystkich odwołań z `.html()` na bezpieczne `.text()`, oraz dodanie natywnego parsowania/escape'owania (`esc_html`, `esc_attr`) we wszystkich stringach przygotowywanych w PHP.
Szacowany nakład: S
```

```text
[PRIORYTET: Krytyczny] Ryzyko SQL Injection (brak $wpdb->prepare)
Lokalizacja: video-chapters-manager.php:zapytania DB
Opis: Dokonywanie bezpośrednich, natywnych zapytań (Raw SQL) i wklejanie argumentów poprzez konkatenację to przepis na katastrofę (SQL Injection).
Rekomendacja: Audyt wszystkich wywołań w obrębie obiektu bazodanowego. Pełne wdrożenie `$wpdb->prepare()` dla każdego parametru. Przykładowo: `$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE video_id = %d", $vid));`
Szacowany nakład: S
```

### 7. Testy

```text
[PRIORYTET: Wysoki] Brak jakichkolwiek testów logiki kluczowej
Lokalizacja: całościowo
Opis: Rozwijanie wtyczki opartej na synchronizacji czasów z YouTube/innego API wideo jest podatne na masę cichych błędów w kalkulacjach, formatach (np. 01:23 vs 83 sekundy) i w parsowaniu z inputów użytkownika. Brak testów uniemożliwia bezpieczny refaktor.
Rekomendacja: Wdrożyć wp-env / PHPUnit dla back-endu i otestować parser czasu oraz operacje `save_chapters`. Dla JS zaprząc Jest / Vitest do sprawdzenia poprawności konwersji modeli.
Szacowany nakład: L
```

### 8. Wydajność

```text
[PRIORYTET: Średni] Wąskie gardło zapisu do bazy (DELETE + pętla INSERT)
Lokalizacja: video-chapters-manager.php:save_chapters
Opis: Logika kasująca zawsze *wszystkie* powiązane rozdziały z wideo (DELETE), a następnie dokonująca ponownych wstawek (INSERT) w pętli. Na dłuższą metę powoduje to pofragmentowanie indeksu bazy, masowe "nabijanie" wartości `AUTO_INCREMENT` i słabą wydajność.
Rekomendacja: Zmienić na inteligentny mechanizm UPDATE/UPSERT, lub zastosować zapytywanie grupowane transakcyjnie.
Szacowany nakład: M
```

```text
[PRIORYTET: Średni] "Self-DDoS" i wycieki pamięci – Autocomplete
Lokalizacja: src/video-chapters.js:get_chapter_titles / inicjalizacja jQuery UI
Opis: Brak mechanizmu ograniczania zapytań (Throttle/Debounce). Każdy wpisany klawisz podczas korzystania z funkcji Autocomplete wysyła request do serwera (na którym i tak nie działa LIMIT zapytania DB). Obiekty widgetów UI nie są też prawidłowo niszczone w pamięci (brak wywołania `.autocomplete('destroy')`) co generuje wycieki pamięci w oknie przeglądarki.
Rekomendacja: Zaimplementować Debounce (np. okienko 300ms) wokół fetchowania autouzypełnień. Zoptymalizować zapytanie i zwrócić `LIMIT 15`. Properly czyścić eventy i DOM nodes.
Szacowany nakład: M
```

```text
[PRIORYTET: Średni] Brak indeksów bazodanowych
Lokalizacja: video-chapters-manager.php (migracja)
Opis: Struktury nowo utworzonych tabel `wp_post_videos` oraz `wp_post_video_chapters` nie posiadają indeksów dla silnie odpytywanych obcych kluczy (np. `video_id` / `post_id`). Skutkuje to zjawiskiem Full Table Scan na relacyjnej bazie przy każdej operacji wczytywania edytora.
Rekomendacja: Przygotować w poleceniu `dbDelta()` klucze `INDEX (video_id)` oraz `INDEX (post_id)`.
Szacowany nakład: S
```

### 9. Logowanie i observability

```text
[PRIORYTET: Niski] Ciche połykanie błędów (Swallowed Errors)
Lokalizacja: src/video-chapters.js:obsługa AJAX
Opis: Zwrócony błąd komunikacji na froncie nie daje użytkownikowi żadnego feedbacku. Administrator dowiaduje się o tym, że jego praca nie została zapisana dopiero po przeładowaniu strony.
Rekomendacja: Wyłapywać odrzucone Promise z API oraz ew. odpowiedzi `wp_send_json_error()` z serwera, i emitować wizualne alerty przez notyfikacje typu Admin Notice/Toast.
Szacowany nakład: S
```

---

## 🔝 Top 10 Najpilniejszych Działań (Priorytet × Nakład)

Klucz: Należy zacząć od poprawek bezpieczeństwa, ponieważ kosztują bardzo mało nakładu (S), a dają gigantyczny zwrot dla integralności
