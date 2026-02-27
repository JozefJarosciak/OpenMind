#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# OpenMind for OpenClaw — Interactive Installer
# https://github.com/JozefJarosciak/OpenMind
#
# Safe to run on servers with existing websites — detects conflicts and never
# modifies any existing web server configuration automatically.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

OPENMIND_REPO="https://github.com/JozefJarosciak/OpenMind.git"
OPENMIND_BRANCH="main"
TOTAL_STEPS=7
OPENCLAW_GW_PORT=18789   # OpenClaw gateway — never use this port

# Detect OS once, used throughout
OS_TYPE="$(uname -s)"   # Linux | Darwin

# ── Colors ────────────────────────────────────────────────────────────────────
if [ -t 1 ] && [ "${NO_COLOR:-0}" = "0" ]; then
  RED='\033[0;31m' GREEN='\033[0;32m' YELLOW='\033[1;33m'
  BLUE='\033[0;34m' CYAN='\033[0;36m' BOLD='\033[1m' DIM='\033[2m' NC='\033[0m'
else
  RED='' GREEN='' YELLOW='' BLUE='' CYAN='' BOLD='' DIM='' NC=''
fi

# ── Output helpers ─────────────────────────────────────────────────────────────
ok()   { printf "  ${GREEN}✓${NC} %s\n" "$*"; }
warn() { printf "  ${YELLOW}⚠${NC} %s\n" "$*"; }
info() { printf "  ${CYAN}→${NC} %s\n" "$*"; }
step() { printf "\n${BOLD}${BLUE}[%s/%s]${NC} ${BOLD}%s${NC}\n" "$1" "$TOTAL_STEPS" "$2"; }
die()  { printf "\n${RED}Fatal:${NC} %s\n\n" "$*" >&2; exit 1; }
hr()   { printf "  ${DIM}%s${NC}\n" "────────────────────────────────────────────"; }

# ── Input helpers (use /dev/tty so curl|bash works) ────────────────────────────
ask() {
  local _var="$1" _prompt="$2" _default="${3:-}" _input
  [ -n "$_default" ] \
    && printf "  %s [%s]: " "$_prompt" "$_default" >&2 \
    || printf "  %s: " "$_prompt" >&2
  IFS= read -r _input </dev/tty || _input=""
  printf -v "$_var" '%s' "${_input:-$_default}"
}

ask_secret() {
  local _var="$1" _prompt="$2" _input
  printf "  %s: " "$_prompt" >&2
  IFS= read -rs _input </dev/tty || _input=""
  echo >&2
  printf -v "$_var" '%s' "$_input"
}

ask_yn() {
  local _prompt="$1" _default="${2:-y}" _input _yn
  [[ "$_default" =~ ^[Yy] ]] && _yn="Y/n" || _yn="y/N"
  printf "  %s [%s]: " "$_prompt" "$_yn" >&2
  IFS= read -r _input </dev/tty || _input=""
  _input="${_input:-$_default}"
  [[ "$_input" =~ ^[Yy] ]]
}

# ── Port utilities ─────────────────────────────────────────────────────────────
port_in_use() {
  local port="$1"
  if command -v ss &>/dev/null; then
    ss -lntp 2>/dev/null | grep -q ":${port}[[:space:]]" && return 0
  fi
  if command -v netstat &>/dev/null; then
    netstat -lntp 2>/dev/null | grep -q ":${port}[[:space:]]" && return 0
  fi
  if command -v lsof &>/dev/null; then
    lsof -i ":${port}" -sTCP:LISTEN &>/dev/null && return 0
  fi
  # Last resort: try connecting
  (echo >/dev/tcp/localhost/"$port") &>/dev/null && return 0 || return 1
}

# Returns a human-readable description of what is using a port
port_owner() {
  local port="$1" _info=""

  # Check if it's a Docker container
  if command -v docker &>/dev/null; then
    _info=$(dkr ps --format '{{.Names}} (image: {{.Image}})' \
            --filter "publish=${port}" 2>/dev/null | head -1)
    if [ -n "$_info" ]; then
      echo "Docker container: $_info"
      return
    fi
  fi

  # Try ss with sudo (docker-proxy runs as root)
  if command -v ss &>/dev/null; then
    _info=$(sudo ss -lntp 2>/dev/null | grep ":${port}[[:space:]]" \
            | sed -n 's/.*users:(("\([^"]*\)",pid=\([0-9]*\).*/\1 (PID \2)/p' | head -1)
    [ -n "$_info" ] && { echo "$_info"; return; }
  fi

  # Try lsof with sudo
  if command -v lsof &>/dev/null; then
    _info=$(sudo lsof -i ":${port}" -sTCP:LISTEN -n -P 2>/dev/null \
            | awk 'NR==2 {print $1 " (PID " $2 ")"}')
    [ -n "$_info" ] && { echo "$_info"; return; }
  fi

  echo "unknown process"
}

# Kill whatever is using a port. Returns 0 on success.
kill_port() {
  local port="$1" _container _pids

  # If it's a Docker container, stop it
  if command -v docker &>/dev/null; then
    _container=$(dkr ps -q --filter "publish=${port}" 2>/dev/null | head -1)
    if [ -n "$_container" ]; then
      info "Stopping Docker container $_container..."
      dkr stop "$_container" &>/dev/null || true
      dkr rm -f "$_container" &>/dev/null || true
      sleep 1
      port_in_use "$port" || { ok "Port $port freed"; return 0; }
    fi
  fi

  # Find PIDs holding the port (use sudo for root-owned processes like docker-proxy)
  _pids=""
  if command -v lsof &>/dev/null; then
    _pids=$(sudo lsof -i ":${port}" -sTCP:LISTEN -t 2>/dev/null || lsof -i ":${port}" -sTCP:LISTEN -t 2>/dev/null || true)
  fi
  if [ -z "$_pids" ] && command -v ss &>/dev/null; then
    _pids=$(sudo ss -lntp 2>/dev/null | grep ":${port}[[:space:]]" \
            | grep -oP 'pid=\K[0-9]+' || true)
  fi
  # Also look for docker-proxy specifically
  if [ -z "$_pids" ]; then
    _pids=$(pgrep -f "docker-proxy.*:${port}" 2>/dev/null || true)
  fi

  if [ -n "${_pids:-}" ]; then
    for _p in $_pids; do
      info "Killing PID $_p..."
      sudo kill "$_p" 2>/dev/null || kill "$_p" 2>/dev/null || true
    done
    sleep 1
    port_in_use "$port" || { ok "Port $port freed"; return 0; }
    # Force kill if still alive
    for _p in $_pids; do
      sudo kill -9 "$_p" 2>/dev/null || kill -9 "$_p" 2>/dev/null || true
    done
    sleep 1
    port_in_use "$port" || { ok "Port $port freed"; return 0; }
  fi

  # Last resort: restart Docker daemon to release all ghost port bindings
  if port_in_use "$port"; then
    info "Restarting Docker daemon to release orphaned port bindings..."
    sudo systemctl restart docker 2>/dev/null || sudo service docker restart 2>/dev/null || true
    sleep 2
    port_in_use "$port" || { ok "Port $port freed"; return 0; }
  fi

  return 1
}

find_free_port() {
  local port="${1:-8080}"
  while port_in_use "$port" || [ "$port" -eq "$OPENCLAW_GW_PORT" ]; do
    port=$((port + 1))
    [ "$port" -gt 9999 ] && die "Could not find a free port between 8080 and 9999"
  done
  echo "$port"
}

# Interactive port selection — identifies conflicts, offers to kill or pick another
pick_port() {
  local _suggested
  _suggested=$(find_free_port 8080)
  ask DOCKER_PORT "Port for OpenMind" "$_suggested"

  while true; do
    [ "$DOCKER_PORT" -eq "$OPENCLAW_GW_PORT" ] 2>/dev/null \
      && { warn "Port $OPENCLAW_GW_PORT is reserved for the OpenClaw gateway."; } \
      || {
        # Check if port is free
        if ! port_in_use "$DOCKER_PORT"; then
          break   # Port is available, we're done
        fi

        _owner=$(port_owner "$DOCKER_PORT")
        warn "Port $DOCKER_PORT is in use by: $_owner"
        printf "\n"
        printf "    1) Free port $DOCKER_PORT (stop/kill what's using it)\n"
        printf "    2) Pick a different port\n"
        printf "\n"
        ask _PORT_ACTION "Choice" "1"

        if [ "$_PORT_ACTION" = "1" ]; then
          if kill_port "$DOCKER_PORT"; then
            break   # Port freed successfully
          else
            warn "Could not free port $DOCKER_PORT automatically."
          fi
        fi
      }

    # Re-prompt with next free port
    _suggested=$(find_free_port "$((DOCKER_PORT + 1))")
    ask DOCKER_PORT "Port for OpenMind" "$_suggested"
  done
}

report_used_ports() {
  local busy=()
  for p in 80 443 3000 8080 8443 "$OPENCLAW_GW_PORT"; do
    port_in_use "$p" && busy+=("$p") || true
  done
  if [ "${#busy[@]}" -gt 0 ]; then
    warn "Ports already in use on this machine: ${busy[*]}"
    info "OpenMind will be placed on a different port automatically."
  fi
}

# ── Banner ─────────────────────────────────────────────────────────────────────
printf "${BOLD}${BLUE}"
cat <<'BANNER'
   ___                 __  __ _           _
  / _ \ _ __  ___ _ _|  \/  (_)_ __   __| |
 | | | | '_ \/ _ \ ' \ |\/| | | '_ \ / _` |
 | |_| | |_) \  __/ | | |  | | | | | (_| |
  \___/| .__/ \___|_| |_|  |_|_|_| |_\__,_|
       |_|         for OpenClaw — Installer
BANNER
printf "${NC}"
printf "  %s\n" "Interactive setup — takes about 2 minutes."
printf "  ${DIM}%s${NC}\n\n" "Safe to run alongside existing websites. Nothing is overwritten automatically."

# ── Docker detection ──────────────────────────────────────────────────────────
HAS_DOCKER=0
if command -v docker &>/dev/null && (docker compose version &>/dev/null 2>&1 || docker-compose version &>/dev/null 2>&1); then
  HAS_DOCKER=1
fi

# Track whether we just installed Docker (need sudo since group not active yet)
DOCKER_JUST_INSTALLED=0

# If Docker is NOT installed, offer to install it
if [ "$HAS_DOCKER" -eq 0 ]; then
  printf "  Docker is not installed on this machine.\n\n"
  printf "  %s\n" "How would you like to install OpenMind?"
  printf "    ${GREEN}1) Install Docker first, then use Docker (recommended)${NC}\n"
  printf "    2) Manual — install PHP and configure directly on this machine\n"
  printf "\n"
  ask INSTALL_MODE "Choice" "1"

  if [ "$INSTALL_MODE" = "1" ]; then
    info "Installing Docker..."
    if [ "$OS_TYPE" = "Darwin" ]; then
      die "On macOS, install Docker Desktop from https://docs.docker.com/desktop/install/mac-install/ then re-run this script."
    fi
    if curl -fsSL https://get.docker.com | sh; then
      ok "Docker installed"
      DOCKER_JUST_INSTALLED=1
      # Add current user to docker group if not root
      if [ "$EUID" -ne 0 ] && [ -n "${USER:-}" ]; then
        sudo usermod -aG docker "$USER" 2>/dev/null || true
      fi
      HAS_DOCKER=1
    else
      die "Docker installation failed. Install manually: https://docs.docker.com/get-docker/"
    fi
  fi
  # If user chose "2", HAS_DOCKER stays 0, falls through to manual path
  printf "\n"
fi

# Helper: run docker commands — uses sudo if current user can't talk to the daemon
DOCKER_NEEDS_SUDO=0
if [ "$EUID" -ne 0 ]; then
  if ! docker info &>/dev/null; then
    DOCKER_NEEDS_SUDO=1
  fi
fi

dkr() {
  if [ "$DOCKER_NEEDS_SUDO" -eq 1 ]; then
    sudo docker "$@"
  else
    docker "$@"
  fi
}

# ── Docker interactive setup ─────────────────────────────────────────────────
if [ "$HAS_DOCKER" -eq 1 ]; then
  # If Docker was already present (not just installed), ask how to install
  if [ "$DOCKER_JUST_INSTALLED" -eq 0 ]; then
    ok "Docker detected"
    printf "\n"
    printf "  %s\n" "How would you like to install OpenMind?"
    printf "    ${GREEN}1) Docker (recommended)${NC} — self-contained, no dependencies to install\n"
    printf "    2) Manual — install PHP and configure directly on this machine\n"
    printf "\n"
    ask INSTALL_MODE "Choice" "1"
  else
    # We just installed Docker — user already chose Docker, go straight to setup
    INSTALL_MODE="1"
  fi

  if [ "$INSTALL_MODE" = "1" ]; then
    # ── Docker install path ────────────────────────────────────────────────
    printf "\n${BOLD}${BLUE}Docker Setup${NC}\n\n"

    # Clone or use existing repo
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || pwd)"
    if [ -f "$SCRIPT_DIR/docker-compose.yml" ] && [ -f "$SCRIPT_DIR/Dockerfile" ]; then
      INSTALL_DIR="$SCRIPT_DIR"
      ok "Using current directory: $INSTALL_DIR"
    else
      if [ "$EUID" -ne 0 ] 2>/dev/null; then
        DEFAULT_DIR="$HOME/openmind"
      else
        DEFAULT_DIR="/opt/openmind"
      fi
      ask INSTALL_DIR "Install directory" "$DEFAULT_DIR"
      if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/docker-compose.yml" ]; then
        ok "Existing installation found"
        if ask_yn "Pull latest changes?"; then
          git -C "$INSTALL_DIR" pull --ff-only && ok "Updated" \
            || warn "Git pull failed — continuing with existing code"
        fi
      else
        info "Cloning OpenMind..."
        mkdir -p "$(dirname "$INSTALL_DIR")"
        git clone --depth 1 --branch "$OPENMIND_BRANCH" "$OPENMIND_REPO" "$INSTALL_DIR" \
          && ok "Cloned to $INSTALL_DIR" \
          || die "Clone failed. Check your internet connection."
      fi
    fi

    printf "\n"

    # ── Thorough Docker cleanup helper ──────────────────────────────────────
    docker_full_cleanup() {
      local _dir="${1:-$INSTALL_DIR}"

      # 1. docker compose down (removes container, network, etc.)
      if [ -f "$_dir/docker-compose.yml" ]; then
        cd "$_dir"
        dkr compose down --remove-orphans &>/dev/null 2>&1 || true
      fi

      # 2. Force-remove the container by name if it's still lingering
      dkr rm -f openmind &>/dev/null 2>&1 || true

      # 3. Remove any stopped containers from this project
      dkr container prune -f &>/dev/null 2>&1 || true

      # 4. Clean up orphaned networks that might hold port bindings
      dkr network prune -f &>/dev/null 2>&1 || true

      # 5. Small pause for Docker daemon to release port bindings
      sleep 1
    }

    # ── Check for existing OpenMind container ──────────────────────────────
    _existing_container=$(dkr ps -a -q --filter "name=openmind" 2>/dev/null || true)
    if [ -n "$_existing_container" ]; then
      _running=$(dkr ps -q --filter "name=openmind" 2>/dev/null || true)
      _existing_port=$(dkr port openmind 80 2>/dev/null | sed 's/.*://' || true)

      if [ -n "$_running" ]; then
        ok "OpenMind is running (container: openmind, port: ${_existing_port:-?})"
      else
        warn "OpenMind container exists but is not running (crashed or stopped)"
      fi

      printf "\n"
      printf "    1) Update — rebuild image, keep existing settings\n"
      printf "    2) Fresh install — reconfigure everything from scratch\n"
      printf "    3) Stop — shut down and exit\n"
      printf "\n"
      ask _EXISTING_ACTION "Choice" "1"

      case "$_EXISTING_ACTION" in
        1)
          info "Updating OpenMind..."
          docker_full_cleanup "$INSTALL_DIR"
          cd "$INSTALL_DIR"
          dkr compose build 2>&1 && ok "Image rebuilt" \
            || die "Docker build failed. Check the output above."
          dkr compose up -d 2>&1 && ok "Container restarted" \
            || die "Failed to start container."
          printf "\n${GREEN}${BOLD}  OpenMind updated and running on port ${_existing_port:-8080}${NC}\n\n"
          exit 0
          ;;
        3)
          info "Stopping OpenMind..."
          docker_full_cleanup "$INSTALL_DIR"
          ok "OpenMind stopped"
          exit 0
          ;;
        *)
          # Fresh install — full cleanup, then continue with setup
          info "Cleaning up existing installation..."
          docker_full_cleanup "$INSTALL_DIR"
          ok "Cleaned up"
          printf "\n"
          ;;
      esac
    else
      # No container at all — still do a cleanup to clear orphaned networks/ports
      docker_full_cleanup "$INSTALL_DIR" 2>/dev/null
    fi

    # ── Detect OpenClaw workspace ──────────────────────────────────────────
    WORKSPACE_PATH=""
    for _dir in \
        "$HOME/.openclaw/workspace" \
        "/root/.openclaw/workspace" \
        "/home/*/.openclaw/workspace"; do
      # shellcheck disable=SC2086
      for _d in $_dir; do
        [ -d "$_d" ] && { WORKSPACE_PATH="$_d"; break 2; }
      done
    done
    if [ -n "$WORKSPACE_PATH" ]; then
      ok "OpenClaw workspace detected: $WORKSPACE_PATH"
      if ! ask_yn "Use this workspace path?"; then WORKSPACE_PATH=""; fi
    fi
    [ -z "$WORKSPACE_PATH" ] && ask WORKSPACE_PATH "OpenClaw workspace path" "$HOME/.openclaw/workspace"
    [ -d "$WORKSPACE_PATH" ] || die "Directory does not exist: $WORKSPACE_PATH"
    WORKSPACE_PATH="$(cd "$WORKSPACE_PATH" && pwd)"

    # ── Detect OpenClaw binary ─────────────────────────────────────────────
    OPENCLAW_CMD=""
    for _c in "$(command -v openclaw 2>/dev/null || true)" \
        /usr/bin/openclaw /usr/local/bin/openclaw \
        "$HOME/.openclaw/bin/openclaw" "$HOME/.local/bin/openclaw" \
        /opt/openclaw/bin/openclaw; do
      [ -n "${_c:-}" ] && [ -x "$_c" ] && { OPENCLAW_CMD="$_c"; ok "OpenClaw binary: $_c"; break; }
    done
    [ -z "$OPENCLAW_CMD" ] && OPENCLAW_CMD="/usr/bin/openclaw"

    # ── Detect OpenClaw agent name ─────────────────────────────────────────
    DETECTED_AGENT="main"
    for _cfg in "$HOME/.openclaw/openclaw.json" "$HOME/.openclaw/config.json"; do
      [ -f "$_cfg" ] || continue
      if command -v jq &>/dev/null; then
        _a=$(jq -r '.agent // .default_agent // .defaultAgent // ""' "$_cfg" 2>/dev/null || true)
        [ -n "$_a" ] && { DETECTED_AGENT="$_a"; break; }
      fi
    done

    # ── Detect run-as user ─────────────────────────────────────────────────
    DETECTED_RUN_AS=""
    if [ "$EUID" -eq 0 ] 2>/dev/null && id openclaw &>/dev/null 2>&1; then
      DETECTED_RUN_AS="openclaw"
    elif [ "$EUID" -eq 0 ] 2>/dev/null && [ -n "${SUDO_USER:-}" ]; then
      DETECTED_RUN_AS="$SUDO_USER"
    fi

    printf "\n"

    # ── Port ───────────────────────────────────────────────────────────────
    pick_port

    # ── Admin credentials ──────────────────────────────────────────────────
    printf "\n"
    ask ADMIN_USER "Admin username" "admin"
    while true; do
      ask_secret ADMIN_PASS "Admin password (8+ chars, upper, lower, number, special)"
      _ok=1
      [ "${#ADMIN_PASS}" -lt 8 ]           && { warn "At least 8 characters required"; _ok=0; }
      [[ "$ADMIN_PASS" =~ [A-Z] ]]         || { warn "Needs at least one uppercase letter"; _ok=0; }
      [[ "$ADMIN_PASS" =~ [a-z] ]]         || { warn "Needs at least one lowercase letter"; _ok=0; }
      [[ "$ADMIN_PASS" =~ [0-9] ]]         || { warn "Needs at least one number"; _ok=0; }
      [[ "$ADMIN_PASS" =~ [^A-Za-z0-9] ]] || { warn "Needs at least one special character"; _ok=0; }
      if [ "$_ok" -eq 1 ]; then
        ask_secret ADMIN_PASS2 "Confirm password"
        [ "$ADMIN_PASS" = "$ADMIN_PASS2" ] && break
        warn "Passwords do not match, try again"
      fi
    done

    # ── Optional settings ──────────────────────────────────────────────────
    printf "\n"
    ask APP_TITLE        "App title"                                    "OpenMind"
    ask OPENCLAW_AGENT   "OpenClaw agent name"                          "$DETECTED_AGENT"
    ask OPENCLAW_RUN_AS  "Run openclaw as user (blank = container user)" "$DETECTED_RUN_AS"

    printf "\n  %s\n" "Network restriction:"
    printf "    %s\n" "1) None — allow all connections"
    printf "    %s\n" "2) Tailscale only (recommended for remote servers)"
    printf "    %s\n" "3) Custom IP ranges (CIDR)"
    ask NET_CHOICE "Choice" "1"

    NETWORK_RESTRICTION="none"
    ALLOWED_IPS=""
    case "$NET_CHOICE" in
      2) NETWORK_RESTRICTION="tailscale" ;;
      3)
        NETWORK_RESTRICTION="custom"
        ask ALLOWED_IPS "Allowed CIDR ranges (comma-separated)" "192.168.1.0/24"
        ;;
    esac

    # ── Write .env ─────────────────────────────────────────────────────────
    cat > "$INSTALL_DIR/.env" <<ENVEOF
OPENMIND_WORKSPACE=$WORKSPACE_PATH
OPENMIND_PORT=$DOCKER_PORT
OPENMIND_ADMIN_USER=$ADMIN_USER
OPENMIND_ADMIN_PASS=$ADMIN_PASS
OPENMIND_TITLE=$APP_TITLE
OPENMIND_OPENCLAW_CMD=$OPENCLAW_CMD
OPENMIND_OPENCLAW_AGENT=$OPENCLAW_AGENT
OPENMIND_OPENCLAW_RUN_AS=$OPENCLAW_RUN_AS
OPENMIND_NETWORK=$NETWORK_RESTRICTION
OPENMIND_ALLOWED_IPS=$ALLOWED_IPS
ENVEOF
    chmod 600 "$INSTALL_DIR/.env"
    ok ".env written"

    # Clear sensitive vars
    ADMIN_PASS="" ADMIN_PASS2="" 2>/dev/null || true

    # ── Build and start ────────────────────────────────────────────────────
    printf "\n"
    cd "$INSTALL_DIR"

    info "Building Docker image (this may take a minute on first run)..."
    if dkr compose build 2>&1; then
      ok "Image built"
    else
      die "Docker build failed. Check the output above."
    fi

    # Verify port is actually free right before starting
    if port_in_use "$DOCKER_PORT"; then
      warn "Port $DOCKER_PORT became occupied during build."
      _owner=$(port_owner "$DOCKER_PORT")
      info "In use by: $_owner"
      info "Attempting to free port $DOCKER_PORT..."
      kill_port "$DOCKER_PORT" || true
      sleep 1
      if port_in_use "$DOCKER_PORT"; then
        warn "Could not free port $DOCKER_PORT."
        _suggested=$(find_free_port "$((DOCKER_PORT + 1))")
        ask DOCKER_PORT "Pick a different port" "$_suggested"
        # Rewrite .env with new port
        sed -i "s/^OPENMIND_PORT=.*/OPENMIND_PORT=$DOCKER_PORT/" "$INSTALL_DIR/.env"
        ok "Updated .env with port $DOCKER_PORT"
      fi
    fi

    info "Starting OpenMind..."
    _up_output=$(dkr compose up -d 2>&1) && _up_ok=true || _up_ok=false
    if [ "$_up_ok" = true ]; then
      ok "Container started"
    else
      echo "$_up_output"
      # Check if it's a port conflict
      if echo "$_up_output" | grep -qi "address already in use\|port is already allocated"; then
        warn "Port $DOCKER_PORT is still in use. Cleaning up and retrying..."
        docker_full_cleanup "$INSTALL_DIR"
        kill_port "$DOCKER_PORT" 2>/dev/null || true
        sleep 2
        if port_in_use "$DOCKER_PORT"; then
          _suggested=$(find_free_port "$((DOCKER_PORT + 1))")
          warn "Port $DOCKER_PORT cannot be freed."
          ask DOCKER_PORT "Pick a different port" "$_suggested"
          sed -i "s/^OPENMIND_PORT=.*/OPENMIND_PORT=$DOCKER_PORT/" "$INSTALL_DIR/.env"
        fi
        info "Retrying on port $DOCKER_PORT..."
        if dkr compose up -d 2>&1; then
          ok "Container started"
        else
          die "Still failing. Try rebooting and re-running the installer, or pick a different port:\n  Edit $INSTALL_DIR/.env and change OPENMIND_PORT, then run:\n  cd $INSTALL_DIR && docker compose up -d"
        fi
      else
        die "Failed to start container. Check the error above.\n  Logs: cd $INSTALL_DIR && docker compose logs openmind"
      fi
    fi

    OPENMIND_URL="http://localhost:$DOCKER_PORT"

    # ── Tailscale (optional) ───────────────────────────────────────────────
    HAS_TAILSCALE=0; command -v tailscale &>/dev/null && HAS_TAILSCALE=1
    if [ "$HAS_TAILSCALE" -eq 1 ]; then
      printf "\n"
      if ask_yn "Expose on Tailscale (private tailnet access via HTTPS)?"; then
        if tailscale serve --bg "http://localhost:$DOCKER_PORT" &>/dev/null; then
          ok "Tailscale Serve configured on port $DOCKER_PORT"
          _ts_url=$(tailscale status --json 2>/dev/null \
            | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    name = d.get('Self', {}).get('DNSName', '').rstrip('.')
    print('https://' + name + '/' if name else '')
except: print('')
" 2>/dev/null || true)
          [ -n "${_ts_url:-}" ] && OPENMIND_URL="$_ts_url" && ok "Tailscale URL: $OPENMIND_URL"
        else
          warn "tailscale serve failed — try manually: tailscale serve --bg http://localhost:$DOCKER_PORT"
        fi
      fi
    fi

    # ── Summary ────────────────────────────────────────────────────────────
    printf "\n${GREEN}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
    printf "${GREEN}${BOLD}  OpenMind installed successfully!${NC}\n"
    printf "${GREEN}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n\n"
    printf "  %-22s %s\n" "URL:"          "$OPENMIND_URL"
    printf "  %-22s %s\n" "Install dir:"  "$INSTALL_DIR"
    printf "  %-22s %s\n" "Workspace:"    "$WORKSPACE_PATH"
    printf "  %-22s %s\n" "Admin user:"   "$ADMIN_USER"
    printf "  %-22s %s\n" "Agent:"        "$OPENCLAW_AGENT"
    printf "  %-22s %s\n" "Network:"      "$NETWORK_RESTRICTION"
    printf "  %-22s %s\n" "Container:"    "openmind"
    printf "\n"
    printf "  Open the URL above in your browser and log in.\n\n"
    if [ "$DOCKER_NEEDS_SUDO" -eq 1 ]; then
      printf "  ${YELLOW}Note:${NC} Log out and back in (or run 'newgrp docker') to use\n"
      printf "  docker commands without sudo.\n\n"
    fi
    printf "  ${DIM}Manage:${NC}\n"
    printf "    Stop:      cd $INSTALL_DIR && docker compose down\n"
    printf "    Start:     cd $INSTALL_DIR && docker compose up -d\n"
    printf "    Logs:      cd $INSTALL_DIR && docker compose logs -f\n"
    printf "    Rebuild:   cd $INSTALL_DIR && docker compose up -d --build\n"
    printf "    Add user:  docker compose -f $INSTALL_DIR/docker-compose.yml exec openmind php setup/manage_users.php add <username>\n"
    printf "\n"
    exit 0
  fi
  # If user chose "2" (manual), fall through to the manual install path below
  printf "\n"
fi

# ── Step 1: Prerequisites ──────────────────────────────────────────────────────
step 1 "Prerequisites"

PHP_BIN=""
# On macOS, Homebrew installs PHP under /opt/homebrew (Apple Silicon) or /usr/local (Intel)
_php_candidates=(php php8 php8.4 php8.3 php8.2 php8.1 php8.0)
if [ "$OS_TYPE" = "Darwin" ]; then
  _php_candidates+=(
    /opt/homebrew/bin/php
    /opt/homebrew/opt/php/bin/php
    /usr/local/bin/php
    /usr/local/opt/php/bin/php
  )
fi
for candidate in "${_php_candidates[@]}"; do
  if command -v "$candidate" &>/dev/null 2>&1 || [ -x "$candidate" ]; then
    _ver=$("$candidate" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "0.0")
    _major="${_ver%%.*}"
    _minor="${_ver#*.}"
    if [ "$_major" -gt 8 ] || { [ "$_major" -eq 8 ] && [ "${_minor%%.*}" -ge 0 ]; }; then
      PHP_BIN="$candidate"
      ok "PHP $_ver ($candidate)"
      break
    fi
  fi
done
if [ -z "$PHP_BIN" ]; then
  if [ "$OS_TYPE" = "Darwin" ]; then
    die "PHP 8.0+ not found.\n  macOS:          brew install php"
  else
    die "PHP 8.0+ not found.\n  Ubuntu/Debian:  sudo apt install php-cli php-sqlite3\n  RHEL/Fedora:    sudo dnf install php php-pdo\n  Arch:           sudo pacman -S php"
  fi
fi

if $PHP_BIN -r 'exit(class_exists("SQLite3") ? 0 : 1);' 2>/dev/null; then
  ok "PHP SQLite3 extension"
else
  if [ "$OS_TYPE" = "Darwin" ]; then
    die "PHP SQLite3 extension not found.\n  macOS: Homebrew PHP includes SQLite3 by default — try reinstalling: brew reinstall php"
  else
    die "PHP SQLite3 extension not found.\n  Ubuntu/Debian:  sudo apt install php-sqlite3\n  RHEL/Fedora:    sudo dnf install php-pdo"
  fi
fi

command -v git &>/dev/null && ok "git $(git --version | awk '{print $3}')" \
  || die "git not found. Install git before running this script."

# Detect optional tools
HAS_NGINX=0
# On macOS, nginx may be in Homebrew paths not yet on PATH
for _ng in nginx /opt/homebrew/bin/nginx /usr/local/bin/nginx; do
  if command -v "$_ng" &>/dev/null 2>&1 || [ -x "$_ng" ]; then
    HAS_NGINX=1
    ok "nginx detected ($("$_ng" -v 2>&1 | grep -o '[0-9][0-9.]*' | head -1))"
    NGINX_BIN="$_ng"
    break
  fi
done
HAS_TAILSCALE=0; command -v tailscale &>/dev/null && HAS_TAILSCALE=1 && ok "Tailscale CLI detected"
HAS_SYSTEMD=0;   command -v systemctl &>/dev/null && HAS_SYSTEMD=1
HAS_LAUNCHD=0;   [ "$OS_TYPE" = "Darwin" ] && command -v launchctl &>/dev/null && HAS_LAUNCHD=1

report_used_ports

# ── Step 2: OpenClaw detection ─────────────────────────────────────────────────
step 2 "Detecting OpenClaw"

# --- Binary ---
OPENCLAW_CMD=""
for candidate in \
    "$(command -v openclaw 2>/dev/null || true)" \
    "/usr/bin/openclaw" "/usr/local/bin/openclaw" \
    "$HOME/.openclaw/bin/openclaw" "$HOME/.local/bin/openclaw" \
    "/opt/openclaw/bin/openclaw" \
    "/opt/homebrew/bin/openclaw" \
    "$HOME/Library/Application Support/OpenClaw/openclaw"; do
  [ -n "${candidate:-}" ] && [ -x "$candidate" ] && { OPENCLAW_CMD="$candidate"; break; }
done

if [ -n "$OPENCLAW_CMD" ]; then
  ok "openclaw binary: $OPENCLAW_CMD"
else
  warn "openclaw binary not found in common locations"
  ask OPENCLAW_CMD "Path to openclaw binary" "/usr/bin/openclaw"
fi

# --- Workspace path ---
WORKSPACE_PATH=""

# 1. Try running 'openclaw config' if the binary exists
if [ -x "${OPENCLAW_CMD:-}" ]; then
  _cw=$("$OPENCLAW_CMD" config --get workspace_path 2>/dev/null \
        || "$OPENCLAW_CMD" config workspace 2>/dev/null \
        || true)
  [ -n "$_cw" ] && [ -d "$_cw" ] && WORKSPACE_PATH="$_cw"
fi

# 2. Try common JSON config file locations
if [ -z "$WORKSPACE_PATH" ]; then
  for _cfg in \
      "$HOME/.openclaw/openclaw.json" \
      "$HOME/.openclaw/config.json" \
      "$HOME/.config/openclaw/config.json" \
      "/root/.openclaw/openclaw.json" \
      "/etc/openclaw/config.json"; do
    [ -f "$_cfg" ] || continue
    _candidate=""
    if command -v jq &>/dev/null; then
      _candidate=$(jq -r '
        .workspace_path // .workspace // .workspacePath //
        .settings.workspace_path // .settings.workspace // ""
      ' "$_cfg" 2>/dev/null || true)
    elif command -v python3 &>/dev/null; then
      _candidate=$(python3 - "$_cfg" <<'PY' 2>/dev/null || true
import sys, json
try:
    c = json.load(open(sys.argv[1]))
    keys = ["workspace_path", "workspace", "workspacePath"]
    for k in keys:
        v = c.get(k) or (c.get("settings") or {}).get(k)
        if v:
            print(v); break
except: pass
PY
)
    fi
    [ -n "${_candidate:-}" ] && [ -d "$_candidate" ] && { WORKSPACE_PATH="$_candidate"; break; }
  done
fi

# 3. Try default location
[ -z "$WORKSPACE_PATH" ] && [ -d "$HOME/.openclaw/workspace" ] \
  && WORKSPACE_PATH="$HOME/.openclaw/workspace"

# 4. Try /root default (common when openclaw runs as root)
[ -z "$WORKSPACE_PATH" ] && [ -d "/root/.openclaw/workspace" ] \
  && WORKSPACE_PATH="/root/.openclaw/workspace"

if [ -n "$WORKSPACE_PATH" ]; then
  ok "Workspace detected: $WORKSPACE_PATH"
  if ! ask_yn "Use this workspace path?"; then
    WORKSPACE_PATH=""
  fi
fi

if [ -z "$WORKSPACE_PATH" ]; then
  ask WORKSPACE_PATH "OpenClaw workspace path" "$HOME/.openclaw/workspace"
  [ -d "$WORKSPACE_PATH" ] || die "Directory does not exist: $WORKSPACE_PATH\nCreate it or start OpenClaw once to initialise the workspace."
fi

# Detect openclaw agent name from config if possible
DETECTED_AGENT="main"
for _cfg in "$HOME/.openclaw/openclaw.json" "$HOME/.openclaw/config.json"; do
  [ -f "$_cfg" ] || continue
  if command -v jq &>/dev/null; then
    _a=$(jq -r '.agent // .default_agent // .defaultAgent // ""' "$_cfg" 2>/dev/null || true)
    [ -n "$_a" ] && { DETECTED_AGENT="$_a"; break; }
  fi
done

# Detect openclaw run_as user
DETECTED_RUN_AS=""
if [ "$EUID" -eq 0 ] && id openclaw &>/dev/null 2>&1; then
  DETECTED_RUN_AS="openclaw"
elif [ "$EUID" -eq 0 ] && [ -n "${SUDO_USER:-}" ]; then
  DETECTED_RUN_AS="$SUDO_USER"
fi

# ── Step 3: Install location ───────────────────────────────────────────────────
step 3 "Install location"

# If running from inside an existing OpenMind directory, use it
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || pwd)"
if [ -f "$SCRIPT_DIR/index.php" ] && [ -f "$SCRIPT_DIR/includes/auth.php" ]; then
  INSTALL_DIR="$SCRIPT_DIR"
  ok "Using current directory: $INSTALL_DIR"
else
  # macOS: /opt requires SIP considerations; default to home regardless of root
  if [ "$OS_TYPE" = "Darwin" ]; then
    DEFAULT_DIR="$HOME/openmind"
  elif [ "$EUID" -ne 0 ]; then
    DEFAULT_DIR="$HOME/openmind"
  else
    DEFAULT_DIR="/opt/openmind"
  fi
  ask INSTALL_DIR "Install OpenMind to" "$DEFAULT_DIR"

  if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/index.php" ]; then
    ok "Existing installation found"
    if ask_yn "Pull latest changes from git?"; then
      git -C "$INSTALL_DIR" pull --ff-only && ok "Updated" \
        || warn "Git pull failed — continuing with existing code"
    fi
  else
    info "Cloning OpenMind from $OPENMIND_REPO ..."
    mkdir -p "$(dirname "$INSTALL_DIR")"
    git clone --depth 1 --branch "$OPENMIND_BRANCH" "$OPENMIND_REPO" "$INSTALL_DIR" \
      && ok "Cloned to $INSTALL_DIR" \
      || die "Clone failed. Check your internet connection.\nAlternatively: git clone $OPENMIND_REPO $INSTALL_DIR"
  fi
fi

mkdir -p "$INSTALL_DIR/backups"

# ── Step 4: Configuration ──────────────────────────────────────────────────────
step 4 "Configuration"

ask APP_TITLE        "App title"                                   "OpenMind"
ask OPENCLAW_AGENT   "OpenClaw agent name"                         "$DETECTED_AGENT"
ask OPENCLAW_RUN_AS  "Run openclaw as user (blank = current user)" "$DETECTED_RUN_AS"
ask BACKUP_PATH      "Backup directory"                            "$INSTALL_DIR/backups"
ask SESSION_LIFETIME "Session lifetime in seconds"                 "86400"

printf "\n  %s\n" "Network restriction:"
printf "    %s\n" "1) None — allow all connections"
printf "    %s\n" "2) Tailscale only (recommended for remote servers)"
printf "    %s\n" "3) Custom IP ranges (CIDR)"
ask NET_CHOICE "Choice" "1"

NETWORK_RESTRICTION="none"
ALLOWED_IPS=""
case "$NET_CHOICE" in
  2) NETWORK_RESTRICTION="tailscale" ;;
  3)
    NETWORK_RESTRICTION="custom"
    ask ALLOWED_IPS "Allowed CIDR ranges (comma-separated)" "192.168.1.0/24"
    ;;
esac

# ── Step 5: Write config.php ───────────────────────────────────────────────────
step 5 "Writing config.php"

OM_WORKSPACE="$WORKSPACE_PATH"          \
OM_BACKUP="$BACKUP_PATH"               \
OM_COMMAND="$OPENCLAW_CMD"             \
OM_AGENT="$OPENCLAW_AGENT"             \
OM_RUNAS="$OPENCLAW_RUN_AS"            \
OM_NETWORK="$NETWORK_RESTRICTION"      \
OM_IPS="$ALLOWED_IPS"                  \
OM_TITLE="$APP_TITLE"                  \
OM_SESSION="${SESSION_LIFETIME:-86400}" \
OM_FILE="$INSTALL_DIR/config.php"      \
"$PHP_BIN" -r '
$c = [
    "workspace_path"      => getenv("OM_WORKSPACE"),
    "backup_path"         => getenv("OM_BACKUP"),
    "openclaw_command"    => getenv("OM_COMMAND"),
    "openclaw_agent"      => getenv("OM_AGENT"),
    "openclaw_run_as"     => getenv("OM_RUNAS"),
    "network_restriction" => getenv("OM_NETWORK"),
    "allowed_ips"         => getenv("OM_IPS"),
    "session_lifetime"    => (int) getenv("OM_SESSION"),
    "app_title"           => getenv("OM_TITLE"),
];
file_put_contents(getenv("OM_FILE"), "<?php\nreturn " . var_export($c, true) . ";\n");
'
ok "config.php written"

# ── Step 6: Create admin user ──────────────────────────────────────────────────
step 6 "Create admin user"

ask ADMIN_USER "Admin username" "admin"

while true; do
  ask_secret ADMIN_PASS "Password (8+ chars, upper, lower, number, special)"
  _ok=1
  [ "${#ADMIN_PASS}" -lt 8 ]           && { warn "At least 8 characters required"; _ok=0; }
  [[ "$ADMIN_PASS" =~ [A-Z] ]]         || { warn "Needs at least one uppercase letter"; _ok=0; }
  [[ "$ADMIN_PASS" =~ [a-z] ]]         || { warn "Needs at least one lowercase letter"; _ok=0; }
  [[ "$ADMIN_PASS" =~ [0-9] ]]         || { warn "Needs at least one number"; _ok=0; }
  [[ "$ADMIN_PASS" =~ [^A-Za-z0-9] ]] || { warn "Needs at least one special character"; _ok=0; }
  if [ "$_ok" -eq 1 ]; then
    ask_secret ADMIN_PASS2 "Confirm password"
    [ "$ADMIN_PASS" = "$ADMIN_PASS2" ] && break
    warn "Passwords do not match, try again"
  fi
done

$PHP_BIN "$INSTALL_DIR/setup/manage_users.php" add "$ADMIN_USER" "$ADMIN_PASS" \
  && ok "User '$ADMIN_USER' created" \
  || die "Failed to create user. Check PHP error output above."

# Clear sensitive vars immediately
ADMIN_PASS=""; ADMIN_PASS2="" 2>/dev/null || true

# ── Step 7: Web server ─────────────────────────────────────────────────────────
step 7 "Web server setup"

hr
printf "  ${YELLOW}Important:${NC} OpenMind will run as a completely separate service.\n"
printf "  ${YELLOW}No existing web server configs will be modified automatically.${NC}\n"
hr
printf "\n"

printf "  %s\n" "Choose how to serve OpenMind:"
printf "    %s\n" "1) PHP built-in server — simple, no extra config needed"
if [ "$HAS_NGINX" -eq 1 ]; then
  printf "    %s\n" "2) Nginx — generates a new config file for you to review and place"
fi
printf "    %s\n" "3) Skip — I'll configure my own web server"
printf "\n"

_default_choice="1"
ask SERVER_CHOICE "Choice" "$_default_choice"

OPENMIND_PORT=""
OPENMIND_URL=""

case "$SERVER_CHOICE" in

  # ── PHP built-in server ───────────────────────────────────────────────────
  1)
    _suggested=$(find_free_port 8080)
    printf "\n"
    info "Scanning for a free port..."
    if port_in_use 8080; then
      warn "Port 8080 is in use — suggesting port $_suggested instead"
    fi
    ask OPENMIND_PORT "Port for OpenMind" "$_suggested"

    # Re-validate the chosen port
    if port_in_use "$OPENMIND_PORT"; then
      warn "Port $OPENMIND_PORT appears to be in use."
      if ! ask_yn "Continue anyway?" "n"; then
        _suggested=$(find_free_port "$((OPENMIND_PORT + 1))")
        ask OPENMIND_PORT "Use this free port instead" "$_suggested"
      fi
    fi
    [ "$OPENMIND_PORT" -eq "$OPENCLAW_GW_PORT" ] \
      && die "Port $OPENCLAW_GW_PORT is reserved for the OpenClaw gateway. Choose a different port."

    OPENMIND_URL="http://localhost:$OPENMIND_PORT"

    if [ "$HAS_LAUNCHD" -eq 1 ] && ask_yn "Create a launchd service to start OpenMind on login?"; then
      # macOS — LaunchAgent (runs when current user logs in)
      _plist_dir="$HOME/Library/LaunchAgents"
      _plist_file="$_plist_dir/com.openmind.server.plist"
      mkdir -p "$_plist_dir"
      cat > "$_plist_file" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.openmind.server</string>
    <key>ProgramArguments</key>
    <array>
        <string>$PHP_BIN</string>
        <string>-S</string>
        <string>0.0.0.0:$OPENMIND_PORT</string>
        <string>-t</string>
        <string>$INSTALL_DIR</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$INSTALL_DIR</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/tmp/openmind.log</string>
    <key>StandardErrorPath</key>
    <string>/tmp/openmind.error.log</string>
</dict>
</plist>
EOF
      launchctl load "$_plist_file" \
        && ok "launchd service loaded — OpenMind will start on login" \
        || warn "launchctl load failed — try manually: launchctl load $_plist_file"
      info "To start now:  launchctl start com.openmind.server"
      info "To stop:       launchctl stop com.openmind.server"
      info "To remove:     launchctl unload $_plist_file && rm $_plist_file"

    elif [ "$HAS_SYSTEMD" -eq 1 ] && ask_yn "Create a systemd service (openmind.service) to start on boot?"; then
      # Linux — systemd
      _svc_user="${SUDO_USER:-$USER}"
      cat > "/tmp/openmind.service" <<EOF
[Unit]
Description=OpenMind for OpenClaw
After=network.target

[Service]
Type=simple
WorkingDirectory=$INSTALL_DIR
ExecStart=$PHP_BIN -S 0.0.0.0:$OPENMIND_PORT -t $INSTALL_DIR
Restart=on-failure
RestartSec=5
User=$_svc_user
Environment=PHP_CLI_SERVER_WORKERS=4

[Install]
WantedBy=multi-user.target
EOF
      if [ "$EUID" -eq 0 ]; then
        mv /tmp/openmind.service /etc/systemd/system/openmind.service
        systemctl daemon-reload
        systemctl enable --now openmind.service \
          && ok "systemd service enabled and started" \
          || warn "Service created but failed to start — check: journalctl -u openmind"
      else
        warn "Not running as root — service file saved to /tmp/openmind.service"
        info "To install the service, run:"
        info "  sudo mv /tmp/openmind.service /etc/systemd/system/"
        info "  sudo systemctl daemon-reload && sudo systemctl enable --now openmind"
      fi

    else
      info "To start OpenMind manually:"
      info "  $PHP_BIN -S 0.0.0.0:$OPENMIND_PORT -t $INSTALL_DIR"
    fi
    ;;

  # ── Nginx new virtual host ────────────────────────────────────────────────
  2)
    if [ "$HAS_NGINX" -eq 0 ]; then
      warn "nginx not detected — falling back to PHP built-in server"
      SERVER_CHOICE=1
      _suggested=$(find_free_port 8080)
      ask OPENMIND_PORT "Port for OpenMind" "$_suggested"
      OPENMIND_URL="http://localhost:$OPENMIND_PORT"
      info "Start with: $PHP_BIN -S 0.0.0.0:$OPENMIND_PORT -t $INSTALL_DIR"
    else
      printf "\n"
      printf "  ${CYAN}Note:${NC} A new nginx config file will be created at:\n"
      printf "        ${BOLD}%s/openmind.nginx.conf${NC}\n" "$INSTALL_DIR"
      printf "  Nothing in /etc/nginx will be touched until you place it there yourself.\n\n"

      ask OPENMIND_DOMAIN "Server name (domain or IP, or _ for any)" "_"
      ask LISTEN_PORT     "nginx listen port" "80"

      if port_in_use "$LISTEN_PORT" && [ "$LISTEN_PORT" -eq 80 ]; then
        warn "Port 80 is already in use."
        printf "  This is expected if you already have a website running on this server.\n"
        printf "  ${BOLD}You have two options:${NC}\n"
        printf "    a) Use a different port (e.g. 8080) — accessible at http://yourserver:8080\n"
        printf "    b) Add this as a virtual host alongside your existing site (same port 80)\n"
        printf "       by setting a unique server_name (e.g. openmind.yourdomain.com)\n\n"
        ask LISTEN_PORT "Listen port" "8080"
      fi

      # Detect PHP-FPM socket — Linux and macOS Homebrew paths
      FPM_SOCKET=""
      for _sock in \
          "/run/php/php-fpm.sock" \
          "/run/php/php8.4-fpm.sock" "/run/php/php8.3-fpm.sock" \
          "/run/php/php8.2-fpm.sock" "/run/php/php8.1-fpm.sock" \
          "/run/php/php8.0-fpm.sock" \
          "/var/run/php-fpm/www.sock" "/run/php-fpm/www.sock" \
          "/opt/homebrew/var/run/php/php-fpm.sock" \
          "/opt/homebrew/var/run/php-fpm.sock" \
          "/usr/local/var/run/php/php-fpm.sock" \
          "/tmp/php-fpm.sock"; do
        [ -S "$_sock" ] && { FPM_SOCKET="$_sock"; break; }
      done
      [ -z "$FPM_SOCKET" ] && {
        [ "$OS_TYPE" = "Darwin" ] \
          && FPM_SOCKET="/opt/homebrew/var/run/php/php-fpm.sock" \
          || FPM_SOCKET="/run/php/php-fpm.sock"
      }
      ask FPM_SOCKET "PHP-FPM socket path" "$FPM_SOCKET"

      NGINX_CONF="$INSTALL_DIR/openmind.nginx.conf"
      if [ "$OS_TYPE" = "Darwin" ]; then
        _nginx_place="/opt/homebrew/etc/nginx/servers/openmind.conf"
        _nginx_reload="brew services restart nginx"
        _nginx_test="nginx -t"
      else
        _nginx_place="/etc/nginx/sites-available/openmind"
        _nginx_reload="sudo systemctl reload nginx"
        _nginx_test="sudo nginx -t"
      fi
      cat > "$NGINX_CONF" <<EOF
# OpenMind for OpenClaw — nginx virtual host
# Review this file, then place it as follows:
#
# Linux:  sudo cp $NGINX_CONF /etc/nginx/sites-available/openmind
#         sudo ln -s /etc/nginx/sites-available/openmind /etc/nginx/sites-enabled/
#         sudo nginx -t && sudo systemctl reload nginx
#
# macOS:  cp $NGINX_CONF /opt/homebrew/etc/nginx/servers/openmind.conf
#         nginx -t && brew services restart nginx

server {
    listen $LISTEN_PORT;
    server_name $OPENMIND_DOMAIN;
    root $INSTALL_DIR;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:$FPM_SOCKET;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    # Block direct access to sensitive files
    location ~ /(config\.php|auth\.db|\.git|backups) {
        deny all;
        return 404;
    }
}
EOF
      ok "Nginx config written to $NGINX_CONF"
      printf "\n"
      printf "  ${BOLD}Next steps to activate:${NC}\n"
      info "  1. Review:   cat $NGINX_CONF"
      if [ "$OS_TYPE" = "Darwin" ]; then
        info "  2. Place:    cp $NGINX_CONF /opt/homebrew/etc/nginx/servers/openmind.conf"
        info "  3. Test:     nginx -t"
        info "  4. Reload:   brew services restart nginx"
      else
        info "  2. Place:    sudo cp $NGINX_CONF /etc/nginx/sites-available/openmind"
        info "  3. Enable:   sudo ln -s /etc/nginx/sites-available/openmind /etc/nginx/sites-enabled/"
        info "  4. Test:     sudo nginx -t"
        info "  5. Reload:   sudo systemctl reload nginx"
        _web_user="www-data"
        id "_www" &>/dev/null 2>&1 && _web_user="_www"   # macOS fallback (shouldn't reach here)
        info "  6. Permissions: sudo chown -R ${_web_user}:${_web_user} $INSTALL_DIR"
      fi

      OPENMIND_PORT="$LISTEN_PORT"
      OPENMIND_URL="http://${OPENMIND_DOMAIN}:${LISTEN_PORT}"
      [ "$LISTEN_PORT" = "80" ] && OPENMIND_URL="http://${OPENMIND_DOMAIN}"
    fi
    ;;

  # ── Manual / skip ─────────────────────────────────────────────────────────
  3)
    OPENMIND_PORT="8080"
    OPENMIND_URL="http://your-server:8080"
    printf "\n"
    info "Skipping web server setup."
    info "Document root: $INSTALL_DIR"
    info "Protect from direct HTTP access: config.php, auth.db, .git, backups/"
    ;;

esac

# ── Tailscale ──────────────────────────────────────────────────────────────────
if [ "$HAS_TAILSCALE" -eq 1 ] && [ -n "${OPENMIND_PORT:-}" ]; then
  printf "\n"
  if ask_yn "Expose on Tailscale (private tailnet access via HTTPS)?"; then
    if tailscale serve --bg "http://localhost:$OPENMIND_PORT" &>/dev/null; then
      ok "Tailscale Serve configured on port $OPENMIND_PORT"
      _ts_url=$(tailscale status --json 2>/dev/null \
        | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    name = d.get('Self', {}).get('DNSName', '').rstrip('.')
    print('https://' + name + '/' if name else '')
except: print('')
" 2>/dev/null || true)
      [ -n "${_ts_url:-}" ] && OPENMIND_URL="$_ts_url" && ok "Tailscale URL: $OPENMIND_URL"
    else
      warn "tailscale serve failed — ensure Tailscale is logged in and try manually:"
      info "  tailscale serve --bg http://localhost:$OPENMIND_PORT"
    fi
  fi
fi

# ── File permissions ───────────────────────────────────────────────────────────
chmod 750 "$INSTALL_DIR" 2>/dev/null || true
chmod 640 "$INSTALL_DIR/config.php" 2>/dev/null || true
mkdir -p "$INSTALL_DIR/backups" && chmod 750 "$INSTALL_DIR/backups" 2>/dev/null || true

# ── Summary ────────────────────────────────────────────────────────────────────
printf "\n${GREEN}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
printf "${GREEN}${BOLD}  OpenMind installed successfully!${NC}\n"
printf "${GREEN}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n\n"
printf "  %-22s %s\n" "URL:"          "${OPENMIND_URL:-http://localhost:${OPENMIND_PORT:-8080}}"
printf "  %-22s %s\n" "Install dir:"  "$INSTALL_DIR"
printf "  %-22s %s\n" "Workspace:"    "$WORKSPACE_PATH"
printf "  %-22s %s\n" "Admin user:"   "$ADMIN_USER"
printf "  %-22s %s\n" "Agent:"        "$OPENCLAW_AGENT"
printf "  %-22s %s\n" "Network:"      "$NETWORK_RESTRICTION"
printf "\n"
printf "  Open the URL above in your browser and log in.\n"
printf "  Add more users:  %s\n" "$PHP_BIN $INSTALL_DIR/setup/manage_users.php add <username>"
printf "\n"
