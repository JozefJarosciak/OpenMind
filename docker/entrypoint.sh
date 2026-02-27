#!/bin/sh
set -e

DATA_DIR="/app/data"
CONFIG_FILE="$DATA_DIR/config.php"
AUTH_DB="$DATA_DIR/auth.db"

mkdir -p "$DATA_DIR/backups"

# ── Generate config.php from env vars on first run ─────────────────────────
if [ ! -f "$CONFIG_FILE" ]; then
  echo ">> First run: generating config.php from environment..."
  php -r '
    $c = [
      "workspace_path"      => getenv("OPENMIND_WORKSPACE") ?: "/workspace",
      "backup_path"         => "/app/data/backups",
      "openclaw_command"    => getenv("OPENMIND_OPENCLAW_CMD") ?: "/usr/bin/openclaw",
      "openclaw_agent"      => getenv("OPENMIND_OPENCLAW_AGENT") ?: "main",
      "openclaw_run_as"     => getenv("OPENMIND_OPENCLAW_RUN_AS") ?: "",
      "network_restriction" => getenv("OPENMIND_NETWORK") ?: "none",
      "allowed_ips"         => getenv("OPENMIND_ALLOWED_IPS") ?: "",
      "session_lifetime"    => 86400,
      "app_title"           => getenv("OPENMIND_TITLE") ?: "OpenMind",
    ];
    file_put_contents(
      getenv("CONFIG_FILE"),
      "<?php\nreturn " . var_export($c, true) . ";\n"
    );
  ' CONFIG_FILE="$CONFIG_FILE"
  echo ">> config.php written"
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

# ── Fix permissions ────────────────────────────────────────────────────────
chown -R www-data:www-data "$DATA_DIR"
chmod 600 "$AUTH_DB" 2>/dev/null || true
chmod 640 "$CONFIG_FILE" 2>/dev/null || true

echo ">> Starting OpenMind on port 80 (mapped to host port via docker-compose)"
exec /usr/bin/supervisord -c /etc/supervisord.conf
