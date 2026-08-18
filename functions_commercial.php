<?php
/**
 * Fonctions CRUD pour les modules commerciaux
 * (clients, devis, factures, commandes, paiements)
 */

require_once __DIR__ . '/config.php';

function safeXmlValue(?string $value): string {
    $value = trim((string) $value);
    // Retire les caractères de contrôle interdits par XML 1.0 (hors tabulation,
    // saut de ligne, retour chariot) pour éviter une DOMException lors de la
    // génération des exports e-facture (UBL, Factur-X, PA).
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    // DOMDocument::createElement($name, $value) traite $value comme du XML
    // brut (pas comme du texte à échapper) : un "&" ou "<" non échappé y est
    // interprété comme un début d'entité/balise invalide, et PHP produit un
    // élément vide sans erreur visible, perdant silencieusement la donnée.
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/**
 * Calcule les montants HT/TVA/TTC de chaque ligne d'un document (devis,
 * facture, commande) ainsi que les totaux du document. Fonction pure,
 * sans accès base de données.
 */
function calculerLignesDocument(array $lignes): array {
    $totalHT = 0.0;
    $totalTVA = 0.0;

    foreach ($lignes as &$l) {
        $l['montant_ht'] = round((float) $l['quantite'] * (float) $l['prix_unitaire_ht'], 2);
        $l['montant_tva'] = round($l['montant_ht'] * ((float) $l['taux_tva'] / 100), 2);
        $l['montant_ttc'] = $l['montant_ht'] + $l['montant_tva'];
        $totalHT += $l['montant_ht'];
        $totalTVA += $l['montant_tva'];
    }
    unset($l);

    return [
        'lignes' => $lignes,
        'total_ht' => round($totalHT, 2),
        'total_tva' => round($totalTVA, 2),
        'total_ttc' => round($totalHT + $totalTVA, 2),
    ];
}

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
    if (!empty($filtres['limit'])) {
        $sql .= ' LIMIT ' . (int) $filtres['limit'] . ' OFFSET ' . (int) ($filtres['offset'] ?? 0);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Agrégats (compteur + montants) pour la liste des devis, indépendants de
// la pagination : évite de charger toutes les lignes juste pour les
// cartes de statistiques et le nombre total de résultats.
function getDevisStats(array $filtres = []): array {
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

    $sql = "SELECT COUNT(*) AS nb,
                   COALESCE(SUM(d.montant_ttc), 0) AS total_ttc,
                   COALESCE(SUM(CASE WHEN d.statut = 'accepte' THEN 1 ELSE 0 END), 0) AS nb_accepte,
                   COALESCE(SUM(CASE WHEN d.statut = 'envoye' THEN 1 ELSE 0 END), 0) AS nb_envoye,
                   COALESCE(SUM(CASE WHEN d.statut = 'brouillon' THEN 1 ELSE 0 END), 0) AS nb_brouillon
            FROM devis d
            JOIN clients c ON d.client_id = c.id
            WHERE " . implode(' AND ', $where);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return [
        'nb' => (int) $row['nb'],
        'total_ttc' => (float) $row['total_ttc'],
        'nb_accepte' => (int) $row['nb_accepte'],
        'nb_envoye' => (int) $row['nb_envoye'],
        'nb_brouillon' => (int) $row['nb_brouillon'],
    ];
}

function getDevis(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT d.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise,
        c.adresse AS client_adresse, c.code_postal AS client_cp, c.ville AS client_ville,
        c.email AS client_email, c.telephone AS client_telephone, c.siret AS client_siret, c.numero_tva AS client_tva,
        c.type AS client_type
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
        $calcul = calculerLignesDocument($lignes);
        $lignes = $calcul['lignes'];
        $totalHT = $calcul['total_ht'];
        $totalTVA = $calcul['total_tva'];
        $totalTTC = $calcul['total_ttc'];

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

    $sql = "SELECT f.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise, c.siren AS client_siren_source
            FROM factures f
            JOIN clients c ON f.client_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.date_facture DESC, f.id DESC";
    if (!empty($filtres['limit'])) {
        $sql .= ' LIMIT ' . (int) $filtres['limit'] . ' OFFSET ' . (int) ($filtres['offset'] ?? 0);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Agrégats (compteur + montants) pour la liste des factures, indépendants
// de la pagination — voir getDevisStats() pour la justification.
function getFacturesStats(array $filtres = []): array {
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

    $sql = "SELECT COUNT(*) AS nb,
                   COALESCE(SUM(f.montant_ttc), 0) AS total_ttc,
                   COALESCE(SUM(f.montant_paye), 0) AS total_paye,
                   COALESCE(SUM(CASE WHEN f.statut = 'en_retard' THEN 1 ELSE 0 END), 0) AS nb_en_retard,
                   COALESCE(SUM(CASE WHEN f.statut = 'envoyee' THEN 1 ELSE 0 END), 0) AS nb_envoyee
            FROM factures f
            JOIN clients c ON f.client_id = c.id
            WHERE " . implode(' AND ', $where);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return [
        'nb' => (int) $row['nb'],
        'total_ttc' => (float) $row['total_ttc'],
        'total_paye' => (float) $row['total_paye'],
        'nb_en_retard' => (int) $row['nb_en_retard'],
        'nb_envoyee' => (int) $row['nb_envoyee'],
    ];
}

function getFacture(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT f.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise,
        c.adresse AS client_adresse, c.code_postal AS client_cp, c.ville AS client_ville,
        c.email AS client_email, c.telephone AS client_telephone, c.siret AS client_siret, c.siren AS client_siren_source, c.numero_tva AS client_tva,
        c.type AS client_type
        FROM factures f JOIN clients c ON f.client_id = c.id WHERE f.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getFactureOperationTypeLabel(string $type): string {
    return match ($type) {
        'biens' => 'Livraison de biens',
        'mixte' => 'Biens et services',
        default => 'Prestation de services',
    };
}

function getFactureCircuitLabel(string $circuit): string {
    return match ($circuit) {
        'b2c' => 'B2C',
        'international' => 'International',
        'secteur_public' => 'Secteur public',
        default => 'B2B France',
    };
}

function getEInvoiceStatusLabel(string $status): array {
    return match ($status) {
        'prete' => ['label' => 'Prête', 'class' => 'info'],
        'a_transmettre' => ['label' => 'À transmettre', 'class' => 'warning text-dark'],
        'transmise' => ['label' => 'Transmise', 'class' => 'success'],
        'rejetee' => ['label' => 'Rejetée', 'class' => 'danger'],
        default => ['label' => 'Non préparée', 'class' => 'secondary'],
    };
}

function getFactureEInvoiceMissingFields(array $facture): array {
    $missing = [];

    if (empty($facture['numero'])) {
        $missing[] = 'numero';
    }
    if (empty($facture['date_facture'])) {
        $missing[] = 'date_facture';
    }
    if (empty($facture['date_echeance'])) {
        $missing[] = 'date_echeance';
    }
    if (empty($facture['objet'])) {
        $missing[] = 'objet';
    }
    if ((float) ($facture['montant_ttc'] ?? 0) <= 0) {
        $missing[] = 'montant_ttc';
    }
    if (empty($facture['client_id'])) {
        $missing[] = 'client';
    }

    $circuit = $facture['circuit_facturation'] ?? 'b2b_france';
    if ($circuit === 'b2b_france' && trim((string) ($facture['client_siren'] ?? $facture['client_siren_source'] ?? '')) === '') {
        $missing[] = 'client_siren';
    }

    if (trim((string) ($facture['type_operation'] ?? '')) === '') {
        $missing[] = 'type_operation';
    }

    if (trim((string) ($facture['einvoice_format'] ?? '')) === '') {
        $missing[] = 'einvoice_format';
    }

    return $missing;
}

function isFactureReadyForEInvoice(array $facture): bool {
    return getFactureEInvoiceMissingFields($facture) === [];
}

function setFactureEInvoiceStatus(int $factureId, string $status): void {
    $allowed = ['non_preparee', 'prete', 'a_transmettre', 'transmise', 'rejetee'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('Statut e-facture invalide.');
    }

    $facture = getFacture($factureId);
    if (!$facture) {
        throw new RuntimeException('Facture introuvable.');
    }

    if (in_array($status, ['prete', 'a_transmettre', 'transmise'], true) && !isFactureReadyForEInvoice($facture)) {
        throw new RuntimeException('La facture n\'est pas prête pour la facturation électronique.');
    }

    $db = getDB();
    $db->prepare('UPDATE factures SET einvoice_statut = ? WHERE id = ?')->execute([$status, $factureId]);
}

function buildFactureEInvoiceXml(int $factureId): string {
    $facture = getFacture($factureId);
    if (!$facture) {
        throw new RuntimeException('Facture introuvable.');
    }

    $lignes = getLignesFacture($factureId);
    $missing = getFactureEInvoiceMissingFields($facture);

    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;

    $root = $xml->createElement('gestionComptaEInvoice');
    $root->setAttribute('version', '1.0');
    $root->setAttribute('profile', 'preparation');
    $xml->appendChild($root);

    $meta = $xml->createElement('metadata');
    $meta->appendChild($xml->createElement('generatedAt', date('c')));
    $meta->appendChild($xml->createElement('targetFormat', (string) ($facture['einvoice_format'] ?? 'factur-x')));
    $meta->appendChild($xml->createElement('status', (string) ($facture['einvoice_statut'] ?? 'non_preparee')));
    $meta->appendChild($xml->createElement('circuit', (string) ($facture['circuit_facturation'] ?? 'b2b_france')));
    $root->appendChild($meta);

    $seller = $xml->createElement('seller');
    $seller->appendChild($xml->createElement('name', safeXmlValue(getParam('nom_entreprise', 'Mon Activité'))));
    $seller->appendChild($xml->createElement('siret', safeXmlValue(getParam('siret', ''))));
    $seller->appendChild($xml->createElement('address', safeXmlValue(getParam('adresse_entreprise', ''))));
    $root->appendChild($seller);

    $customer = $xml->createElement('customer');
    $customer->appendChild($xml->createElement('name', safeXmlValue((string) ($facture['client_entreprise'] ?: trim(($facture['client_prenom'] ?? '') . ' ' . ($facture['client_nom'] ?? ''))))));
    $customer->appendChild($xml->createElement('siren', safeXmlValue((string) ($facture['client_siren'] ?: ($facture['client_siren_source'] ?? '')))));
    $customer->appendChild($xml->createElement('siret', safeXmlValue((string) ($facture['client_siret'] ?? ''))));
    $customer->appendChild($xml->createElement('vatNumber', safeXmlValue((string) ($facture['client_tva'] ?? ''))));
    $customer->appendChild($xml->createElement('billingAddress', safeXmlValue(trim((string) (($facture['client_adresse'] ?? '') . ' ' . ($facture['client_cp'] ?? '') . ' ' . ($facture['client_ville'] ?? ''))))));
    $root->appendChild($customer);

    $delivery = $xml->createElement('delivery');
    $delivery->appendChild($xml->createElement('address', safeXmlValue((string) ($facture['adresse_livraison'] ?? ''))));
    $delivery->appendChild($xml->createElement('postalCode', safeXmlValue((string) ($facture['code_postal_livraison'] ?? ''))));
    $delivery->appendChild($xml->createElement('city', safeXmlValue((string) ($facture['ville_livraison'] ?? ''))));
    $delivery->appendChild($xml->createElement('country', safeXmlValue((string) ($facture['pays_livraison'] ?? ''))));
    $root->appendChild($delivery);

    $invoice = $xml->createElement('invoice');
    $invoice->appendChild($xml->createElement('number', safeXmlValue((string) $facture['numero'])));
    $invoice->appendChild($xml->createElement('issueDate', safeXmlValue((string) $facture['date_facture'])));
    $invoice->appendChild($xml->createElement('dueDate', safeXmlValue((string) $facture['date_echeance'])));
    $invoice->appendChild($xml->createElement('businessStatus', safeXmlValue((string) $facture['statut'])));
    $invoice->appendChild($xml->createElement('operationType', safeXmlValue((string) ($facture['type_operation'] ?? 'services'))));
    $invoice->appendChild($xml->createElement('subject', safeXmlValue((string) ($facture['objet'] ?? ''))));
    $invoice->appendChild($xml->createElement('notes', safeXmlValue((string) ($facture['notes'] ?? ''))));
    $invoice->appendChild($xml->createElement('platform', safeXmlValue((string) ($facture['einvoice_plateforme'] ?? ''))));
    $invoice->appendChild($xml->createElement('platformReference', safeXmlValue((string) ($facture['einvoice_reference'] ?? ''))));
    $root->appendChild($invoice);

    $totals = $xml->createElement('totals');
    $totals->appendChild($xml->createElement('amountHT', number_format((float) ($facture['montant_ht'] ?? 0), 2, '.', '')));
    $totals->appendChild($xml->createElement('amountTVA', number_format((float) ($facture['montant_tva'] ?? 0), 2, '.', '')));
    $totals->appendChild($xml->createElement('amountTTC', number_format((float) ($facture['montant_ttc'] ?? 0), 2, '.', '')));
    $totals->appendChild($xml->createElement('amountPaid', number_format((float) ($facture['montant_paye'] ?? 0), 2, '.', '')));
    $root->appendChild($totals);

    $linesNode = $xml->createElement('lines');
    foreach ($lignes as $index => $ligne) {
        $lineNode = $xml->createElement('line');
        $lineNode->appendChild($xml->createElement('position', (string) ($index + 1)));
        $lineNode->appendChild($xml->createElement('description', safeXmlValue((string) ($ligne['description'] ?? ''))));
        $lineNode->appendChild($xml->createElement('quantity', number_format((float) ($ligne['quantite'] ?? 0), 3, '.', '')));
        $lineNode->appendChild($xml->createElement('unit', safeXmlValue((string) ($ligne['unite'] ?? 'unité'))));
        $lineNode->appendChild($xml->createElement('unitPriceHT', number_format((float) ($ligne['prix_unitaire_ht'] ?? 0), 2, '.', '')));
        $lineNode->appendChild($xml->createElement('taxRate', number_format((float) ($ligne['taux_tva'] ?? 0), 2, '.', '')));
        $lineNode->appendChild($xml->createElement('lineAmountHT', number_format((float) ($ligne['montant_ht'] ?? 0), 2, '.', '')));
        $lineNode->appendChild($xml->createElement('lineAmountTTC', number_format((float) ($ligne['montant_ttc'] ?? 0), 2, '.', '')));
        $linesNode->appendChild($lineNode);
    }
    $root->appendChild($linesNode);

    if (!empty($missing)) {
        $issues = $xml->createElement('issues');
        foreach ($missing as $field) {
            $issues->appendChild($xml->createElement('missingField', $field));
        }
        $root->appendChild($issues);
    }

    return $xml->saveXML();
}

function getPdpConfig(): array {
    return [
        'enabled' => getParam('pdp_enabled', '0') === '1',
        'provider' => getParam('pdp_provider', 'generic_api'),
        'auto_send' => getParam('pdp_auto_send', '0') === '1',
        'endpoint_url' => trim(getParam('pdp_endpoint_url', '')),
        'auth_type' => getParam('pdp_auth_type', 'bearer_env'),
        'api_key_env' => trim(getParam('pdp_api_key_env', 'GESTION_COMPTA_PDP_API_KEY')),
        'custom_header_name' => trim(getParam('pdp_custom_header_name', 'X-API-Key')),
        'export_format' => getParam('pdp_export_format', 'ubl'),
    ];
}

function einvoiceStorageBasePath(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'einvoices';
}

function ensureEinvoiceStorageBase(): void {
    $base = einvoiceStorageBasePath();
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }
    // Défense en profondeur : ces fichiers contiennent des données client
    // (SIREN, adresse, montants) et ne doivent jamais être servis directement,
    // même si la règle du .htaccess racine n'est pas prise en compte.
    $htaccess = $base . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
}

function getTransmissionHistory(int $factureId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM einvoice_transmissions WHERE facture_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$factureId]);
    return $stmt->fetchAll();
}

function writeEinvoicePayloadToDisk(string $filename, string $content): string {
    ensureEinvoiceStorageBase();
    $path = einvoiceStorageBasePath() . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $content);
    return $path;
}

function buildFactureUblXml(int $factureId): string {
    $facture = getFacture($factureId);
    if (!$facture) {
        throw new RuntimeException('Facture introuvable.');
    }

    $lignes = getLignesFacture($factureId);
    $entreprise = [
        'nom' => getParam('nom_entreprise', 'Mon Activité'),
        'siret' => getParam('siret', ''),
        'adresse' => getParam('adresse_entreprise', ''),
        'pays' => 'FR',
    ];

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $invoice = $doc->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
    $invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $doc->appendChild($invoice);

    $invoice->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:CustomizationID', 'urn:cen.eu:en16931:2017'));
    $invoice->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0'));
    $invoice->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:ID', safeXmlValue($facture['numero'] ?? '')));
    $invoice->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:IssueDate', safeXmlValue($facture['date_facture'] ?? '')));
    $invoice->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:DueDate', safeXmlValue($facture['date_echeance'] ?? '')));
    $invoice->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:InvoiceTypeCode', '380'));
    $invoice->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:DocumentCurrencyCode', 'EUR'));
    $invoice->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:BuyerReference', safeXmlValue($facture['client_siren'] ?: ($facture['client_siren_source'] ?? 'CLIENT'))));

    $supplierParty = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:AccountingSupplierParty');
    $supplierPartyParty = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:Party');
    $supplierName = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:PartyName');
    $supplierName->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:Name', safeXmlValue($entreprise['nom'])));
    $supplierPartyParty->appendChild($supplierName);
    $supplierLegal = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:PartyLegalEntity');
    $supplierLegal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:RegistrationName', safeXmlValue($entreprise['nom'])));
    $supplierLegal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:CompanyID', safeXmlValue($entreprise['siret'])));
    $supplierPartyParty->appendChild($supplierLegal);
    $supplierParty->appendChild($supplierPartyParty);
    $invoice->appendChild($supplierParty);

    $customerParty = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:AccountingCustomerParty');
    $customerPartyParty = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:Party');
    $customerName = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:PartyName');
    $customerName->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:Name', safeXmlValue($facture['client_entreprise'] ?: trim(($facture['client_prenom'] ?? '') . ' ' . ($facture['client_nom'] ?? '')))));
    $customerPartyParty->appendChild($customerName);
    $customerLegal = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:PartyLegalEntity');
    $customerLegal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:RegistrationName', safeXmlValue($facture['client_entreprise'] ?: trim(($facture['client_prenom'] ?? '') . ' ' . ($facture['client_nom'] ?? '')))));
    $customerLegal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:CompanyID', safeXmlValue($facture['client_siren'] ?: ($facture['client_siren_source'] ?? ''))));
    $customerPartyParty->appendChild($customerLegal);
    $customerParty->appendChild($customerPartyParty);
    $invoice->appendChild($customerParty);

    foreach ($lignes as $index => $ligne) {
        $invoiceLine = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:InvoiceLine');
        $invoiceLine->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:ID', (string) ($index + 1)));
        $invoiceLine->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:InvoicedQuantity', number_format((float) ($ligne['quantite'] ?? 0), 3, '.', '')));
        $invoiceLine->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:LineExtensionAmount', number_format((float) ($ligne['montant_ht'] ?? 0), 2, '.', '')));

        $item = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:Item');
        $item->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:Description', safeXmlValue($ligne['description'] ?? '')));
        $invoiceLine->appendChild($item);

        $price = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:Price');
        $price->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:PriceAmount', number_format((float) ($ligne['prix_unitaire_ht'] ?? 0), 2, '.', '')));
        $invoiceLine->appendChild($price);

        $invoice->appendChild($invoiceLine);
    }

    $taxTotal = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:TaxTotal');
    $taxTotal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:TaxAmount', number_format((float) ($facture['montant_tva'] ?? 0), 2, '.', '')));
    $invoice->appendChild($taxTotal);

    $monetaryTotal = $doc->createElementNS($invoice->lookupNamespaceURI('cac'), 'cac:LegalMonetaryTotal');
    $monetaryTotal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:LineExtensionAmount', number_format((float) ($facture['montant_ht'] ?? 0), 2, '.', '')));
    $monetaryTotal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:TaxExclusiveAmount', number_format((float) ($facture['montant_ht'] ?? 0), 2, '.', '')));
    $monetaryTotal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:TaxInclusiveAmount', number_format((float) ($facture['montant_ttc'] ?? 0), 2, '.', '')));
    $monetaryTotal->appendChild($doc->createElementNS($invoice->lookupNamespaceURI('cbc'), 'cbc:PayableAmount', number_format((float) ($facture['montant_ttc'] ?? 0), 2, '.', '')));
    $invoice->appendChild($monetaryTotal);

    return $doc->saveXML();
}

function getEinvoiceExportContent(int $factureId, ?string $format = null): array {
    $facture = getFacture($factureId);
    if (!$facture) {
        throw new RuntimeException('Facture introuvable.');
    }

    $format = $format ?: ($facture['einvoice_format'] ?? getPdpConfig()['export_format']);
    if ($format === 'factur-x') {
        return [
            'format' => 'ubl',
            'content' => buildFactureUblXml($factureId),
            'extension' => 'xml',
            'actual_format' => 'ubl',
            'note' => 'Factur-X non encore supporté en sortie native ; export UBL généré à la place.',
        ];
    }

    if ($format === 'ubl') {
        return [
            'format' => 'ubl',
            'content' => buildFactureUblXml($factureId),
            'extension' => 'xml',
            'actual_format' => 'ubl',
            'note' => null,
        ];
    }

    return [
        'format' => 'xml',
        'content' => buildFactureEInvoiceXml($factureId),
        'extension' => 'xml',
        'actual_format' => 'xml',
        'note' => null,
    ];
}

function transmitFactureToPdp(int $factureId, bool $automatic = false): array {
    $facture = getFacture($factureId);
    if (!$facture) {
        throw new RuntimeException('Facture introuvable.');
    }

    if (!isFactureReadyForEInvoice($facture)) {
        throw new RuntimeException('La facture n\'est pas prête pour une transmission à la PA.');
    }

    $pdp = getPdpConfig();
    if (!$pdp['enabled']) {
        throw new RuntimeException('Le module PA n\'est pas activé dans les paramètres.');
    }
    if ($pdp['endpoint_url'] === '') {
        throw new RuntimeException('Aucune URL de PA configurée.');
    }

    $export = getEinvoiceExportContent($factureId, $pdp['export_format']);
    $payloadName = 'einvoice-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $facture['numero'] ?? ('facture-' . $factureId)) . '.' . $export['extension'];
    $payloadPath = writeEinvoicePayloadToDisk($payloadName, $export['content']);

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO einvoice_transmissions (facture_id, format, endpoint, payload_path, statut) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$factureId, $export['actual_format'], $pdp['endpoint_url'], $payloadPath, 'pending']);
    $transmissionId = (int) $db->lastInsertId();

    $headers = ['Content-Type: application/xml; charset=utf-8'];
    $apiKey = $pdp['api_key_env'] !== '' ? envValue($pdp['api_key_env'], '') : '';
    if ($pdp['auth_type'] === 'bearer_env' && $apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    } elseif ($pdp['auth_type'] === 'header_env' && $apiKey !== '') {
        $headers[] = ($pdp['custom_header_name'] ?: 'X-API-Key') . ': ' . $apiKey;
    }

    if (!function_exists('curl_init')) {
        $db->prepare('UPDATE einvoice_transmissions SET statut = ?, response_excerpt = ? WHERE id = ?')
            ->execute(['error', 'cURL indisponible sur le serveur.', $transmissionId]);
        throw new RuntimeException('cURL est indisponible sur le serveur PHP.');
    }

    $ch = curl_init($pdp['endpoint_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $export['content'],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $isSuccess = $curlError === '' && $httpCode >= 200 && $httpCode < 300;
    $excerpt = mb_substr($curlError !== '' ? $curlError : (string) $response, 0, 400);

    $db->prepare('UPDATE einvoice_transmissions SET statut = ?, http_code = ?, response_excerpt = ? WHERE id = ?')
        ->execute([$isSuccess ? 'success' : 'error', $httpCode ?: null, $excerpt !== '' ? $excerpt : null, $transmissionId]);

    if ($isSuccess) {
        $db->prepare('UPDATE factures SET einvoice_statut = ?, einvoice_reference = COALESCE(NULLIF(einvoice_reference, \'\'), ?) WHERE id = ?')
            ->execute(['transmise', 'TX-' . $transmissionId, $factureId]);
    } elseif (!$automatic) {
        throw new RuntimeException('Transmission PA échouée' . ($excerpt !== '' ? ' : ' . $excerpt : '.'));
    }

    return [
        'success' => $isSuccess,
        'http_code' => $httpCode,
        'response_excerpt' => $excerpt,
        'transmission_id' => $transmissionId,
        'payload_path' => $payloadPath,
        'note' => $export['note'],
    ];
}

function maybeAutoSendFactureToPdp(int $factureId): void {
    $pdp = getPdpConfig();
    if (!$pdp['enabled'] || !$pdp['auto_send']) {
        return;
    }

    $facture = getFacture($factureId);
    if (!$facture) {
        return;
    }

    if (($facture['statut'] ?? '') !== 'envoyee') {
        return;
    }

    $currentStatus = $facture['einvoice_statut'] ?? 'non_preparee';
    if (!in_array($currentStatus, ['prete', 'a_transmettre'], true)) {
        return;
    }

    try {
        transmitFactureToPdp($factureId, true);
    } catch (Throwable $e) {
        // Ne jamais bloquer le parcours métier.
    }
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
        $client = getClient((int) $data['client_id']);
        $clientSiren = trim((string) ($data['client_siren'] ?? ''));
        if ($clientSiren === '' && $client) {
            $clientSiren = trim((string) ($client['siren'] ?? ''));
        }

        $adresseLivraison = trim((string) ($data['adresse_livraison'] ?? ''));
        $codePostalLivraison = trim((string) ($data['code_postal_livraison'] ?? ''));
        $villeLivraison = trim((string) ($data['ville_livraison'] ?? ''));
        $paysLivraison = trim((string) ($data['pays_livraison'] ?? ''));

        if ($client && $adresseLivraison === '') {
            $adresseLivraison = trim((string) ($client['adresse'] ?? ''));
            $codePostalLivraison = trim((string) ($client['code_postal'] ?? ''));
            $villeLivraison = trim((string) ($client['ville'] ?? ''));
            $paysLivraison = trim((string) ($client['pays'] ?? 'France'));
        }

        $calcul = calculerLignesDocument($lignes);
        $lignes = $calcul['lignes'];
        $totalHT = $calcul['total_ht'];
        $totalTVA = $calcul['total_tva'];
        $totalTTC = $calcul['total_ttc'];

        if ($id) {
            $stmt = $db->prepare('UPDATE factures SET client_id=?, devis_id=?, commande_id=?, client_siren=?, adresse_livraison=?, code_postal_livraison=?, ville_livraison=?, pays_livraison=?, type_operation=?, circuit_facturation=?, einvoice_format=?, einvoice_statut=?, einvoice_plateforme=?, einvoice_reference=?, date_facture=?, date_echeance=?, statut=?, objet=?, notes=?, conditions=?, montant_ht=?, montant_tva=?, montant_ttc=? WHERE id=?');
            $stmt->execute([
                $data['client_id'], $data['devis_id'] ?: null, $data['commande_id'] ?: null,
                $clientSiren !== '' ? $clientSiren : null,
                $adresseLivraison !== '' ? $adresseLivraison : null,
                $codePostalLivraison !== '' ? $codePostalLivraison : null,
                $villeLivraison !== '' ? $villeLivraison : null,
                $paysLivraison !== '' ? $paysLivraison : null,
                $data['type_operation'] ?? 'services',
                $data['circuit_facturation'] ?? 'b2b_france',
                $data['einvoice_format'] ?? 'factur-x',
                $data['einvoice_statut'] ?? 'non_preparee',
                $data['einvoice_plateforme'] ?: null,
                $data['einvoice_reference'] ?: null,
                $data['date_facture'], $data['date_echeance'], $data['statut'],
                $data['objet'], $data['notes'] ?: null, $data['conditions'] ?: null,
                $totalHT, $totalTVA, $totalTTC, $id
            ]);
        } else {
            $stmt = $db->prepare('INSERT INTO factures (numero, client_id, devis_id, commande_id, client_siren, adresse_livraison, code_postal_livraison, ville_livraison, pays_livraison, type_operation, circuit_facturation, einvoice_format, einvoice_statut, einvoice_plateforme, einvoice_reference, date_facture, date_echeance, statut, objet, notes, conditions, montant_ht, montant_tva, montant_ttc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $data['numero'] ?? genererNumero('facture'),
                $data['client_id'], $data['devis_id'] ?: null, $data['commande_id'] ?: null,
                $clientSiren !== '' ? $clientSiren : null,
                $adresseLivraison !== '' ? $adresseLivraison : null,
                $codePostalLivraison !== '' ? $codePostalLivraison : null,
                $villeLivraison !== '' ? $villeLivraison : null,
                $paysLivraison !== '' ? $paysLivraison : null,
                $data['type_operation'] ?? 'services',
                $data['circuit_facturation'] ?? 'b2b_france',
                $data['einvoice_format'] ?? 'factur-x',
                $data['einvoice_statut'] ?? 'non_preparee',
                $data['einvoice_plateforme'] ?: null,
                $data['einvoice_reference'] ?: null,
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
    if (!empty($filtres['limit'])) {
        $sql .= ' LIMIT ' . (int) $filtres['limit'] . ' OFFSET ' . (int) ($filtres['offset'] ?? 0);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Agrégats (compteur + montants) pour la liste des commandes, indépendants
// de la pagination — voir getDevisStats() pour la justification.
function getCommandesStats(array $filtres = []): array {
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

    $sql = "SELECT COUNT(*) AS nb,
                   COALESCE(SUM(co.montant_ttc), 0) AS total_ttc,
                   COALESCE(SUM(CASE WHEN co.statut = 'en_attente' THEN 1 ELSE 0 END), 0) AS nb_en_attente,
                   COALESCE(SUM(CASE WHEN co.statut = 'confirmee' THEN 1 ELSE 0 END), 0) AS nb_confirmee,
                   COALESCE(SUM(CASE WHEN co.statut = 'livree' THEN 1 ELSE 0 END), 0) AS nb_livree
            FROM commandes co
            JOIN clients c ON co.client_id = c.id
            WHERE " . implode(' AND ', $where);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return [
        'nb' => (int) $row['nb'],
        'total_ttc' => (float) $row['total_ttc'],
        'nb_en_attente' => (int) $row['nb_en_attente'],
        'nb_confirmee' => (int) $row['nb_confirmee'],
        'nb_livree' => (int) $row['nb_livree'],
    ];
}

function getCommande(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT co.*, c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise,
        c.adresse AS client_adresse, c.code_postal AS client_cp, c.ville AS client_ville, c.type AS client_type
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
        $calcul = calculerLignesDocument($lignes);
        $lignes = $calcul['lignes'];
        $totalHT = $calcul['total_ht'];
        $totalTVA = $calcul['total_tva'];
        $totalTTC = $calcul['total_ttc'];

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

function genererConditionsDocumentVente(string $type, array $document = []): string {
    $societe = trim((string) getParam('nom_entreprise', 'Votre entreprise'));
    $activite = trim((string) getParam('activite', 'prestations et travaux'));
    $siret = trim((string) getParam('siret', ''));
    $conditionsPaiement = trim((string) getParam('conditions_paiement', 'Paiement à réception'));
    $validiteDevis = (int) getParam('validite_devis', '30');
    $acomptePct = (float) getParam('acompte_commande_pct', '30');
    $garantieMois = max(0, (int) getParam('garantie_sav_mois', '12'));
    $delaiLivraison = max(0, (int) getParam('delai_livraison_jours', '30'));
    $clauseChantierLivraison = trim((string) getParam('clause_chantier_livraison', ''));
    $clientProfessionnel = ($document['client_type'] ?? null) === 'professionnel' || !empty($document['client_entreprise']) || !empty($document['entreprise']);
    $dateValidite = !empty($document['date_validite']) ? formatDate($document['date_validite']) : null;
    $dateEcheance = !empty($document['date_echeance']) ? formatDate($document['date_echeance']) : null;
    $montantValue = isset($document['montant_ttc']) ? (float) $document['montant_ttc'] : null;
    $montant = $montantValue !== null ? formatMontant($montantValue) : null;
    $acompteMontant = ($montantValue !== null && $acomptePct > 0) ? formatMontant(round($montantValue * ($acomptePct / 100), 2)) : null;
    $typeLabel = match ($type) {
        'devis' => 'devis',
        'facture' => 'facture',
        'commande' => 'commande',
        default => 'document commercial',
    };
    $isTravaux = (bool) preg_match('/travaux|chantier|installation|renovation|maintenance|pose|intervention/i', $activite);

    $lignes = [];
    $lignes[] = "Document commercial émis par {$societe}" . ($siret !== '' ? " - SIRET {$siret}" : '') . ".";

    if ($type === 'devis') {
        $lignes[] = "Le devis est établi pour {$activite}" . ($dateValidite ? " et reste valable jusqu'au {$dateValidite}" : " et reste valable {$validiteDevis} jours") . ".";
        if ($acomptePct > 0) {
            $lignes[] = "La commande devient ferme après acceptation écrite du devis et versement d'un acompte de {$acomptePct} %" . ($acompteMontant ? ", soit {$acompteMontant}" : '') . ".";
        } else {
            $lignes[] = "La commande devient ferme après acceptation écrite du devis par le client.";
        }
    } elseif ($type === 'commande') {
        $lignes[] = "Toute commande validée vaut acceptation pleine et entière des présentes conditions et des prix convenus.";
        if ($acomptePct > 0) {
            $lignes[] = "Un acompte de {$acomptePct} %" . ($acompteMontant ? " du montant total, soit {$acompteMontant}," : '') . " peut être exigé à la confirmation de commande.";
        }
        $lignes[] = "Les délais d'exécution ou de livraison sont communiqués à titre indicatif, sauf engagement exprès mentionné sur le document.";
    } else {
        $lignes[] = "La facture correspond aux prestations, travaux ou fournitures réalisés et réceptionnés conformément aux éléments convenus.";
        $lignes[] = "Le règlement est attendu {$conditionsPaiement}" . ($dateEcheance ? ", avec échéance fixée au {$dateEcheance}" : '') . ".";
    }

    $lignes[] = "Toute prestation complémentaire, modification demandée en cours d'exécution ou travaux non prévus initialement peut faire l'objet d'un avenant ou d'une facturation complémentaire.";
    $lignes[] = "Le client s'engage à faciliter l'accès, la préparation et les conditions d'intervention nécessaires à la bonne réalisation des prestations ou travaux.";

    if ($clauseChantierLivraison !== '') {
        $lignes[] = $clauseChantierLivraison;
    } elseif ($isTravaux) {
        $lignes[] = "Pour les travaux sur site, le client garantit la disponibilité du chantier, l'accès aux zones d'intervention, la présence des alimentations utiles et l'absence d'obstacle non signalé avant le démarrage.";
        $lignes[] = "Toute interruption, attente, déplacement supplémentaire ou intervention rendue impossible du fait du client ou d'un tiers pourra entraîner une replanification et une facturation complémentaire.";
    } else {
        $lignes[] = "Les livraisons ou interventions sont organisées selon les disponibilités convenues. En cas d'absence, d'adresse incomplète ou d'impossibilité d'accès, une nouvelle présentation pourra être refacturée.";
        if ($delaiLivraison > 0) {
            $lignes[] = "Sauf mention contraire, le délai indicatif d'exécution ou de livraison est de {$delaiLivraison} jours à compter de la validation administrative et, le cas échéant, de l'encaissement de l'acompte.";
        }
    }

    if ($montant !== null) {
        $lignes[] = "Le montant global de ce {$typeLabel} est arrêté à {$montant} TTC, sauf ajustement validé par écrit en cas de modification du périmètre.";
    }

    $lignes[] = "Sauf mention contraire, aucun escompte n'est accordé pour paiement anticipé.";
    $lignes[] = "En cas de retard de paiement, des pénalités sont exigibles de plein droit sur la base du taux directeur de la BCE majoré de 10 points.";
    if ($clientProfessionnel) {
        $lignes[] = "Pour tout client professionnel, une indemnité forfaitaire de 40 EUR pour frais de recouvrement est également due de plein droit.";
        $lignes[] = "Toute réclamation d'un client professionnel relative à la conformité ou à l'exécution doit être formulée par écrit dans les meilleurs délais suivant la livraison ou la réception des travaux.";
        if ($garantieMois > 0) {
            $lignes[] = "Les prestations correctives relevant du SAV sont appréciées dans la limite de {$garantieMois} mois pour les défauts directement imputables à l'intervention initiale, hors usure normale, mauvais usage ou intervention extérieure.";
        }
    } else {
        if ($garantieMois > 0) {
            $lignes[] = "Le client particulier bénéficie, en complément des garanties légales applicables, d'un suivi SAV commercial pouvant aller jusqu'à {$garantieMois} mois sur les éléments posés ou réparés, hors consommables, usure normale, mauvaise utilisation ou défaut provenant d'un tiers.";
        }
        $lignes[] = "Pour les clients particuliers, les garanties légales de conformité et des vices cachés demeurent pleinement applicables conformément aux dispositions légales en vigueur.";
    }
    $lignes[] = "Les marchandises, fournitures ou éléments installés demeurent la propriété du vendeur jusqu'au paiement complet du prix lorsqu'une réserve de propriété est applicable.";
    $lignes[] = "Toute contestation relative au {$typeLabel} doit être formulée par écrit dans un délai raisonnable à compter de sa réception.";

    return implode("\n", $lignes);
}

function getConditionsDocumentVente(string $type, array $document = []): string {
    $custom = trim((string) ($document['conditions'] ?? ''));
    return $custom !== '' ? $custom : genererConditionsDocumentVente($type, $document);
}

function genererConditionsDocumentVenteCompletes(string $type, array $document = []): string
{
    $base = genererConditionsDocumentVente($type, $document);
    $societe = trim((string) getParam('nom_entreprise', 'Votre entreprise'));
    $clientProfessionnel = ($document['client_type'] ?? null) === 'professionnel' || !empty($document['client_entreprise']) || !empty($document['entreprise']);
    $garantieMois = max(0, (int) getParam('garantie_sav_mois', '12'));
    $acomptePct = (float) getParam('acompte_commande_pct', '30');

    $clauses = [];
    $clauses[] = "1. Champ d'application : les présentes conditions générales de vente s'appliquent à l'ensemble des devis, commandes, factures, prestations, travaux, fournitures et interventions réalisés par {$societe}, sauf conditions particulières acceptées par écrit.";
    $clauses[] = "2. Formation du contrat : le contrat est réputé formé à compter de l'acceptation du devis ou de la commande par le client. Toute demande complémentaire, modification de périmètre ou prestation additionnelle donnera lieu à validation préalable et, si nécessaire, à un ajustement de prix.";
    if ($acomptePct > 0) {
        $clauses[] = "3. Acompte : un acompte de {$acomptePct} % peut être demandé à la commande. L'exécution ou l'approvisionnement peut être suspendu tant que cet acompte n'est pas encaissé.";
    } else {
        $clauses[] = "3. Acompte : sauf mention contraire, aucun acompte n'est exigé avant démarrage. Le vendeur se réserve toutefois le droit d'en solliciter un pour les commandes spécifiques ou approvisionnements particuliers.";
    }
    $clauses[] = "4. Délais d'exécution et livraison : les délais communiqués sont donnés à titre indicatif sauf engagement exprès. Ils peuvent être ajustés en cas d'aléa technique, de retard d'approvisionnement, d'indisponibilité d'accès, d'absence du client, d'intempérie, de force majeure ou d'instruction complémentaire.";
    $clauses[] = "5. Réception et réserves : toute réserve ou contestation doit être formulée par écrit avec précision dès la livraison, la réception ou la découverte du désordre apparent. À défaut, la prestation est réputée acceptée sous réserve des garanties légales applicables.";
    if ($garantieMois > 0) {
        $clauses[] = "6. SAV et garantie commerciale : un accompagnement SAV peut être assuré pendant {$garantieMois} mois dans la limite des défauts directement imputables à l'intervention du vendeur, hors usure normale, consommables, mauvaise utilisation, défaut d'entretien ou intervention d'un tiers.";
    } else {
        $clauses[] = "6. SAV et garantie commerciale : toute demande de service après-vente est étudiée au cas par cas, sans préjudice des garanties légales éventuellement applicables.";
    }
    if ($clientProfessionnel) {
        $clauses[] = "7. Clients professionnels : le client professionnel reconnaît agir dans le cadre de son activité. En cas de retard de paiement, l'indemnité forfaitaire légale de 40 EUR pour frais de recouvrement est due de plein droit, sans préjudice de toute indemnisation complémentaire justifiée.";
    } else {
        $clauses[] = "7. Clients particuliers : le client particulier bénéficie, lorsque la loi le prévoit, des garanties légales de conformité et contre les vices cachés. Les présentes conditions ne limitent en rien ses droits d'ordre public.";
    }
    $clauses[] = "8. Responsabilité : la responsabilité du vendeur ne saurait être engagée en cas de dommage indirect, perte d'exploitation, perte de données, manque à gagner, ou conséquence résultant d'un usage non conforme, d'une information incomplète ou d'une intervention d'un tiers.";
    $clauses[] = "9. Données et documents : les documents commerciaux, plans, visuels, chiffrages, études et propositions remis au client restent la propriété intellectuelle du vendeur et ne peuvent être reproduits, communiqués ou utilisés sans accord préalable.";
    $clauses[] = "10. Droit applicable et litiges : les présentes CGV sont soumises au droit français. En cas de différend, les parties rechercheront d'abord une solution amiable avant toute procédure.";

    return $base . "\n\n" . implode("\n\n", $clauses);
}

function convertirDevisEnFacture(int $devisId): int {
    $devis = getDevis($devisId);
    if (!$devis) throw new Exception('Devis introuvable');
    
    $lignesDevis = getLignesDevis($devisId);
    $validite = (int)getParam('validite_devis', '30');
    
    $dataFacture = [
        'client_id' => $devis['client_id'],
        'devis_id' => $devisId,
        'commande_id' => null,
        'date_facture' => date('Y-m-d'),
        'date_echeance' => date('Y-m-d', strtotime("+{$validite} days")),
        'statut' => 'brouillon',
        'objet' => $devis['objet'],
        'notes' => $devis['notes'],
        'conditions' => getConditionsDocumentVente('facture', $devis),
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
