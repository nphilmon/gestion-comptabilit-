-- =============================================================
-- Gestion Comptabilité - Multi-régimes (Auto-entrepreneur, EI, EURL, SARL, SAS, SASU)
-- Script de création de la base de données
-- =============================================================

CREATE DATABASE IF NOT EXISTS `gestion_compta` 
    DEFAULT CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE `gestion_compta`;

-- -------------------------------------------------------------
-- Table des catégories de transactions
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nom` VARCHAR(100) NOT NULL,
    `type` ENUM('recette', 'depense') NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `couleur` VARCHAR(7) DEFAULT '#6c757d',
    `actif` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table des transactions (livre des recettes + registre achats)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `date_transaction` DATE NOT NULL,
    `type` ENUM('recette', 'depense') NOT NULL,
    `categorie_id` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(255) NOT NULL,
    `client_fournisseur` VARCHAR(150) DEFAULT NULL,
    `montant` DECIMAL(10,2) NOT NULL,
    `mode_paiement` ENUM('virement', 'cheque', 'especes', 'carte', 'paypal', 'autre') DEFAULT 'virement',
    `reference` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`categorie_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table des paramètres de l'application
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parametres` (
    `cle` VARCHAR(50) PRIMARY KEY,
    `valeur` VARCHAR(255) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table des utilisateurs
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nom` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'comptable', 'lecteur') NOT NULL DEFAULT 'comptable',
    `actif` TINYINT(1) DEFAULT 1,
    `derniere_connexion` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Utilisateur admin par défaut (mot de passe: admin)
INSERT INTO `users` (`nom`, `email`, `password_hash`, `role`) VALUES
('Administrateur', 'admin@compta.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- -------------------------------------------------------------
-- Table des logs d'activité
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `details` VARCHAR(500) DEFAULT '',
    `cible` VARCHAR(100) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT '',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_date` (`created_at`)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table de rate limiting / sécurité
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `scope` VARCHAR(50) NOT NULL,
    `key_hash` CHAR(64) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_scope_key_date` (`scope`, `key_hash`, `created_at`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table des exercices comptables
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exercices` (
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
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Catégories par défaut - Recettes
-- -------------------------------------------------------------
INSERT INTO `categories` (`nom`, `type`, `description`, `couleur`) VALUES
('Prestations de services', 'recette', 'Honoraires et prestations facturées', '#28a745'),
('Formations dispensées', 'recette', 'Revenus de formations', '#20c997'),
('Droits d''auteur', 'recette', 'Droits d''auteur et royalties', '#17a2b8'),
('Vente de marchandises', 'recette', 'Vente de produits et marchandises', '#0d6efd'),
('Commissions', 'recette', 'Commissions perçues', '#198754'),
('Subventions', 'recette', 'Subventions et aides publiques', '#0dcaf0'),
('Autres recettes', 'recette', 'Revenus divers', '#6f42c1');

-- -------------------------------------------------------------
-- Catégories par défaut - Dépenses
-- -------------------------------------------------------------
INSERT INTO `categories` (`nom`, `type`, `description`, `couleur`) VALUES
('Matériel informatique', 'depense', 'Ordinateurs, périphériques, logiciels', '#dc3545'),
('Fournitures bureau', 'depense', 'Papeterie, consommables', '#fd7e14'),
('Abonnements & Licences', 'depense', 'SaaS, hébergement, domaines', '#e83e8c'),
('Déplacements', 'depense', 'Transport, péages, parking', '#ffc107'),
('Télécommunications', 'depense', 'Téléphone, internet', '#6610f2'),
('Formation professionnelle', 'depense', 'Cours, certifications, livres', '#007bff'),
('Assurance professionnelle', 'depense', 'RC Pro et autres assurances', '#795548'),
('Frais bancaires', 'depense', 'Commissions et frais de compte pro', '#9e9e9e'),
('Cotisations URSSAF', 'depense', 'Charges sociales obligatoires', '#ff5722'),
('CFE', 'depense', 'Cotisation foncière des entreprises', '#ff9800'),
('Loyer / Local professionnel', 'depense', 'Loyer, charges locatives', '#ab47bc'),
('Salaires & charges', 'depense', 'Rémunérations, charges patronales', '#c62828'),
('Honoraires comptable', 'depense', 'Expert-comptable, conseil juridique', '#4e342e'),
('Publicité & Marketing', 'depense', 'Publicité, communication, SEO', '#00897b'),
('Véhicule professionnel', 'depense', 'Carburant, entretien, leasing', '#e65100'),
('Repas & Réception', 'depense', 'Repas d''affaires, réceptions', '#6d4c41'),
('Autres dépenses', 'depense', 'Dépenses diverses', '#607d8b');

-- -------------------------------------------------------------
-- Paramètres par défaut (auto-entrepreneur BNC 2026)
-- -------------------------------------------------------------
INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('forme_juridique', 'auto-entrepreneur', 'Forme juridique'),
('regime', 'micro-bnc', 'Régime fiscal'),
('regime_imposition', 'ir', 'IR ou IS'),
('plafond_ca', '77700', 'Plafond de chiffre d''affaires annuel (€)'),
('taux_abattement', '34', 'Abattement forfaitaire BNC (%)'),
('taux_cotisations_sociales', '21.10', 'Taux URSSAF (%)'),
('taux_cfp', '0.20', 'Contribution à la formation professionnelle (%)'),
('taux_versement_liberatoire', '2.20', 'Versement libératoire IR (%)'),
('versement_liberatoire_actif', '0', 'Versement libératoire activé (0/1)'),
('tva_applicable', '0', 'Assujetti à la TVA (0/1)'),
('taux_tva', '20', 'Taux de TVA principal (%)'),
('taux_is', '15', 'Taux IS réduit PME (%)'),
('inscription_ouverte', '0', 'Autoriser l''inscription publique (0/1)'),
('nom_entreprise', 'Mon Activité', 'Nom de l''activité'),
('siret', '', 'Numéro SIRET'),
('activite', 'Prestations de services', 'Nature de l''activité');
