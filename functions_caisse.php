<?php
/**
 * Fonctions CRUD pour la caisse et la gestion de stock
 */

require_once __DIR__ . '/config.php';

// =============================================================
// CATÉGORIES PRODUITS
// =============================================================

function getCategoriesProduits(bool $actifOnly = true): array {
    $db = getDB();
    $sql = 'SELECT * FROM categories_produits' . ($actifOnly ? ' WHERE actif = 1' : '') . ' ORDER BY nom';
    return $db->query($sql)->fetchAll();
}

function getCategorieProduit(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM categories_produits WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function ajouterCategorieProduit(array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO categories_produits (nom, description, couleur) VALUES (?, ?, ?)');
    $stmt->execute([$data['nom'], $data['description'] ?? null, $data['couleur'] ?? '#6c757d']);
    return (int)$db->lastInsertId();
}

function modifierCategorieProduit(int $id, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE categories_produits SET nom = ?, description = ?, couleur = ? WHERE id = ?');
    $stmt->execute([$data['nom'], $data['description'] ?? null, $data['couleur'] ?? '#6c757d', $id]);
}

// =============================================================
// PRODUITS
// =============================================================

function getProduits(array $filtres = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filtres['recherche'])) {
        $where[] = '(p.nom LIKE ? OR p.reference LIKE ? OR p.code_barres LIKE ?)';
        $like = '%' . $filtres['recherche'] . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }
    if (!empty($filtres['categorie_produit_id'])) {
        $where[] = 'p.categorie_produit_id = ?';
        $params[] = (int)$filtres['categorie_produit_id'];
    }
    if (isset($filtres['actif'])) {
        $where[] = 'p.actif = ?';
        $params[] = (int)$filtres['actif'];
    } else {
        $where[] = 'p.actif = 1';
    }
    if (!empty($filtres['stock_bas'])) {
        $where[] = 'p.gestion_stock = 1 AND p.stock_actuel <= p.stock_minimum';
    }

    $sql = "SELECT p.*, cp.nom AS categorie_nom, cp.couleur AS categorie_couleur 
            FROM produits p 
            LEFT JOIN categories_produits cp ON p.categorie_produit_id = cp.id 
            WHERE " . implode(' AND ', $where) . " 
            ORDER BY p.nom";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getProduit(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT p.*, cp.nom AS categorie_nom FROM produits p LEFT JOIN categories_produits cp ON p.categorie_produit_id = cp.id WHERE p.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getProduitParCodeBarres(string $code): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM produits WHERE code_barres = ? AND actif = 1');
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function ajouterProduit(array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO produits (reference, code_barres, nom, description, categorie_produit_id, prix_achat, prix_vente, taux_tva, unite, stock_actuel, stock_minimum, gestion_stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['reference'] ?? null,
        $data['code_barres'] ?? null,
        $data['nom'],
        $data['description'] ?? null,
        !empty($data['categorie_produit_id']) ? (int)$data['categorie_produit_id'] : null,
        (float)($data['prix_achat'] ?? 0),
        (float)$data['prix_vente'],
        (float)($data['taux_tva'] ?? 0),
        $data['unite'] ?? 'pièce',
        (float)($data['stock_actuel'] ?? 0),
        (float)($data['stock_minimum'] ?? 0),
        isset($data['gestion_stock']) ? 1 : 0
    ]);
    $id = (int)$db->lastInsertId();

    // Mouvement de stock initial si stock > 0
    if ((float)($data['stock_actuel'] ?? 0) > 0) {
        ajouterMouvementStock($id, 'entree', (float)$data['stock_actuel'], 'Stock initial');
    }

    return $id;
}

function modifierProduit(int $id, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE produits SET reference = ?, code_barres = ?, nom = ?, description = ?, categorie_produit_id = ?, prix_achat = ?, prix_vente = ?, taux_tva = ?, unite = ?, stock_minimum = ?, gestion_stock = ? WHERE id = ?');
    $stmt->execute([
        $data['reference'] ?? null,
        $data['code_barres'] ?? null,
        $data['nom'],
        $data['description'] ?? null,
        !empty($data['categorie_produit_id']) ? (int)$data['categorie_produit_id'] : null,
        (float)($data['prix_achat'] ?? 0),
        (float)$data['prix_vente'],
        (float)($data['taux_tva'] ?? 0),
        $data['unite'] ?? 'pièce',
        (float)($data['stock_minimum'] ?? 0),
        isset($data['gestion_stock']) ? 1 : 0,
        $id
    ]);
}

function supprimerProduit(int $id): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE produits SET actif = 0 WHERE id = ?');
    $stmt->execute([$id]);
}

// =============================================================
// STOCK
// =============================================================

function ajouterMouvementStock(int $produitId, string $type, float $quantite, string $notes = '', string $reference = ''): void {
    $db = getDB();
    $produit = getProduit($produitId);
    if (!$produit || !$produit['gestion_stock'] || $quantite < 0) return;

    $stockAvant = (float)$produit['stock_actuel'];
    if (in_array($type, ['sortie', 'vente'], true)) {
        $stockApres = max(0, $stockAvant - $quantite);
        if ($stockApres === 0.0 && $quantite > $stockAvant) {
            $notes = trim($notes . ' (stock insuffisant, ajusté à 0)');
        }
    } elseif ($type === 'inventaire') {
        $stockApres = max(0, $quantite); // quantité = nouveau stock
    } else {
        $stockApres = $stockAvant + $quantite;
    }

    $stmt = $db->prepare('INSERT INTO mouvements_stock (produit_id, type, quantite, stock_avant, stock_apres, reference, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$produitId, $type, $quantite, $stockAvant, $stockApres, $reference ?: null, $notes ?: null]);

    $stmt = $db->prepare('UPDATE produits SET stock_actuel = ? WHERE id = ?');
    $stmt->execute([$stockApres, $produitId]);
}

function getMouvementsStock(int $produitId = 0, int $limit = 50): array {
    $db = getDB();
    $where = '1=1';
    $params = [];
    if ($produitId) {
        $where = 'ms.produit_id = ?';
        $params[] = $produitId;
    }
    $sql = "SELECT ms.*, p.nom AS produit_nom FROM mouvements_stock ms LEFT JOIN produits p ON ms.produit_id = p.id WHERE $where ORDER BY ms.created_at DESC LIMIT $limit";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// =============================================================
// MOYENS DE PAIEMENT
// =============================================================

function getMoyensPaiementCaisse(bool $actifOnly = true): array {
    $db = getDB();
    $sql = 'SELECT * FROM moyens_paiement_caisse' . ($actifOnly ? ' WHERE actif = 1' : '') . ' ORDER BY ordre, nom';
    return $db->query($sql)->fetchAll();
}

function getMoyenPaiementCaisse(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM moyens_paiement_caisse WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// =============================================================
// NOTES DE CAISSE
// =============================================================

function genererNumeroNote(): string {
    $db = getDB();
    $prefix = 'NC-' . date('Ymd') . '-';
    $stmt = $db->prepare("SELECT numero FROM notes_caisse WHERE numero LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    if ($last) {
        $num = (int)substr($last, strlen($prefix)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function creerNoteCaisse(?int $clientId = null): int {
    $db = getDB();
    $numero = genererNumeroNote();
    $stmt = $db->prepare('INSERT INTO notes_caisse (numero, client_id, statut) VALUES (?, ?, ?)');
    $stmt->execute([$numero, $clientId, 'en_cours']);
    return (int)$db->lastInsertId();
}

function getNoteCaisse(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT nc.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise 
        FROM notes_caisse nc 
        LEFT JOIN clients c ON nc.client_id = c.id 
        WHERE nc.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getNotesCaisse(array $filtres = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filtres['statut'])) {
        $where[] = 'nc.statut = ?';
        $params[] = $filtres['statut'];
    }
    if (!empty($filtres['date'])) {
        $where[] = 'DATE(nc.date_note) = ?';
        $params[] = $filtres['date'];
    }
    if (!empty($filtres['date_debut'])) {
        $where[] = 'DATE(nc.date_note) >= ?';
        $params[] = $filtres['date_debut'];
    }
    if (!empty($filtres['date_fin'])) {
        $where[] = 'DATE(nc.date_note) <= ?';
        $params[] = $filtres['date_fin'];
    }
    if (!empty($filtres['recherche'])) {
        $where[] = '(nc.numero LIKE ? OR c.nom LIKE ? OR c.entreprise LIKE ?)';
        $like = '%' . $filtres['recherche'] . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }

    $sql = "SELECT nc.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise
            FROM notes_caisse nc
            LEFT JOIN clients c ON nc.client_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY nc.date_note DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getLignesNoteCaisse(int $noteId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT l.*, p.reference AS produit_reference FROM lignes_notes_caisse l LEFT JOIN produits p ON l.produit_id = p.id WHERE l.note_id = ? ORDER BY l.id');
    $stmt->execute([$noteId]);
    return $stmt->fetchAll();
}

function ajouterLigneNote(int $noteId, array $data): int {
    $db = getDB();
    $qte = (float)$data['quantite'];
    $pu = (float)$data['prix_unitaire'];
    $tva = (float)($data['taux_tva'] ?? 0);
    $ht = round($qte * $pu, 2);
    $mTva = round($ht * $tva / 100, 2);
    $ttc = $ht + $mTva;

    $stmt = $db->prepare('INSERT INTO lignes_notes_caisse (note_id, produit_id, designation, quantite, prix_unitaire, taux_tva, montant_ht, montant_tva, montant_ttc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $noteId,
        !empty($data['produit_id']) ? (int)$data['produit_id'] : null,
        $data['designation'],
        $qte, $pu, $tva, $ht, $mTva, $ttc
    ]);
    $ligneId = (int)$db->lastInsertId();
    recalculerTotauxNote($noteId);
    return $ligneId;
}

function modifierLigneNote(int $ligneId, array $data): void {
    $db = getDB();
    $qte = (float)$data['quantite'];
    $pu = (float)$data['prix_unitaire'];
    $tva = (float)($data['taux_tva'] ?? 0);
    $ht = round($qte * $pu, 2);
    $mTva = round($ht * $tva / 100, 2);
    $ttc = $ht + $mTva;

    $stmt = $db->prepare('UPDATE lignes_notes_caisse SET designation = ?, quantite = ?, prix_unitaire = ?, taux_tva = ?, montant_ht = ?, montant_tva = ?, montant_ttc = ? WHERE id = ?');
    $stmt->execute([$data['designation'], $qte, $pu, $tva, $ht, $mTva, $ttc, $ligneId]);

    // get note_id
    $stmt = $db->prepare('SELECT note_id FROM lignes_notes_caisse WHERE id = ?');
    $stmt->execute([$ligneId]);
    $noteId = (int)$stmt->fetchColumn();
    recalculerTotauxNote($noteId);
}

function supprimerLigneNote(int $ligneId): void {
    $db = getDB();
    $stmt = $db->prepare('SELECT note_id FROM lignes_notes_caisse WHERE id = ?');
    $stmt->execute([$ligneId]);
    $noteId = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('DELETE FROM lignes_notes_caisse WHERE id = ?');
    $stmt->execute([$ligneId]);
    recalculerTotauxNote($noteId);
}

function recalculerTotauxNote(int $noteId): void {
    $db = getDB();
    $stmt = $db->prepare('SELECT COALESCE(SUM(montant_ht),0), COALESCE(SUM(montant_tva),0), COALESCE(SUM(montant_ttc),0) FROM lignes_notes_caisse WHERE note_id = ?');
    $stmt->execute([$noteId]);
    [$ht, $tva, $ttc] = $stmt->fetch(PDO::FETCH_NUM);
    $stmt = $db->prepare('UPDATE notes_caisse SET total_ht = ?, total_tva = ?, total_ttc = ? WHERE id = ?');
    $stmt->execute([$ht, $tva, $ttc, $noteId]);
}

// =============================================================
// PAIEMENTS CAISSE
// =============================================================

function getPaiementsNote(int $noteId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT pc.*, mpc.nom AS moyen_nom, mpc.type AS moyen_type FROM paiements_caisse pc JOIN moyens_paiement_caisse mpc ON pc.moyen_paiement_id = mpc.id WHERE pc.note_id = ? ORDER BY pc.id');
    $stmt->execute([$noteId]);
    return $stmt->fetchAll();
}

function getTotalPayeNote(int $noteId): float {
    $db = getDB();
    $stmt = $db->prepare('SELECT COALESCE(SUM(montant), 0) FROM paiements_caisse WHERE note_id = ?');
    $stmt->execute([$noteId]);
    return (float)$stmt->fetchColumn();
}

function ajouterPaiementNote(int $noteId, int $moyenId, float $montant, string $reference = ''): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO paiements_caisse (note_id, moyen_paiement_id, montant, reference) VALUES (?, ?, ?, ?)');
    $stmt->execute([$noteId, $moyenId, $montant, $reference ?: null]);
    return (int)$db->lastInsertId();
}

function encaisserNote(int $noteId): bool {
    $note = getNoteCaisse($noteId);
    if (!$note || $note['statut'] !== 'en_cours') return false;

    $totalPaye = getTotalPayeNote($noteId);
    if ($totalPaye < (float)$note['total_ttc']) return false;

    $db = getDB();
    $stmt = $db->prepare("UPDATE notes_caisse SET statut = 'terminee' WHERE id = ?");
    $stmt->execute([$noteId]);

    // Décrémenter le stock pour chaque ligne
    $lignes = getLignesNoteCaisse($noteId);
    foreach ($lignes as $l) {
        if ($l['produit_id']) {
            ajouterMouvementStock((int)$l['produit_id'], 'vente', (float)$l['quantite'], 'Vente note ' . $note['numero'], 'note_' . $noteId);
        }
    }

    return true;
}

function annulerNote(int $noteId): bool {
    $note = getNoteCaisse($noteId);
    if (!$note) return false;

    $db = getDB();

    // Si la note était terminée, remettre le stock
    if ($note['statut'] === 'terminee') {
        $lignes = getLignesNoteCaisse($noteId);
        foreach ($lignes as $l) {
            if ($l['produit_id']) {
                ajouterMouvementStock((int)$l['produit_id'], 'annulation_vente', (float)$l['quantite'], 'Annulation note ' . $note['numero'], 'note_' . $noteId);
            }
        }
    }

    $stmt = $db->prepare("UPDATE notes_caisse SET statut = 'annulee' WHERE id = ?");
    $stmt->execute([$noteId]);
    return true;
}

// =============================================================
// INVENTAIRES
// =============================================================

function creerInventaire(string $date, string $notes = ''): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO inventaires (date_inventaire, notes) VALUES (?, ?)');
    $stmt->execute([$date, $notes ?: null]);
    return (int)$db->lastInsertId();
}

function getInventaire(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM inventaires WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getInventaires(): array {
    $db = getDB();
    return $db->query('SELECT i.*, (SELECT COUNT(*) FROM lignes_inventaire WHERE inventaire_id = i.id) AS nb_lignes FROM inventaires i ORDER BY i.date_inventaire DESC')->fetchAll();
}

function getLignesInventaire(int $inventaireId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT li.*, p.nom AS produit_nom, p.reference AS produit_reference FROM lignes_inventaire li JOIN produits p ON li.produit_id = p.id WHERE li.inventaire_id = ? ORDER BY p.nom');
    $stmt->execute([$inventaireId]);
    return $stmt->fetchAll();
}

function ajouterLigneInventaire(int $inventaireId, int $produitId, float $stockReel): void {
    $db = getDB();
    $produit = getProduit($produitId);
    $stockTheorique = $produit ? (float)$produit['stock_actuel'] : 0;
    $ecart = $stockReel - $stockTheorique;

    $stmt = $db->prepare('INSERT INTO lignes_inventaire (inventaire_id, produit_id, stock_theorique, stock_reel, ecart) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE stock_reel = VALUES(stock_reel), ecart = VALUES(ecart)');
    $stmt->execute([$inventaireId, $produitId, $stockTheorique, $stockReel, $ecart]);
}

function validerInventaire(int $inventaireId): bool {
    $db = getDB();
    $inv = getInventaire($inventaireId);
    if (!$inv || $inv['statut'] !== 'en_cours') return false;

    $lignes = getLignesInventaire($inventaireId);
    foreach ($lignes as $l) {
        if ((float)$l['ecart'] != 0) {
            ajouterMouvementStock((int)$l['produit_id'], 'inventaire', (float)$l['stock_reel'], 'Inventaire #' . $inventaireId, 'inventaire_' . $inventaireId);
        }
    }

    $stmt = $db->prepare("UPDATE inventaires SET statut = 'valide', validated_at = NOW() WHERE id = ?");
    $stmt->execute([$inventaireId]);
    return true;
}

// =============================================================
// CLÔTURES DE CAISSE
// =============================================================

function getClotureCaisse(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM clotures_caisse WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getCloturesCaisse(): array {
    $db = getDB();
    return $db->query('SELECT * FROM clotures_caisse ORDER BY date_cloture DESC')->fetchAll();
}

function getStatsCaisseJour(string $date): array {
    $db = getDB();

    $stmt = $db->prepare("SELECT COUNT(*) FROM notes_caisse WHERE DATE(date_note) = ? AND statut = 'terminee'");
    $stmt->execute([$date]);
    $nbNotes = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(total_ttc), 0) FROM notes_caisse WHERE DATE(date_note) = ? AND statut = 'terminee'");
    $stmt->execute([$date]);
    $totalVentes = (float)$stmt->fetchColumn();

    // Ventilation par moyen
    $stmt = $db->prepare("SELECT mpc.type, COALESCE(SUM(pc.montant), 0) AS total, COUNT(DISTINCT CASE WHEN mpc.type = 'cheque' THEN pc.id END) AS nb_cheques
        FROM paiements_caisse pc
        JOIN moyens_paiement_caisse mpc ON pc.moyen_paiement_id = mpc.id
        JOIN notes_caisse nc ON pc.note_id = nc.id
        WHERE DATE(nc.date_note) = ? AND nc.statut = 'terminee'
        GROUP BY mpc.type");
    $stmt->execute([$date]);
    $ventilation = ['especes' => 0, 'carte' => 0, 'cheque' => 0, 'virement' => 0, 'bon_achat' => 0, 'autre' => 0, 'nb_cheques' => 0];
    while ($row = $stmt->fetch()) {
        $ventilation[$row['type']] = (float)$row['total'];
        if ($row['type'] === 'cheque') $ventilation['nb_cheques'] = (int)$row['nb_cheques'];
    }

    return [
        'nb_notes' => $nbNotes,
        'total_ventes' => $totalVentes,
        'especes' => $ventilation['especes'],
        'carte' => $ventilation['carte'],
        'cheque' => $ventilation['cheque'],
        'nb_cheques' => $ventilation['nb_cheques'],
        'autre' => $ventilation['virement'] + $ventilation['bon_achat'] + $ventilation['autre'],
    ];
}

function cloturerCaisse(string $date, ?float $especesReel, ?int $nbChequesReel, string $notes = ''): int {
    $db = getDB();
    $stats = getStatsCaisseJour($date);

    $ecartEspeces = ($especesReel !== null) ? round($especesReel - $stats['especes'], 2) : 0;
    $ecartCheques = ($nbChequesReel !== null) ? $nbChequesReel - $stats['nb_cheques'] : 0;

    $stmt = $db->prepare('INSERT INTO clotures_caisse (date_cloture, nb_notes, total_ventes, montant_especes_theorique, montant_especes_reel, montant_carte, montant_cheque, nb_cheques_theorique, nb_cheques_reel, montant_autre, ecart_especes, ecart_cheques, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $date, $stats['nb_notes'], $stats['total_ventes'],
        $stats['especes'], $especesReel,
        $stats['carte'], $stats['cheque'],
        $stats['nb_cheques'], $nbChequesReel,
        $stats['autre'], $ecartEspeces, $ecartCheques,
        $notes ?: null
    ]);
    $clotureId = (int)$db->lastInsertId();

    // Marquer les notes comme clôturées
    $stmt = $db->prepare("UPDATE notes_caisse SET cloture_id = ? WHERE DATE(date_note) = ? AND statut = 'terminee'");
    $stmt->execute([$clotureId, $date]);

    return $clotureId;
}

function synchroComptaCloture(int $clotureId): bool {
    $db = getDB();
    $cloture = getClotureCaisse($clotureId);
    if (!$cloture || $cloture['synchro_compta']) return false;

    // Créer une transaction recette
    $desc = 'Caisse du ' . date('d/m/Y', strtotime($cloture['date_cloture'])) . ' (' . $cloture['nb_notes'] . ' ventes)';
    $stmt = $db->prepare("INSERT INTO transactions (date_transaction, type, description, montant, mode_paiement, reference) VALUES (?, 'recette', ?, ?, 'especes', ?)");
    $stmt->execute([$cloture['date_cloture'], $desc, $cloture['total_ventes'], 'cloture_caisse_' . $clotureId]);
    $transId = (int)$db->lastInsertId();

    $ecartTransId = null;
    // Écriture erreur de caisse si écart espèces
    if ((float)$cloture['ecart_especes'] != 0) {
        $type = $cloture['ecart_especes'] > 0 ? 'recette' : 'depense';
        $montant = abs($cloture['ecart_especes']);
        $descEcart = 'Erreur de caisse du ' . date('d/m/Y', strtotime($cloture['date_cloture']));
        $stmt = $db->prepare("INSERT INTO transactions (date_transaction, type, description, montant, mode_paiement, reference) VALUES (?, ?, ?, ?, 'especes', ?)");
        $stmt->execute([$cloture['date_cloture'], $type, $descEcart, $montant, 'erreur_caisse_' . $clotureId]);
        $ecartTransId = (int)$db->lastInsertId();
    }

    $stmt = $db->prepare('UPDATE clotures_caisse SET synchro_compta = 1, transaction_id = ?, transaction_ecart_id = ? WHERE id = ?');
    $stmt->execute([$transId, $ecartTransId, $clotureId]);

    return true;
}

// =============================================================
// STATISTIQUES CAISSE
// =============================================================

function getStatsCaisse(int $annee): array {
    $db = getDB();

    // Produits les plus vendus
    $stmt = $db->prepare("SELECT p.nom, SUM(l.quantite) AS total_qte, SUM(l.montant_ttc) AS total_ca
        FROM lignes_notes_caisse l
        JOIN notes_caisse nc ON l.note_id = nc.id
        LEFT JOIN produits p ON l.produit_id = p.id
        WHERE YEAR(nc.date_note) = ? AND nc.statut = 'terminee'
        GROUP BY l.produit_id
        ORDER BY total_ca DESC
        LIMIT 10");
    $stmt->execute([$annee]);
    $topProduits = $stmt->fetchAll();

    // Moyens de paiement
    $stmt = $db->prepare("SELECT mpc.nom, SUM(pc.montant) AS total
        FROM paiements_caisse pc
        JOIN moyens_paiement_caisse mpc ON pc.moyen_paiement_id = mpc.id
        JOIN notes_caisse nc ON pc.note_id = nc.id
        WHERE YEAR(nc.date_note) = ? AND nc.statut = 'terminee'
        GROUP BY mpc.id
        ORDER BY total DESC");
    $stmt->execute([$annee]);
    $topMoyens = $stmt->fetchAll();

    // CA mensuel caisse
    $stmt = $db->prepare("SELECT DATE_FORMAT(date_note, '%Y-%m') AS mois, SUM(total_ttc) AS ca
        FROM notes_caisse
        WHERE YEAR(date_note) = ? AND statut = 'terminee'
        GROUP BY mois ORDER BY mois");
    $stmt->execute([$annee]);
    $caMensuel = $stmt->fetchAll();

    return [
        'top_produits' => $topProduits,
        'top_moyens' => $topMoyens,
        'ca_mensuel' => $caMensuel,
    ];
}
