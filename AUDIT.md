## PROMPT

Jesteś doświadczonym senior software architektem/inżynierem code quality. Twoim zadaniem jest przeprowadzenie **całościowego audytu** tego projektu — WordPress pluginu Video Chapters Manager 2 (https://github.com/amrutadotorg/wp-video-manager). Plugin był rozwijany długo, wielokrotnie łatany i modyfikowany „na szybko" — celem audytu jest znalezienie miejsc, gdzie nawarstwił się dług techniczny, niespójności i ryzyka, oraz wskazanie konkretnych, priorytetyzowanych rekomendacji.

**WAŻNE: nie zmieniaj kodu.** Twoim zadaniem jest analiza i raport, nie refaktoryzacja. Jeśli chcesz zaproponować przykładową poprawkę, umieść ją jako fragment w raporcie, nie edytuj plików źródłowych.

### Kontekst projektu

- **Typ**: WordPress plugin (PHP 8.x) + frontend (jQuery/jQuery UI, Webpack)
- **Backend**: PHP, WordPress hooks, AJAX handlers (`wp_ajax_`), własna baza danych (2 tabele: `wp_post_videos`, `wp_post_video_chapters`)
- **Frontend**: jQuery 4 (WP-bundled), jQuery UI Autocomplete (WP-bundled), ES modules, Babel 8, Webpack 5 (ESM config)
- **Linting**: ESLint 10 (flat config), brak PHP lintingu
- **Build**: Webpack → `dist/video-chapters.min.js` (~7 KB) + `dist/video-chapters.min.css`
- **Deploy**: ręczne kopiowanie plików do `~/containers/amruta_wp/wp-content/plugins/video-manager-php/`
- **Testy**: brak jakichkolwiek testów (ani JS, ani PHP)

### 1. Rozpoznanie projektu

- Przejrzyj strukturę katalogów, `package.json` (scripts, dependencies), pliki konfiguracyjne (`webpack.config.js`, `eslint.config.js`).
- Przejrzyj główny plik PHP (`video-chapters-manager.php`) — zidentyfikuj hooki WordPress, klasy, obsługę AJAX.
- Ustal, jaki jest **zamierzony** wzorzec architektoniczny (czy jest jakaś separacja warstw, czy logika biznesowa i prezentacja są wymieszane).
- Sprawdź, czy plugin przestrzega WordPress Coding Standards (nonce verification, capability checks, sanitization, escaping).

### 2. Architektura i struktura kodu

- Czy podział na pliki jest spójny, czy występuje wymieszanie odpowiedzialności (np. logika biznesowa, prezentacja, baza danych w jednym pliku)?
- `video-chapters-manager.php` — czy nie jest „god file" (za duży, za dużo odpowiedzialności)?
- Czy są duplikaty logiki, które powinny być wspólną funkcją/modułem?
- Martwy kod: nieużywane funkcje, zakomentowane bloki, nieosiągalne gałęzie.
- Czy klasa `Sync_Queue` jest prawidłowo zintegrowana, czy jest to martwy/ryzykowny kod?
- Czy frontend (`src/video-chapters.js`) ma spójną architekturę, czy jest „spaghetti" z callbacków?

### 3. Ślady wielokrotnych poprawek („łatania")

- Szukaj śladów prowizorki: `TODO`, `FIXME`, `HACK`, `XXX`, zakomentowany kod.
- Niespójne konwencje nazewnictwa/stylu w różnych częściach kodu (ślad różnych „epok" rozwoju).
- Czy są pliki/wersje backupowe, nieużywane skrypty, stare wersje kodu?
- Nadmiarowe zależności npm (czy wszystkie pakiety w `dependencies` są faktycznie używane po webpack externals?).
- Czy `dist/` jest commitowane do git — czy nie ma tam starych/nieużywanych plików?

### 4. Jakość kodu i utrzymywalność

**JavaScript:**
- Spójność stylu — czy ESLint jest egzekwowany przed commitami?
- Czy importy i exporty są spójne (ES modules vs. global scope)?
- Obsługa błędów w AJAX calls — czy błędy są user-friendly, czy połykane?
- Czy jQuery UI Autocomplete jest prawidłowo inicjalizowany i czyszczony (brak wycieków event listenerów)?

**PHP:**
- Czy jest spójna obsługa błędów (try/catch vs. error handling)?
- Czy input jest sanitizowany (`sanitize_text_field`, `filter_input`) i escaped przy output?
- Czy `FILTER_SANITIZE_STRING` (usunięty w PHP 8.2) został wszędzie zastąpiony?
- Czy `WP_CLI::log()` jest chroniony guardem przed wywołaniem w kontekście AJAX?
- Czy nonce verification jest obecna we WSZYSTKICH handlerach AJAX?

**CSS:**
- Czy style są proper scoped (`.vcm-*`) i nie kolidują z innymi pluginami?
- Czy CSS variables z WordPress są używane prawidłowo?

### 5. Bezpieczeństwo

- **Nonce verification**: Czy każdy handler AJAX sprawdza nonce? Czy nonce jest generowany i przekazywany prawidłowo?
- **Capability checks**: Czy handlerzy AJAX sprawdzają uprawnienia użytkownika (`current_user_can`)?
- **SQL Injection**: Czy zapytania SQL używają `$wpdb->prepare()` prawidłowo? Czy są miejsca z raw SQL bez prepare?
- **Input sanitization**: Czy dane z `$_POST`/`$_GET` są sanitizowane przed użyciem?
- **Output escaping**: Czy dane wyświetlane w HTML są escaped (`esc_html`, `esc_attr`, `esc_url`)?
- **XSS**: Czy JavaScript nie wstawia nieescapeowanego HTML z danych serwera (`.html()` zamiast `.text()`)?
- **Hardcoded secrets**: Czy nie ma kluczy API, tokenów, haseł w kodzie źródłowym?

### 6. Zależności i środowisko

- Czy wersja PHP (8.x) jest zgodna z użyciami w kodzie?
- `package.json`: Czy wszystkie `dependencies` (nie tylko `devDependencies`) są faktycznie używane? Po dodaniu `externals` w webpack, `jquery` i `jquery-ui-dist` powinny być usunięte.
- Czy `package-lock.json` jest spójny z `package.json`?
- Czy Webpack config jest poprawny i nie zawiera przestarzałych opcji?

### 7. Testy

- **JavaScript**: Brak jakichkolwiek testów — czy jest to akceptowalne dla tego typu pluginu? Jakie krytyczne ścieżki powinny być testowane?
- **PHP**: Brak testów PHP — czy są miejsca, gdzie testy jednostkowe byłyby szczególnie wartościowe (np. walidacja czasu, sortowanie rozdziałów)?
- Czy istnieje possibility dodania prostych testów (np. PHPUnit dla PHP, Vitest/Jest dla JS)?

### 8. Wydajność

**Backend:**
- Zapytania SQL: Czy brak indeksów na frequently queried columns (`video_id`, `platform`, `title`)?
- Czy `save_chapters` robi DELETE + INSERT zamiast UPSERT? Jak to wpływa na wydajność przy dużych ilościach rozdziałów?
- Czy `get_chapter_titles` autocomplete ma proper debouncing i limiting wyników?

**Frontend:**
- Czy AJAX calls mają timeout i proper error handling?
- Czy event listenery są properly cleaned up (brak memory leaks)?
- Czy jQuery UI jest ładowane efficiently (via WP, nie bundle)?

### 9. Logowanie i observability

- Czy logowanie PHP jest spójne (`error_log` vs. `WP_CLI::log` vs. `WP_Error`)?
- Czy błędy AJAX są logowane z kontekstem (post_id, user_id, error details)?
- Czy jest jakiś sposób monitorowania pluginu w produkcji (poza ręcznym sprawdzaniem error logów)?

### 10. Dokumentacja i DX

- Czy `README.md` jest aktualny i wystarczający dla nowego developera?
- Czy `AGENTS.md` odzwierciedla rzeczywisty stan projektu?
- Czy nowy developer byłby w stanie zrozumieć flow: kod → build → lint → commit → deploy?
- Czy konwencje commitowania (Conventional Commits) są przestrzegane w historii git?

---

## Format raportu wyjściowego

Raport podziel na sekcje odpowiadające punktom powyżej, a każdy znaleziony problem opisz w formacie:

```
[PRIORYTET: Krytyczny/Wysoki/Średni/Niski] Tytuł problemu
Lokalizacja: ścieżka/do/pliku.php:lub_src/video-chapters.js:linia (lub „całościowo")
Opis: co jest nie tak i dlaczego to problem
Rekomendacja: konkretny sposób naprawy / kierunek refaktoru
Szacowany nakład: S/M/L
```

Na końcu dodaj:
- **Top 10 najpilniejszych działań** (posortowane priorytetem × nakładem).
- Ogólną ocenę stanu projektu (1–10) z uzasadnieniem.
- Sugerowaną kolejność refaktoryzacji (co robić najpierw, żeby nie zepsuć działającego systemu).
