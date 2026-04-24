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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('module_cp_actif', '0', 'Activer le module de gestion des congés payés (0/1)'),
('cp_mode_decompte', 'ouvrables', 'Mode légal de décompte des congés payés'),
('cp_reference_start_month', '6', 'Mois de début de la période de référence CP'),
('cp_reference_start_day', '1', 'Jour de début de la période de référence CP'),
('cp_acquisition_rate', '2.5', 'Jours acquis par mois de travail effectif'),
('cp_annual_cap', '30', 'Plafond annuel de jours acquis')
ON DUPLICATE KEY UPDATE valeur = valeur;
