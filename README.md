# OpenMind for OpenClaw

Interactive mindmap workspace viewer for [OpenClaw](https://openclaw.ai). Turns your OpenClaw workspace of markdown files into a navigable, editable mindmap — with a built-in WYSIWYG editor and live chat to your agent. All without npm, a bundler, or a build step. (Yes, really.)

![OpenMind Screenshot](docs/screenshot.png)

---

## Quick Start (Docker)

The fastest way to run OpenMind. Requires only [Docker](https://docs.docker.com/get-docker/).

```bash
git clone https://github.com/JozefJarosciak/OpenMind.git
cd OpenMind
cp .env.example .env
# Edit .env: set OPENMIND_WORKSPACE and OPENMIND_ADMIN_PASS
docker compose up -d
# Open http://localhost:8080
```

That's it. Nginx, PHP, and SQLite are all inside the container. Your data (auth database, config, backups) persists in a Docker volume across restarts.

### Docker environment variables

Edit `.env` before first run. Only `OPENMIND_ADMIN_PASS` and `OPENMIND_WORKSPACE` are required:

| Variable | Default | Description |
|----------|---------|-------------|
| `OPENMIND_WORKSPACE` | `/root/.openclaw/workspace` | Host path to your OpenClaw workspace |
| `OPENMIND_ADMIN_PASS` | *(required)* | Admin password (8+ chars, upper, lower, number, special) |
| `OPENMIND_ADMIN_USER` | `admin` | Admin username |
| `OPENMIND_PORT` | `8080` | Host port to expose |
| `OPENMIND_TITLE` | `OpenMind` | App title |
| `OPENMIND_OPENCLAW_CMD` | `/usr/bin/openclaw` | Path to openclaw binary inside the container |
| `OPENMIND_OPENCLAW_AGENT` | `main` | Which OpenClaw agent to use for chat |
| `OPENMIND_OPENCLAW_RUN_AS` | *(empty)* | Run openclaw as this user via `sudo -u` |
| `OPENMIND_NETWORK` | `none` | Network restriction: `none`, `tailscale`, or `custom` |
| `OPENMIND_ALLOWED_IPS` | *(empty)* | Comma-separated CIDRs for `custom` mode |

### Docker management

```bash
docker compose logs -f openmind   # View logs
docker compose down               # Stop (data persists in volume)
docker compose up -d              # Restart
docker compose down -v            # Stop and delete all data
docker compose build --no-cache   # Rebuild after updates
```

---

## Alternative: Interactive Installer

If you prefer a guided setup (also supports Docker), download and run the installer:

```bash
curl -fsSL https://raw.githubusercontent.com/JozefJarosciak/OpenMind/main/install.sh -o install.sh
bash install.sh
```

The installer auto-detects Docker and offers it as the recommended path. If Docker isn't available, it walks you through a bare-metal PHP setup.

---

## Alternative: Manual (Bare Metal) Installation

For environments where you want to run PHP directly on the host.

### Requirements

- PHP 8.0+ with SQLite3 extension
- Nginx or Apache web server (or `php -S localhost:8080` for dev)
- [OpenClaw](https://openclaw.ai) installed (for chat and workspace files)

No Node.js. No npm. No Composer. No bundler. Frontend dependencies load from CDN:
- [jsMind v0.9.1](https://github.com/hizzgdev/jsmind) — mindmap
- [Toast UI Editor v3](https://github.com/nhn/tui.editor) — WYSIWYG markdown editor

### Platform support

| Platform | Notes |
|---|---|
| **Linux** | Tested on Ubuntu, Debian, RHEL/Fedora, Arch |
| **macOS** | Detects Homebrew PHP automatically |
| **Windows (WSL)** | Run inside [WSL](https://learn.microsoft.com/en-us/windows/wsl/install) — identical to Linux |

### Prerequisites by platform

**Linux (Ubuntu/Debian)**
```bash
sudo apt install php-cli php-sqlite3 git
```

**Linux (RHEL/Fedora)**
```bash
sudo dnf install php php-pdo git
```

**macOS**
```bash
brew install php git
```

### Steps

#### 1. Clone the repository

```bash
git clone https://github.com/JozefJarosciak/OpenMind.git /opt/openmind
cd /opt/openmind
```

#### 2. Configure

```bash
cp config.sample.php config.php
```

Edit `config.php` and set at minimum:

```php
'workspace_path' => '/home/youruser/.openclaw/workspace',
```

Or skip manual config — the first-run setup wizard will guide you when you open the app in a browser.

#### 3. Create a user

```bash
php setup/manage_users.php add admin YourPassword123!
```

#### 4. Set up your web server

**Important:** OpenMind should run on its own port or virtual host. Do not place it inside an existing site's document root.

##### Nginx (new virtual host)

```nginx
server {
    listen 8080;
    server_name openmind.example.com;
    root /opt/openmind;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /(config\.php|auth\.db|\.git|backups) {
        deny all;
        return 404;
    }
}
```

Place in `/etc/nginx/sites-available/openmind`, symlink to `sites-enabled/`, then `nginx -t && systemctl reload nginx`.

##### PHP built-in server (dev or simple personal use)

```bash
php -S 0.0.0.0:8080 -t /opt/openmind
```

#### 5. Set permissions

```bash
chown -R www-data:www-data /opt/openmind
chmod 750 /opt/openmind
chmod 640 /opt/openmind/config.php
```

#### 6. Open in browser

Navigate to your server's URL and log in with the user you created.

---

## Features

### Mindmap Visualization
- **jsMind-powered** interactive mindmap rendering of all `.md` files in your workspace
- Files and their heading structure (`##` and deeper) become clickable, expandable nodes
- Heading nodes get a **consistent default color**; the `headingColor()` function in `includes/workspace.php` is easy to extend with your own keyword → color rules
- **Auto-balanced left/right layout** with unique branch colors per top-level file
- **5 visual design themes**: Classic, Outline, Soft Orb, Rounded, Neon
- **Dark / Light mode** (Catppuccin Mocha and Latte color schemes), all persisted in `localStorage`
- Expand All / Collapse All / Reload controls

### Editor Panel
- Click any node to open the **resizable side panel** (drag handle saves your preferred width)
- **WYSIWYG editor** (Toast UI Editor, lazy-loaded) with Markdown/WYSIWYG toggle
- Clicking a file node loads the full file; clicking a sub-heading shows just that section's content
- **Save to file** writes back to disk and hot-reloads the heading branch — no full page reload needed
- Cancel reverts unsaved changes
- Root node shows a workspace summary (file count and section counts per file)

### OpenClaw Chat
- **Built-in Chat tab** in the same panel — no switching apps
- Proxies messages to the OpenClaw CLI agent via `proc_open` / `shell_exec`
- Chat sessions persist across page loads via `localStorage`
- Responses rendered with markdown (bold, italic, inline code, code blocks, lists)
- Shows model name and response duration per message
- Typing indicator while waiting for the agent
- Enter to send, Shift+Enter for newline; textarea auto-resizes

### Full-Text Search
- Searches across all node topics, heading bodies, and file names
- Keyboard navigation: Arrow keys to move, Enter to jump, Escape to close
- Results show breadcrumb path and body snippet with match highlighted
- Navigating to a result expands its ancestors, selects the node, scrolls it into view, and flashes an outline

### File Management (Right-Click Context Menu)
- **Create** a new `.md` file at the workspace root or inside a file's subdirectory
- **Rename** a file (stays in the same directory, `.md` extension enforced)
- **Delete** a file with a confirmation prompt
- All operations create a timestamped backup first

### Authentication & Security
- **SQLite3-based** multi-user authentication with bcrypt password hashing
- Secure session cookies: `HttpOnly`, `Secure` (when HTTPS), `SameSite=Strict`, strict mode
- **"Remember me"** option extends session to 30 days
- Password change from the Settings UI with real-time strength validation (length, uppercase, lowercase, number, special character)
- **Network restriction** modes: none, Tailscale-only (IPv4 `100.64.0.0/10` and IPv6 `fd7a:115c:a1e0::/48`), or custom CIDR ranges
- All file paths validated with `realpath()` to prevent directory traversal; filenames sanitized to `[a-zA-Z0-9._\- ]`

### Settings Modal
Four tabs, all editable from the UI — no config file editing required after setup:
- **Profile** — Change password with live validation checklist
- **OpenClaw** — Workspace path, CLI binary path, agent name, run-as user
- **Security** — Network restriction mode and allowed IP ranges
- **App** — Title, backup path, session lifetime

### Workspace Structure Handling
- Root `.md` files become top-level nodes
- A **subdirectory named after a root file** automatically nests its `.md` files under that node
- Subdirectories without a matching root file appear as group nodes
- `memory/` directory gets special handling:
  - Date-named files (`YYYY-MM-DD.md`) are collected into a **Memory History** group, sorted newest-first
  - Other memory files appear as regular top-level nodes

### Automatic Backups
Every save, rename, and delete creates a timestamped backup in `backups/YYYY-MM-DD_HH-MM-SS/` before touching the original. Sleep soundly.

---

## Configuration Reference

All settings are editable via the in-app Settings modal. They can also be set directly in `config.php`. Default values come from `includes/defaults.php` — you only need to put values you want to override in `config.php`.

| Setting | Default | Description |
|---------|---------|-------------|
| `workspace_path` | `/root/.openclaw/workspace` | Path to your OpenClaw workspace directory |
| `backup_path` | `./backups` | Where timestamped backups are stored before edits/deletes |
| `openclaw_command` | `/usr/bin/openclaw` | Full path to the openclaw CLI binary |
| `openclaw_agent` | `main` | Which OpenClaw agent to use for chat |
| `openclaw_run_as` | *(empty)* | Run openclaw as this user via `sudo -u`. Leave empty for the web server user. |
| `network_restriction` | `none` | `none`, `tailscale`, or `custom` |
| `allowed_ips` | *(empty)* | Comma-separated CIDRs for `custom` mode (e.g. `192.168.1.0/24, 10.0.0.0/8`) |
| `session_lifetime` | `86400` | Session duration in seconds (default: 24 hours) |
| `app_title` | `OpenMind` | Title shown in the header and browser tab |

---

## OpenClaw Chat Setup

For chat to work, the web server user needs to be able to run the openclaw CLI.

**Option A — OpenClaw installed as the web server user**

Leave `openclaw_run_as` empty. It will run as `www-data` (or whoever PHP is running as).

**Option B — OpenClaw installed under a different user (most common)**

If openclaw is set up under a user like `alice`:

1. Set `openclaw_run_as` to that username in config (or via the Settings modal)
2. Add a sudoers rule so `www-data` can run it without a password:

```bash
echo 'www-data ALL=(alice) NOPASSWD: /usr/bin/openclaw' | sudo tee /etc/sudoers.d/openclaw-www
sudo chmod 440 /etc/sudoers.d/openclaw-www
```

The chat handler passes `--json` to the CLI and parses structured output including response text, model name, and duration.

---

## User Management

All user management is done via the CLI tool. For Docker deployments, prefix commands with `docker compose exec openmind`:

```bash
# Docker
docker compose exec openmind php setup/manage_users.php add alice
docker compose exec openmind php setup/manage_users.php list

# Bare metal
php setup/manage_users.php add alice
php setup/manage_users.php add alice MyPassword123!
php setup/manage_users.php list
php setup/manage_users.php passwd alice
php setup/manage_users.php remove alice
```

---

## Project Structure

```
OpenMind/
├── index.php              # Entry point — routing, auth, HTML template
├── config.sample.php      # Sample config (copy to config.php)
├── config.php             # Your config (gitignored, auto-generated by setup)
├── auth.db                # User database (gitignored, auto-created)
├── Dockerfile             # Docker image definition
├── docker-compose.yml     # Docker Compose service config
├── .env.example           # Template for Docker environment variables
├── docker/
│   ├── entrypoint.sh      # Container first-run setup (config + admin user)
│   ├── nginx.conf         # Nginx config for the container
│   └── supervisord.conf   # Runs nginx + php-fpm inside the container
├── includes/
│   ├── defaults.php       # Single source of truth for all config defaults
│   ├── auth.php           # Network restriction, sessions, login/logout, bcrypt auth
│   ├── workspace.php      # Workspace scanner, markdown heading parser, jsMind tree builder
│   ├── api.php            # File CRUD: save, rename, delete, create, branch refresh
│   ├── chat.php           # OpenClaw CLI proxy — sends messages, parses JSON response
│   ├── settings-api.php   # Settings read (GET) and write (POST) — live-rewrites config.php
│   └── setup.php          # First-run browser setup wizard
├── public/
│   ├── css/
│   │   ├── main.css       # Layout, header, resizable panel
│   │   ├── themes.css     # Catppuccin dark/light CSS custom properties
│   │   ├── designs.css    # 5 jsMind visual design themes
│   │   ├── components.css # Search, context menu, settings modal, login page
│   │   └── chat.css       # Chat bubbles, typing indicator, meta line
│   └── js/
│       ├── app.js         # Core: jsMind init, nodeDataMap, panel, editor, themes
│       ├── search.js      # Full-text search index, keyboard navigation
│       ├── context-menu.js # Right-click file operations (create, rename, delete)
│       ├── settings.js    # Settings modal with tabs and live password validation
│       └── chat.js        # Chat UI, markdown renderer, session persistence
├── setup/
│   └── manage_users.php   # CLI user management tool
├── docs/
│   └── screenshot.png     # App screenshot
├── backups/               # Auto-created; timestamped backup directories
├── install.sh             # Interactive installer (supports Docker + bare metal)
├── LICENSE                # MIT
└── README.md
```

---

## Security Notes

- `config.php`, `auth.db`, and `.env` are gitignored and must never be committed
- Passwords are hashed with bcrypt (PHP `PASSWORD_BCRYPT`)
- Sessions use `HttpOnly`, `Secure` (when HTTPS), and `SameSite=Strict` cookies with strict mode enabled
- Session ID is regenerated on successful login to prevent fixation
- Login rate limiting: 10 attempts per 15-minute window per IP
- All file read/write operations validate paths with `realpath()` — no `../../../etc/passwd` for you
- Filenames are sanitized to alphanumeric plus `._- ` characters only; `.md` extension is enforced
- The Docker container and Nginx configs deny direct access to `config.php`, `auth.db`, `.env`, and `backups/`
- Consider Tailscale or custom CIDR restriction for an extra layer of access control

---

## Tailscale Access

OpenMind works great over Tailscale — it runs on its own port entirely separate from OpenClaw's gateway (port 18789). To expose it on your tailnet:

```bash
tailscale serve --bg http://localhost:8080
```

Your OpenMind instance will then be reachable at `https://your-machine.tail-xyz.ts.net/` from any device on your tailnet, with Tailscale handling HTTPS automatically.

---

## License

MIT License. See [LICENSE](LICENSE) for details.
