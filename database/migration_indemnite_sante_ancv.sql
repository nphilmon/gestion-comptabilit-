-- Migration : ajout des champs indemnite_sante et ancv_ce sur bulletins_paie
-- À exécuter une seule fois sur les bases existantes

ALTER TABLE `bulletins_paie`
    ADD COLUMN IF NOT EXISTS `indemnite_sante` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `indemnites`,
    ADD COLUMN IF NOT EXISTS `ancv_ce`          DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `indemnite_sante`;
