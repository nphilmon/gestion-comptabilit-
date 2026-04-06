<?php
/**
 * Configuration de la base de données et constantes
 */

function envValue(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

// --- Configuration BDD ---
define('DB_HOST', envValue('GESTION_COMPTA_DB_HOST', 'localhost'));
define('DB_NAME', envValue('GESTION_COMPTA_DB_NAME', 'gestion_compta'));
define('DB_USER', envValue('GESTION_COMPTA_DB_USER', 'root'));
define('DB_PASS', envValue('GESTION_COMPTA_DB_PASS', ''));
define('DB_CHARSET', envValue('GESTION_COMPTA_DB_CHARSET', 'utf8mb4'));

// --- Configuration application ---
define('APP_NAME', 'Gestion Comptabilité');
define('APP_VERSION', '2.1.0');

$baseUrl = trim(envValue('GESTION_COMPTA_BASE_URL', '/gestion%20comptabilit%C3%A9/'));
if ($baseUrl === '') {
    $baseUrl = '/';
} elseif ($baseUrl[0] !== '/') {
    $baseUrl = '/' . $baseUrl;
}
define('BASE_URL', rtrim($baseUrl, '/') . '/');

// --- Timezone ---
date_default_timezone_set('Europe/Paris');
setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'fr');

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([DB_NAME, $table]);
    return (bool) $stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([DB_NAME, $table, $column]);
    return (bool) $stmt->fetchColumn();
}

function indexExists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([DB_NAME, $table, $index]);
    return (bool) $stmt->fetchColumn();
}

function initializeDatabaseSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `rate_limits` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `scope` VARCHAR(50) NOT NULL,
            `key_hash` CHAR(64) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_scope_key_date` (`scope`, `key_hash`, `created_at`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `exercices` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `nom` VARCHAR(120) NOT NULL,
            `date_debut` DATE NOT NULL,
            `date_fin` DATE NOT NULL,
            `statut` ENUM('ouvert', 'clos') NOT NULL DEFAULT 'ouvert',
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_exercices_periode` (`date_debut`, `date_fin`),
            INDEX `idx_exercices_statut` (`statut`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (tableExists($pdo, 'clients') && !columnExists($pdo, 'clients', 'siren')) {
            $pdo->exec("ALTER TABLE `clients` ADD COLUMN `siren` VARCHAR(20) DEFAULT NULL AFTER `siret`");
        }

        if (tableExists($pdo, 'produits')) {
            if (!columnExists($pdo, 'produits', 'seuil_alerte')) {
                $pdo->exec("ALTER TABLE `produits` ADD COLUMN `seuil_alerte` DECIMAL(10,2) DEFAULT 0 AFTER `stock_minimum`");
            }
            $pdo->exec("UPDATE `produits` SET `seuil_alerte` = `stock_minimum` WHERE (`seuil_alerte` IS NULL OR `seuil_alerte` = 0) AND `stock_minimum` > 0");
        }

        if (tableExists($pdo, 'transactions') && !indexExists($pdo, 'transactions', 'idx_transactions_date')) {
            $pdo->exec("ALTER TABLE `transactions` ADD INDEX `idx_transactions_date` (`date_transaction`)");
        }
        if (tableExists($pdo, 'transactions') && !indexExists($pdo, 'transactions', 'idx_transactions_type')) {
            $pdo->exec("ALTER TABLE `transactions` ADD INDEX `idx_transactions_type` (`type`)");
        }
        if (tableExists($pdo, 'categories') && !indexExists($pdo, 'categories', 'idx_categories_type')) {
            $pdo->exec("ALTER TABLE `categories` ADD INDEX `idx_categories_type` (`type`)");
        }
        if (tableExists($pdo, 'clients') && !indexExists($pdo, 'clients', 'idx_clients_actif')) {
            $pdo->exec("ALTER TABLE `clients` ADD INDEX `idx_clients_actif` (`actif`)");
        }
        if (tableExists($pdo, 'notes_caisse') && !indexExists($pdo, 'notes_caisse', 'idx_notes_caisse_cloture')) {
            $pdo->exec("ALTER TABLE `notes_caisse` ADD INDEX `idx_notes_caisse_cloture` (`cloture_id`)");
        }

        if (tableExists($pdo, 'parametres')) {
            $stmt = $pdo->prepare('INSERT INTO parametres (cle, valeur, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valeur = valeur');
            $stmt->execute(['inscription_ouverte', '0', 'Autoriser l\'inscription publique (0/1)']);
        }
    } catch (Throwable $e) {
        // Ne jamais bloquer l'application pour une migration corrective.
    }
}

// --- Connexion PDO ---
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        initializeDatabaseSchema($pdo);
    }
    return $pdo;
}
