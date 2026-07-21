# Project Context & Rules for AI Agents

## Overview

**Video Chapters Manager 2** — WordPress plugin for managing video chapters (chapters) on YouTube-embedded videos. Provides AJAX-powered chapter CRUD, autocomplete from existing chapter titles, time validation, and keyboard navigation. Frontend built with jQuery + jQuery UI Autocomplete, bundled via Webpack.

## GitHub Repository

- **URL**: https://github.com/amrutadotorg/wp-video-manager
- **Default branch**: `main`
- **Clone**: `git clone git@github.com:amrutadotorg/wp-video-manager.git`

## Tech Stack

| Category | Technology |
|---|---|
| Backend | WordPress plugin (PHP 8.x), AJAX |
| Frontend | jQuery (WP-bundled), jQuery UI Autocomplete (WP-bundled), WP native classes |
| Build Tool | Webpack 5.108 (ESM config) |
| Language | JavaScript (ES modules, Babel 8 transpilation) |
| Linting | ESLint 10 (JS), PHPCS with WordPress-Extra (PHP) |
| Pre-commit | Husky + lint-staged (auto-lint on commit) |
| CSS Extraction | MiniCssExtractPlugin + css-loader + css-minimizer-webpack-plugin |
| Minification | TerserPlugin (JS), CssMinimizerPlugin (CSS) |

## Scripts

```bash
npm install              # Install dependencies (npm + composer)
npm run build            # Production build → dist/
npm run dev              # Development build with watch mode
npm run lint             # ESLint check on src/
npm run lint:fix         # ESLint auto-fix on src/
npm run php:lint         # PHPCS check (WordPress-Extra)
npm run php:lint:fix     # PHPCBF auto-fix
composer run lint        # Alternative PHPCS via composer
```

## Directory Structure

```
├── video-chapters-manager.php    # Orchestrator: hooks, enqueue, menu (slim)
├── class-video-chapters-db.php   # DB layer: all $wpdb interactions (singleton)
├── class-ajax-handlers.php       # AJAX handlers: validation, capabilities, JSON responses
├── class-sync-queue.php          # Sync queue for async operations
├── src/
│   ├── video-chapters.js         # Entry point: event binding, orchestration
│   ├── validation.js             # Pure functions: time parsing, YouTube ID extraction
│   ├── api.js                    # AJAX wrappers: search, save, autocomplete
│   ├── ui.js                     # DOM: createChapterRow, showMessage, time input
│   └── video-chapters.css        # Frontend styles (WP CSS variables)
├── dist/                         # Compiled output (do not edit, gitignored)
│   ├── video-chapters.min.js     # Minified JS bundle
│   └── video-chapters.min.css    # Minified CSS bundle
├── webpack.config.js             # Webpack ESM config
├── eslint.config.js              # ESLint flat config
├── phpcs.xml                     # PHPCS config (WordPress-Extra)
├── composer.json                 # PHP dependencies (PHPCS, WPCS)
├── package.json                  # JS dependencies & scripts (type: module)
└── README.md                     # Developer workflow documentation
```

## Architecture

### PHP (3-class pattern)

| Class | File | Responsibility |
|---|---|---|
| `VideoChaptersManager` | `video-chapters-manager.php` | WordPress hooks, enqueue, admin menu |
| `Video_Chapters_DB` | `class-video-chapters-db.php` | All DB queries, schema activation (singleton) |
| `Video_Chapters_AJAX` | `class-ajax-handlers.php` | AJAX handlers, input validation, JSON responses |
| `Sync_Queue` | `class-sync-queue.php` | Sync queue operations |

### JavaScript (4-module pattern)

| Module | File | Exports |
|---|---|---|
| `validation.js` | `src/validation.js` | `timeToSeconds`, `secondsToTimeStr`, `sortChapters`, `isValidTimeFormat`, `isValidChapterTime`, `extractYouTubeId` |
| `api.js` | `src/api.js` | `searchVideoAPI`, `saveChaptersAPI`, `getChapterTitlesAPI` |
| `ui.js` | `src/ui.js` | `createChapterRow`, `showMessage`, `clearAllErrors`, `initializeTimeInput` |
| Entry | `src/video-chapters.js` | Event binding, `initializeApp`, `searchVideo`, `saveChapters` |

## Deploy (Local WordPress)

Copy PHP files and compiled `dist/` folder:

```bash
cp video-chapters-manager.php class-video-chapters-db.php class-ajax-handlers.php class-sync-queue.php ~/containers/amruta_wp/wp-content/plugins/video-manager-php/
cp -r dist/ ~/containers/amruta_wp/wp-content/plugins/video-manager-php/
```

> Source files (`src/`, `package.json`, `webpack.config.js`, `vendor/`) are NOT needed in production.

## Database Schema

Managed by `Video_Chapters_DB::activate()` on plugin activation:

- `wp_post_videos` — Links YouTube video IDs to WordPress posts (indexed: `post_id`, `video_id`, UNIQUE `platform_video`)
- `wp_post_video_chapters` — Stores individual chapters (indexed: `video_id`, `sort_order`)

## Verification Workflow

Before considering any change complete, run:

```bash
npm run lint             # 1. ESLint — must pass with zero errors
npm run php:lint         # 2. PHPCS — must pass with zero errors
npm run build            # 3. Production build — must succeed
```

Pre-commit hooks (Husky + lint-staged) run ESLint and PHPCS automatically on staged files.

## Git Workflow

```bash
# 1. Make changes
# 2. If src/ files changed, rebuild:
npm run build

# 3. Lint (or rely on pre-commit hooks):
npm run lint && npm run php:lint

# 4. Commit and push (pre-commit hooks will also lint):
git add -A
git commit -m "<type>: <short description>"
git push
```

**Commit message format** (Conventional Commits):
- `feat:` — new feature
- `fix:` — bug fix
- `refactor:` — code restructuring without behavior change
- `chore:` — build, config, dependencies, tooling
- `docs:` — documentation only

## Coding Guidelines

### JavaScript (src/)

- **ES modules** throughout (`import`/`export`)
- **No console.log** in production code — use proper error handling
- **Unused variables** must be removed — ESLint `no-unused-vars` is enforced
- **jQuery patterns**: Use `$()` for DOM queries, `.on()` for event binding
- **Autocomplete**: Uses jQuery UI Autocomplete with AJAX source
- **Time validation**: All chapter times must be ≥60 seconds apart
- **Security**: Never use `.html()` with user data — use `.text()` or `.attr()`

### CSS (src/video-chapters.css)

- Scoped to `.vcm-*` prefixed selectors
- Uses WP CSS variables (`--wp-admin-theme-color`, `--border-color`, `--text-color`) for Dracula Dark Mode compatibility
- No Bootstrap or external CSS dependencies

### PHP (*.php)

- WordPress coding standards (enforced by PHPCS WordPress-Extra)
- `ABSPATH` check, nonce verification (`check_ajax_referer`), capability checks (`current_user_can`)
- Yoda conditions for comparisons
- All DB queries use `$wpdb->prepare()`
- All input sanitized with `sanitize_text_field()`, all output escaped with `esc_html()`/`esc_attr()`

## Key Files

| File | Purpose |
|---|---|
| `video-chapters-manager.php` | Orchestrator: WordPress hooks, enqueue, admin menu |
| `class-video-chapters-db.php` | DB layer: queries, schema, transactions |
| `class-ajax-handlers.php` | AJAX handlers: validation, capabilities, responses |
| `class-sync-queue.php` | Sync queue operations |
| `src/video-chapters.js` | Entry point: event binding, orchestration |
| `src/validation.js` | Pure functions: time, YouTube ID |
| `src/api.js` | AJAX wrappers |
| `src/ui.js` | DOM creation, messages |
| `webpack.config.js` | Webpack ESM config (externals for jQuery) |
| `eslint.config.js` | ESLint flat config |
| `phpcs.xml` | PHPCS config (WordPress-Extra) |
| `package.json` | Dependencies, scripts (type: module) |
| `composer.json` | PHP dependencies (PHPCS, WPCS) |

## Known Issues / Notes

- **jQuery UI from WordPress**: jQuery and jQuery UI are loaded from WordPress (not bundled). Webpack `externals` maps `jquery` to `window.jQuery`.
- **Bundle size**: `video-chapters.min.js` is ~7 KiB (jQuery/jQuery UI excluded from bundle)
- **No TypeScript** — pure JavaScript with Babel transpilation
- **No unit tests** — verification is via lint + build + WordPress integration testing
- **Pre-commit hooks**: Husky runs ESLint + PHPCS on staged files before each commit

## WordPress Integration

### Custom DB Tables
- `wp_post_videos` — YouTube video ↔ post mapping
- `wp_post_video_chapters` — Chapter data (time, title, order)

### AJAX Endpoints
- `search_video` — Search YouTube video by ID (requires `edit_posts` capability)
- `save_chapters` — Save chapter data (requires `edit_posts` capability)
- `get_chapter_titles` — Autocomplete for chapter titles (requires authenticated user)

### Security
- All AJAX handlers verify nonce (`check_ajax_referer`)
- All AJAX handlers check user capabilities (`current_user_can`)
- All input sanitized, all output escaped
- No `wp_ajax_nopriv_` hooks (authenticated users only)

### Deploy to WordPress
Plugin lives in `~/containers/amruta_wp/wp-content/plugins/video-manager-php/` as a bind mount. ACLs are configured for `admin` user (no sudo needed for file operations).
