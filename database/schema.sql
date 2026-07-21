-- =============================================================
-- Gestion Comptable Pro - Multi-régimes (Auto-entrepreneur, EI, EURL, SARL, SAS, SASU)
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

-- Aucun utilisateur par défaut : setup.php détecte une base sans
-- utilisateur et guide la création du premier compte administrateur
-- avec un mot de passe choisi par l'installateur.

-- -------------------------------------------------------------
-- Table des jetons de réinitialisation de mot de passe
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_password_resets_token` (`token_hash`),
    INDEX `idx_password_resets_user` (`user_id`),
    CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

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
-- Tables RH / Congés payés
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cp_profils` (
    `user_id` INT UNSIGNED PRIMARY KEY,
    `date_entree` DATE DEFAULT NULL,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `solde_initial` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `jours_supplementaires_annuels` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cp_profils_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `cp_demandes` (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `cp_ajustements` (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `paie_profils` (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `bulletins_paie` (
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
('activite', 'Prestations de services', 'Nature de l''activité'),
('acompte_commande_pct', '30', 'Pourcentage d''acompte automatique sur les documents de vente'),
('garantie_sav_mois', '12', 'Durée de garantie ou SAV commercial en mois'),
('delai_livraison_jours', '30', 'Délai indicatif de livraison ou d''exécution'),
('clause_chantier_livraison', '', 'Clause personnalisée chantier / livraison'),
('logo_pdf_path', '', 'Chemin du logo utilisé dans les PDF'),
('signature_pdf_path', '', 'Chemin de la signature image utilisée dans les PDF'),
('cachet_pdf_path', '', 'Chemin du cachet image utilisé dans les PDF'),
('module_cp_actif', '0', 'Activer le module de gestion des congés payés (0/1)'),
('cp_mode_decompte', 'ouvrables', 'Mode légal de décompte des congés payés'),
('cp_reference_start_month', '6', 'Mois de début de la période de référence CP'),
('cp_reference_start_day', '1', 'Jour de début de la période de référence CP'),
('cp_acquisition_rate', '2.5', 'Jours acquis par mois de travail effectif'),
('cp_annual_cap', '30', 'Plafond annuel de jours acquis'),
('module_paie_actif', '0', 'Activer le module de gestion des bulletins de paye (0/1)'),
('paie_jours_travail_mensuel', '151.67', 'Base mensuelle d''heures de travail pour la paie'),
('paie_taux_charges_patronales', '42', 'Taux indicatif de charges patronales (%)'),
('paie_taux_charges_salariales', '22', 'Taux indicatif de charges salariales (%)'),
('pdp_enabled', '0', 'Activer le module PDP / facturation électronique (0/1)'),
('pdp_provider', 'generic_api', 'Type de plateforme PDP configurée'),
('pdp_auto_send', '0', 'Envoi automatique des factures prêtes (0/1)'),
('pdp_endpoint_url', '', 'URL de dépôt de la plateforme PDP'),
('pdp_auth_type', 'bearer_env', 'Mode d''authentification PDP'),
('pdp_api_key_env', 'GESTION_COMPTA_PDP_API_KEY', 'Nom de la variable d''environnement contenant la clé PDP'),
('pdp_custom_header_name', 'X-API-Key', 'Nom d''en-tête personnalisé PDP'),
('pdp_export_format', 'ubl', 'Format d''export électronique par défaut');
