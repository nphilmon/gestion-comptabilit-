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
define('APP_NAME', 'Gestion Comptable Pro');
define('APP_VERSION', '2.1.1');

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

function applySqlMigrationIfMissing(PDO $pdo, string $relativePath, array $requiredTables): void {
    $missingTable = false;
    foreach ($requiredTables as $table) {
        if (!tableExists($pdo, $table)) {
            $missingTable = true;
            break;
        }
    }

    if (!$missingTable) {
        return;
    }

    $path = __DIR__ . '/' . ltrim($relativePath, '/\\');
    if (!is_file($path)) {
        return;
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        return;
    }

    $sql = preg_replace('/^\s*USE\s+`?[^`;]+`?\s*;\s*/mi', '', $sql);
    $sql = preg_replace('/^\s*ALTER\s+TABLE\s+`[^`]+`\s+ADD\s+INDEX\s+`[^`]+`\s+\([^)]+\)\s*;\s*/mi', '', $sql);
    $pdo->exec($sql);
}

function ensureIndex(PDO $pdo, string $table, string $index, string $definition): void {
    if (tableExists($pdo, $table) && !indexExists($pdo, $table, $index)) {
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` ($definition)");
    }
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

        applySqlMigrationIfMissing($pdo, 'database/migration_commercial.sql', [
            'clients', 'devis', 'lignes_devis', 'factures', 'lignes_factures',
            'commandes', 'lignes_commandes', 'paiements'
        ]);
        applySqlMigrationIfMissing($pdo, 'database/migration_caisse.sql', [
            'categories_produits', 'produits', 'moyens_paiement_caisse',
            'notes_caisse', 'lignes_notes_caisse', 'paiements_caisse',
            'mouvements_stock', 'inventaires', 'lignes_inventaire', 'clotures_caisse'
        ]);

        ensureIndex($pdo, 'devis', 'idx_devis_client', '`client_id`');
        ensureIndex($pdo, 'devis', 'idx_devis_statut', '`statut`');
        ensureIndex($pdo, 'factures', 'idx_factures_client', '`client_id`');
        ensureIndex($pdo, 'factures', 'idx_factures_statut', '`statut`');
        ensureIndex($pdo, 'commandes', 'idx_commandes_client', '`client_id`');
        ensureIndex($pdo, 'paiements', 'idx_paiements_facture', '`facture_id`');

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cp_profils` (
            `user_id` INT UNSIGNED PRIMARY KEY,
            `date_entree` DATE DEFAULT NULL,
            `actif` TINYINT(1) NOT NULL DEFAULT 1,
            `solde_initial` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `jours_supplementaires_annuels` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT `fk_cp_profils_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cp_demandes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `date_debut` DATE NOT NULL,
            `date_fin` DATE NOT NULL,
            `mode_decompte` ENUM('ouvrables', 'ouvres') NOT NULL DEFAULT 'ouvrables',
            `statut` ENUM('en_attente', 'valide', 'refuse') NOT NULL DEFAULT 'en_attente',
            `jours_demandes` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `motif` VARCHAR(255) DEFAULT NULL,
            `commentaire` TEXT DEFAULT NULL,
            `traite_par` INT UNSIGNED DEFAULT NULL,
            `traite_le` DATETIME DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_cp_demandes_user_statut` (`user_id`, `statut`),
            INDEX `idx_cp_demandes_dates` (`date_debut`, `date_fin`),
            CONSTRAINT `fk_cp_demandes_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cp_demandes_traite_par` FOREIGN KEY (`traite_par`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cp_ajustements` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `date_mouvement` DATE NOT NULL,
            `jours` DECIMAL(6,2) NOT NULL,
            `motif` VARCHAR(255) NOT NULL,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_cp_ajustements_user_date` (`user_id`, `date_mouvement`),
            CONSTRAINT `fk_cp_ajustements_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cp_ajustements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `paie_profils` (
            `user_id` INT UNSIGNED PRIMARY KEY,
            `matricule` VARCHAR(50) DEFAULT NULL,
            `poste` VARCHAR(120) DEFAULT NULL,
            `date_entree` DATE DEFAULT NULL,
            `salaire_base_brut` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `taux_horaire` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `iban` VARCHAR(34) DEFAULT NULL,
            `numero_securite_sociale` VARCHAR(20) DEFAULT NULL,
            `actif` TINYINT(1) NOT NULL DEFAULT 1,
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT `fk_paie_profils_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `bulletins_paie` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `periode` CHAR(7) NOT NULL,
            `date_paiement` DATE NOT NULL,
            `statut` ENUM('brouillon', 'valide', 'paye') NOT NULL DEFAULT 'brouillon',
            `salaire_base_brut` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `heures_travaillees` DECIMAL(8,2) NOT NULL DEFAULT 151.67,
            `heures_supplementaires` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            `taux_horaire_majore` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `montant_heures_supplementaires` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `prime` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `bonus` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `indemnites` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `retenues` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `cotisations_salariales` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `cotisations_patronales` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `salaire_brut` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `net_imposable` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `net_a_payer` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `cout_total_employeur` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `montant_net_social` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `mode_paiement` ENUM('virement', 'cheque', 'especes', 'autre') NOT NULL DEFAULT 'virement',
            `reference_paiement` VARCHAR(120) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_bulletins_paie_user_periode` (`user_id`, `periode`),
            INDEX `idx_bulletins_paie_periode_statut` (`periode`, `statut`),
            CONSTRAINT `fk_bulletins_paie_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_bulletins_paie_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (tableExists($pdo, 'bulletins_paie')) {
            if (!columnExists($pdo, 'bulletins_paie', 'indemnite_sante')) {
                $pdo->exec("ALTER TABLE `bulletins_paie` ADD COLUMN `indemnite_sante` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `indemnites`");
            }
            if (!columnExists($pdo, 'bulletins_paie', 'ancv_ce')) {
                $pdo->exec("ALTER TABLE `bulletins_paie` ADD COLUMN `ancv_ce` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `indemnite_sante`");
            }
            if (!columnExists($pdo, 'bulletins_paie', 'montant_net_social')) {
                $pdo->exec("ALTER TABLE `bulletins_paie` ADD COLUMN `montant_net_social` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `cout_total_employeur`");
            }
        }

        if (tableExists($pdo, 'clients') && !columnExists($pdo, 'clients', 'siren')) {
            $pdo->exec("ALTER TABLE `clients` ADD COLUMN `siren` VARCHAR(20) DEFAULT NULL AFTER `siret`");
        }

        if (tableExists($pdo, 'factures')) {
            if (!columnExists($pdo, 'factures', 'client_siren')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `client_siren` VARCHAR(20) DEFAULT NULL AFTER `commande_id`");
            }
            if (!columnExists($pdo, 'factures', 'adresse_livraison')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `adresse_livraison` VARCHAR(255) DEFAULT NULL AFTER `client_siren`");
            }
            if (!columnExists($pdo, 'factures', 'code_postal_livraison')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `code_postal_livraison` VARCHAR(10) DEFAULT NULL AFTER `adresse_livraison`");
            }
            if (!columnExists($pdo, 'factures', 'ville_livraison')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `ville_livraison` VARCHAR(100) DEFAULT NULL AFTER `code_postal_livraison`");
            }
            if (!columnExists($pdo, 'factures', 'pays_livraison')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `pays_livraison` VARCHAR(50) DEFAULT NULL AFTER `ville_livraison`");
            }
            if (!columnExists($pdo, 'factures', 'type_operation')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `type_operation` ENUM('services', 'biens', 'mixte') NOT NULL DEFAULT 'services' AFTER `pays_livraison`");
            }
            if (!columnExists($pdo, 'factures', 'circuit_facturation')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `circuit_facturation` ENUM('b2b_france', 'b2c', 'international', 'secteur_public') NOT NULL DEFAULT 'b2b_france' AFTER `type_operation`");
            }
            if (!columnExists($pdo, 'factures', 'einvoice_format')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `einvoice_format` ENUM('factur-x', 'ubl', 'cii', 'pdf') NOT NULL DEFAULT 'factur-x' AFTER `circuit_facturation`");
            }
            if (!columnExists($pdo, 'factures', 'einvoice_statut')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `einvoice_statut` ENUM('non_preparee', 'prete', 'a_transmettre', 'transmise', 'rejetee') NOT NULL DEFAULT 'non_preparee' AFTER `einvoice_format`");
            }
            if (!columnExists($pdo, 'factures', 'einvoice_plateforme')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `einvoice_plateforme` VARCHAR(120) DEFAULT NULL AFTER `einvoice_statut`");
            }
            if (!columnExists($pdo, 'factures', 'einvoice_reference')) {
                $pdo->exec("ALTER TABLE `factures` ADD COLUMN `einvoice_reference` VARCHAR(120) DEFAULT NULL AFTER `einvoice_plateforme`");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS `einvoice_transmissions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `facture_id` INT UNSIGNED NOT NULL,
                `format` VARCHAR(20) NOT NULL,
                `endpoint` VARCHAR(255) DEFAULT NULL,
                `payload_path` VARCHAR(255) DEFAULT NULL,
                `statut` ENUM('pending', 'success', 'error') NOT NULL DEFAULT 'pending',
                `http_code` SMALLINT UNSIGNED DEFAULT NULL,
                `response_excerpt` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_einvoice_transmissions_facture` (`facture_id`, `created_at`),
                CONSTRAINT `fk_einvoice_transmissions_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
            $stmt->execute(['acompte_commande_pct', '30', 'Pourcentage d\'acompte automatique sur les documents de vente']);
            $stmt->execute(['garantie_sav_mois', '12', 'Durée de garantie ou SAV commercial en mois']);
            $stmt->execute(['delai_livraison_jours', '30', 'Délai indicatif de livraison ou d\'exécution']);
            $stmt->execute(['clause_chantier_livraison', '', 'Clause personnalisée chantier / livraison']);
            $stmt->execute(['logo_pdf_path', '', 'Chemin du logo utilisé dans les PDF']);
            $stmt->execute(['signature_pdf_path', '', 'Chemin de la signature image utilisée dans les PDF']);
            $stmt->execute(['cachet_pdf_path', '', 'Chemin du cachet image utilisé dans les PDF']);
            $stmt->execute(['module_cp_actif', '0', 'Activer le module de gestion des congés payés (0/1)']);
            $stmt->execute(['cp_mode_decompte', 'ouvrables', 'Mode légal de décompte des congés payés']);
            $stmt->execute(['cp_reference_start_month', '6', 'Mois de début de la période de référence CP']);
            $stmt->execute(['cp_reference_start_day', '1', 'Jour de début de la période de référence CP']);
            $stmt->execute(['cp_acquisition_rate', '2.5', 'Jours acquis par mois de travail effectif']);
            $stmt->execute(['cp_annual_cap', '30', 'Plafond annuel de jours acquis']);
            $stmt->execute(['cp_fractionnement_actif', '1', 'Appliquer les jours de fractionnement du congé principal (Code du travail L3141-23) (0/1)']);
            $stmt->execute(['module_paie_actif', '0', 'Activer le module de gestion des bulletins de paye (0/1)']);
            $stmt->execute(['paie_jours_travail_mensuel', '151.67', 'Base mensuelle d\'heures de travail pour la paie']);
            $stmt->execute(['paie_taux_charges_patronales', '42', 'Taux indicatif de charges patronales (%)']);
            $stmt->execute(['paie_taux_charges_salariales', '22', 'Taux indicatif de charges salariales (%)']);
            $stmt->execute(['pdp_enabled', '0', 'Activer le module PDP / facturation électronique (0/1)']);
            $stmt->execute(['pdp_provider', 'generic_api', 'Type de plateforme PDP configurée']);
            $stmt->execute(['pdp_auto_send', '0', 'Envoi automatique des factures prêtes (0/1)']);
            $stmt->execute(['pdp_endpoint_url', '', 'URL de dépôt de la plateforme PDP']);
            $stmt->execute(['pdp_auth_type', 'bearer_env', 'Mode d\'authentification PDP']);
            $stmt->execute(['pdp_api_key_env', 'GESTION_COMPTA_PDP_API_KEY', 'Nom de la variable d\'environnement contenant la clé PDP']);
            $stmt->execute(['pdp_custom_header_name', 'X-API-Key', 'Nom d\'en-tête personnalisé PDP']);
            $stmt->execute(['pdp_export_format', 'ubl', 'Format d\'export électronique par défaut']);
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
