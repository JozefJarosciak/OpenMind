<?php
/**
 * OpenMind — Authentication & Network Restriction
 *
 * Expects $config array and $authDb path to be set before including this file.
 * Handles: network restriction, session management, login, logout, login page.
 */

// ── Network Restriction ─────────────────────────────────────────────────────
$restriction = $config['network_restriction'];

if ($restriction !== 'none') {
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowed = false;

    if ($restriction === 'tailscale') {
        $allowed = isTailscaleIP($clientIP);
    } elseif ($restriction === 'custom') {
        $cidrs = array_filter(array_map('trim', explode(',', $config['allowed_ips'])));
        foreach ($cidrs as $cidr) {
            if (ipInCidr($clientIP, $cidr)) {
                $allowed = true;
                break;
            }
        }
    }

    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        $appTitle = htmlspecialchars(getDisplayTitle($config));
        $safeIP = htmlspecialchars($clientIP);
        echo <<<BLOCKED
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>403 Forbidden</title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#1e1e2e;color:#cdd6f4;font-family:"Segoe UI",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}
.card{background:#181825;border:1px solid #45475a;border-radius:16px;padding:2.5rem;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,.4)}
h1{color:#f38ba8;font-size:1.3rem;margin-bottom:.5rem}p{color:#7f849c;font-size:.9rem;line-height:1.5;margin-bottom:.3rem}.ip{font-family:monospace;color:#89b4fa;font-size:.8rem;margin-top:1rem;opacity:.5}</style>
</head><body><div class="card"><h1>&#x1F6AB; Access Denied</h1>
<p><strong>{$appTitle}</strong> is restricted to authorized networks.</p>
<p>Connect to the required network and try again.</p>
<div class="ip">Your IP: {$safeIP}</div>
</div></body></html>
BLOCKED;
        exit;
    }
}

// ── Session Management ──────────────────────────────────────────────────────
$sessionOpts = [
    'cookie_httponly' => true,
    'cookie_secure'  => isset($_SERVER['HTTPS']),
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
];

$sessionLifetime = $config['session_lifetime'];

$rememberLifetime = $config['remember_me_lifetime'];
$isRememberLogin = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login' && !empty($_POST['remember']));

// Set gc_maxlifetime BEFORE session_start (cannot be changed after)
if ($isRememberLogin || (isset($_COOKIE[session_name()]) && !empty($_COOKIE['openmind_remember']))) {
    ini_set('session.gc_maxlifetime', $rememberLifetime);
    $sessionOpts['cookie_lifetime'] = $rememberLifetime;
}
session_start($sessionOpts);

// Keep remembered sessions alive — refresh the cookie expiry on each request
if (!empty($_SESSION['remember'])) {
    $cookieParams = [
        'expires'  => time() + $rememberLifetime,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly'  => true,
        'samesite' => 'Strict',
    ];
    setcookie(session_name(), session_id(), $cookieParams);
}

// ── Persistent "Remember Me" Token ──────────────────────────────────────────
// Auto-login via remember token cookie if no active session
if (!isset($_SESSION['user']) && !empty($_COOKIE['openmind_token']) && file_exists($authDb)) {
    $db = new SQLite3($authDb);
    $db->exec('CREATE TABLE IF NOT EXISTS remember_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    )');
    // Prune expired tokens
    $db->exec('DELETE FROM remember_tokens WHERE expires_at < ' . time());

    $rawToken = $_COOKIE['openmind_token'];
    $tokenHash = hash('sha256', $rawToken);
    $stmt = $db->prepare('SELECT username FROM remember_tokens WHERE token_hash = :h AND expires_at > :now');
    $stmt->bindValue(':h', $tokenHash, SQLITE3_TEXT);
    $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        // Valid token — auto-login
        session_regenerate_id(true);
        $_SESSION['user'] = $row['username'];
        $_SESSION['remember'] = true;
        // Refresh the token (rotate for security)
        $db->exec("DELETE FROM remember_tokens WHERE token_hash = '" . $db->escapeString($tokenHash) . "'");
        $newToken = bin2hex(random_bytes(32));
        $newHash = hash('sha256', $newToken);
        $expires = time() + $rememberLifetime;
        $ins = $db->prepare('INSERT INTO remember_tokens (username, token_hash, expires_at) VALUES (:u, :h, :e)');
        $ins->bindValue(':u', $row['username'], SQLITE3_TEXT);
        $ins->bindValue(':h', $newHash, SQLITE3_TEXT);
        $ins->bindValue(':e', $expires, SQLITE3_INTEGER);
        $ins->execute();
        setcookie('openmind_token', $newToken, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    $db->close();
}

// ── Logout ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    // Delete remember token from DB
    if (!empty($_COOKIE['openmind_token']) && file_exists($authDb)) {
        $db = new SQLite3($authDb);
        $tokenHash = hash('sha256', $_COOKIE['openmind_token']);
        $db->exec("DELETE FROM remember_tokens WHERE token_hash = '" . $db->escapeString($tokenHash) . "'");
        $db->close();
    }
    // Clear cookies
    setcookie('openmind_token', '', ['expires' => 1, 'path' => '/', 'samesite' => 'Strict']);
    setcookie('openmind_user', '', ['expires' => 1, 'path' => '/', 'samesite' => 'Strict']);
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ── Login ───────────────────────────────────────────────────────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (file_exists($authDb)) {
        $db = new SQLite3($authDb);
        $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            attempted_at INTEGER NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS remember_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        )');

        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
        $window = time() - $config['rate_limit_window'];
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM login_attempts WHERE ip = :ip AND attempted_at > :w');
        $stmt->bindValue(':ip', $clientIP, SQLITE3_TEXT);
        $stmt->bindValue(':w', $window, SQLITE3_INTEGER);
        $attempts = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (($attempts['cnt'] ?? 0) >= $config['rate_limit_max_attempts']) {
            $db->close();
            $loginError = 'Too many failed attempts. Please wait 15 minutes and try again.';
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $stmt = $db->prepare('SELECT password FROM users WHERE username = :u');
            $stmt->bindValue(':u', $username, SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($row && password_verify($password, $row['password'])) {
                $clr = $db->prepare('DELETE FROM login_attempts WHERE ip = :ip');
                $clr->bindValue(':ip', $clientIP, SQLITE3_TEXT);
                $clr->execute();

                session_regenerate_id(true);
                $_SESSION['user'] = $username;

                if (!empty($_POST['remember'])) {
                    $_SESSION['remember'] = true;
                    // Generate persistent remember token
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expires = time() + $rememberLifetime;
                    // Remove old tokens for this user (limit to 5 devices)
                    $db->exec("DELETE FROM remember_tokens WHERE username = '" . $db->escapeString($username) . "' AND id NOT IN (SELECT id FROM remember_tokens WHERE username = '" . $db->escapeString($username) . "' ORDER BY expires_at DESC LIMIT 4)");
                    $ins = $db->prepare('INSERT INTO remember_tokens (username, token_hash, expires_at) VALUES (:u, :h, :e)');
                    $ins->bindValue(':u', $username, SQLITE3_TEXT);
                    $ins->bindValue(':h', $tokenHash, SQLITE3_TEXT);
                    $ins->bindValue(':e', $expires, SQLITE3_INTEGER);
                    $ins->execute();
                    setcookie('openmind_token', $rawToken, [
                        'expires'  => $expires,
                        'path'     => '/',
                        'secure'   => isset($_SERVER['HTTPS']),
                        'httponly' => true,
                        'samesite' => 'Strict',
                    ]);
                    // Also save username for the login form
                    setcookie('openmind_user', $username, [
                        'expires'  => $expires,
                        'path'     => '/',
                        'secure'   => isset($_SERVER['HTTPS']),
                        'httponly' => false,
                        'samesite' => 'Strict',
                    ]);
                }

                $db->close();
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
            $ins = $db->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (:ip, :t)');
            $ins->bindValue(':ip', $clientIP, SQLITE3_TEXT);
            $ins->bindValue(':t', time(), SQLITE3_INTEGER);
            $ins->execute();
            // Prune records older than 24h to keep the table tidy
            $db->exec('DELETE FROM login_attempts WHERE attempted_at < ' . (time() - 86400));
            $db->close();
            $loginError = 'Invalid username or password';
        }
    } else {
        $loginError = 'Invalid username or password';
    }
}

// ── Show Login Page if Not Authenticated ────────────────────────────────────
if (!isset($_SESSION['user'])) {
    // For AJAX / API requests, return JSON 401 instead of the HTML login page
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isApi = stripos($contentType, 'application/json') !== false
          || stripos($acceptHeader, 'application/json') !== false
          || isset($_GET['getSettings']) || isset($_GET['checkUpdate'])
          || isset($_GET['file']) || isset($_GET['refreshFile']);
    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Session expired. Please refresh the page and log in again.']);
        exit;
    }

    $err = $loginError ? '<div class="error">' . htmlspecialchars($loginError) . '</div>' : '';
    $appTitle = htmlspecialchars(getDisplayTitle($config));
    $savedUser = htmlspecialchars($_COOKIE['openmind_user'] ?? '');
    $rememberChecked = $savedUser ? ' checked' : '';
    $focusUser = $savedUser ? '' : ' autofocus';
    $focusPass = $savedUser ? ' autofocus' : '';
    echo <<<LOGIN_PAGE
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login &mdash; {$appTitle}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#1e1e2e;color:#cdd6f4;font-family:'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-card{background:#181825;border:1px solid #45475a;border-radius:16px;padding:2.5rem;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,.4)}
.login-card h1{text-align:center;color:#89b4fa;font-size:1.4rem;margin-bottom:.3rem}
.login-card .subtitle{text-align:center;color:#7f849c;font-size:.85rem;margin-bottom:2rem}
.login-card label{display:block;font-size:.82rem;color:#a6adc8;margin-bottom:.3rem;font-weight:500}
.login-card input[type=text],.login-card input[type=password]{width:100%;padding:.65rem .8rem;border:1px solid #45475a;border-radius:8px;background:#313244;color:#cdd6f4;font-size:.9rem;outline:none;transition:border-color .2s}
.login-card input:focus{border-color:#89b4fa}
.field{margin-bottom:1.2rem}
.login-card button{width:100%;padding:.7rem;border:none;border-radius:8px;background:linear-gradient(135deg,#89b4fa,#74c7ec);color:#1e1e2e;font-size:.9rem;font-weight:600;cursor:pointer;transition:all .2s}
.login-card button:hover{box-shadow:0 4px 16px rgba(137,180,250,.3);transform:translateY(-1px)}
.error{background:#f38ba822;color:#f38ba8;border:1px solid #f38ba844;border-radius:8px;padding:.6rem .8rem;font-size:.82rem;margin-bottom:1.2rem;text-align:center}
</style>
</head>
<body>
<div class="login-card">
  <h1>{$appTitle}</h1>
  <p class="subtitle">Sign in to access the workspace</p>
  {$err}
  <form method="POST" action="" autocomplete="on">
    <input type="hidden" name="action" value="login">
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="{$savedUser}" required{$focusUser} autocomplete="username">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required{$focusPass} autocomplete="current-password">
    </div>
    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1.2rem">
      <input type="checkbox" id="remember" name="remember" value="1"{$rememberChecked} style="width:auto;accent-color:#89b4fa;cursor:pointer">
      <label for="remember" style="margin:0;font-size:.82rem;cursor:pointer;color:#a6adc8">Remember me</label>
    </div>
    <button type="submit">Sign In</button>
  </form>
</div>
</body>
</html>
LOGIN_PAGE;
    exit;
}

// ── Helper Functions ────────────────────────────────────────────────────────
function isTailscaleIP($ip) {
    // IPv4: 100.64.0.0/10 (Tailscale CGNAT range)
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        $net  = ip2long('100.64.0.0');
        $mask = ip2long('255.192.0.0');
        return ($long & $mask) === ($net & $mask);
    }
    // IPv6: fd7a:115c:a1e0::/48 (Tailscale ULA range)
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $normalized = strtolower(inet_ntop(inet_pton($ip)));
        return strpos($normalized, 'fd7a:115c:a1e0:') === 0;
    }
    return false;
}

function ipInCidr($ip, $cidr) {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($subnet, $bits) = explode('/', $cidr, 2);
    $bits = (int) $bits;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
        filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $mask = -1 << (32 - $bits);
        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
    return false;
}
