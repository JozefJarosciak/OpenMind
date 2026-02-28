#!/bin/sh
set -e

DATA_DIR="/app/data"
CONFIG_FILE="$DATA_DIR/config.php"
AUTH_DB="$DATA_DIR/auth.db"

mkdir -p "$DATA_DIR/backups"

# ── Detect OpenClaw agent name ────────────────────────────────────────────
AGENT_NAME=""
if [ -d /openclaw-home/agents ]; then
  for d in /openclaw-home/agents/*/; do
    _name=$(basename "$d")
    if [ "$_name" = "main" ]; then
      AGENT_NAME="main"
      break
    fi
    [ -z "$AGENT_NAME" ] && AGENT_NAME="$_name"
  done
  [ -n "$AGENT_NAME" ] && echo ">> Detected OpenClaw agent: $AGENT_NAME"
fi

# Resolve display title: explicit OPENMIND_TITLE > detected agent name > "OpenMind"
DISPLAY_TITLE="${OPENMIND_TITLE:-}"
[ -z "$DISPLAY_TITLE" ] && DISPLAY_TITLE="${AGENT_NAME:-OpenMind}"

# ── Generate config.php from env vars on first run ─────────────────────────
if [ ! -f "$CONFIG_FILE" ]; then
  echo ">> First run: generating config.php from environment..."
  CONFIG_FILE="$CONFIG_FILE" DISPLAY_TITLE="$DISPLAY_TITLE" php -r '
    $c = [
      "workspace_path"      => getenv("OPENMIND_WORKSPACE") ?: "/workspace",
      "backup_path"         => "/app/data/backups",
      "network_restriction" => getenv("OPENMIND_NETWORK") ?: "none",
      "allowed_ips"         => getenv("OPENMIND_ALLOWED_IPS") ?: "",
      "session_lifetime"    => 86400,
      "app_title"           => getenv("DISPLAY_TITLE"),
    ];
    file_put_contents(
      getenv("CONFIG_FILE"),
      "<?php\nreturn " . var_export($c, true) . ";\n"
    );
  '
  echo ">> config.php written (title: $DISPLAY_TITLE)"
else
  # Update app_title in existing config if it was auto-detected
  if [ -n "$AGENT_NAME" ]; then
    DISPLAY_TITLE="$DISPLAY_TITLE" php -r '
      $file = "/app/data/config.php";
      $c = require $file;
      $title = getenv("DISPLAY_TITLE");
      if ($c["app_title"] !== $title) {
        $c["app_title"] = $title;
        file_put_contents($file, "<?php\nreturn " . var_export($c, true) . ";\n");
      }
    '
  fi
fi

# Symlink config.php and auth.db into app root (where PHP expects them)
ln -sf "$CONFIG_FILE" /app/config.php
ln -sf "$AUTH_DB" /app/auth.db 2>/dev/null || true

# ── Create admin user on first run ─────────────────────────────────────────
if [ ! -f "$AUTH_DB" ]; then
  ADMIN_USER="${OPENMIND_ADMIN_USER:-admin}"
  ADMIN_PASS="${OPENMIND_ADMIN_PASS:-}"
  if [ -z "$ADMIN_PASS" ]; then
    echo ">> ERROR: OPENMIND_ADMIN_PASS is required on first run"
    exit 1
  fi
  echo ">> Creating admin user: $ADMIN_USER"
  php /app/setup/manage_users.php add "$ADMIN_USER" "$ADMIN_PASS"
  # Now symlink the created auth.db into data dir for persistence
  if [ -f /app/auth.db ] && [ ! -L /app/auth.db ]; then
    mv /app/auth.db "$AUTH_DB"
    ln -sf "$AUTH_DB" /app/auth.db
  fi
  echo ">> Admin user created"
fi

# Ensure symlink exists for auth.db (may have been created by manage_users.php)
if [ -f "$AUTH_DB" ] && [ ! -L /app/auth.db ]; then
  ln -sf "$AUTH_DB" /app/auth.db
fi

# ── Initialize git repo for update checks ─────────────────────────────────
if [ ! -d /app/.git ]; then
  echo ">> Initializing git repo for update checks..."
  cd /app
  git init -b main
  git remote add origin https://github.com/JozefJarosciak/OpenMind.git
  git fetch --depth=1 origin main 2>/dev/null || true
  git reset --soft origin/main 2>/dev/null || true
  cd /
  echo ">> Git repo initialized"
fi

# ── Fix permissions ────────────────────────────────────────────────────────
chown -R www-data:www-data "$DATA_DIR"
chmod 600 "$AUTH_DB" 2>/dev/null || true
chmod 640 "$CONFIG_FILE" 2>/dev/null || true

echo ">> Starting OpenMind on port 80 (mapped to host port via docker-compose)"
exec /usr/bin/supervisord -c /etc/supervisord.conf
