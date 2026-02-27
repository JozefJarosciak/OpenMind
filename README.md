# OpenMind for OpenClaw

Interactive mindmap workspace viewer for [OpenClaw](https://openclaw.ai). Turns your OpenClaw workspace of markdown files into a navigable, editable mindmap — with a built-in WYSIWYG editor and live chat to your agent. All without npm, a bundler, or a build step. (Yes, really.)

![OpenMind Screenshot](docs/screenshot.png)

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
  - Date-named files (`YYYY-MM-DD.md`) are collected into a **📅 Memory History** group, sorted newest-first
  - Other memory files appear as regular top-level nodes

### Automatic Backups
Every save, rename, and delete creates a timestamped backup in `backups/YYYY-MM-DD_HH-MM-SS/` before touching the original. Sleep soundly.

---

## Requirements

- PHP 8.0+ with SQLite3 extension
- Nginx or Apache web server (or `php -S localhost:8080` for dev)
- [OpenClaw](https://openclaw.ai) installed (for chat and workspace files)

No Node.js. No npm. No Composer. No bundler. The dependencies are loaded from CDN at runtime:
- [jsMind v0.9.1](https://github.com/hizzgdev/jsmind) — mindmap
- [Toast UI Editor v3](https://github.com/nhn/tui.editor) — WYSIWYG markdown editor

---

## Installation

### Platform support

| Platform | Installer | Notes |
|---|---|---|
| **Linux** | Full support | Tested on Ubuntu, Debian, RHEL/Fedora, Arch |
| **macOS** | Full support | Detects Homebrew PHP automatically; offers launchd instead of systemd |
| **Windows (WSL)** | Full support | Run inside [WSL](https://learn.microsoft.com/en-us/windows/wsl/install) — identical to Linux |
| **Windows (native)** | Not supported | Bash scripts don't run natively; use WSL (see below) |

**Windows users:** Install [WSL](https://learn.microsoft.com/en-us/windows/wsl/install), open a WSL terminal, and follow the Linux path. OpenMind runs inside WSL and is accessible from your Windows browser at `http://localhost:PORT`. Pair it with Tailscale for remote access from anywhere.

---

### Quick Install (recommended)

Download and run the interactive installer. It auto-detects your OpenClaw setup, checks for port conflicts with any existing websites, and walks you through the rest:

```bash
curl -fsSL https://raw.githubusercontent.com/JozefJarosciak/OpenMind/main/install.sh -o install.sh
bash install.sh
```

> **Note:** Download and inspect the script before running if you prefer — it's a plain bash file and never modifies existing web server configurations automatically.

The installer will:
- Detect your OpenClaw binary, workspace path, and agent name automatically
- Scan active ports and warn about any conflicts before touching anything
- Ask where to install OpenMind (defaults to `/opt/openmind` or `~/openmind`)
- Collect all config settings interactively with sensible defaults
- Create your first admin user with password validation
- Generate a web server config (your choice: PHP built-in server, nginx, or skip)
- Optionally set up a systemd service and/or Tailscale access

**Existing websites are safe.** The installer only generates new config files — it never edits or overwrites anything already on your server. For nginx, it creates `openmind.nginx.conf` inside the install directory and shows you exactly how to place it yourself.

---

### Manual Installation

If you prefer to set things up by hand, or the installer doesn't cover your environment:

#### Prerequisites by platform

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
# Install Homebrew first if needed: https://brew.sh
brew install php git
# PHP from Homebrew includes SQLite3 by default
```

**Windows** — Install [WSL](https://learn.microsoft.com/en-us/windows/wsl/install), then follow the Linux steps above inside the WSL terminal.

#### 1. Clone the repository

```bash
# Linux / WSL
git clone https://github.com/JozefJarosciak/OpenMind.git /opt/openmind
cd /opt/openmind

# macOS (home directory recommended)
git clone https://github.com/JozefJarosciak/OpenMind.git ~/openmind
cd ~/openmind
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
    listen 8080;                    # Use a free port, or 80 with a unique server_name
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

Place this in `/etc/nginx/sites-available/openmind`, symlink to `sites-enabled/`, then `nginx -t && systemctl reload nginx`.

##### Apache (new virtual host)

```apache
<VirtualHost *:8080>
    ServerName openmind.example.com
    DocumentRoot /opt/openmind

    <Directory /opt/openmind>
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "(config\.php|auth\.db)$">
        Require all denied
    </FilesMatch>
</VirtualHost>
```

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

If the workspace is owned by a different user, see the [OpenClaw Chat Setup](#openclaw-chat-setup) section.

#### 6. Open in browser

Navigate to your server's URL and log in with the user you created.

---

### Tailscale Access

OpenMind works great over Tailscale — it runs on its own port entirely separate from OpenClaw's gateway (port 18789). To expose it on your tailnet:

```bash
tailscale serve --bg http://localhost:8080
```

Your OpenMind instance will then be reachable at `https://your-machine.tail-xyz.ts.net/` from any device on your tailnet, with Tailscale handling HTTPS automatically.

---

## Configuration Reference

All settings are editable via the in-app Settings modal. They can also be set directly in `config.php`.

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

All user management is done via the CLI tool. There is no admin UI for this — managing users in a browser is a solved problem for enterprise apps; this is not that.

```bash
# Add a user (prompts for password if not provided)
php setup/manage_users.php add alice

# Add with password inline
php setup/manage_users.php add alice MyPassword123!

# List all users
php setup/manage_users.php list

# Change a user's password
php setup/manage_users.php passwd alice

# Remove a user
php setup/manage_users.php remove alice
```

---

## Project Structure

```
OpenMind/
├── index.php              # Entry point — routing, auth, HTML template
├── config.sample.php      # Sample config (copy to config.php)
├── config.php             # Your config (gitignored, auto-generated by setup)
├── auth.db                # User database (gitignored, auto-created by manage_users.php)
├── includes/
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
│       ├── app.js         # Core: jsMind init, nodeDataMap, panel, editor, themes, branch refresh
│       ├── search.js      # Full-text search index, keyboard navigation, flash-on-navigate
│       ├── context-menu.js # Right-click file operations (create, rename, delete)
│       ├── settings.js    # Settings modal with tabs and live password validation
│       └── chat.js        # Chat UI, markdown renderer, typing indicator, session persistence
├── setup/
│   └── manage_users.php   # CLI user management tool
├── docs/
│   └── screenshot.png     # App screenshot
├── backups/               # Auto-created; timestamped backup directories
├── LICENSE                # MIT
└── README.md
```

---

## Security Notes

- `config.php` and `auth.db` are gitignored and must never be committed
- Passwords are hashed with bcrypt (PHP `PASSWORD_BCRYPT`)
- Sessions use `HttpOnly`, `Secure` (when HTTPS), and `SameSite=Strict` cookies with strict mode enabled
- Session ID is regenerated on successful login to prevent fixation
- All file read/write operations validate paths with `realpath()` — no `../../../etc/passwd` for you
- Filenames are sanitized to alphanumeric plus `._- ` characters only; `.md` extension is enforced
- The Nginx/Apache configs above deny direct access to `config.php` and `auth.db`
- Consider Tailscale or custom CIDR restriction for an extra layer of access control

---

## License

MIT License. See [LICENSE](LICENSE) for details.
