<?php
/**
 * Fonctions d'intelligence artificielle et d'analyse avancée
 * Dashboard prédictif, alertes proactives, analyse clients/produits,
 * auto-catégorisation, KPIs avancés, trésorerie prévisionnelle
 */

require_once __DIR__ . '/config.php';

// =============================================================
// 1. DASHBOARD PRÉDICTIF
// =============================================================

/**
 * Projection du CA en fin d'année basée sur la tendance actuelle
 */
function getProjectionCA(int $annee): array {
    $db = getDB();
    $moisCourant = ($annee == (int) date('Y')) ? (int) date('n') : 12;

    $stmt = $db->prepare("
        SELECT MONTH(date_transaction) AS mois, COALESCE(SUM(montant), 0) AS total
        FROM transactions
        WHERE type = 'recette' AND YEAR(date_transaction) = ?
        GROUP BY MONTH(date_transaction)
        ORDER BY mois
    ");
    $stmt->execute([$annee]);
    $rows = $stmt->fetchAll();

    $mensuel = array_fill(1, 12, 0.0);
    foreach ($rows as $r) {
        $mensuel[(int) $r['mois']] = (float) $r['total'];
    }

    $totalActuel = array_sum(array_slice($mensuel, 0, $moisCourant, true));
    $moyenneMensuelle = $moisCourant > 0 ? $totalActuel / $moisCourant : 0;
    $moisRestants = 12 - $moisCourant;
    $projectionFinAnnee = $totalActuel + ($moyenneMensuelle * $moisRestants);

    // Tendance linéaire (régression simple)
    $moisAvecCA = [];
    for ($i = 1; $i <= $moisCourant; $i++) {
        if ($mensuel[$i] > 0) {
            $moisAvecCA[] = ['x' => $i, 'y' => $mensuel[$i]];
        }
    }

    $projectionTendance = $projectionFinAnnee;
    $tendance = 'stable';
    if (count($moisAvecCA) >= 3) {
        $n = count($moisAvecCA);
        $sumX = $sumY = $sumXY = $sumX2 = 0;
        foreach ($moisAvecCA as $p) {
            $sumX += $p['x'];
            $sumY += $p['y'];
            $sumXY += $p['x'] * $p['y'];
            $sumX2 += $p['x'] * $p['x'];
        }
        $denom = ($n * $sumX2 - $sumX * $sumX);
        if ($denom != 0) {
            $pente = ($n * $sumXY - $sumX * $sumY) / $denom;
            $intercept = ($sumY - $pente * $sumX) / $n;
            $projectionTendance = 0;
            for ($m = 1; $m <= 12; $m++) {
                $projectionTendance += max(0, $intercept + $pente * $m);
            }
            if ($pente > $moyenneMensuelle * 0.05) $tendance = 'hausse';
            elseif ($pente < -$moyenneMensuelle * 0.05) $tendance = 'baisse';
        }
    }

    // CA N-1 pour comparaison
    $stmt2 = $db->prepare("
        SELECT COALESCE(SUM(montant), 0) FROM transactions
        WHERE type = 'recette' AND YEAR(date_transaction) = ?
    ");
    $stmt2->execute([$annee - 1]);
    $caN1 = (float) $stmt2->fetchColumn();

    $plafondCA = (float) getParam('plafond_ca', '0');

    return [
        'total_actuel' => $totalActuel,
        'moyenne_mensuelle' => $moyenneMensuelle,
        'projection_lineaire' => round($projectionFinAnnee, 2),
        'projection_tendance' => round($projectionTendance, 2),
        'tendance' => $tendance,
        'mois_courant' => $moisCourant,
        'mois_restants' => $moisRestants,
        'ca_n1' => $caN1,
        'variation_n1' => $caN1 > 0 ? round((($totalActuel / max(1, $moisCourant) * 12) - $caN1) / $caN1 * 100, 1) : 0,
        'plafond_ca' => $plafondCA,
        'pct_projection_plafond' => $plafondCA > 0 ? round($projectionFinAnnee / $plafondCA * 100, 1) : 0,
        'mensuel' => $mensuel,
    ];
}

/**
 * Estimation des charges URSSAF trimestrielles
 */
function getEstimationChargesTrimestrielles(int $annee): array {
    $db = getDB();
    $conf = getRegimeConfig();
    if (!$conf['is_micro']) return [];

    $tauxCotisations = (float) getParam('taux_cotisations_sociales', '0');
    $tauxCfp = (float) getParam('taux_cfp', '0');
    $vlActif = (bool) getParam('versement_liberatoire_actif', '0');
    $tauxVL = (float) getParam('taux_versement_liberatoire', '0');

    $trimestres = [];
    for ($t = 1; $t <= 4; $t++) {
        $moisDebut = ($t - 1) * 3 + 1;
        $moisFin = $t * 3;
        $dateDebut = sprintf('%d-%02d-01', $annee, $moisDebut);
        $dateFin = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $annee, $moisFin)));

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(montant), 0) FROM transactions
            WHERE type = 'recette' AND date_transaction BETWEEN ? AND ?
        ");
        $stmt->execute([$dateDebut, $dateFin]);
        $caTrimestriel = (float) $stmt->fetchColumn();

        $urssaf = $caTrimestriel * ($tauxCotisations / 100);
        $cfp = $caTrimestriel * ($tauxCfp / 100);
        $vl = $vlActif ? $caTrimestriel * ($tauxVL / 100) : 0;

        $trimestres[] = [
            'trimestre' => $t,
            'label' => 'T' . $t . ' ' . $annee,
            'ca' => $caTrimestriel,
            'urssaf' => round($urssaf, 2),
            'cfp' => round($cfp, 2),
            'versement_liberatoire' => round($vl, 2),
            'total_charges' => round($urssaf + $cfp + $vl, 2),
            'echeance' => getEcheanceURSSAF($t, $annee),
            'est_passe' => strtotime($dateFin) < time(),
        ];
    }

    return $trimestres;
}

function getEcheanceURSSAF(int $trimestre, int $annee): string {
    $echeances = [
        1 => $annee . '-04-30',
        2 => $annee . '-07-31',
        3 => $annee . '-10-31',
        4 => ($annee + 1) . '-01-31',
    ];
    return $echeances[$trimestre] ?? '';
}

// =============================================================
// 2. ALERTES PROACTIVES AVANCÉES
// =============================================================

function getAlertesProactives(int $annee): array {
    $db = getDB();
    $alertes = [];
    $today = date('Y-m-d');

    // Devis expirant dans les 7 prochains jours
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM devis
            WHERE statut IN ('envoye', 'brouillon')
            AND date_validite BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
        ");
        $stmt->execute([$today, $today]);
        $devisExpirants = (int) $stmt->fetchColumn();
        if ($devisExpirants > 0) {
            $alertes[] = [
                'type' => 'warning',
                'icon' => 'hourglass-split',
                'titre' => 'Devis expirants',
                'texte' => $devisExpirants . ' devis expire(nt) dans les 7 prochains jours.',
                'link' => 'devis.php',
                'priorite' => 3,
            ];
        }
    } catch (\PDOException $e) {}

    // Factures approchant l'échéance (dans 5 jours)
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM factures
            WHERE statut IN ('envoyee', 'partielle')
            AND date_echeance BETWEEN ? AND DATE_ADD(?, INTERVAL 5 DAY)
        ");
        $stmt->execute([$today, $today]);
        $facturesBientotDues = (int) $stmt->fetchColumn();
        if ($facturesBientotDues > 0) {
            $alertes[] = [
                'type' => 'info',
                'icon' => 'calendar-event',
                'titre' => 'Échéances proches',
                'texte' => $facturesBientotDues . ' facture(s) arrivent à échéance dans 5 jours.',
                'link' => 'factures.php',
                'priorite' => 2,
            ];
        }
    } catch (\PDOException $e) {}

    // Seuil TVA (franchise en base : 36 800 € services, 91 900 € vente)
    $conf = getRegimeConfig();
    if (!$conf['tva_applicable']) {
        $seuilTVA = in_array($conf['regime'], ['micro-bic-vente']) ? 91900 : 36800;
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(montant), 0) FROM transactions
                WHERE type = 'recette' AND YEAR(date_transaction) = ?
            ");
            $stmt->execute([$annee]);
            $caActuel = (float) $stmt->fetchColumn();
            $pctTVA = $seuilTVA > 0 ? round($caActuel / $seuilTVA * 100, 1) : 0;
            if ($pctTVA >= 75) {
                $alertes[] = [
                    'type' => $pctTVA >= 90 ? 'danger' : 'warning',
                    'icon' => 'percent',
                    'titre' => 'Seuil de TVA',
                    'texte' => 'CA à ' . $pctTVA . '% du seuil de franchise TVA (' . number_format($seuilTVA, 0, ',', ' ') . ' €).',
                    'link' => 'parametres.php',
                    'priorite' => $pctTVA >= 90 ? 5 : 3,
                ];
            }
        } catch (\PDOException $e) {}
    }

    // Rappels déclarations URSSAF
    if ($conf['is_micro']) {
        $moisCourant = (int) date('n');
        $prochaineEcheance = null;
        $echeancesMensuelles = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        $echeancesTrimestrielles = [1 => '30 avril', 2 => '31 juillet', 3 => '31 octobre', 4 => '31 janvier'];

        $tCourant = ceil($moisCourant / 3);
        $prochainT = ($tCourant % 4) + 1;
        $echeanceLabel = $echeancesTrimestrielles[$tCourant] ?? '';

        // Alerte si on est dans les 15 jours avant échéance trimestrielle
        $dateEcheance = getEcheanceURSSAF($tCourant, $annee);
        $joursAvant = (strtotime($dateEcheance) - time()) / 86400;
        if ($joursAvant >= 0 && $joursAvant <= 15) {
            $alertes[] = [
                'type' => 'info',
                'icon' => 'calendar-check',
                'titre' => 'Déclaration URSSAF',
                'texte' => 'Échéance URSSAF T' . $tCourant . ' le ' . $echeanceLabel . '. Préparez votre déclaration.',
                'link' => 'rapports.php?annee=' . $annee,
                'priorite' => 4,
            ];
        }
    }

    // Aucune transaction depuis 30 jours
    try {
        $stmt = $db->prepare("SELECT MAX(date_transaction) FROM transactions");
        $stmt->execute();
        $derniereDate = $stmt->fetchColumn();
        if ($derniereDate) {
            $joursInactivite = (time() - strtotime($derniereDate)) / 86400;
            if ($joursInactivite > 30) {
                $alertes[] = [
                    'type' => 'warning',
                    'icon' => 'pause-circle',
                    'titre' => 'Inactivité comptable',
                    'texte' => 'Aucune écriture depuis ' . (int) $joursInactivite . ' jours. Pensez à mettre à jour votre comptabilité.',
                    'link' => 'transactions.php?action=ajouter',
                    'priorite' => 2,
                ];
            }
        }
    } catch (\PDOException $e) {}

    // Trier par priorité décroissante
    usort($alertes, fn($a, $b) => ($b['priorite'] ?? 0) - ($a['priorite'] ?? 0));

    return $alertes;
}

// =============================================================
// 3. ANALYSE CLIENTS INTELLIGENTE
// =============================================================

function getAnalyseClients(): array {
    $db = getDB();

    // Top clients par CA facturé
    $topClients = [];
    try {
        $stmt = $db->query("
            SELECT c.id, c.nom, c.prenom, c.entreprise,
                   COUNT(DISTINCT f.id) AS nb_factures,
                   COALESCE(SUM(f.montant_ttc), 0) AS ca_total,
                   COALESCE(SUM(f.montant_paye), 0) AS total_paye,
                   MAX(f.date_facture) AS derniere_facture,
                   MIN(f.date_facture) AS premiere_facture
            FROM clients c
            JOIN factures f ON f.client_id = c.id AND f.statut != 'annulee'
            WHERE c.actif = 1
            GROUP BY c.id
            ORDER BY ca_total DESC
            LIMIT 10
        ");
        $topClients = $stmt->fetchAll();
    } catch (\PDOException $e) {}

    // Clients dormants (aucune facture depuis 6 mois)
    $clientsDormants = [];
    try {
        $stmt = $db->query("
            SELECT c.id, c.nom, c.prenom, c.entreprise,
                   MAX(f.date_facture) AS derniere_facture,
                   DATEDIFF(CURDATE(), MAX(f.date_facture)) AS jours_inactif,
                   COUNT(DISTINCT f.id) AS nb_factures_total,
                   COALESCE(SUM(f.montant_ttc), 0) AS ca_historique
            FROM clients c
            JOIN factures f ON f.client_id = c.id AND f.statut != 'annulee'
            WHERE c.actif = 1
            GROUP BY c.id
            HAVING derniere_facture < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            ORDER BY ca_historique DESC
            LIMIT 10
        ");
        $clientsDormants = $stmt->fetchAll();
    } catch (\PDOException $e) {}

    // Panier moyen
    $panierMoyen = 0;
    try {
        $stmt = $db->query("SELECT AVG(montant_ttc) FROM factures WHERE statut NOT IN ('annulee', 'brouillon')");
        $panierMoyen = round((float) $stmt->fetchColumn(), 2);
    } catch (\PDOException $e) {}

    // Fréquence d'achat moyenne
    $frequenceAchat = 0;
    try {
        $stmt = $db->query("
            SELECT AVG(freq) FROM (
                SELECT client_id, COUNT(*) / GREATEST(1, DATEDIFF(MAX(date_facture), MIN(date_facture)) / 30) AS freq
                FROM factures
                WHERE statut NOT IN ('annulee', 'brouillon')
                GROUP BY client_id
                HAVING COUNT(*) >= 2
            ) sub
        ");
        $frequenceAchat = round((float) $stmt->fetchColumn(), 2);
    } catch (\PDOException $e) {}

    // Scoring client (basé sur CA, fréquence, régularité)
    $clientsScores = [];
    foreach ($topClients as $client) {
        $score = 0;
        // CA : max 40 pts
        $maxCA = !empty($topClients) ? (float) $topClients[0]['ca_total'] : 1;
        $score += $maxCA > 0 ? round(((float) $client['ca_total'] / $maxCA) * 40) : 0;
        // Nombre de factures : max 30 pts
        $score += min(30, (int) $client['nb_factures'] * 5);
        // Récence : max 30 pts (dernière facture < 30j = 30pts, < 90j = 20pts, etc.)
        $joursDepuis = $client['derniere_facture'] ? (time() - strtotime($client['derniere_facture'])) / 86400 : 365;
        if ($joursDepuis < 30) $score += 30;
        elseif ($joursDepuis < 90) $score += 20;
        elseif ($joursDepuis < 180) $score += 10;

        $clientsScores[] = array_merge($client, [
            'score' => min(100, $score),
            'niveau' => $score >= 70 ? 'premium' : ($score >= 40 ? 'regulier' : 'occasionnel'),
        ]);
    }

    // Répartition par type
    $repartition = ['pro' => 0, 'particulier' => 0];
    try {
        $stmt = $db->query("SELECT type, COUNT(*) AS nb FROM clients WHERE actif = 1 GROUP BY type");
        foreach ($stmt->fetchAll() as $r) {
            if ($r['type'] === 'professionnel') $repartition['pro'] = (int) $r['nb'];
            else $repartition['particulier'] = (int) $r['nb'];
        }
    } catch (\PDOException $e) {}

    // Taux de fidélisation (clients avec > 1 facture / total clients avec factures)
    $tauxFidelisation = 0;
    try {
        $stmt = $db->query("
            SELECT 
                COUNT(CASE WHEN cnt > 1 THEN 1 END) AS fideles,
                COUNT(*) AS total
            FROM (
                SELECT client_id, COUNT(*) AS cnt
                FROM factures WHERE statut NOT IN ('annulee', 'brouillon')
                GROUP BY client_id
            ) sub
        ");
        $row = $stmt->fetch();
        if ($row && $row['total'] > 0) {
            $tauxFidelisation = round(($row['fideles'] / $row['total']) * 100, 1);
        }
    } catch (\PDOException $e) {}

    return [
        'top_clients' => $clientsScores,
        'clients_dormants' => $clientsDormants,
        'panier_moyen' => $panierMoyen,
        'frequence_achat' => $frequenceAchat,
        'repartition' => $repartition,
        'taux_fidelisation' => $tauxFidelisation,
    ];
}

// =============================================================
// 4. ANALYSE PRODUITS & STOCK
// =============================================================

function getAnalyseProduits(): array {
    $db = getDB();

    // Produits les plus vendus
    $bestSellers = [];
    try {
        $stmt = $db->query("
            SELECT p.id, p.nom, p.reference, p.prix_vente_ht, p.prix_achat_ht,
                   COALESCE(SUM(l.quantite), 0) AS total_vendu,
                   COALESCE(SUM(l.quantite * l.prix_unitaire), 0) AS ca_genere,
                   p.stock_actuel, p.seuil_alerte, p.gestion_stock
            FROM produits p
            LEFT JOIN lignes_notes_caisse l ON l.produit_id = p.id
            LEFT JOIN notes_caisse nc ON l.note_caisse_id = nc.id AND nc.statut != 'annulee'
            WHERE p.actif = 1
            GROUP BY p.id
            ORDER BY total_vendu DESC
            LIMIT 10
        ");
        $bestSellers = $stmt->fetchAll();
    } catch (\PDOException $e) {}

    // Marge par produit
    $margesProduits = [];
    foreach ($bestSellers as $p) {
        $prixVente = (float) $p['prix_vente_ht'];
        $prixAchat = (float) $p['prix_achat_ht'];
        $marge = $prixAchat > 0 ? round(($prixVente - $prixAchat) / $prixAchat * 100, 1) : 0;
        $margesProduits[] = array_merge($p, [
            'marge_pct' => $marge,
            'marge_absolue' => round($prixVente - $prixAchat, 2),
        ]);
    }

    // Rotation de stock
    $rotationStock = [];
    try {
        $stmt = $db->query("
            SELECT p.id, p.nom, p.stock_actuel, p.seuil_alerte,
                   COALESCE(SUM(CASE WHEN ms.type_mouvement = 'vente' THEN ms.quantite ELSE 0 END), 0) AS ventes_30j
            FROM produits p
            LEFT JOIN mouvements_stock ms ON ms.produit_id = p.id
                AND ms.date_mouvement >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            WHERE p.actif = 1 AND p.gestion_stock = 1
            GROUP BY p.id
            ORDER BY ventes_30j DESC
        ");
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $ventes30j = (float) $r['ventes_30j'];
            $stock = (float) $r['stock_actuel'];
            $joursStock = $ventes30j > 0 ? round($stock / ($ventes30j / 30)) : ($stock > 0 ? 999 : 0);
            $rotationStock[] = array_merge($r, [
                'ventes_mensuelles' => $ventes30j,
                'jours_stock_restant' => $joursStock,
                'besoin_reappro' => $joursStock < 15 && $joursStock > 0,
            ]);
        }
    } catch (\PDOException $e) {}

    // Prévision de réapprovisionnement
    $reappro = array_filter($rotationStock, fn($p) => $p['besoin_reappro']);

    // Produits sans mouvement (stock mort)
    $stockMort = [];
    try {
        $stmt = $db->query("
            SELECT p.id, p.nom, p.stock_actuel, p.prix_achat_ht,
                   MAX(ms.date_mouvement) AS dernier_mouvement,
                   DATEDIFF(CURDATE(), MAX(ms.date_mouvement)) AS jours_sans_mvt
            FROM produits p
            LEFT JOIN mouvements_stock ms ON ms.produit_id = p.id
            WHERE p.actif = 1 AND p.gestion_stock = 1 AND p.stock_actuel > 0
            GROUP BY p.id
            HAVING dernier_mouvement IS NULL OR dernier_mouvement < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            ORDER BY p.stock_actuel * p.prix_achat_ht DESC
            LIMIT 10
        ");
        $stockMort = $stmt->fetchAll();
    } catch (\PDOException $e) {}

    return [
        'best_sellers' => $margesProduits,
        'rotation_stock' => $rotationStock,
        'reappro_urgente' => $reappro,
        'stock_mort' => $stockMort,
    ];
}

// =============================================================
// 5. AUTO-CATÉGORISATION DES TRANSACTIONS
// =============================================================

/**
 * Suggère une catégorie en analysant la description d'une transaction
 * Utilise du pattern matching sur l'historique + mots-clés
 */
function suggererCategorie(string $description, string $type = 'depense'): ?array {
    $db = getDB();
    $description = mb_strtolower(trim($description));

    if (empty($description)) return null;

    // 1. Chercher dans l'historique : même description = même catégorie
    $stmt = $db->prepare("
        SELECT t.categorie_id, c.nom AS categorie_nom, COUNT(*) AS freq
        FROM transactions t
        JOIN categories c ON t.categorie_id = c.id
        WHERE t.type = ? AND LOWER(t.description) = ? AND t.categorie_id IS NOT NULL
        GROUP BY t.categorie_id
        ORDER BY freq DESC
        LIMIT 1
    ");
    $stmt->execute([$type, $description]);
    $exact = $stmt->fetch();
    if ($exact) {
        return [
            'categorie_id' => (int) $exact['categorie_id'],
            'categorie_nom' => $exact['categorie_nom'],
            'confiance' => 95,
            'methode' => 'historique_exact',
        ];
    }

    // 2. Recherche par mots similaires dans l'historique
    $mots = array_filter(explode(' ', $description), fn($m) => mb_strlen($m) >= 3);
    if (!empty($mots)) {
        $likeConditions = [];
        $params = [$type];
        foreach ($mots as $mot) {
            $likeConditions[] = 'LOWER(t.description) LIKE ?';
            $params[] = '%' . $mot . '%';
        }
        $sql = "
            SELECT t.categorie_id, c.nom AS categorie_nom, COUNT(*) AS freq
            FROM transactions t
            JOIN categories c ON t.categorie_id = c.id
            WHERE t.type = ? AND (" . implode(' OR ', $likeConditions) . ") AND t.categorie_id IS NOT NULL
            GROUP BY t.categorie_id
            ORDER BY freq DESC
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $similar = $stmt->fetch();
        if ($similar && (int) $similar['freq'] >= 2) {
            return [
                'categorie_id' => (int) $similar['categorie_id'],
                'categorie_nom' => $similar['categorie_nom'],
                'confiance' => 70,
                'methode' => 'historique_similaire',
            ];
        }
    }

    // 3. Pattern matching avec mots-clés prédéfinis
    $patterns = getMotsClesCategories();
    $bestMatch = null;
    $bestScore = 0;

    foreach ($patterns as $catId => $motsCles) {
        $score = 0;
        foreach ($motsCles as $motCle) {
            if (mb_strpos($description, mb_strtolower($motCle)) !== false) {
                $score += mb_strlen($motCle);
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $catId;
        }
    }

    if ($bestMatch && $bestScore >= 3) {
        $cat = getCategorie($bestMatch);
        if ($cat) {
            return [
                'categorie_id' => (int) $bestMatch,
                'categorie_nom' => $cat['nom'],
                'confiance' => min(60, $bestScore * 10),
                'methode' => 'mots_cles',
            ];
        }
    }

    return null;
}

/**
 * Mots-clés associés aux catégories pour l'auto-catégorisation
 */
function getMotsClesCategories(): array {
    $db = getDB();
    $categories = $db->query("SELECT id, nom, type FROM categories WHERE actif = 1")->fetchAll();

    $mapping = [];
    foreach ($categories as $cat) {
        $nom = mb_strtolower($cat['nom']);
        $id = (int) $cat['id'];

        // Mapping automatique basé sur les noms de catégories
        $motsCles = [];

        // Recettes
        if (strpos($nom, 'prestation') !== false || strpos($nom, 'service') !== false) {
            $motsCles = ['prestation', 'service', 'consulting', 'conseil', 'mission', 'formation', 'audit', 'accompagnement'];
        } elseif (strpos($nom, 'vente') !== false || strpos($nom, 'marchandise') !== false) {
            $motsCles = ['vente', 'marchandise', 'produit', 'commande', 'livraison', 'article'];
        } elseif (strpos($nom, 'commission') !== false || strpos($nom, 'affiliation') !== false) {
            $motsCles = ['commission', 'affiliation', 'apport affaire', 'parrainage', 'referral'];
        }

        // Dépenses
        if (strpos($nom, 'loyer') !== false || strpos($nom, 'local') !== false) {
            $motsCles = ['loyer', 'bail', 'local', 'bureau', 'coworking', 'location'];
        } elseif (strpos($nom, 'assurance') !== false) {
            $motsCles = ['assurance', 'mutuelle', 'rc pro', 'responsabilité', 'prévoyance', 'garantie'];
        } elseif (strpos($nom, 'télécom') !== false || strpos($nom, 'internet') !== false || strpos($nom, 'téléphone') !== false) {
            $motsCles = ['téléphone', 'internet', 'mobile', 'fibre', 'forfait', 'orange', 'sfr', 'free', 'bouygues', 'ovh', 'hébergement'];
        } elseif (strpos($nom, 'fourniture') !== false || strpos($nom, 'bureau') !== false) {
            $motsCles = ['fourniture', 'bureau', 'papier', 'encre', 'stylo', 'enveloppe', 'classeur', 'amazon'];
        } elseif (strpos($nom, 'transport') !== false || strpos($nom, 'déplacement') !== false) {
            $motsCles = ['transport', 'déplacement', 'train', 'avion', 'sncf', 'essence', 'carburant', 'péage', 'parking', 'taxi', 'uber', 'km'];
        } elseif (strpos($nom, 'repas') !== false || strpos($nom, 'restauration') !== false) {
            $motsCles = ['repas', 'restaurant', 'déjeuner', 'dîner', 'traiteur', 'snack', 'café'];
        } elseif (strpos($nom, 'logiciel') !== false || strpos($nom, 'informatique') !== false || strpos($nom, 'abonnement') !== false) {
            $motsCles = ['logiciel', 'abonnement', 'saas', 'licence', 'adobe', 'microsoft', 'google', 'notion', 'slack', 'zoom', 'app', 'cloud'];
        } elseif (strpos($nom, 'banque') !== false || strpos($nom, 'bancaire') !== false) {
            $motsCles = ['banque', 'bancaire', 'frais bancaire', 'commission', 'agios', 'cotisation carte'];
        } elseif (strpos($nom, 'comptable') !== false || strpos($nom, 'expert') !== false) {
            $motsCles = ['comptable', 'expert-comptable', 'honoraire', 'bilan', 'liasse'];
        } elseif (strpos($nom, 'publicité') !== false || strpos($nom, 'marketing') !== false || strpos($nom, 'communication') !== false) {
            $motsCles = ['publicité', 'marketing', 'pub', 'google ads', 'facebook', 'flyer', 'carte visite', 'communication', 'seo', 'référencement'];
        } elseif (strpos($nom, 'formation') !== false) {
            $motsCles = ['formation', 'stage', 'cours', 'mooc', 'udemy', 'certification', 'diplôme'];
        } elseif (strpos($nom, 'matériel') !== false || strpos($nom, 'équipement') !== false) {
            $motsCles = ['matériel', 'équipement', 'ordinateur', 'pc', 'imprimante', 'écran', 'clavier', 'souris', 'mobilier', 'meuble'];
        } elseif (strpos($nom, 'achat') !== false) {
            $motsCles = ['achat', 'fournisseur', 'stock', 'approvisionnement', 'marchandise'];
        }

        if (!empty($motsCles)) {
            $mapping[$id] = $motsCles;
        }
    }

    return $mapping;
}

/**
 * API endpoint pour l'auto-suggestion AJAX
 */
function apiSuggestionCategorie(): void {
    header('Content-Type: application/json; charset=utf-8');
    $description = trim($_GET['description'] ?? '');
    $type = in_array($_GET['type'] ?? '', ['recette', 'depense']) ? $_GET['type'] : 'depense';

    if (empty($description)) {
        echo json_encode(['suggestion' => null]);
        exit;
    }

    $suggestion = suggererCategorie($description, $type);
    echo json_encode(['suggestion' => $suggestion]);
    exit;
}

// =============================================================
// 6. KPIs AVANCÉS TEMPS RÉEL
// =============================================================

function getKPIsAvances(int $annee): array {
    $db = getDB();
    $kpis = [];

    // Marge brute (CA - Achats)
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE type = 'recette' AND YEAR(date_transaction) = ?");
        $stmt->execute([$annee]);
        $ca = (float) $stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(t.montant), 0)
            FROM transactions t
            JOIN categories c ON t.categorie_id = c.id
            WHERE t.type = 'depense' AND YEAR(t.date_transaction) = ?
            AND (LOWER(c.nom) LIKE '%achat%' OR LOWER(c.nom) LIKE '%marchandise%' OR LOWER(c.nom) LIKE '%stock%')
        ");
        $stmt->execute([$annee]);
        $achats = (float) $stmt->fetchColumn();

        $margeBrute = $ca - $achats;
        $tauxMarge = $ca > 0 ? round($margeBrute / $ca * 100, 1) : 0;
        $kpis['marge_brute'] = $margeBrute;
        $kpis['taux_marge_brute'] = $tauxMarge;
    } catch (\PDOException $e) {
        $kpis['marge_brute'] = 0;
        $kpis['taux_marge_brute'] = 0;
    }

    // DSO (Days Sales Outstanding) — Délai moyen de paiement
    try {
        $stmt = $db->query("
            SELECT AVG(DATEDIFF(
                COALESCE(
                    (SELECT MIN(date_paiement) FROM paiements p WHERE p.facture_id = f.id),
                    CURDATE()
                ),
                f.date_facture
            )) AS dso
            FROM factures f
            WHERE f.statut NOT IN ('annulee', 'brouillon')
            AND f.date_facture >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        ");
        $kpis['dso'] = round((float) $stmt->fetchColumn(), 1);
    } catch (\PDOException $e) {
        $kpis['dso'] = 0;
    }

    // Taux de conversion devis → facture
    try {
        $totalDevis = $db->query("SELECT COUNT(*) FROM devis WHERE statut NOT IN ('brouillon')")->fetchColumn();
        $devisConverts = $db->query("
            SELECT COUNT(DISTINCT d.id) FROM devis d
            JOIN factures f ON f.devis_id = d.id
            WHERE d.statut != 'brouillon'
        ")->fetchColumn();
        $kpis['taux_conversion_devis'] = $totalDevis > 0 ? round(($devisConverts / $totalDevis) * 100, 1) : 0;
        $kpis['devis_total'] = (int) $totalDevis;
        $kpis['devis_convertis'] = (int) $devisConverts;
    } catch (\PDOException $e) {
        $kpis['taux_conversion_devis'] = 0;
    }

    // Saisonnalité — mois le plus fort et le plus faible
    try {
        $stmt = $db->prepare("
            SELECT MONTH(date_transaction) AS mois, COALESCE(SUM(montant), 0) AS total
            FROM transactions
            WHERE type = 'recette' AND YEAR(date_transaction) = ?
            GROUP BY MONTH(date_transaction)
            ORDER BY total DESC
        ");
        $stmt->execute([$annee]);
        $rows = $stmt->fetchAll();
        $noms_mois = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];

        if (!empty($rows)) {
            $kpis['meilleur_mois'] = $noms_mois[(int) $rows[0]['mois']] ?? '';
            $kpis['meilleur_mois_ca'] = (float) $rows[0]['total'];
            $dernier = end($rows);
            $kpis['pire_mois'] = $noms_mois[(int) $dernier['mois']] ?? '';
            $kpis['pire_mois_ca'] = (float) $dernier['total'];
        }
    } catch (\PDOException $e) {}

    // CA moyen par client
    try {
        $stmt = $db->query("
            SELECT AVG(ca) FROM (
                SELECT SUM(montant_ttc) AS ca FROM factures
                WHERE statut NOT IN ('annulee', 'brouillon')
                GROUP BY client_id
            ) sub
        ");
        $kpis['ca_moyen_client'] = round((float) $stmt->fetchColumn(), 2);
    } catch (\PDOException $e) {
        $kpis['ca_moyen_client'] = 0;
    }

    // Taux de recouvrement
    try {
        $stmt = $db->query("
            SELECT
                COALESCE(SUM(montant_ttc), 0) AS total_facture,
                COALESCE(SUM(montant_paye), 0) AS total_paye
            FROM factures
            WHERE statut NOT IN ('annulee', 'brouillon')
        ");
        $row = $stmt->fetch();
        $totalFacture = (float) ($row['total_facture'] ?? 0);
        $totalPaye = (float) ($row['total_paye'] ?? 0);
        $kpis['taux_recouvrement'] = $totalFacture > 0 ? round(($totalPaye / $totalFacture) * 100, 1) : 0;
    } catch (\PDOException $e) {
        $kpis['taux_recouvrement'] = 0;
    }

    // Dépenses moyennes mensuelles
    try {
        $stmt = $db->prepare("
            SELECT AVG(total) FROM (
                SELECT SUM(montant) AS total
                FROM transactions
                WHERE type = 'depense' AND YEAR(date_transaction) = ?
                GROUP BY MONTH(date_transaction)
            ) sub
        ");
        $stmt->execute([$annee]);
        $kpis['depenses_moy_mensuelles'] = round((float) $stmt->fetchColumn(), 2);
    } catch (\PDOException $e) {
        $kpis['depenses_moy_mensuelles'] = 0;
    }

    return $kpis;
}

// =============================================================
// 7. TRÉSORERIE PRÉVISIONNELLE
// =============================================================

function getTresoreriePrevisionnelle(int $annee): array {
    $db = getDB();

    // Solde actuel (recettes - dépenses total)
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type = 'recette' THEN montant ELSE 0 END), 0) AS recettes,
            COALESCE(SUM(CASE WHEN type = 'depense' THEN montant ELSE 0 END), 0) AS depenses
        FROM transactions
    ");
    $stmt->execute();
    $row = $stmt->fetch();
    $soldeActuel = (float) $row['recettes'] - (float) $row['depenses'];

    // Flux mensuel moyen des 6 derniers mois
    $stmt = $db->prepare("
        SELECT
            DATE_FORMAT(date_transaction, '%Y-%m') AS mois,
            COALESCE(SUM(CASE WHEN type = 'recette' THEN montant ELSE 0 END), 0) AS recettes,
            COALESCE(SUM(CASE WHEN type = 'depense' THEN montant ELSE 0 END), 0) AS depenses
        FROM transactions
        WHERE date_transaction >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(date_transaction, '%Y-%m')
        ORDER BY mois
    ");
    $stmt->execute();
    $fluxMensuels = $stmt->fetchAll();

    $fluxNetMoyen = 0;
    if (!empty($fluxMensuels)) {
        $totalFlux = 0;
        foreach ($fluxMensuels as $fm) {
            $totalFlux += ((float) $fm['recettes'] - (float) $fm['depenses']);
        }
        $fluxNetMoyen = $totalFlux / count($fluxMensuels);
    }

    // Entrées prévues (factures en attente de paiement)
    $stmt = $db->query("
        SELECT COALESCE(SUM(montant_ttc - montant_paye), 0)
        FROM factures
        WHERE statut IN ('envoyee', 'partielle', 'en_retard')
    ");
    $entreesPrevues = (float) $stmt->fetchColumn();

    // Charges URSSAF à venir (pour micro)
    $chargesAvenir = 0;
    $conf = getRegimeConfig();
    if ($conf['is_micro']) {
        $trimestres = getEstimationChargesTrimestrielles($annee);
        foreach ($trimestres as $t) {
            if (!$t['est_passe']) {
                $chargesAvenir += $t['total_charges'];
            }
        }
    }

    // Projection sur 6 mois
    $projectionMensuelle = [];
    $soldeProj = $soldeActuel;
    $noms_mois = [1=>'Jan',2=>'Fév',3=>'Mar',4=>'Avr',5=>'Mai',6=>'Juin',7=>'Juil',8=>'Aoû',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Déc'];
    $moisActuel = (int) date('n');
    $anneeActuelle = (int) date('Y');

    for ($i = 0; $i < 6; $i++) {
        $m = (($moisActuel - 1 + $i) % 12) + 1;
        $a = $anneeActuelle + intdiv($moisActuel - 1 + $i, 12);
        $label = $noms_mois[$m] . ' ' . $a;

        // Ajouter les entrées prévues au premier mois de projection
        $entreesMois = ($i === 0) ? $entreesPrevues * 0.3 : 0;
        $soldeProj += $fluxNetMoyen + $entreesMois;

        $projectionMensuelle[] = [
            'label' => $label,
            'solde' => round($soldeProj, 2),
            'flux_net' => round($fluxNetMoyen, 2),
        ];
    }

    // Date estimée de rupture (si flux négatif)
    $dateRupture = null;
    $moisAvantRupture = null;
    if ($fluxNetMoyen < 0 && $soldeActuel > 0) {
        $moisAvantRupture = abs(ceil($soldeActuel / $fluxNetMoyen));
        $dateRupture = date('F Y', strtotime('+' . $moisAvantRupture . ' months'));
    }

    // Runway (mois de trésorerie restants)
    $depMoyMensuelle = 0;
    try {
        $stmt = $db->prepare("
            SELECT AVG(total) FROM (
                SELECT SUM(montant) AS total
                FROM transactions
                WHERE type = 'depense' AND date_transaction >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(date_transaction, '%Y-%m')
            ) sub
        ");
        $stmt->execute();
        $depMoyMensuelle = (float) $stmt->fetchColumn();
    } catch (\PDOException $e) {}

    $runway = $depMoyMensuelle > 0 ? round($soldeActuel / $depMoyMensuelle, 1) : null;

    return [
        'solde_actuel' => round($soldeActuel, 2),
        'flux_net_moyen' => round($fluxNetMoyen, 2),
        'entrees_prevues' => round($entreesPrevues, 2),
        'charges_a_venir' => round($chargesAvenir, 2),
        'projection_mensuelle' => $projectionMensuelle,
        'date_rupture' => $dateRupture,
        'mois_avant_rupture' => $moisAvantRupture,
        'runway_mois' => $runway,
        'depenses_moy_mensuelles' => round($depMoyMensuelle, 2),
    ];
}

// =============================================================
// 8. RECOMMANDATIONS INTELLIGENTES GLOBALES
// =============================================================

function getRecommandationsIntelligentes(int $annee): array {
    $recommandations = [];
    $db = getDB();
    $moisCourant = ($annee == (int) date('Y')) ? (int) date('n') : 12;
    $conf = getRegimeConfig();

    // ─── 1. DÉPENDANCE CLIENT ───────────────────────────────
    try {
        $stmt = $db->prepare("
            SELECT client_id, SUM(montant_ttc) AS ca
            FROM factures
            WHERE YEAR(date_facture) = ? AND statut NOT IN ('annulee', 'brouillon')
            GROUP BY client_id ORDER BY ca DESC
        ");
        $stmt->execute([$annee]);
        $clients = $stmt->fetchAll();
        if (count($clients) >= 2) {
            $caTotal = array_sum(array_column($clients, 'ca'));
            $caPremier = (float) $clients[0]['ca'];
            $dependance = $caTotal > 0 ? round($caPremier / $caTotal * 100, 1) : 0;
            if ($dependance > 50) {
                $recommandations[] = [
                    'type' => 'warning', 'icon' => 'diagram-3',
                    'titre' => 'Risque de dépendance client',
                    'texte' => 'Un seul client représente ' . $dependance . '% du CA. Diversifier le portefeuille réduirait le risque.',
                    'categorie' => 'commercial', 'priorite' => 4,
                ];
            }
        }
    } catch (\PDOException $e) {}

    // ─── 2. FACTURATION CONCENTRÉE FIN DE MOIS ──────────────
    try {
        $stmt = $db->prepare("
            SELECT SUM(CASE WHEN DAY(date_facture) > 20 THEN montant_ttc ELSE 0 END) AS fin_mois,
                   SUM(montant_ttc) AS total
            FROM factures WHERE YEAR(date_facture) = ? AND statut NOT IN ('annulee', 'brouillon')
        ");
        $stmt->execute([$annee]);
        $row = $stmt->fetch();
        if ($row && (float) $row['total'] > 0) {
            $pctFinMois = round((float) $row['fin_mois'] / (float) $row['total'] * 100, 1);
            if ($pctFinMois > 60) {
                $recommandations[] = [
                    'type' => 'info', 'icon' => 'calendar-range',
                    'titre' => 'Facturation concentrée en fin de mois',
                    'texte' => $pctFinMois . '% de la facturation tombe après le 20. Lisser les factures améliorerait la trésorerie.',
                    'categorie' => 'tresorerie', 'priorite' => 2,
                ];
            }
        }
    } catch (\PDOException $e) {}

    // ─── 3. MOIS DÉFICITAIRES RÉCURRENTS ────────────────────
    try {
        $stmt = $db->prepare("
            SELECT DATE_FORMAT(date_transaction, '%Y-%m') AS mois,
                   SUM(CASE WHEN type = 'recette' THEN montant ELSE 0 END) AS recettes,
                   SUM(CASE WHEN type = 'depense' THEN montant ELSE 0 END) AS depenses
            FROM transactions WHERE YEAR(date_transaction) = ?
            GROUP BY DATE_FORMAT(date_transaction, '%Y-%m')
            HAVING depenses > recettes
        ");
        $stmt->execute([$annee]);
        $moisDeficitaires = $stmt->fetchAll();
        if (count($moisDeficitaires) >= 3) {
            $recommandations[] = [
                'type' => 'danger', 'icon' => 'graph-down',
                'titre' => count($moisDeficitaires) . ' mois déficitaires cette année',
                'texte' => 'Identifier les charges fixes à réduire ou augmenter le volume de facturation pourrait inverser la tendance.',
                'categorie' => 'comptabilite', 'priorite' => 5,
            ];
        }
    } catch (\PDOException $e) {}

    // ─── 4. OPTIMISATION FISCALE MICRO ──────────────────────
    if ($conf['is_micro']) {
        $caActuel = $depensesActuelles = 0;
        try {
            $stmt = $db->prepare("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE type = 'recette' AND YEAR(date_transaction) = ?");
            $stmt->execute([$annee]);
            $caActuel = (float) $stmt->fetchColumn();
            $stmt = $db->prepare("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE type = 'depense' AND YEAR(date_transaction) = ?");
            $stmt->execute([$annee]);
            $depensesActuelles = (float) $stmt->fetchColumn();
        } catch (\PDOException $e) {}
        $tauxAbattement = (float) getParam('taux_abattement', '0');
        $abattementReel = $caActuel > 0 ? round($depensesActuelles / $caActuel * 100, 1) : 0;
        if ($tauxAbattement > 0 && $abattementReel > $tauxAbattement + 10) {
            $recommandations[] = [
                'type' => 'info', 'icon' => 'lightbulb',
                'titre' => 'Passage au réel potentiellement avantageux',
                'texte' => 'Charges réelles (' . $abattementReel . '%) > abattement forfaitaire (' . $tauxAbattement . '%). Simulez un passage au régime réel.',
                'categorie' => 'fiscal', 'priorite' => 3,
            ];
        }
    }

    // ─── 5. DÉLAI MOYEN DE PAIEMENT ÉLEVÉ ───────────────────
    try {
        $stmt = $db->prepare("
            SELECT AVG(DATEDIFF(
                COALESCE((SELECT MIN(p.date_paiement) FROM paiements p WHERE p.facture_id = f.id), CURDATE()),
                f.date_facture
            )) AS delai_moyen
            FROM factures f
            WHERE YEAR(f.date_facture) = ? AND f.statut NOT IN ('annulee', 'brouillon')
        ");
        $stmt->execute([$annee]);
        $delaiMoyen = round((float) ($stmt->fetchColumn() ?: 0));
        if ($delaiMoyen > 45) {
            $recommandations[] = [
                'type' => 'warning', 'icon' => 'hourglass-split',
                'titre' => 'Délai de paiement élevé : ' . $delaiMoyen . ' jours',
                'texte' => 'Les clients mettent en moyenne ' . $delaiMoyen . 'j à payer. Envisagez des relances automatiques ou un acompte systématique.',
                'categorie' => 'tresorerie', 'priorite' => 4,
            ];
        }
    } catch (\PDOException $e) {}

    // ─── 6. DEVIS NON RELANCÉS (en attente > 15 jours) ─────
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM devis
            WHERE statut = 'envoye' AND DATEDIFF(CURDATE(), date_devis) > 15
        ");
        $stmt->execute();
        $devisEnAttente = (int) $stmt->fetchColumn();
        if ($devisEnAttente > 0) {
            $recommandations[] = [
                'type' => 'warning', 'icon' => 'send-exclamation',
                'titre' => $devisEnAttente . ' devis en attente depuis +15 jours',
                'texte' => 'Des devis envoyés attendent une réponse. Une relance pourrait déclencher des commandes.',
                'lien' => 'devis.php?statut=envoye',
                'categorie' => 'commercial', 'priorite' => 4,
            ];
        }
    } catch (\PDOException $e) {}

    // ─── 7. CLIENTS DORMANTS À RÉACTIVER ────────────────────
    try {
        $stmt = $db->query("
            SELECT c.id,
                   COALESCE(c.entreprise, CONCAT(c.prenom, ' ', c.nom)) AS nom_client,
                   MAX(f.date_facture) AS derniere_facture,
                   DATEDIFF(CURDATE(), MAX(f.date_facture)) AS jours_inactif,
                   SUM(f.montant_ttc) AS ca_historique
            FROM clients c
            JOIN factures f ON f.client_id = c.id AND f.statut NOT IN ('annulee', 'brouillon')
            GROUP BY c.id
            HAVING jours_inactif BETWEEN 90 AND 365 AND ca_historique > 500
            ORDER BY ca_historique DESC
            LIMIT 5
        ");
        $dormants = $stmt->fetchAll();
        if (count($dormants) >= 1) {
            $noms = array_slice(array_column($dormants, 'nom_client'), 0, 3);
            $caPerdu = array_sum(array_column($dormants, 'ca_historique'));
            $recommandations[] = [
                'type' => 'info', 'icon' => 'person-plus',
                'titre' => count($dormants) . ' client(s) à réactiver (' . formatMontant($caPerdu) . ' de CA historique)',
                'texte' => implode(', ', $noms) . (count($dormants) > 3 ? '...' : '') . ' — Inactifs depuis 3+ mois. Un message ou une offre ciblée pourrait relancer la relation.',
                'lien' => 'clients.php',
                'categorie' => 'commercial', 'priorite' => 3,
            ];
        }
    } catch (\PDOException $e) {}

    // ─── 8. SAISONNALITÉ DÉTECTÉE (N-1) ─────────────────────
    try {
        $anneePrec = $annee - 1;
        $stmt = $db->prepare("
            SELECT MONTH(date_transaction) AS mois, COALESCE(SUM(montant), 0) AS total
            FROM transactions WHERE type = 'recette' AND YEAR(date_transaction) = ?
            GROUP BY MONTH(date_transaction) ORDER BY mois
        ");
        $stmt->execute([$anneePrec]);
        $prevYear = [];
        foreach ($stmt->fetchAll() as $r) $prevYear[(int)$r['mois']] = (float)$r['total'];

        if (count($prevYear) >= 6) {
            $avg = array_sum($prevYear) / count($prevYear);
            $moisForts = $moisFaibles = [];
            foreach ($prevYear as $m => $v) {
                if ($v > $avg * 1.4) $moisForts[] = $m;
                if ($v < $avg * 0.5 && $v > 0) $moisFaibles[] = $m;
            }
            $nomsMois = [1=>'Jan',2=>'Fév',3=>'Mar',4=>'Avr',5=>'Mai',6=>'Juin',7=>'Juil',8=>'Aoû',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Déc'];

            if (!empty($moisForts)) {
                $prochainFort = null;
                foreach ($moisForts as $m) {
                    if ($m > $moisCourant) { $prochainFort = $m; break; }
                }
                if ($prochainFort) {
                    $recommandations[] = [
                        'type' => 'success', 'icon' => 'calendar-event',
                        'titre' => 'Pic d\'activité prévu en ' . $nomsMois[$prochainFort],
                        'texte' => 'En ' . $anneePrec . ', ' . $nomsMois[$prochainFort] . ' était un mois fort. Préparez vos devis et stocks en avance.',
                        'categorie' => 'prevision', 'priorite' => 2,
                    ];
                }
            }
            if (!empty($moisFaibles)) {
                $prochainFaible = null;
                foreach ($moisFaibles as $m) {
                    if ($m > $moisCourant) { $prochainFaible = $m; break; }
                }
                if ($prochainFaible) {
                    $recommandations[] = [
                        'type' => 'info', 'icon' => 'calendar-minus',
                        'titre' => 'Creux saisonnier attendu en ' . $nomsMois[$prochainFaible],
                        'texte' => 'Historiquement, ce mois est faible. Anticipez en facturation ou réduisez les dépenses non essentielles.',
                        'categorie' => 'prevision', 'priorite' => 2,
                    ];
                }
            }
        }
    } catch (\PDOException $e) {}

    // ─── 9. DÉPENSE RÉCURRENTE ANORMALE ─────────────────────
    try {
        $stmt = $db->prepare("
            SELECT description, COUNT(*) AS nb, AVG(montant) AS moy, MAX(montant) AS maxi, MIN(montant) AS mini
            FROM transactions
            WHERE type = 'depense' AND YEAR(date_transaction) = ?
            GROUP BY LOWER(TRIM(description))
            HAVING nb >= 3 AND (maxi - mini) > moy * 0.5 AND moy > 50
            ORDER BY (maxi - moy) DESC
            LIMIT 3
        ");
        $stmt->execute([$annee]);
        $anomalies = $stmt->fetchAll();
        foreach ($anomalies as $a) {
            $ecart = round((float)$a['maxi'] - (float)$a['moy'], 2);
            $recommandations[] = [
                'type' => 'warning', 'icon' => 'exclamation-diamond',
                'titre' => 'Variation de dépense : « ' . mb_substr($a['description'], 0, 40) . ' »',
                'texte' => 'Montant max (' . formatMontant($a['maxi']) . ') dépasse la moyenne (' . formatMontant($a['moy']) . ') de ' . formatMontant($ecart) . '. Vérifiez s\'il s\'agit d\'une erreur.',
                'categorie' => 'comptabilite', 'priorite' => 3,
            ];
        }
    } catch (\PDOException $e) {}

    // ─── 10. TAUX DE CONVERSION DEVIS → FACTURE ─────────────
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM devis WHERE YEAR(date_devis) = ? AND statut NOT IN ('brouillon')");
        $stmt->execute([$annee]);
        $totalDevis = (int) $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM devis WHERE YEAR(date_devis) = ? AND statut = 'accepte'");
        $stmt->execute([$annee]);
        $devisAcceptes = (int) $stmt->fetchColumn();
        if ($totalDevis >= 5) {
            $tauxConversion = round($devisAcceptes / $totalDevis * 100);
            if ($tauxConversion < 40) {
                $recommandations[] = [
                    'type' => 'warning', 'icon' => 'funnel',
                    'titre' => 'Taux de conversion devis faible : ' . $tauxConversion . '%',
                    'texte' => 'Seulement ' . $devisAcceptes . '/' . $totalDevis . ' devis acceptés. Revoyez le positionnement tarifaire ou le suivi commercial.',
                    'categorie' => 'commercial', 'priorite' => 4,
                ];
            } elseif ($tauxConversion > 80) {
                $recommandations[] = [
                    'type' => 'success', 'icon' => 'trophy',
                    'titre' => 'Excellent taux de conversion : ' . $tauxConversion . '%',
                    'texte' => 'Vos devis convertissent très bien. Vous pourriez tester une hausse tarifaire progressive.',
                    'categorie' => 'commercial', 'priorite' => 1,
                ];
            }
        }
    } catch (\PDOException $e) {}

    // ─── 11. STOCK CRITIQUE — PRÉVISION RUPTURE ─────────────
    try {
        $stmt = $db->query("
            SELECT p.id, p.nom, p.stock_actuel, p.seuil_alerte,
                COALESCE((
                    SELECT SUM(lf.quantite)
                    FROM lignes_factures lf
                    JOIN factures f ON f.id = lf.facture_id
                    WHERE lf.produit_id = p.id AND f.date_facture >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                ), 0) AS ventes_90j
            FROM produits p
            WHERE p.gestion_stock = 1 AND p.actif = 1 AND p.stock_actuel > 0
            HAVING ventes_90j > 0
        ");
        $produits = $stmt->fetchAll();
        foreach ($produits as $p) {
            $vitesseJour = (float)$p['ventes_90j'] / 90;
            if ($vitesseJour > 0) {
                $joursRestants = round((float)$p['stock_actuel'] / $vitesseJour);
                if ($joursRestants <= 14) {
                    $recommandations[] = [
                        'type' => 'danger', 'icon' => 'box-seam',
                        'titre' => 'Rupture de « ' . mb_substr($p['nom'], 0, 30) . ' » dans ~' . $joursRestants . 'j',
                        'texte' => 'Au rythme actuel (' . round($vitesseJour, 1) . '/jour), le stock sera épuisé sous 2 semaines. Commandez maintenant.',
                        'lien' => 'produits.php',
                        'categorie' => 'stock', 'priorite' => 5,
                    ];
                } elseif ($joursRestants <= 30) {
                    $recommandations[] = [
                        'type' => 'warning', 'icon' => 'box-seam',
                        'titre' => '« ' . mb_substr($p['nom'], 0, 30) . ' » : stock pour ~' . $joursRestants . 'j',
                        'texte' => 'Anticipez la commande fournisseur pour éviter une rupture le mois prochain.',
                        'lien' => 'produits.php',
                        'categorie' => 'stock', 'priorite' => 3,
                    ];
                }
            }
        }
    } catch (\PDOException $e) {}

    // ─── 12. PROGRESSION DU CA vs OBJECTIF MENSUEL ──────────
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(montant), 0) AS ca_mois
            FROM transactions
            WHERE type = 'recette' AND YEAR(date_transaction) = ? AND MONTH(date_transaction) = ?
        ");
        $stmt->execute([$annee, $moisCourant]);
        $caMois = (float) $stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT AVG(ca) AS moy FROM (
                SELECT COALESCE(SUM(montant), 0) AS ca
                FROM transactions
                WHERE type = 'recette' AND YEAR(date_transaction) = ? AND MONTH(date_transaction) < ?
                GROUP BY MONTH(date_transaction)
            ) sub
        ");
        $stmt->execute([$annee, $moisCourant]);
        $caMoyMois = (float) ($stmt->fetchColumn() ?: 0);

        if ($caMoyMois > 0 && $moisCourant > 2) {
            $jourDuMois = (int) date('j');
            $joursDansMois = (int) date('t');
            $projectionMois = ($jourDuMois > 0) ? round($caMois * $joursDansMois / $jourDuMois, 2) : 0;
            $ecartPct = round(($projectionMois - $caMoyMois) / $caMoyMois * 100);

            if ($ecartPct < -25) {
                $recommandations[] = [
                    'type' => 'danger', 'icon' => 'graph-down-arrow',
                    'titre' => 'CA du mois en retard de ' . abs($ecartPct) . '%',
                    'texte' => 'Projection : ' . formatMontant($projectionMois) . ' vs moyenne ' . formatMontant($caMoyMois) . '. Accélérez la facturation.',
                    'lien' => 'factures.php?action=nouvelle',
                    'categorie' => 'commercial', 'priorite' => 5,
                ];
            } elseif ($ecartPct > 30) {
                $recommandations[] = [
                    'type' => 'success', 'icon' => 'graph-up-arrow',
                    'titre' => 'Mois en forte progression : +' . $ecartPct . '%',
                    'texte' => 'Le CA projeté (' . formatMontant($projectionMois) . ') dépasse largement la moyenne. Pensez à provisionner pour les charges.',
                    'categorie' => 'comptabilite', 'priorite' => 1,
                ];
            }
        }
    } catch (\PDOException $e) {}

    // ─── 13. FACTURES BIENTÔT EN RETARD ─────────────────────
    try {
        $stmt = $db->query("
            SELECT COUNT(*) AS nb, SUM(montant_ttc - COALESCE(montant_paye, 0)) AS montant
            FROM factures
            WHERE statut IN ('envoyee', 'partiellement_payee')
              AND date_echeance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $row = $stmt->fetch();
        if ($row && (int)$row['nb'] > 0) {
            $recommandations[] = [
                'type' => 'warning', 'icon' => 'clock-history',
                'titre' => (int)$row['nb'] . ' facture(s) échéance < 7 jours (' . formatMontant($row['montant']) . ')',
                'texte' => 'Envoyez une relance préventive maintenant pour éviter les impayés.',
                'lien' => 'factures.php',
                'categorie' => 'tresorerie', 'priorite' => 4,
            ];
        }
    } catch (\PDOException $e) {}

    // ─── 14. CATÉGORIES NON UTILISÉES ───────────────────────
    try {
        $stmt = $db->prepare("
            SELECT c.nom FROM categories c
            LEFT JOIN transactions t ON t.categorie_id = c.id AND YEAR(t.date_transaction) = ?
            WHERE t.id IS NULL AND c.actif = 1
        ");
        $stmt->execute([$annee]);
        $catsVides = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (count($catsVides) > 3) {
            $recommandations[] = [
                'type' => 'info', 'icon' => 'tags',
                'titre' => count($catsVides) . ' catégories non utilisées cette année',
                'texte' => 'Désactivez ou fusionnez les catégories inutiles pour simplifier la saisie : ' . implode(', ', array_slice($catsVides, 0, 4)) . '...',
                'lien' => 'categories.php',
                'categorie' => 'organisation', 'priorite' => 1,
            ];
        }
    } catch (\PDOException $e) {}

    // ─── 15. PANIER MOYEN EN BAISSE ─────────────────────────
    try {
        $stmt = $db->prepare("
            SELECT
                AVG(CASE WHEN MONTH(date_facture) <= 6 THEN montant_ttc END) AS s1,
                AVG(CASE WHEN MONTH(date_facture) > 6 THEN montant_ttc END) AS s2
            FROM factures
            WHERE YEAR(date_facture) = ? AND statut NOT IN ('annulee', 'brouillon')
        ");
        $stmt->execute([$annee]);
        $row = $stmt->fetch();
        if ($row && (float)$row['s1'] > 0 && (float)$row['s2'] > 0) {
            $evolution = round(((float)$row['s2'] - (float)$row['s1']) / (float)$row['s1'] * 100);
            if ($evolution < -15) {
                $recommandations[] = [
                    'type' => 'warning', 'icon' => 'cart-dash',
                    'titre' => 'Panier moyen en baisse de ' . abs($evolution) . '%',
                    'texte' => 'S1 : ' . formatMontant($row['s1']) . ' → S2 : ' . formatMontant($row['s2']) . '. Proposez des prestations complémentaires ou revoyez vos tarifs.',
                    'categorie' => 'commercial', 'priorite' => 3,
                ];
            }
        }
    } catch (\PDOException $e) {}

    // ─── 16. AUCUNE SAUVEGARDE / EXPORT RÉCENT ──────────────
    if (empty($recommandations)) {
        $recommandations[] = [
            'type' => 'success', 'icon' => 'check-circle',
            'titre' => 'Aucun problème détecté',
            'texte' => 'L\'analyse automatique ne détecte pas d\'anomalie. Continuez sur cette lancée !',
            'categorie' => 'general', 'priorite' => 0,
        ];
    }

    usort($recommandations, fn($a, $b) => ($b['priorite'] ?? 0) - ($a['priorite'] ?? 0));
    return $recommandations;
}
