-- =============================================================
-- Migration : Support multi-régimes
-- À exécuter sur une base existante
-- =============================================================

USE `gestion_compta`;

-- Nouveaux paramètres pour les régimes supplémentaires
INSERT IGNORE INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('forme_juridique', 'auto-entrepreneur', 'Forme juridique de l''entreprise'),
('tva_applicable', '0', 'Assujetti à la TVA (0/1)'),
('taux_tva', '20', 'Taux de TVA principal (%)'),
('taux_is', '15', 'Taux IS réduit PME (%)'),
('regime_imposition', 'ir', 'IR ou IS');

-- Catégories supplémentaires pour sociétés
INSERT INTO `categories` (`nom`, `type`, `description`, `couleur`) VALUES
('Vente de marchandises', 'recette', 'Vente de produits et marchandises', '#0d6efd'),
('Commissions', 'recette', 'Commissions perçues', '#198754'),
('Subventions', 'recette', 'Subventions et aides publiques', '#0dcaf0'),
('Loyer / Local professionnel', 'depense', 'Loyer, charges locatives', '#ab47bc'),
('Salaires & charges', 'depense', 'Rémunérations, charges patronales', '#c62828'),
('Honoraires comptable', 'depense', 'Expert-comptable, conseil juridique', '#4e342e'),
('Publicité & Marketing', 'depense', 'Publicité, communication, SEO', '#00897b'),
('Véhicule professionnel', 'depense', 'Carburant, entretien, leasing', '#e65100'),
('Repas & Réception', 'depense', 'Repas d''affaires, réceptions', '#6d4c41');
