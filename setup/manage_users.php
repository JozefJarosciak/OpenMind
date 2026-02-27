#!/usr/bin/env php
<?php
/**
 * OpenMind — User Management CLI
 *
 * Usage:
 *   php manage_users.php add <username> [password]
 *   php manage_users.php list
 *   php manage_users.php remove <username>
 *   php manage_users.php passwd <username> [password]
 */

require_once __DIR__ . '/../includes/defaults.php';

$dbFile = __DIR__ . '/../auth.db';

function initDb($dbFile) {
    $isNew = !file_exists($dbFile);
    $db = new SQLite3($dbFile);
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    if ($isNew) {
        chmod($dbFile, 0600);
    }
    return $db;
}

function readPassword($prompt = 'Password: ') {
    if (function_exists('readline')) {
        // Try to hide input on Unix
        if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
            echo $prompt;
            system('stty -echo');
            $pw = rtrim(fgets(STDIN), "\n");
            system('stty echo');
            echo "\n";
            return $pw;
        }
    }
    echo $prompt;
    return rtrim(fgets(STDIN), "\n");
}

if ($argc < 2) {
    echo "OpenMind User Manager\n";
    echo "=====================\n\n";
    echo "Usage:\n";
    echo "  php {$argv[0]} add <username> [password]    Add a new user\n";
    echo "  php {$argv[0]} list                         List all users\n";
    echo "  php {$argv[0]} remove <username>             Remove a user\n";
    echo "  php {$argv[0]} passwd <username> [password]  Change password\n\n";
    exit(1);
}

$action = $argv[1];

switch ($action) {
    case 'add':
        if ($argc < 3) { echo "Usage: php {$argv[0]} add <username> [password]\n"; exit(1); }
        $username = $argv[2];
        $password = $argv[3] ?? readPassword();
        $pwErrors = validate_password($password);
        if (!empty($pwErrors)) { echo "Error: Password needs: " . implode(', ', $pwErrors) . ".\n"; exit(1); }
        $db = initDb($dbFile);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('INSERT OR IGNORE INTO users (username, password) VALUES (:u, :p)');
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
        $stmt->execute();
        if ($db->changes() > 0) {
            echo "User '$username' created successfully.\n";
        } else {
            echo "Error: User '$username' already exists.\n";
            exit(1);
        }
        $db->close();
        break;

    case 'list':
        $db = initDb($dbFile);
        $result = $db->query('SELECT username, created_at FROM users ORDER BY id');
        echo str_pad('Username', 20) . "Created At\n";
        echo str_repeat('-', 45) . "\n";
        $count = 0;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            echo str_pad($row['username'], 20) . ($row['created_at'] ?? '-') . "\n";
            $count++;
        }
        echo "\nTotal: $count user(s)\n";
        $db->close();
        break;

    case 'remove':
        if ($argc < 3) { echo "Usage: php {$argv[0]} remove <username>\n"; exit(1); }
        $username = $argv[2];
        $db = initDb($dbFile);
        $stmt = $db->prepare('DELETE FROM users WHERE username = :u');
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $stmt->execute();
        if ($db->changes() > 0) {
            echo "User '$username' removed.\n";
        } else {
            echo "Error: User '$username' not found.\n";
            exit(1);
        }
        $db->close();
        break;

    case 'passwd':
        if ($argc < 3) { echo "Usage: php {$argv[0]} passwd <username> [password]\n"; exit(1); }
        $username = $argv[2];
        $password = $argv[3] ?? readPassword('New password: ');
        $pwErrors = validate_password($password);
        if (!empty($pwErrors)) { echo "Error: Password needs: " . implode(', ', $pwErrors) . ".\n"; exit(1); }
        $db = initDb($dbFile);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET password = :p WHERE username = :u');
        $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $stmt->execute();
        if ($db->changes() > 0) {
            echo "Password updated for '$username'.\n";
        } else {
            echo "Error: User '$username' not found.\n";
            exit(1);
        }
        $db->close();
        break;

    default:
        echo "Unknown action: $action\n";
        echo "Valid actions: add, list, remove, passwd\n";
        exit(1);
}
