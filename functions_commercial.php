<?php
/**
 * Fonctions CRUD pour les modules commerciaux
 * (clients, devis, factures, commandes, paiements)
 */

require_once __DIR__ . '/config.php';

// =============================================================
// CLIENTS
// =============================================================

function getClients(array $filtres = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filtres['recherche'])) {
        $where[] = '(nom LIKE ? OR prenom LIKE ? OR entreprise LIKE ? OR email LIKE ?)';
        $like = '%' . $filtres['recherche'] . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    if (!empty($filtres['type'])) {
        $where[] = 'type = ?';
        $params[] = $filtres['type'];
    }
    if (isset($filtres['actif'])) {
        $where[] = 'actif = ?';
        $params[] = (int)$filtres['actif'];
    } else {
        $where[] = 'actif = 1';
    }

    $sql = "SELECT * FROM clients WHERE " . implode(' AND ', $where) . " ORDER BY nom, prenom";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getClient(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function clientNomComplet(array $client): string {
    $parts = [];
    if (!empty($client['entreprise'])) $parts[] = $client['entreprise'];
    $nom = trim(($client['prenom'] ?? '') . ' ' . $client['nom']);
    if (!empty($nom) && $nom !== ($client['entreprise'] ?? '')) $parts[] = $nom;
    return implode(' — ', $parts);
}

function ajouterClient(array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO clients (type, nom, prenom, entreprise, email, telephone, adresse, code_postal, ville, pays, siret, siren, numero_tva, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['type'] ?? 'professionnel',
        $data['nom'],
        $data['prenom'] ?: null,
        $data['entreprise'] ?: null,
        $data['email'] ?: null,
        $data['telephone'] ?: null,
        $data['adresse'] ?: null,
        $data['code_postal'] ?: null,
        $data['ville'] ?: null,
        $data['pays'] ?? 'France',
        $data['siret'] ?: null,
        $data['siren'] ?: null,
        $data['numero_tva'] ?: null,
        $data['notes'] ?: null,
    ]);
    return (int)$db->lastInsertId();
}

function modifierClient(int $id, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE clients SET type=?, nom=?, prenom=?, entreprise=?, email=?, telephone=?, adresse=?, code_postal=?, ville=?, pays=?, siret=?, siren=?, numero_tva=?, notes=? WHERE id=?');
    $stmt->execute([
        $data['type'] ?? 'professionnel',
        $data['nom'],
        $data['prenom'] ?: null,
        $data['entreprise'] ?: null,
        $data['email'] ?: null,
        $data['telephone'] ?: null,
        $data['adresse'] ?: null,
        $data['code_postal'] ?: null,
        $data['ville'] ?: null,
        $data['pays'] ?? 'France',
        $data['siret'] ?: null,
        $data['siren'] ?: null,
        $data['numero_tva'] ?: null,
        $data['notes'] ?: null,
        $id,
    ]);
}

function supprimerClient(int $id): bool {
    $db = getDB();
    // Check if client has documents
    $stmt = $db->prepare('SELECT COUNT(*) FROM devis WHERE client_id = ? UNION ALL SELECT COUNT(*) FROM factures WHERE client_id = ? UNION ALL SELECT COUNT(*) FROM commandes WHERE client_id = ?');
    $stmt->execute([$id, $id, $id]);
    $counts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (array_sum($counts) > 0) {
        // Soft delete
        $stmt = $db->prepare('UPDATE clients SET actif = 0 WHERE id = ?');
        $stmt->execute([$id]);
        return false; // not hard deleted
    }
    $stmt = $db->prepare('DELETE FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    return true;
}

// =============================================================
// NUMÉROTATION AUTO
// =============================================================

function genererNumero(string $type): string {
    $db = getDB();
    $prefixe = getParam('prefixe_' . $type, strtoupper(substr($type, 0, 3)));
    $annee = date('Y');
    $table = $type === 'devis' ? 'devis' : ($type === 'facture' ? 'factures' : 'commandes');
    $lockName = 'gestion_compta_numero_' . $table;

    try {
        $db->prepare('SELECT GET_LOCK(?, 5)')->execute([$lockName]);
    } catch (Throwable $e) {
        // Si GET_LOCK n'est pas disponible, on continue avec le contrôle d'unicité.
    }

    try {
        $pattern = $prefixe . '-' . $annee . '-%';
        $stmt = $db->prepare("SELECT numero FROM {$table} WHERE numero LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$pattern]);
        $dernier = $stmt->fetchColumn();

        $seq = 1;
        if ($dernier) {
            $parts = explode('-', $dernier);
            $seq = (int) end($parts) + 1;
        }

        do {
            $numero = $prefixe . '-' . $annee . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $check = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE numero = ?");
            $check->execute([$numero]);
            $exists = (int) $check->fetchColumn() > 0;
            $seq++;
        } while ($exists);

        return $numero;
    } finally {
        try {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        } catch (Throwable $e) {
            // Ignorer silencieusement.
        }
    }
}

// =============================================================
// DEVIS
// =============================================================

function getDevisList(array $filtres = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filtres['statut'])) {
        $where[] = 'd.statut = ?';
        $params[] = $filtres['statut'];
    }
    if (!empty($filtres['client_id'])) {
        $where[] = 'd.client_id = ?';
        $params[] = (int)$filtres['client_id'];
    }
    if (!empty($filtres['annee'])) {
        $where[] = 'YEAR(d.date_devis) = ?';
        $params[] = (int)$filtres['annee'];
    }
    if (!empty($filtres['recherche'])) {
        $where[] = '(d.numero LIKE ? OR d.objet LIKE ? OR c.nom LIKE ? OR c.entreprise LIKE ?)';
        $like = '%' . $filtres['recherche'] . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    $sql = "SELECT d.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise
            FROM devis d
            JOIN clients c ON d.client_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY d.date_devis DESC, d.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getDevis(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT d.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise,
        c.adresse AS client_adresse, c.code_postal AS client_cp, c.ville AS client_ville,
        c.email AS client_email, c.telephone AS client_telephone, c.siret AS client_siret, c.numero_tva AS client_tva
        FROM devis d JOIN clients c ON d.client_id = c.id WHERE d.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getLignesDevis(int $devisId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM lignes_devis WHERE devis_id = ? ORDER BY ordre, id');
    $stmt->execute([$devisId]);
    return $stmt->fetchAll();
}

function sauvegarderDevis(array $data, array $lignes, ?int $id = null): int {
    $db = getDB();
    $db->beginTransaction();

    try {
        $totalHT = 0;
        $totalTVA = 0;
        foreach ($lignes as &$l) {
            $l['montant_ht'] = round($l['quantite'] * $l['prix_unitaire_ht'], 2);
            $l['montant_tva'] = round($l['montant_ht'] * ($l['taux_tva'] / 100), 2);
            $l['montant_ttc'] = $l['montant_ht'] + $l['montant_tva'];
            $totalHT += $l['montant_ht'];
            $totalTVA += $l['montant_tva'];
        }
        unset($l);
        $totalTTC = $totalHT + $totalTVA;

        if ($id) {
            $stmt = $db->prepare('UPDATE devis SET client_id=?, date_devis=?, date_validite=?, statut=?, objet=?, notes=?, conditions=?, montant_ht=?, montant_tva=?, montant_ttc=? WHERE id=?');
            $stmt->execute([
                $data['client_id'], $data['date_devis'], $data['date_validite'],
                $data['statut'], $data['objet'], $data['notes'] ?: null,
                $data['conditions'] ?: null, $totalHT, $totalTVA, $totalTTC, $id
            ]);
        } else {
            $stmt = $db->prepare('INSERT INTO devis (numero, client_id, date_devis, date_validite, statut, objet, notes, conditions, montant_ht, montant_tva, montant_ttc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $data['numero'] ?? genererNumero('devis'),
                $data['client_id'], $data['date_devis'], $data['date_validite'],
                $data['statut'] ?? 'brouillon', $data['objet'], $data['notes'] ?: null,
                $data['conditions'] ?: null, $totalHT, $totalTVA, $totalTTC
            ]);
            $id = (int) $db->lastInsertId();
        }

        $db->prepare('DELETE FROM lignes_devis WHERE devis_id = ?')->execute([$id]);
        $stmtL = $db->prepare('INSERT INTO lignes_devis (devis_id, description, quantite, unite, prix_unitaire_ht, taux_tva, montant_ht, montant_tva, montant_ttc, ordre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lignes as $i => $l) {
            $stmtL->execute([$id, $l['description'], $l['quantite'], $l['unite'] ?? 'unité', $l['prix_unitaire_ht'], $l['taux_tva'], $l['montant_ht'], $l['montant_tva'], $l['montant_ttc'], $i]);
        }

        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function supprimerDevis(int $id): void {
    $db = getDB();
    $db->prepare('DELETE FROM devis WHERE id = ?')->execute([$id]);
}

// =============================================================
// FACTURES
// =============================================================

function getFacturesList(array $filtres = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filtres['statut'])) {
        $where[] = 'f.statut = ?';
        $params[] = $filtres['statut'];
    }
    if (!empty($filtres['client_id'])) {
        $where[] = 'f.client_id = ?';
        $params[] = (int)$filtres['client_id'];
    }
    if (!empty($filtres['annee'])) {
        $where[] = 'YEAR(f.date_facture) = ?';
        $params[] = (int)$filtres['annee'];
    }
    if (!empty($filtres['recherche'])) {
        $where[] = '(f.numero LIKE ? OR f.objet LIKE ? OR c.nom LIKE ? OR c.entreprise LIKE ?)';
        $like = '%' . $filtres['recherche'] . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    $sql = "SELECT f.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise
            FROM factures f
            JOIN clients c ON f.client_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.date_facture DESC, f.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getFacture(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT f.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise,
        c.adresse AS client_adresse, c.code_postal AS client_cp, c.ville AS client_ville,
        c.email AS client_email, c.telephone AS client_telephone, c.siret AS client_siret, c.numero_tva AS client_tva,
        c.type AS client_type
        FROM factures f JOIN clients c ON f.client_id = c.id WHERE f.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getLignesFacture(int $factureId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM lignes_factures WHERE facture_id = ? ORDER BY ordre, id');
    $stmt->execute([$factureId]);
    return $stmt->fetchAll();
}

function sauvegarderFacture(array $data, array $lignes, ?int $id = null): int {
    $db = getDB();
    $db->beginTransaction();

    try {
        $totalHT = 0;
        $totalTVA = 0;
        foreach ($lignes as &$l) {
            $l['montant_ht'] = round($l['quantite'] * $l['prix_unitaire_ht'], 2);
            $l['montant_tva'] = round($l['montant_ht'] * ($l['taux_tva'] / 100), 2);
            $l['montant_ttc'] = $l['montant_ht'] + $l['montant_tva'];
            $totalHT += $l['montant_ht'];
            $totalTVA += $l['montant_tva'];
        }
        unset($l);
        $totalTTC = $totalHT + $totalTVA;

        if ($id) {
            $stmt = $db->prepare('UPDATE factures SET client_id=?, devis_id=?, commande_id=?, date_facture=?, date_echeance=?, statut=?, objet=?, notes=?, conditions=?, montant_ht=?, montant_tva=?, montant_ttc=? WHERE id=?');
            $stmt->execute([
                $data['client_id'], $data['devis_id'] ?: null, $data['commande_id'] ?: null,
                $data['date_facture'], $data['date_echeance'], $data['statut'],
                $data['objet'], $data['notes'] ?: null, $data['conditions'] ?: null,
                $totalHT, $totalTVA, $totalTTC, $id
            ]);
        } else {
            $stmt = $db->prepare('INSERT INTO factures (numero, client_id, devis_id, commande_id, date_facture, date_echeance, statut, objet, notes, conditions, montant_ht, montant_tva, montant_ttc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $data['numero'] ?? genererNumero('facture'),
                $data['client_id'], $data['devis_id'] ?: null, $data['commande_id'] ?: null,
                $data['date_facture'], $data['date_echeance'],
                $data['statut'] ?? 'brouillon', $data['objet'], $data['notes'] ?: null,
                $data['conditions'] ?: null, $totalHT, $totalTVA, $totalTTC
            ]);
            $id = (int) $db->lastInsertId();
        }

        $db->prepare('DELETE FROM lignes_factures WHERE facture_id = ?')->execute([$id]);
        $stmtL = $db->prepare('INSERT INTO lignes_factures (facture_id, description, quantite, unite, prix_unitaire_ht, taux_tva, montant_ht, montant_tva, montant_ttc, ordre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lignes as $i => $l) {
            $stmtL->execute([$id, $l['description'], $l['quantite'], $l['unite'] ?? 'unité', $l['prix_unitaire_ht'], $l['taux_tva'], $l['montant_ht'], $l['montant_tva'], $l['montant_ttc'], $i]);
        }

        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function supprimerFacture(int $id): void {
    $db = getDB();
    $db->prepare('DELETE FROM factures WHERE id = ?')->execute([$id]);
}

function mettreAJourStatutFacture(int $factureId): void {
    $db = getDB();
    $stmt = $db->prepare('SELECT montant_ttc, montant_paye, date_echeance, statut FROM factures WHERE id = ?');
    $stmt->execute([$factureId]);
    $f = $stmt->fetch();
    if (!$f || $f['statut'] === 'annulee') return;

    $paye = (float)$f['montant_paye'];
    $total = (float)$f['montant_ttc'];

    if ($paye >= $total) {
        $newStatut = 'payee';
    } elseif ($paye > 0) {
        $newStatut = 'partielle';
    } elseif ($f['date_echeance'] < date('Y-m-d') && $f['statut'] !== 'brouillon') {
        $newStatut = 'en_retard';
    } else {
        return;
    }

    $db->prepare('UPDATE factures SET statut = ? WHERE id = ?')->execute([$newStatut, $factureId]);
}

// =============================================================
// COMMANDES
// =============================================================

function getCommandesList(array $filtres = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filtres['statut'])) {
        $where[] = 'co.statut = ?';
        $params[] = $filtres['statut'];
    }
    if (!empty($filtres['client_id'])) {
        $where[] = 'co.client_id = ?';
        $params[] = (int)$filtres['client_id'];
    }
    if (!empty($filtres['recherche'])) {
        $where[] = '(co.numero LIKE ? OR co.objet LIKE ? OR c.nom LIKE ? OR c.entreprise LIKE ?)';
        $like = '%' . $filtres['recherche'] . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    $sql = "SELECT co.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise
            FROM commandes co
            JOIN clients c ON co.client_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY co.date_commande DESC, co.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getCommande(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT co.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise,
        c.adresse AS client_adresse, c.code_postal AS client_cp, c.ville AS client_ville
        FROM commandes co JOIN clients c ON co.client_id = c.id WHERE co.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getLignesCommande(int $commandeId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM lignes_commandes WHERE commande_id = ? ORDER BY ordre, id');
    $stmt->execute([$commandeId]);
    return $stmt->fetchAll();
}

function sauvegarderCommande(array $data, array $lignes, ?int $id = null): int {
    $db = getDB();
    $db->beginTransaction();

    try {
        $totalHT = 0;
        $totalTVA = 0;
        foreach ($lignes as &$l) {
            $l['montant_ht'] = round($l['quantite'] * $l['prix_unitaire_ht'], 2);
            $l['montant_tva'] = round($l['montant_ht'] * ($l['taux_tva'] / 100), 2);
            $l['montant_ttc'] = $l['montant_ht'] + $l['montant_tva'];
            $totalHT += $l['montant_ht'];
            $totalTVA += $l['montant_tva'];
        }
        unset($l);
        $totalTTC = $totalHT + $totalTVA;

        if ($id) {
            $stmt = $db->prepare('UPDATE commandes SET client_id=?, devis_id=?, date_commande=?, statut=?, objet=?, notes=?, montant_ht=?, montant_tva=?, montant_ttc=? WHERE id=?');
            $stmt->execute([
                $data['client_id'], $data['devis_id'] ?: null, $data['date_commande'],
                $data['statut'], $data['objet'], $data['notes'] ?: null,
                $totalHT, $totalTVA, $totalTTC, $id
            ]);
        } else {
            $stmt = $db->prepare('INSERT INTO commandes (numero, client_id, devis_id, date_commande, statut, objet, notes, montant_ht, montant_tva, montant_ttc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $data['numero'] ?? genererNumero('commande'),
                $data['client_id'], $data['devis_id'] ?: null, $data['date_commande'],
                $data['statut'] ?? 'en_attente', $data['objet'], $data['notes'] ?: null,
                $totalHT, $totalTVA, $totalTTC
            ]);
            $id = (int) $db->lastInsertId();
        }

        $db->prepare('DELETE FROM lignes_commandes WHERE commande_id = ?')->execute([$id]);
        $stmtL = $db->prepare('INSERT INTO lignes_commandes (commande_id, description, quantite, unite, prix_unitaire_ht, taux_tva, montant_ht, montant_tva, montant_ttc, ordre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lignes as $i => $l) {
            $stmtL->execute([$id, $l['description'], $l['quantite'], $l['unite'] ?? 'unité', $l['prix_unitaire_ht'], $l['taux_tva'], $l['montant_ht'], $l['montant_tva'], $l['montant_ttc'], $i]);
        }

        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function supprimerCommande(int $id): void {
    $db = getDB();
    $db->prepare('DELETE FROM commandes WHERE id = ?')->execute([$id]);
}

// =============================================================
// PAIEMENTS
// =============================================================

function getPaiementsFacture(int $factureId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM paiements WHERE facture_id = ? ORDER BY date_paiement DESC');
    $stmt->execute([$factureId]);
    return $stmt->fetchAll();
}

function getPaiementsList(array $filtres = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filtres['mode_paiement'])) {
        $where[] = 'p.mode_paiement = ?';
        $params[] = $filtres['mode_paiement'];
    }
    if (!empty($filtres['annee'])) {
        $where[] = 'YEAR(p.date_paiement) = ?';
        $params[] = (int)$filtres['annee'];
    }
    if (!empty($filtres['facture_id'])) {
        $where[] = 'p.facture_id = ?';
        $params[] = (int)$filtres['facture_id'];
    }

    $sql = "SELECT p.*, f.numero AS facture_numero, f.objet AS facture_objet,
            c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise
            FROM paiements p
            JOIN factures f ON p.facture_id = f.id
            JOIN clients c ON f.client_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.date_paiement DESC, p.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function ajouterPaiement(array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO paiements (facture_id, date_paiement, montant, mode_paiement, reference, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['facture_id'],
        $data['date_paiement'],
        $data['montant'],
        $data['mode_paiement'],
        $data['reference'] ?: null,
        $data['notes'] ?: null,
    ]);
    $paiementId = (int)$db->lastInsertId();

    // Update facture montant_paye
    $stmtSum = $db->prepare('SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE facture_id = ?');
    $stmtSum->execute([$data['facture_id']]);
    $totalPaye = (float)$stmtSum->fetchColumn();

    $db->prepare('UPDATE factures SET montant_paye = ? WHERE id = ?')->execute([$totalPaye, $data['facture_id']]);
    mettreAJourStatutFacture((int)$data['facture_id']);

    return $paiementId;
}

function supprimerPaiement(int $id): void {
    $db = getDB();
    $stmt = $db->prepare('SELECT facture_id FROM paiements WHERE id = ?');
    $stmt->execute([$id]);
    $factureId = (int)$stmt->fetchColumn();

    $db->prepare('DELETE FROM paiements WHERE id = ?')->execute([$id]);

    if ($factureId) {
        $stmtSum = $db->prepare('SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE facture_id = ?');
        $stmtSum->execute([$factureId]);
        $totalPaye = (float)$stmtSum->fetchColumn();
        $db->prepare('UPDATE factures SET montant_paye = ? WHERE id = ?')->execute([$totalPaye, $factureId]);
        mettreAJourStatutFacture($factureId);
    }
}

// =============================================================
// HELPERS COMMERCIAUX
// =============================================================

function getModePaiementLabel(string $mode): string {
    $labels = [
        'especes' => 'Espèces',
        'carte_bancaire' => 'Carte bancaire',
        'virement' => 'Virement',
        'cheque' => 'Chèque',
        'prelevement' => 'Prélèvement',
        'paypal' => 'PayPal',
        'autre' => 'Autre',
    ];
    return $labels[$mode] ?? ucfirst($mode);
}

function getStatutDevisLabel(string $statut): array {
    $map = [
        'brouillon' => ['label' => 'Brouillon', 'class' => 'secondary'],
        'envoye'    => ['label' => 'Envoyé', 'class' => 'primary'],
        'accepte'   => ['label' => 'Accepté', 'class' => 'success'],
        'refuse'    => ['label' => 'Refusé', 'class' => 'danger'],
        'expire'    => ['label' => 'Expiré', 'class' => 'warning'],
    ];
    return $map[$statut] ?? ['label' => ucfirst($statut), 'class' => 'secondary'];
}

function getStatutFactureLabel(string $statut): array {
    $map = [
        'brouillon'  => ['label' => 'Brouillon', 'class' => 'secondary'],
        'envoyee'    => ['label' => 'Envoyée', 'class' => 'primary'],
        'payee'      => ['label' => 'Payée', 'class' => 'success'],
        'partielle'  => ['label' => 'Partiellement payée', 'class' => 'warning'],
        'en_retard'  => ['label' => 'En retard', 'class' => 'danger'],
        'annulee'    => ['label' => 'Annulée', 'class' => 'dark'],
    ];
    return $map[$statut] ?? ['label' => ucfirst($statut), 'class' => 'secondary'];
}

function getStatutCommandeLabel(string $statut): array {
    $map = [
        'en_attente' => ['label' => 'En attente', 'class' => 'secondary'],
        'confirmee'  => ['label' => 'Confirmée', 'class' => 'primary'],
        'en_cours'   => ['label' => 'En cours', 'class' => 'info'],
        'livree'     => ['label' => 'Livrée', 'class' => 'success'],
        'annulee'    => ['label' => 'Annulée', 'class' => 'dark'],
    ];
    return $map[$statut] ?? ['label' => ucfirst($statut), 'class' => 'secondary'];
}

function convertirDevisEnFacture(int $devisId): int {
    $devis = getDevis($devisId);
    if (!$devis) throw new Exception('Devis introuvable');
    
    $lignesDevis = getLignesDevis($devisId);
    $validite = (int)getParam('conditions_paiement_jours', '30');
    
    $dataFacture = [
        'client_id' => $devis['client_id'],
        'devis_id' => $devisId,
        'commande_id' => null,
        'date_facture' => date('Y-m-d'),
        'date_echeance' => date('Y-m-d', strtotime("+{$validite} days")),
        'statut' => 'brouillon',
        'objet' => $devis['objet'],
        'notes' => $devis['notes'],
        'conditions' => $devis['conditions'] ?: getParam('conditions_paiement', ''),
    ];

    $lignes = [];
    foreach ($lignesDevis as $l) {
        $lignes[] = [
            'description' => $l['description'],
            'quantite' => $l['quantite'],
            'unite' => $l['unite'],
            'prix_unitaire_ht' => $l['prix_unitaire_ht'],
            'taux_tva' => $l['taux_tva'],
        ];
    }

    return sauvegarderFacture($dataFacture, $lignes);
}

function convertirDevisEnCommande(int $devisId): int {
    $devis = getDevis($devisId);
    if (!$devis) throw new Exception('Devis introuvable');
    
    $lignesDevis = getLignesDevis($devisId);
    
    $dataCmd = [
        'client_id' => $devis['client_id'],
        'devis_id' => $devisId,
        'date_commande' => date('Y-m-d'),
        'statut' => 'en_attente',
        'objet' => $devis['objet'],
        'notes' => $devis['notes'],
    ];

    $lignes = [];
    foreach ($lignesDevis as $l) {
        $lignes[] = [
            'description' => $l['description'],
            'quantite' => $l['quantite'],
            'unite' => $l['unite'],
            'prix_unitaire_ht' => $l['prix_unitaire_ht'],
            'taux_tva' => $l['taux_tva'],
        ];
    }

    return sauvegarderCommande($dataCmd, $lignes);
}

// =============================================================
// STATS COMMERCIALES
// =============================================================

function getStatsCommerciales(): array {
    $db = getDB();
    $annee = date('Y');
    
    $stats = [];
    
    // Devis
    $stmt = $db->prepare("SELECT COUNT(*) FROM devis WHERE YEAR(date_devis) = ?");
    $stmt->execute([$annee]);
    $stats['devis_total'] = (int)$stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM devis WHERE statut = 'accepte' AND YEAR(date_devis) = ?");
    $stmt->execute([$annee]);
    $stats['devis_acceptes'] = (int)$stmt->fetchColumn();
    
    // Factures
    $stmt = $db->prepare("SELECT COALESCE(SUM(montant_ttc), 0) FROM factures WHERE statut != 'annulee' AND YEAR(date_facture) = ?");
    $stmt->execute([$annee]);
    $stats['ca_facture'] = (float)$stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COALESCE(SUM(montant_ttc - montant_paye), 0) FROM factures WHERE statut IN ('envoyee', 'partielle', 'en_retard') AND YEAR(date_facture) = ?");
    $stmt->execute([$annee]);
    $stats['en_attente_paiement'] = (float)$stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM factures WHERE statut = 'en_retard'");
    $stmt->execute();
    $stats['factures_en_retard'] = (int)$stmt->fetchColumn();
    
    // Clients
    $stmt = $db->query("SELECT COUNT(*) FROM clients WHERE actif = 1");
    $stats['clients_actifs'] = (int)$stmt->fetchColumn();
    
    return $stats;
}
