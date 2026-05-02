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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    `indemnite_sante` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `ancv_ce` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('module_paie_actif', '0', 'Activer le module de gestion des bulletins de paye (0/1)'),
('paie_jours_travail_mensuel', '151.67', 'Base mensuelle d''heures de travail pour la paie'),
('paie_taux_charges_patronales', '42', 'Taux indicatif de charges patronales (%)'),
('paie_taux_charges_salariales', '22', 'Taux indicatif de charges salariales (%)')
ON DUPLICATE KEY UPDATE valeur = valeur;
