# Video Chapters Manager 2

Plugin do zarządzania rozdziałami (chapters) w wideo na WordPressie.

## Workflow deweloperski

### 1. Instalacja zależności
Jeśli pracujesz w nowym środowisku, najpierw zainstaluj paczki npm:
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
Aby zaktualizować plugin w lokalnej instalacji WordPress (kontenerze), kopiujemy **tylko** plik główny i skompilowany folder `dist/`:
```bash
sudo cp -r video-chapters-manager.php dist/ ~/containers/amruta_wp/wp-content/plugins/video-manager-php/
```

> [!TIP]
> W docelowym folderze `plugins/video-manager-php/` **nie potrzebujesz** plików źródłowych (`src/`, `package.json`, `webpack.config.js`). Możesz je bezpiecznie usunąć, aby zachować porządek.

### 4. Struktura bazy danych
Plugin korzysta z dwóch głównych tabel:
- `wp_post_videos` - przechowuje powiązanie wideo (YouTube ID) z postem WordPress.
- `wp_post_video_chapters` - przechowuje poszczególne rozdziały (czas startu, tytuł, kolejność).

### 4. Główne pliki
- `video-chapters-manager.php` - Logika PHP, hooki WordPress, obsługa AJAX.
- `src/video-chapters.js` - Kod aplikacji frontendowej (jQuery + Autocomplete).
- `src/video-chapters.css` - Style frontendu.
- `webpack.config.js` - Konfiguracja budowania.

## Git Workflow
1. Wprowadź zmiany w kodzie.
2. Jeśli zmieniałeś pliki w `src/`, uruchom `npm run build`.
3. Dodaj zmiany: `git add .`
4. Stwórz commit: `git commit -m "Opis zmian"`
5. Wyślij na GitHub: `git push`
