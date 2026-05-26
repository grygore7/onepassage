<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: auth.php');
    exit;
}

function adminTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return $cache[$table] = (int)$stmt->fetchColumn() > 0;
}

function adminColumnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!adminTableExists($pdo, $table)) {
        return $cache[$key] = false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return $cache[$key] = (int)$stmt->fetchColumn() > 0;
}

function adminUserLabel(?array $user): string
{
    if (!$user) {
        return 'Utente non disponibile';
    }

    $name = trim(($user['nome'] ?? '') . ' ' . ($user['cognome'] ?? ''));
    return $name !== '' ? $name : ($user['email'] ?? 'Utente #' . ($user['id'] ?? ''));
}

function adminStatusBadge(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['risolta', 'resolved', 'chiusa'], true)) {
        return 'badge-success';
    }
    if (in_array($status, ['bannato', 'banned', 'sospeso'], true)) {
        return 'badge-danger';
    }
    return 'badge-warning';
}

function adminBanColumn(PDO $pdo): ?array
{
    $columns = [
        'ban_status' => 'bannato',
        'is_banned' => 1,
        'banned' => 1,
        'stato' => 'bannato',
    ];

    foreach ($columns as $column => $value) {
        if (adminColumnExists($pdo, 'users', $column)) {
            return [$column, $value];
        }
    }

    return null;
}

function adminOffersTable(PDO $pdo): ?string
{
    if (adminTableExists($pdo, 'ride_offers')) {
        return 'ride_offers';
    }
    if (adminTableExists($pdo, 'offers')) {
        return 'offers';
    }
    return null;
}

function adminReportsTable(PDO $pdo): ?string
