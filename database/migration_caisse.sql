-- =============================================================
-- Migration : Caisse informatisée & Gestion de stock
-- =============================================================

USE `gestion_compta`;

-- -------------------------------------------------------------
-- Catégories de produits
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories_produits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nom` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `couleur` VARCHAR(7) DEFAULT '#6c757d',
    `actif` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Produits
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `produits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference` VARCHAR(50) DEFAULT NULL,
    `code_barres` VARCHAR(50) DEFAULT NULL,
    `nom` VARCHAR(150) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `categorie_produit_id` INT UNSIGNED DEFAULT NULL,
    `prix_achat` DECIMAL(10,2) DEFAULT 0.00,
    `prix_vente` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `taux_tva` DECIMAL(5,2) DEFAULT 0.00,
    `unite` VARCHAR(20) DEFAULT 'pièce',
    `stock_actuel` DECIMAL(10,2) DEFAULT 0,
    `stock_minimum` DECIMAL(10,2) DEFAULT 0,
    `seuil_alerte` DECIMAL(10,2) DEFAULT 0,
    `gestion_stock` TINYINT(1) DEFAULT 1,
    `actif` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`categorie_produit_id`) REFERENCES `categories_produits`(`id`) ON DELETE SET NULL,
    INDEX `idx_code_barres` (`code_barres`),
    INDEX `idx_reference` (`reference`)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Moyens de paiement caisse
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `moyens_paiement_caisse` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nom` VARCHAR(50) NOT NULL,
    `type` ENUM('especes','carte','cheque','virement','bon_achat','autre') NOT NULL DEFAULT 'autre',
    `montant_min` DECIMAL(10,2) DEFAULT NULL,
    `montant_max` DECIMAL(10,2) DEFAULT NULL,
    `actif` TINYINT(1) DEFAULT 1,
    `ordre` INT DEFAULT 0
) ENGINE=InnoDB;

-- Moyens de paiement par défaut
INSERT INTO `moyens_paiement_caisse` (`nom`, `type`, `montant_min`, `montant_max`, `actif`, `ordre`) VALUES
('Espèces', 'especes', NULL, NULL, 1, 1),
('Carte bancaire', 'carte', 1.00, NULL, 1, 2),
('Chèque', 'cheque', NULL, NULL, 1, 3),
('Virement', 'virement', NULL, NULL, 1, 4),
('Bon d''achat', 'bon_achat', NULL, NULL, 1, 5);

-- -------------------------------------------------------------
-- Notes de caisse (tickets)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notes_caisse` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `numero` VARCHAR(20) NOT NULL,
    `date_note` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `client_id` INT UNSIGNED DEFAULT NULL,
    `statut` ENUM('en_cours','terminee','annulee') NOT NULL DEFAULT 'en_cours',
    `total_ht` DECIMAL(10,2) DEFAULT 0.00,
    `total_tva` DECIMAL(10,2) DEFAULT 0.00,
    `total_ttc` DECIMAL(10,2) DEFAULT 0.00,
    `notes` TEXT DEFAULT NULL,
    `cloture_id` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_numero` (`numero`),
    INDEX `idx_date` (`date_note`),
    INDEX `idx_statut` (`statut`)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Lignes de notes de caisse
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lignes_notes_caisse` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT UNSIGNED NOT NULL,
    `produit_id` INT UNSIGNED DEFAULT NULL,
    `designation` VARCHAR(200) NOT NULL,
    `quantite` DECIMAL(10,2) NOT NULL DEFAULT 1,
    `prix_unitaire` DECIMAL(10,2) NOT NULL,
    `taux_tva` DECIMAL(5,2) DEFAULT 0.00,
    `montant_ht` DECIMAL(10,2) NOT NULL,
    `montant_tva` DECIMAL(10,2) DEFAULT 0.00,
    `montant_ttc` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`note_id`) REFERENCES `notes_caisse`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`produit_id`) REFERENCES `produits`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Paiements sur notes de caisse
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `paiements_caisse` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `note_id` INT UNSIGNED NOT NULL,
    `moyen_paiement_id` INT UNSIGNED NOT NULL,
    `montant` DECIMAL(10,2) NOT NULL,
    `reference` VARCHAR(100) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`note_id`) REFERENCES `notes_caisse`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`moyen_paiement_id`) REFERENCES `moyens_paiement_caisse`(`id`)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Mouvements de stock
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mouvements_stock` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `produit_id` INT UNSIGNED NOT NULL,
    `type` ENUM('entree','sortie','inventaire','vente','annulation_vente') NOT NULL,
    `quantite` DECIMAL(10,2) NOT NULL,
    `stock_avant` DECIMAL(10,2) NOT NULL,
    `stock_apres` DECIMAL(10,2) NOT NULL,
    `reference` VARCHAR(100) DEFAULT NULL COMMENT 'ex: note_id, inventaire_id',
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`produit_id`) REFERENCES `produits`(`id`) ON DELETE CASCADE,
    INDEX `idx_type` (`type`),
    INDEX `idx_date` (`created_at`)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Inventaires
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventaires` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `date_inventaire` DATE NOT NULL,
    `statut` ENUM('en_cours','valide') NOT NULL DEFAULT 'en_cours',
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `validated_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `lignes_inventaire` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `inventaire_id` INT UNSIGNED NOT NULL,
    `produit_id` INT UNSIGNED NOT NULL,
    `stock_theorique` DECIMAL(10,2) NOT NULL,
    `stock_reel` DECIMAL(10,2) NOT NULL,
    `ecart` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`inventaire_id`) REFERENCES `inventaires`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`produit_id`) REFERENCES `produits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Clôtures de caisse
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clotures_caisse` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `date_cloture` DATE NOT NULL,
    `nb_notes` INT DEFAULT 0,
    `total_ventes` DECIMAL(10,2) DEFAULT 0.00,
    `montant_especes_theorique` DECIMAL(10,2) DEFAULT 0.00,
    `montant_especes_reel` DECIMAL(10,2) DEFAULT NULL,
    `montant_carte` DECIMAL(10,2) DEFAULT 0.00,
    `montant_cheque` DECIMAL(10,2) DEFAULT 0.00,
    `nb_cheques_theorique` INT DEFAULT 0,
    `nb_cheques_reel` INT DEFAULT NULL,
    `montant_autre` DECIMAL(10,2) DEFAULT 0.00,
    `ecart_especes` DECIMAL(10,2) DEFAULT 0.00,
    `ecart_cheques` INT DEFAULT 0,
    `notes` TEXT DEFAULT NULL,
    `synchro_compta` TINYINT(1) DEFAULT 0,
    `transaction_id` INT UNSIGNED DEFAULT NULL COMMENT 'Transaction compta liée',
    `transaction_ecart_id` INT UNSIGNED DEFAULT NULL COMMENT 'Transaction erreur de caisse',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_date` (`date_cloture`)
) ENGINE=InnoDB;

-- Catégories de produits par défaut
INSERT INTO `categories_produits` (`nom`, `description`, `couleur`) VALUES
('Général', 'Produits divers', '#6c757d'),
('Alimentaire', 'Produits alimentaires', '#28a745'),
('Boissons', 'Boissons et rafraîchissements', '#17a2b8'),
('Services', 'Prestations de services', '#0d6efd'),
('Adhésions', 'Cotisations et adhésions', '#6f42c1');
