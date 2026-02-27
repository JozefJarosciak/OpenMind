# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

OpenMind is a browser-based interactive mindmap workspace viewer for OpenClaw (AI agent framework). It visualizes markdown files as mindmap nodes using jsMind, with a WYSIWYG editor panel (Toast UI Editor) and integrated OpenClaw agent chat.

## Tech Stack

- **Backend:** PHP 8.0+ (functional style, no frameworks), SQLite3 for auth
- **Frontend:** Vanilla ES6 JavaScript, no build tools or bundlers
- **Libraries (CDN only):** jsMind v0.9.1 (mindmap), Toast UI Editor v3 (markdown editor)
- **Styling:** Pure CSS3 with CSS custom properties, Catppuccin color scheme (Mocha/Latte)
- **No npm, no build step, no linters, no test framework**

## Running the Application

Serve with any PHP-capable web server (Apache/Nginx + PHP-FPM, or `php -S localhost:8080` for dev). On first access, a setup wizard generates `config.php` from user input.

### User Management (CLI)

```bash
php setup/manage_users.php add <username> [password]
php setup/manage_users.php list
php setup/manage_users.php remove <username>
php setup/manage_users.php passwd <username> [password]
```

## Architecture

### Request Flow

`index.php` is the single entry point. It loads config, checks auth/network restrictions, then routes by `$_POST['action']` or `$_GET` parameters to handler files in `includes/`.

### Backend (`includes/`)

| File | Role |
|------|------|
| `defaults.php` | Single source of truth for all config defaults; `get_defaults()`, `get_config()`, `validate_password()` |
| `auth.php` | Session auth, bcrypt passwords, login rate limiting, network restriction (Tailscale/custom CIDR) |
| `workspace.php` | Parses markdown files into jsMind tree structure; heading extraction, config-driven color assignment |
| `api.php` | File CRUD (save, rename, delete, create), password change, automatic backups |
| `chat.php` | Proxies messages to OpenClaw CLI agent via `shell_exec` |
| `settings-api.php` | Reads/writes `config.php` settings (merge-based, preserves non-form keys) |
| `setup.php` | First-run setup wizard |

### Frontend (`public/js/`)

| File | Role |
|------|------|
| `app.js` | Core: jsMind init, Toast UI editor (lazy-loaded), panel management, theme/design switching, node content save/refresh |
| `search.js` | Full-text search index across all nodes, keyboard navigation (arrows + Enter) |
| `context-menu.js` | Right-click context menu for file create/rename/delete |
| `settings.js` | Settings modal with live validation |
| `chat.js` | Chat interface with session persistence (localStorage) |

### CSS (`public/css/`)

- `themes.css` — Catppuccin dark/light CSS variables
- `designs.css` — 5 jsMind visual themes (Classic, Outline, Soft Orb, Rounded, Neon)
- `main.css` — Layout, header, resizable panel
- `components.css` — Search, context menu, settings modal, login
- `chat.css` — Chat bubbles and input

## Key Globals & State

**JavaScript globals:** `jm` (jsMind instance), `tuiEditor` (lazy-init editor), `nodeDataMap` (node ID to metadata map), `raw` (parsed mindmap JSON), `searchIndex`, `window.APP_CONFIG`

**localStorage keys:** `panelWidth`, `mapTheme`, `mapDesign`, `chatSessionId`

## Conventions

- **PHP:** snake_case functions/variables; JSON responses `{success: bool, error?: string}`
- **JavaScript:** camelCase; all AJAX via `fetch()`; event delegation with `closest()`
- **CSS:** kebab-case classes/IDs; theming via `[data-theme]` and `[data-design]` attributes with CSS variables (`--bg`, `--fg`, `--accent`, `--border`, `--bc` for branch color)
- **Security:** All file paths validated with `realpath()` to prevent directory traversal; filenames sanitized to `[a-zA-Z0-9._\- ]`
- **Backups:** `makeBackup()` in `api.php` creates timestamped copies before any write/delete operation

## Configuration

`includes/defaults.php` is the single source of truth for every configurable value (~35 keys: user-facing settings, security constants, node colors, layout params, search/panel/chat behavior). `config.php` (gitignored, sparse) overrides only user-customized values. `get_config()` merges both so every key is guaranteed. JS gets relevant values via `window.APP_CONFIG` injected in `index.php`. Core user-facing settings are editable via the UI settings modal; security and visualization constants are edited in `config.php` directly.

## Workspace Expectations

The app reads a directory of `.md` files. Subdirectories named after a root `.md` file are grouped under it. A `memory/` directory gets special handling (date-named files shown in a History group). Markdown headings (`##` and deeper) become mindmap nodes; `#` (h1) is treated as the file title only.
