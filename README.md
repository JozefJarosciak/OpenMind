# OpenMind for OpenClaw

**Give your [OpenClaw](https://openclaw.ai) agent a brain you can actually see.**

OpenMind turns your OpenClaw workspace into a fully interactive, live-editable mind map. No more digging through markdown files and logs — just visual, navigable structure. Edit any memory node, hot-swap logic on the fly, search across everything, and manage your agent's knowledge in real time.

All without npm, a bundler, or a build step.

![OpenMind Screenshot](docs/screenshot.png)

> **Early Alpha** — This project is under active development. It works, but expect rough edges and breaking changes. Bug reports and pull requests are welcome.
>
> **Want to help?** We need contributors — whether that's code, testing, documentation, or just spreading the word. If you find OpenMind useful, tell someone about it. Word of mouth matters more than anything at this stage.

---

## Table of Contents

- [Quick Install via OpenClaw](#quick-install-via-openclaw)
- [Manual Installation](#manual-installation)
  - [Linux](#linux-ubuntu-debian-fedora-arch-etc)
  - [macOS](#macos)
  - [Windows](#windows)
  - [Bare Metal (no Docker)](#bare-metal-no-docker)
- [Docker Environment Variables](#docker-environment-variables)
- [Managing Your Installation](#managing-your-installation)
- [Features](#features)
- [Configuration Reference](#configuration-reference)
- [Tailscale Access](#tailscale-access)
- [Project Structure](#project-structure)
- [Security](#security)
- [Disclaimer](#disclaimer)
- [Contributing](#contributing)
- [License](#license)
- [Acknowledgments](#acknowledgments)

---

## Quick Install via OpenClaw

Already have OpenClaw running? Just ask your agent to install OpenMind. You can message your bot directly or run it from the command line.

**If you have a domain** — make it accessible on the web:

```
Install OpenMind from https://github.com/JozefJarosciak/OpenMind.git — clone to
~/openmind, configure Docker with my workspace, create an admin user with a strong
password, start on port 8080, make it available at example.com/openmind with HTTPS,
and give me the URL.
```

**If you don't have a domain** — secure it with Tailscale so only your devices can reach it:

```
Install OpenMind from https://github.com/JozefJarosciak/OpenMind.git — clone to
~/openmind, configure Docker with my workspace, create an admin user with a strong
password, start on port 8080, use tailscale network restriction, then give me the URL.
```

**From the command line:**

```bash
openclaw -m "Install OpenMind from https://github.com/JozefJarosciak/OpenMind.git — clone to ~/openmind, configure Docker with my workspace, create admin user with a strong password, start on port 8080 with tailscale restriction, then give me the URL."
```

Your agent handles cloning, configuration, Docker setup, and startup. It already knows where your workspace is.

You can customize any of these in your prompt: **port** (default 8080), **admin username** (default `admin`), **admin password**, and **network restriction** (`none`, `tailscale`, or custom IP ranges). The bot name is auto-detected from your OpenClaw config.

---

## Manual Installation

Prefer to do it yourself? OpenMind runs anywhere Docker runs. Pick your OS below.

### Linux (Ubuntu, Debian, Fedora, Arch, etc.)

**Option A — One-line interactive installer (recommended)**

```bash
curl -fsSL https://raw.githubusercontent.com/JozefJarosciak/OpenMind/main/install.sh -o install.sh
bash install.sh
```

The installer auto-detects your OpenClaw workspace and bot name, then walks you through:

- **Port** — which port to run on (default 8080, auto-picks another if taken)
- **Admin username** — login username (default `admin`)
- **Admin password** — must be 8+ characters with upper, lower, number, and special character
- **Network restriction** — `none` (allow all), `tailscale` (recommended for remote servers), or custom IP/CIDR ranges

It will also install Docker for you if it's not already present, build the image, and start the container — ready in about a minute.

**Option B — Manual Docker setup**

```bash
# 1. Install Docker (skip if already installed)
curl -fsSL https://get.docker.com | sh

# 2. Clone and configure
git clone https://github.com/JozefJarosciak/OpenMind.git
cd OpenMind
cp .env.example .env
nano .env   # Set OPENMIND_WORKSPACE, OPENCLAW_HOME, and OPENMIND_ADMIN_PASS

# 3. Start
docker compose up -d

# 4. Open http://localhost:8080
```

---

### macOS

**Option A — Interactive installer**

```bash
curl -fsSL https://raw.githubusercontent.com/JozefJarosciak/OpenMind/main/install.sh -o install.sh
bash install.sh
```

> Requires [Docker Desktop for Mac](https://docs.docker.com/desktop/install/mac-install/) — the installer will tell you if it's missing.

**Option B — Manual Docker setup**

1. Install [Docker Desktop for Mac](https://docs.docker.com/desktop/install/mac-install/) and start it
2. Then:

```bash
git clone https://github.com/JozefJarosciak/OpenMind.git
cd OpenMind
cp .env.example .env
nano .env   # Set OPENMIND_WORKSPACE, OPENCLAW_HOME, and OPENMIND_ADMIN_PASS
docker compose up -d
# Open http://localhost:8080
```

---

### Windows

Docker Desktop provides full Linux container support on Windows. Two ways to run it:

**Option A — WSL terminal (recommended)**

1. Install [WSL](https://learn.microsoft.com/en-us/windows/wsl/install) if you haven't:
   ```powershell
   wsl --install
   ```
2. Install [Docker Desktop for Windows](https://docs.docker.com/desktop/install/windows-install/) — enable the WSL 2 backend during setup
3. Open a WSL terminal (Ubuntu) and run:
   ```bash
   curl -fsSL https://raw.githubusercontent.com/JozefJarosciak/OpenMind/main/install.sh -o install.sh
   bash install.sh
   ```
4. Open `http://localhost:8080` in your Windows browser

**Option B — PowerShell / CMD**

1. Install [Docker Desktop for Windows](https://docs.docker.com/desktop/install/windows-install/) and start it
2. Open PowerShell or CMD:
   ```powershell
   git clone https://github.com/JozefJarosciak/OpenMind.git
   cd OpenMind
   copy .env.example .env
   # Edit .env with notepad: set OPENMIND_WORKSPACE, OPENCLAW_HOME, and OPENMIND_ADMIN_PASS
   notepad .env
   docker compose up -d
   # Open http://localhost:8080
   ```

> **Note:** The interactive installer (`install.sh`) requires a bash shell. On Windows, use it inside WSL. The manual `docker compose` approach works from any terminal.

---

### Bare Metal (no Docker)

For environments where you want to run PHP directly on the host without Docker.

<details>
<summary>Click to expand bare metal instructions</summary>

#### Requirements

- PHP 8.0+ with SQLite3 extension
- Nginx or Apache web server (or `php -S localhost:8080` for dev)
- [OpenClaw](https://openclaw.ai) installed

No Node.js, npm, Composer, or bundler needed. Frontend dependencies load from CDN.

#### Prerequisites by platform

**Ubuntu / Debian**
```bash
sudo apt install php-cli php-sqlite3 git
```

**RHEL / Fedora**
```bash
sudo dnf install php php-pdo git
```

**macOS**
```bash
brew install php git
```

**Windows** — Use WSL and follow the Linux steps above.

#### Steps

```bash
# 1. Clone
git clone https://github.com/JozefJarosciak/OpenMind.git /opt/openmind
cd /opt/openmind

# 2. Configure (or skip — the setup wizard will guide you on first browser visit)
cp config.sample.php config.php
# Edit config.php: set workspace_path at minimum

# 3. Create admin user
php setup/manage_users.php add admin YourPassword123!

# 4. Start the server
php -S 0.0.0.0:8080 -t /opt/openmind

# 5. Open http://localhost:8080
```

#### Nginx (production)

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

#### Permissions

```bash
chown -R www-data:www-data /opt/openmind
chmod 750 /opt/openmind
chmod 640 /opt/openmind/config.php
```

</details>

---

## Docker Environment Variables

Edit `.env` before first run. Only `OPENMIND_ADMIN_PASS` and `OPENMIND_WORKSPACE` are required:

| Variable | Default | Description |
|----------|---------|-------------|
| `OPENMIND_WORKSPACE` | `/root/.openclaw/workspace` | Host path to your OpenClaw workspace |
| `OPENCLAW_HOME` | *(derived from workspace)* | OpenClaw home directory (for bot name auto-detection) |
| `OPENMIND_ADMIN_PASS` | *(required)* | Admin password (8+ chars, upper, lower, number, special) |
| `OPENMIND_ADMIN_USER` | `admin` | Admin username |
| `OPENMIND_PORT` | `8080` | Host port to expose |
| `OPENMIND_TITLE` | *(auto-detected)* | App title — auto-detected from Telegram bot name if blank |
| `OPENMIND_NETWORK` | `none` | Network restriction: `none`, `tailscale`, or `custom` |
| `OPENMIND_ALLOWED_IPS` | *(empty)* | Comma-separated CIDRs for `custom` mode |

---

## Managing Your Installation

### Docker commands

```bash
docker compose logs -f openmind   # View logs
docker compose down               # Stop (data persists in volume)
docker compose up -d              # Restart
docker compose down -v            # Stop and delete all data
docker compose build --no-cache   # Rebuild after updates
```

### User management

```bash
# Docker
docker compose exec openmind php setup/manage_users.php add alice
docker compose exec openmind php setup/manage_users.php list
docker compose exec openmind php setup/manage_users.php passwd alice
docker compose exec openmind php setup/manage_users.php remove alice

# Bare metal
php setup/manage_users.php add alice
php setup/manage_users.php list
php setup/manage_users.php passwd alice
php setup/manage_users.php remove alice
```

### Updating

```bash
cd OpenMind
git pull
docker compose up -d --build
```

---

## Features

### Mindmap Visualization
- **jsMind-powered** interactive mindmap rendering of all `.md` files in your workspace
- Files and their heading structure (`##` and deeper) become clickable, expandable nodes
- **Auto-balanced left/right layout** with unique branch colors per top-level file
- **5 visual design themes**: Classic, Outline, Soft Orb, Rounded, Neon
- **Dark / Light mode** (Catppuccin Mocha and Latte color schemes), persisted in `localStorage`
- **Fit to screen** button to zoom and center the entire mindmap
- Expand All / Collapse All / Reload controls

### Editor Panel
- Click any node to open the **resizable side panel** (drag handle saves your preferred width)
- **WYSIWYG editor** (Toast UI Editor, lazy-loaded) with Markdown/WYSIWYG toggle
- Clicking a file node loads the full file; clicking a sub-heading shows just that section's content
- **Save to file** writes back to disk and hot-reloads the heading branch — no full page reload needed
- Cancel reverts unsaved changes
- Root node shows a workspace summary (file count and section counts per file)

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

### Authentication & Access Control
- **SQLite3-based** multi-user authentication with bcrypt password hashing
- Secure session cookies: `HttpOnly`, `Secure` (when HTTPS), `SameSite=Strict`, strict mode
- **"Remember me"** option for persistent login across browser sessions
- Password change from the Settings UI with real-time strength validation
- **Network restriction** modes: none, Tailscale-only, or custom CIDR ranges
- Login rate limiting: 10 attempts per 15-minute window per IP

### Auto-Detected Bot Name
- On startup, OpenMind reads your OpenClaw config and detects your bot's display name
- The bot name is shown as the root node, page title, and header — not a generic "OpenMind" label
- Detection priority: `IDENTITY.md` in workspace, then Telegram Bot API, then agent directory name

### Settings Modal
Four tabs, all editable from the UI:
- **Profile** — Change password with live validation checklist
- **Workspace** — Workspace path configuration
- **Security** — Network restriction mode and allowed IP ranges
- **App** — Title, backup path, session lifetime
- **Update** — Check for and apply updates from GitHub

### Workspace Structure Handling
- Root `.md` files become top-level nodes
- A **subdirectory named after a root file** automatically nests its `.md` files under that node
- Subdirectories without a matching root file appear as group nodes
- `memory/` directory gets special handling:
  - Date-named files (`YYYY-MM-DD.md`) are collected into a **Memory History** group, sorted newest-first
  - Other memory files appear as regular top-level nodes

### Automatic Backups
Every save, rename, and delete creates a timestamped backup in `backups/YYYY-MM-DD_HH-MM-SS/` before touching the original.

---

## Configuration Reference

All settings are editable via the in-app Settings modal. They can also be set directly in `config.php`. Default values come from `includes/defaults.php` — you only need to put values you want to override in `config.php`.

| Setting | Default | Description |
|---------|---------|-------------|
| `workspace_path` | `/root/.openclaw/workspace` | Path to your OpenClaw workspace directory |
| `backup_path` | `./backups` | Where timestamped backups are stored before edits/deletes |
| `network_restriction` | `none` | `none`, `tailscale`, or `custom` |
| `allowed_ips` | *(empty)* | Comma-separated CIDRs for `custom` mode (e.g. `192.168.1.0/24, 10.0.0.0/8`) |
| `session_lifetime` | `86400` | Session duration in seconds (default: 24 hours) |
| `app_title` | *(auto-detected)* | Title shown in the header and browser tab (auto-detected from bot name) |

---

## Tailscale Access

OpenMind works great over Tailscale — it runs on its own port entirely separate from OpenClaw's gateway (port 18789). To expose it on your tailnet:

```bash
tailscale serve --bg http://localhost:8080
```

Your OpenMind instance will then be reachable at `https://your-machine.tail-xyz.ts.net/` from any device on your tailnet, with Tailscale handling HTTPS automatically.

---

## Project Structure

```
OpenMind/
├── index.php              # Entry point — routing, auth, HTML template
├── config.sample.php      # Sample config (copy to config.php)
├── Dockerfile             # Docker image definition
├── docker-compose.yml     # Docker Compose service config
├── .env.example           # Template for Docker environment variables
├── docker/
│   ├── entrypoint.sh      # Container setup (config, admin user, bot name detection)
│   ├── nginx.conf         # Nginx config for the container
│   └── supervisord.conf   # Runs nginx + php-fpm inside the container
├── includes/
│   ├── defaults.php       # Single source of truth for all config defaults
│   ├── auth.php           # Network restriction, sessions, login/logout, bcrypt auth
│   ├── workspace.php      # Workspace scanner, markdown heading parser, jsMind tree builder
│   ├── api.php            # File CRUD: save, rename, delete, create, branch refresh
│   ├── settings-api.php   # Settings read (GET) and write (POST) — live-rewrites config.php
│   └── setup.php          # First-run browser setup wizard
├── public/
│   ├── css/               # main, themes, designs, components, chat
│   └── js/                # app, search, context-menu, settings
├── setup/
│   └── manage_users.php   # CLI user management tool
├── install.sh             # Interactive installer (Docker + bare metal)
├── LICENSE                # AGPL-3.0
└── README.md
```

---

## Security

### What OpenMind does to protect your installation

- Passwords are hashed with bcrypt (`PASSWORD_BCRYPT`) — never stored in plain text
- Sessions use `HttpOnly`, `Secure` (when HTTPS), and `SameSite=Strict` cookies with strict mode enabled
- Session ID is regenerated on successful login to prevent session fixation
- Login rate limiting: 10 attempts per 15-minute window per IP
- All file read/write operations validate paths with `realpath()` to enforce strict path constraints
- Filenames are sanitized to alphanumeric plus `._- ` characters only; `.md` extension is enforced
- The Docker container and Nginx configs restrict direct access to `config.php`, `auth.db`, `.env`, and `backups/`
- `config.php`, `auth.db`, and `.env` are gitignored and must never be committed

### What you should do

- **Use HTTPS** in production — either via a reverse proxy (Nginx, Caddy) or Tailscale (which handles TLS automatically)
- **Use Tailscale or CIDR restriction** if your server is on the public internet — don't rely on password auth alone
- **Use a strong admin password** — the installer enforces minimum complexity requirements
- **Keep OpenMind updated** — use the in-app Update tab or `git pull && docker compose up -d --build`
- **Review your firewall rules** — OpenMind should not be exposed on ports you don't intend to open
- **Back up your data** — OpenMind creates automatic backups before edits, but maintain your own backup strategy for the workspace and `auth.db`

---

## Disclaimer

**This software is provided "as is", without warranty of any kind, express or implied.**

By installing or using OpenMind, you acknowledge and agree to the following:

1. **No Warranty.** The authors and contributors make no guarantees regarding the reliability, availability, accuracy, or fitness for purpose of this software.

2. **Use at Your Own Risk.** You are solely responsible for evaluating whether this software is appropriate for your environment and use case. The authors and contributors accept no liability for any damages, data loss, service interruptions, or other adverse outcomes arising from the use, deployment, misconfiguration, or inability to use this software.

3. **Deployment is Your Responsibility.** While reasonable effort has been made to follow established best practices, no software is guaranteed to be free of defects. You are responsible for properly configuring and maintaining your installation — including network setup, access controls, TLS/HTTPS, firewall rules, credential management, and keeping the software up to date.

4. **Compliance.** You are responsible for ensuring your use of this software complies with all applicable laws and regulations in your jurisdiction.

5. **Early-Stage Software.** OpenMind is in early alpha. APIs, configuration formats, and behavior may change without notice between versions.

The authors and contributors shall not be held liable under any legal theory for any direct, indirect, incidental, special, exemplary, consequential, or punitive damages arising out of the use of or inability to use this software.

---

## Contributing

OpenMind is open source and contributions are welcome. Whether it's a bug fix, a new feature, better documentation, or just a typo correction — every contribution helps.

### How to contribute

1. Fork the repository
2. Create a feature branch (`git checkout -b my-feature`)
3. Commit your changes
4. Push to your fork and open a Pull Request

### Ways to help beyond code

- **Report bugs** — open an issue with steps to reproduce
- **Suggest features** — ideas and feedback are valuable
- **Spread the word** — tell other OpenClaw users about OpenMind, post about it, share it in communities you're part of
- **Write documentation** — tutorials, guides, or translations

### Contribution expectations

OpenMind is licensed under AGPL-3.0. By submitting a pull request, you agree that your contribution will be licensed under the same terms. The AGPL requires that anyone who modifies this software and makes it available over a network (which is the primary use case for OpenMind) must share their modifications under the same license. This ensures that improvements made by the community benefit everyone.

---

## License

**GNU Affero General Public License v3.0 (AGPL-3.0)**

OpenMind is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

The AGPL-3.0 was chosen specifically because OpenMind is a web application. Under this license:

- You are free to use, modify, and distribute OpenMind
- If you modify OpenMind and make it available over a network (e.g., run a modified version on your server), you must make your modified source code available under the same license
- This ensures that improvements to OpenMind are shared back with the community

See [LICENSE](LICENSE) for the full license text.

---

## Acknowledgments

- [OpenClaw](https://openclaw.ai) — the AI agent framework that OpenMind is built for
- [steipete](https://github.com/steipete) — for the foundational work that inspired this project
- [jsMind](https://github.com/nicedoc/jsMind) — mindmap rendering engine
- [Toast UI Editor](https://github.com/nhn/tui.editor) — WYSIWYG markdown editor
- [Catppuccin](https://github.com/catppuccin) — color scheme inspiration
