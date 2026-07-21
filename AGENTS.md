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
| Backend | WordPress plugin (PHP), jQuery 4, AJAX |
| Frontend | jQuery (WP-bundled), jQuery UI Autocomplete (WP-bundled), WP native classes |
| Build Tool | Webpack 5.108 (ESM config) |
| Language | JavaScript (ES modules, Babel 8 transpilation) |
| Linting | ESLint 10 (flat config, `eslint.config.js`) |
| CSS Extraction | MiniCssExtractPlugin + css-loader + css-minimizer-webpack-plugin |
| Minification | TerserPlugin (JS), CssMinimizerPlugin (CSS) |

## Scripts

```bash
npm install          # Install dependencies
npm run build        # Production build → dist/
npm run dev          # Development build with watch mode
npm run lint         # ESLint check on src/
npm run lint:fix     # ESLint auto-fix on src/
```

## Directory Structure

```
├── video-chapters-manager.php    # Main plugin file: PHP logic, WordPress hooks, AJAX handlers
├── class-sync-queue.php          # Sync queue class for async operations
├── src/
│   ├── video-chapters.js         # Frontend application (jQuery, Autocomplete, validation)
│   └── video-chapters.css        # Frontend styles
├── dist/                         # Compiled output (do not edit)
│   ├── video-chapters.min.js     # Minified JS bundle
│   ├── video-chapters.min.css    # Minified CSS bundle
│   └── *.map                     # Source maps
├── webpack.config.js             # Webpack config (ESM format)
├── eslint.config.js              # ESLint flat config
├── package.json                  # Dependencies & scripts (type: module)
└── README.md                     # Developer workflow documentation
```

## Deploy (Local WordPress)

Copy only the main plugin file and compiled `dist/` folder:

```bash
cp -r video-chapters-manager.php class-sync-queue.php dist/ ~/containers/amruta_wp/wp-content/plugins/video-manager-php/
```

> Source files (`src/`, `package.json`, `webpack.config.js`) are NOT needed in production. Remove them from the target directory to keep it clean.

## Database Schema

The plugin uses two custom tables:
- `wp_post_videos` — Links YouTube video IDs to WordPress posts
- `wp_post_video_chapters` — Stores individual chapters (start time, title, sort order)

## Verification Workflow

Before considering any change complete, run:

```bash
npm run lint         # 1. ESLint — must pass with zero errors
npm run build        # 2. Production build — must succeed
```

Fix any issues before proceeding. Never commit code that fails lint or build.

## Git Workflow

```bash
# 1. Make changes
# 2. If src/ files changed, rebuild:
npm run build

# 3. Lint:
npm run lint

# 4. Commit and push:
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

### JavaScript (src/video-chapters.js)

- **ES modules** throughout (`import`/`export`)
- **No console.log** in production code — use proper error handling
- **Unused variables** must be removed — ESLint `no-unused-vars` is enforced
- **jQuery patterns**: Use `$()` for DOM queries, `.on()` for event binding
- **Autocomplete**: Uses jQuery UI Autocomplete with AJAX source
- **Time validation**: All chapter times must be ≥60 seconds apart

### CSS (src/video-chapters.css)

- Scoped to `.vcm-*` prefixed selectors
- Uses WP CSS variables (`--wp-admin-theme-color`, `--border-color`, `--text-color`) for Dracula Dark Mode compatibility
- No Bootstrap or external CSS dependencies

### PHP (video-chapters-manager.php)

- WordPress coding standards: `ABSPATH` check, nonce verification, capability checks
- AJAX handlers use `wp_ajax_` hook prefix
- Class autoloading with `class_exists()` guard

## Key Files

| File | Purpose |
|---|---|
| `video-chapters-manager.php` | PHP logic, WordPress hooks, AJAX handlers, DB schema |
| `class-sync-queue.php` | Async sync queue operations |
| `src/video-chapters.js` | Frontend app: jQuery UI Autocomplete, chapter CRUD, validation |
| `src/video-chapters.css` | Frontend styles |
| `webpack.config.js` | Webpack ESM config (Babel, CSS extraction, minification) |
| `eslint.config.js` | ESLint flat config (browser globals, no-console warning) |
| `package.json` | Dependencies, scripts (type: module) |

## Known Issues / Notes

- **jQuery UI from WordPress**: jQuery and jQuery UI are loaded from WordPress (not bundled). Webpack `externals` maps `jquery` to `window.jQuery`.
- **Bundle size**: `video-chapters.min.js` is ~7 KiB (jQuery/jQuery UI excluded from bundle)
- **No TypeScript** — pure JavaScript with Babel transpilation
- **No unit tests** — verification is manual via lint + build + WordPress integration testing

## WordPress Integration

### Custom DB Tables
- `wp_post_videos` — YouTube video ↔ post mapping
- `wp_post_video_chapters` — Chapter data (time, title, order)

### AJAX Endpoints
- `search_video` — Search YouTube video by ID
- `save_chapters` — Save chapter data
- `get_chapter_titles` — Autocomplete for chapter titles

### Deploy to WordPress
Plugin lives in `~/containers/amruta_wp/wp-content/plugins/video-manager-php/` as a bind mount. ACLs are configured for `admin` user (no sudo needed for file operations).
