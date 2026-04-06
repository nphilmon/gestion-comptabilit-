-- =============================================================
-- Migration : Modules commerciaux (clients, devis, factures, commandes, paiements)
-- =============================================================

USE `gestion_compta`;

-- -------------------------------------------------------------
-- Table des clients / contacts
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('particulier', 'professionnel') DEFAULT 'professionnel',
    `nom` VARCHAR(100) NOT NULL,
    `prenom` VARCHAR(100) DEFAULT NULL,
    `entreprise` VARCHAR(150) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `telephone` VARCHAR(20) DEFAULT NULL,
    `adresse` VARCHAR(255) DEFAULT NULL,
    `code_postal` VARCHAR(10) DEFAULT NULL,
    `ville` VARCHAR(100) DEFAULT NULL,
    `pays` VARCHAR(50) DEFAULT 'France',
    `siret` VARCHAR(20) DEFAULT NULL,
    `siren` VARCHAR(20) DEFAULT NULL,
    `numero_tva` VARCHAR(20) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `actif` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table des devis
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `devis` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `numero` VARCHAR(30) NOT NULL UNIQUE,
    `client_id` INT UNSIGNED NOT NULL,
    `date_devis` DATE NOT NULL,
    `date_validite` DATE NOT NULL,
    `statut` ENUM('brouillon', 'envoye', 'accepte', 'refuse', 'expire') DEFAULT 'brouillon',
    `objet` VARCHAR(255) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `conditions` TEXT DEFAULT NULL,
    `montant_ht` DECIMAL(12,2) DEFAULT 0.00,
    `montant_tva` DECIMAL(12,2) DEFAULT 0.00,
    `montant_ttc` DECIMAL(12,2) DEFAULT 0.00,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Lignes de devis
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lignes_devis` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `devis_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `quantite` DECIMAL(10,3) DEFAULT 1.000,
    `unite` VARCHAR(20) DEFAULT 'unité',
    `prix_unitaire_ht` DECIMAL(12,2) NOT NULL,
    `taux_tva` DECIMAL(5,2) DEFAULT 20.00,
    `montant_ht` DECIMAL(12,2) NOT NULL,
    `montant_tva` DECIMAL(12,2) DEFAULT 0.00,
    `montant_ttc` DECIMAL(12,2) NOT NULL,
    `ordre` INT DEFAULT 0,
    FOREIGN KEY (`devis_id`) REFERENCES `devis`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table des factures
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `factures` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `numero` VARCHAR(30) NOT NULL UNIQUE,
    `client_id` INT UNSIGNED NOT NULL,
    `devis_id` INT UNSIGNED DEFAULT NULL,
    `commande_id` INT UNSIGNED DEFAULT NULL,
    `date_facture` DATE NOT NULL,
    `date_echeance` DATE NOT NULL,
    `statut` ENUM('brouillon', 'envoyee', 'payee', 'partielle', 'en_retard', 'annulee') DEFAULT 'brouillon',
    `objet` VARCHAR(255) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `conditions` TEXT DEFAULT NULL,
    `montant_ht` DECIMAL(12,2) DEFAULT 0.00,
    `montant_tva` DECIMAL(12,2) DEFAULT 0.00,
    `montant_ttc` DECIMAL(12,2) DEFAULT 0.00,
    `montant_paye` DECIMAL(12,2) DEFAULT 0.00,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`devis_id`) REFERENCES `devis`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Lignes de factures
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lignes_factures` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `facture_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `quantite` DECIMAL(10,3) DEFAULT 1.000,
    `unite` VARCHAR(20) DEFAULT 'unité',
    `prix_unitaire_ht` DECIMAL(12,2) NOT NULL,
    `taux_tva` DECIMAL(5,2) DEFAULT 20.00,
    `montant_ht` DECIMAL(12,2) NOT NULL,
    `montant_tva` DECIMAL(12,2) DEFAULT 0.00,
    `montant_ttc` DECIMAL(12,2) NOT NULL,
    `ordre` INT DEFAULT 0,
    FOREIGN KEY (`facture_id`) REFERENCES `factures`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table des commandes
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `commandes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `numero` VARCHAR(30) NOT NULL UNIQUE,
    `client_id` INT UNSIGNED NOT NULL,
    `devis_id` INT UNSIGNED DEFAULT NULL,
    `date_commande` DATE NOT NULL,
    `statut` ENUM('en_attente', 'confirmee', 'en_cours', 'livree', 'annulee') DEFAULT 'en_attente',
    `objet` VARCHAR(255) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `montant_ht` DECIMAL(12,2) DEFAULT 0.00,
    `montant_tva` DECIMAL(12,2) DEFAULT 0.00,
    `montant_ttc` DECIMAL(12,2) DEFAULT 0.00,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`devis_id`) REFERENCES `devis`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Lignes de commandes
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lignes_commandes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `commande_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `quantite` DECIMAL(10,3) DEFAULT 1.000,
    `unite` VARCHAR(20) DEFAULT 'unité',
    `prix_unitaire_ht` DECIMAL(12,2) NOT NULL,
    `taux_tva` DECIMAL(5,2) DEFAULT 20.00,
    `montant_ht` DECIMAL(12,2) NOT NULL,
    `montant_tva` DECIMAL(12,2) DEFAULT 0.00,
    `montant_ttc` DECIMAL(12,2) NOT NULL,
    `ordre` INT DEFAULT 0,
    FOREIGN KEY (`commande_id`) REFERENCES `commandes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Table des paiements
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `paiements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `facture_id` INT UNSIGNED NOT NULL,
    `date_paiement` DATE NOT NULL,
    `montant` DECIMAL(12,2) NOT NULL,
    `mode_paiement` ENUM('especes', 'carte_bancaire', 'virement', 'cheque', 'prelevement', 'paypal', 'autre') NOT NULL,
    `reference` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`facture_id`) REFERENCES `factures`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- Index pour performances
-- -------------------------------------------------------------
ALTER TABLE `devis` ADD INDEX `idx_devis_client` (`client_id`);
ALTER TABLE `devis` ADD INDEX `idx_devis_statut` (`statut`);
ALTER TABLE `factures` ADD INDEX `idx_factures_client` (`client_id`);
ALTER TABLE `factures` ADD INDEX `idx_factures_statut` (`statut`);
ALTER TABLE `commandes` ADD INDEX `idx_commandes_client` (`client_id`);
ALTER TABLE `paiements` ADD INDEX `idx_paiements_facture` (`facture_id`);

-- Paramètres pour la numérotation
INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('prefixe_devis', 'DEV', 'Préfixe numérotation des devis')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('prefixe_facture', 'FAC', 'Préfixe numérotation des factures')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('prefixe_commande', 'CMD', 'Préfixe numérotation des commandes')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('conditions_paiement', 'Paiement à 30 jours à compter de la date de facture.', 'Conditions de paiement par défaut')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('validite_devis', '30', 'Durée de validité des devis (jours)')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('adresse_entreprise', '', 'Adresse de l''entreprise')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('telephone_entreprise', '', 'Téléphone de l''entreprise')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('email_entreprise', '', 'Email de l''entreprise')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

-- Ajout FK factures -> commandes (si pas encore là)
-- ALTER TABLE `factures` ADD FOREIGN KEY (`commande_id`) REFERENCES `commandes`(`id`) ON DELETE SET NULL;
