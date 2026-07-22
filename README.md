# Video Chapters Manager 2

Plugin do zarządzania rozdziałami (chapters) w wideo na WordPressie.

## Workflow deweloperski

### 1. Instalacja zależności
Jeśli pracujesz w nowym środowisku, najpierw zainstaluj zależności npm i composer:
```bash
npm install
```

### 2. Kompilacja assetów (JS/CSS)
Plugin korzysta z Webpacka do budowania plików w katalogu `dist/`.

*   **Produkcja** (zawsze przed wysyłką na serwer/GitHub):
    ```bash
    npm run build
    ```
*   **Development** (automatyczna rekompilacja przy zmianach w `src/`):
    ```bash
    npm run dev
    ```

### 3. Deploy flow (Local environment)
Aby zaktualizować plugin w lokalnej instalacji WordPress (kontenerze), kopiujemy pliki PHP i skompilowany folder `dist/`:
```bash
cp video-chapters-manager.php class-video-chapters-db.php class-ajax-handlers.php class-sync-queue.php ~/containers/amruta_wp/wp-content/plugins/video-manager-php/
cp -r dist/ ~/containers/amruta_wp/wp-content/plugins/video-manager-php/
```

> [!TIP]
> W docelowym folderze `plugins/video-manager-php/` **nie potrzebujesz** plików źródłowych (`src/`, `package.json`, `webpack.config.js`, `vendor/`). Możesz je bezpiecznie usunąć, aby zachować porządek.

### 4. Linting
Plugin korzysta z ESLint (JS) i PHPCS (PHP) do sprawdzania jakości kodu:
```bash
npm run lint          # ESLint — sprawdź błędy JS
npm run lint:fix      # ESLint — automatyczna naprawa
npm run php:lint      # PHPCS — sprawdź błędy PHP
npm run php:lint:fix  # PHPCBF — automatyczna naprawa PHP
npm test              # Vitest — testy jednostkowe funkcji walidacji
npm run test:watch    # Vitest — tryb obserwacji zmian
```

### 4a. Testy PHP

Testy walidacji backendu uruchamiaj w Dockerze, aby lokalny PHP nie był wymagany:
```bash
docker compose -f containers/video-manager-test/docker-compose.yml --profile test run --rm --no-deps phpunit
```

Pre-commit hooks (Husky + lint-staged) automatycznie uruchamiają linting przed każdym commitem.

### 5. Struktura bazy danych
Plugin korzysta z dwóch głównych tabel (AUTO CREATE przy aktywacji):
- `wp_post_videos` — przechowuje powiązanie wideo (YouTube ID) z postem WordPress.
- `wp_post_video_chapters` — przechowuje poszczególne rozdziały (czas startu, tytuł, kolejność).

### 6. Architektura kodu

**PHP (3 klasy):**
- `video-chapters-manager.php` — Orchestrator: hooki WordPress, enqueue, menu admin
- `class-video-chapters-db.php` — Warstwa DB: wszystkie zapytania $wpdb (singleton)
- `class-ajax-handlers.php` — Handlery AJAX: walidacja, uprawnienia, odpowiedzi JSON
- `class-sync-queue.php` — Kolejka synchronizacji

**JavaScript (4 moduły):**
- `src/validation.js` — Czyste funkcje: parsowanie czasu, YouTube ID
- `src/api.js` — Wrappery AJAX: search, save, autocomplete
- `src/ui.js` — DOM: createChapterRow, showMessage, time input
- `src/video-chapters.js` — Entry point: event binding, orkiestracja

### 7. Główne pliki
- `video-chapters-manager.php` — Orchestrator pluginu.
- `class-video-chapters-db.php` — Warstwa dostępu do bazy danych.
- `class-ajax-handlers.php` — Obsługa żądań AJAX.
- `src/video-chapters.js` — Kod aplikacji frontendowej.
- `src/video-chapters.css` — Style frontendu.
- `webpack.config.js` — Konfiguracja budowania (ESM).
- `phpcs.xml` — Konfiguracja PHPCS (WordPress-Extra).

## Git Workflow
1. Wprowadź zmiany w kodzie.
2. Jeśli zmieniałeś pliki w `src/`, uruchom `npm run build`.
3. Sprawdź kod: `npm run lint && npm run php:lint`
4. Dodaj zmiany: `git add .`
5. Stwórz commit: `git commit -m "Opis zmian"` (pre-commit hooks sprawdzą lint automatycznie)
6. Wyślij na GitHub: `git push`
