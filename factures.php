<?php
/**
 * Gestion des factures
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Factures';

$action = $_GET['action'] ?? 'liste';
$conf = getRegimeConfig();

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Erreur de sécurité.');
        header('Location: factures.php');
        exit;
    }
    
    $postAction = $_POST['post_action'] ?? '';
    
    if ($postAction === 'sauvegarder') {
        $data = [
            'client_id'     => (int)$_POST['client_id'],
            'devis_id'      => (int)($_POST['devis_id'] ?? 0) ?: null,
            'commande_id'   => (int)($_POST['commande_id'] ?? 0) ?: null,
            'client_siren'  => trim($_POST['client_siren'] ?? ''),
            'adresse_livraison' => trim($_POST['adresse_livraison'] ?? ''),
            'code_postal_livraison' => trim($_POST['code_postal_livraison'] ?? ''),
            'ville_livraison' => trim($_POST['ville_livraison'] ?? ''),
            'pays_livraison' => trim($_POST['pays_livraison'] ?? ''),
            'type_operation' => $_POST['type_operation'] ?? 'services',
            'circuit_facturation' => $_POST['circuit_facturation'] ?? 'b2b_france',
            'einvoice_format' => $_POST['einvoice_format'] ?? 'factur-x',
            'einvoice_statut' => $_POST['einvoice_statut'] ?? 'non_preparee',
            'einvoice_plateforme' => trim($_POST['einvoice_plateforme'] ?? ''),
            'einvoice_reference' => trim($_POST['einvoice_reference'] ?? ''),
            'date_facture'  => $_POST['date_facture'],
            'date_echeance' => $_POST['date_echeance'],
            'statut'        => $_POST['statut'] ?? 'brouillon',
            'objet'         => trim($_POST['objet'] ?? ''),
            'notes'         => trim($_POST['notes'] ?? ''),
            'conditions'    => trim($_POST['conditions'] ?? ''),
        ];

        if (empty($data['objet']) || empty($data['client_id'])) {
            setFlash('danger', 'L\'objet et le client sont obligatoires.');
            header('Location: factures.php?action=nouvelle');
            exit;
        }

        $lignes = [];
        if (!empty($_POST['ligne_description'])) {
            foreach ($_POST['ligne_description'] as $i => $desc) {
                $desc = trim($desc);
                if (empty($desc)) continue;
                $lignes[] = [
                    'description'     => $desc,
                    'quantite'        => (float)($_POST['ligne_quantite'][$i] ?? 1),
                    'unite'           => trim($_POST['ligne_unite'][$i] ?? 'unité'),
                    'prix_unitaire_ht' => (float)($_POST['ligne_prix'][$i] ?? 0),
                    'taux_tva'        => $conf['tva_applicable'] ? (float)($_POST['ligne_tva'][$i] ?? 20) : 0,
                ];
            }
        }

        $editId = !empty($_POST['facture_id']) ? (int)$_POST['facture_id'] : null;
        if (!$editId) {
            $data['numero'] = genererNumero('facture');
        }
        $id = sauvegarderFacture($data, $lignes, $editId);
        maybeAutoSendFactureToPdp($id);
        setFlash('success', $editId ? 'Facture modifiée.' : 'Facture créée.');
        header('Location: factures.php?action=voir&id=' . $id);
        exit;
    }

    if ($postAction === 'supprimer') {
        supprimerFacture((int)$_POST['id']);
        setFlash('success', 'Facture supprimée.');
        header('Location: factures.php');
        exit;
    }

    if ($postAction === 'changer_statut') {
        $db = getDB();
        $factureId = (int) $_POST['id'];
        $db->prepare('UPDATE factures SET statut = ? WHERE id = ?')->execute([$_POST['statut'], $factureId]);
        maybeAutoSendFactureToPdp($factureId);
        setFlash('success', 'Statut mis à jour.');
        header('Location: factures.php?action=voir&id=' . $factureId);
        exit;
    }

    if ($postAction === 'changer_statut_einvoice') {
        $factureId = (int) ($_POST['id'] ?? 0);
        $status = $_POST['einvoice_statut'] ?? 'non_preparee';

        try {
            setFactureEInvoiceStatus($factureId, $status);
            setFlash('success', 'Statut e-facture mis à jour.');
        } catch (Throwable $e) {
            setFlash('danger', $e->getMessage());
        }

        header('Location: factures.php?action=voir&id=' . $factureId);
        exit;
    }

    if ($postAction === 'transmettre_pdp') {
        $factureId = (int) ($_POST['id'] ?? 0);
        try {
            $result = transmitFactureToPdp($factureId, false);
            $message = 'Transmission PA réussie.';
            if (!empty($result['note'])) {
                $message .= ' ' . $result['note'];
            }
            setFlash('success', $message);
        } catch (Throwable $e) {
            setFlash('danger', $e->getMessage());
        }
        header('Location: factures.php?action=voir&id=' . $factureId);
        exit;
    }

    if ($postAction === 'supprimer_paiement') {
        supprimerPaiement((int)$_POST['paiement_id']);
        setFlash('success', 'Paiement supprimé.');
        header('Location: factures.php?action=voir&id=' . (int)$_POST['facture_redirect']);
        exit;
    }

    if ($postAction === 'enregistrer_paiement') {
        $pData = [
            'facture_id'    => (int)$_POST['facture_id'],
            'date_paiement' => $_POST['date_paiement'],
            'montant'       => (float)$_POST['montant'],
            'mode_paiement' => $_POST['mode_paiement'],
            'reference'     => trim($_POST['reference'] ?? ''),
            'notes'         => trim($_POST['paiement_notes'] ?? ''),
        ];
        ajouterPaiement($pData);
        setFlash('success', 'Paiement enregistré.');
        header('Location: factures.php?action=voir&id=' . $pData['facture_id']);
        exit;
    }
}

$clientsList = getClients();

include 'header.php';
include 'commercial_header.php';
?>

<?php if ($action === 'liste'): ?>
<?php
$filtreStatut = $_GET['statut'] ?? '';
$filtreRecherche = $_GET['recherche'] ?? '';
$facturesList = getFacturesList(['statut' => $filtreStatut, 'recherche' => $filtreRecherche]);
$totalTTC = array_sum(array_map(fn($f) => (float)$f['montant_ttc'], $facturesList));
$totalPaye = array_sum(array_map(fn($f) => (float)$f['montant_paye'], $facturesList));
$totalReste = max(0, $totalTTC - $totalPaye);
$nbEnRetard = count(array_filter($facturesList, fn($f) => $f['statut'] === 'en_retard'));
$nbEnvoyee = count(array_filter($facturesList, fn($f) => $f['statut'] === 'envoyee'));
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-receipt"></i> Factures</h2>
            <p class="text-muted mb-0"><?= count($facturesList) ?> facture<?= count($facturesList) > 1 ? 's' : '' ?></p>
        </div>
        <a href="?action=nouvelle" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouvelle facture</a>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Total TTC</div>
                        <div class="fs-4 fw-bold text-primary"><?= formatMontant($totalTTC) ?></div>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Encaissé</div>
                        <div class="fs-4 fw-bold text-success"><?= formatMontant($totalPaye) ?></div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Envoyées</div>
                        <div class="fs-4 fw-bold text-info"><?= $nbEnvoyee ?></div>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-send"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">En retard</div>
                        <div class="fs-4 fw-bold text-danger"><?= $nbEnRetard ?></div>
                    </div>
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filtres -->
<div class="card border-0 mb-4 commercial-filter-card">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-auto flex-grow-1">
                <input type="text" name="recherche" class="form-control form-control-sm" placeholder="Rechercher (n°, client, objet)..." value="<?= e($filtreRecherche) ?>">
            </div>
            <div class="col-auto">
                <select name="statut" class="form-select form-select-sm">
                    <option value="">Tous statuts</option>
                    <?php foreach (['brouillon', 'envoyee', 'payee', 'partielle', 'en_retard', 'annulee'] as $s): $sl = getStatutFactureLabel($s); ?>
                        <option value="<?= $s ?>" <?= $filtreStatut === $s ? 'selected' : '' ?>><?= $sl['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrer</button>
                <?php if ($filtreStatut || $filtreRecherche): ?>
                    <a href="factures.php" class="btn btn-sm btn-outline-secondary">Effacer</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (empty($facturesList)): ?>
    <div class="card border-0">
        <div class="card-body text-center empty-state">
            <div class="empty-state-icon">
                <i class="bi bi-receipt"></i>
            </div>
            <h3 class="empty-state-title">Aucune facture trouvée</h3>
            <p class="empty-state-text">
                Crée ta première facture pour suivre les échéances, les paiements reçus et les éventuels retards clients.
            </p>
            <div class="empty-state-actions">
                <a href="?action=nouvelle" class="btn btn-primary empty-state-btn"><i class="bi bi-plus-lg"></i> Créer une facture</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 commercial-table-card">
        <div class="card-header commercial-table-card__header">
            <div>
                <div class="commercial-table-card__eyebrow">Suivi du poste clients</div>
                <div class="commercial-table-card__title">Liste des factures</div>
            </div>
            <div class="commercial-table-card__stats">
                <span class="commercial-table-pill"><i class="bi bi-wallet2"></i> À encaisser : <strong><?= formatMontant($totalReste) ?></strong></span>
                <span class="commercial-table-pill"><i class="bi bi-hourglass-split"></i> En retard : <strong><?= $nbEnRetard ?></strong></span>
                <span class="commercial-table-pill"><i class="bi bi-collection"></i> Total : <strong><?= count($facturesList) ?></strong></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-commercial mb-0">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Date</th>
                        <th>Échéance</th>
                        <th>Client</th>
                        <th>Objet</th>
                        <th class="text-end">Montant TTC</th>
                        <th class="text-end">Payé</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facturesList as $f): $s = getStatutFactureLabel($f['statut']); ?>
                    <tr class="<?= $f['statut'] === 'en_retard' ? 'row-danger' : '' ?>">
                        <td><a href="?action=voir&id=<?= $f['id'] ?>" class="numero-link"><?= e($f['numero']) ?></a></td>
                        <td class="date-cell"><?= formatDate($f['date_facture']) ?></td>
                        <td class="date-cell"><?= formatDate($f['date_echeance']) ?></td>
                        <td class="client-name"><?= e($f['client_entreprise'] ?: trim($f['client_prenom'] . ' ' . $f['client_nom'])) ?></td>
                        <td class="text-muted"><?= e(mb_strimwidth($f['objet'], 0, 40, '...')) ?></td>
                        <td class="text-end montant-cell"><?= formatMontant($f['montant_ttc']) ?></td>
                        <td class="text-end montant-cell text-success"><?= formatMontant($f['montant_paye']) ?></td>
                        <td><span class="badge-statut bg-<?= $s['class'] ?>"><?= $s['label'] ?></span></td>
                        <td class="text-end">
                            <div class="btn-actions justify-content-end">
                                <a href="?action=voir&id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-info table-action-btn" title="Voir"><i class="bi bi-eye"></i></a>
                                <a href="pdf_generator.php?type=facture&id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-danger table-action-btn" target="_blank" title="PDF"><i class="bi bi-file-pdf"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($action === 'voir'): ?>
<?php
$facture = getFacture((int)($_GET['id'] ?? 0));
if (!$facture) { setFlash('danger', 'Facture introuvable.'); header('Location: factures.php'); exit; }
$lignes = getLignesFacture($facture['id']);
$paiements = getPaiementsFacture($facture['id']);
$s = getStatutFactureLabel($facture['statut']);
$eStatus = getEInvoiceStatusLabel($facture['einvoice_statut'] ?? 'non_preparee');
$eMissing = getFactureEInvoiceMissingFields($facture);
$pdpConfig = getPdpConfig();
$transmissions = getTransmissionHistory((int) $facture['id']);
$resteAPayer = (float)$facture['montant_ttc'] - (float)$facture['montant_paye'];
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-receipt"></i> Facture <?= e($facture['numero']) ?></h2>
            <p class="text-muted mb-0">
                <span class="badge bg-<?= $s['class'] ?>"><?= $s['label'] ?></span>
                <span class="badge bg-<?= $eStatus['class'] ?>"><?= $eStatus['label'] ?> e-facture</span>
                — <?= e($facture['client_entreprise'] ?: trim($facture['client_prenom'] . ' ' . $facture['client_nom'])) ?>
                — Émise le <?= formatDate($facture['date_facture']) ?>
                <?php if ($resteAPayer > 0.01 && $facture['statut'] !== 'annulee'): ?>
                    — <span class="text-danger fw-bold">Reste : <?= formatMontant($resteAPayer) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap document-actions">
            <a href="pdf_generator.php?type=facture&id=<?= $facture['id'] ?>" class="btn btn-danger document-action-btn" target="_blank"><i class="bi bi-file-pdf"></i> PDF</a>
            <a href="export_einvoice.php?id=<?= $facture['id'] ?>&format=ubl" class="btn btn-outline-primary document-action-btn">
                <i class="bi bi-filetype-xml"></i> Export UBL
            </a>
            <?php if ($pdpConfig['enabled']): ?>
            <form method="post" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="transmettre_pdp">
                <input type="hidden" name="id" value="<?= $facture['id'] ?>">
                <button type="submit" class="btn btn-outline-success document-action-btn" <?= empty($eMissing) ? '' : 'disabled' ?>>
                    <i class="bi bi-send-check"></i> Transmettre à la PA
                </button>
            </form>
            <?php endif; ?>
            <?php if ($facture['statut'] === 'brouillon'): ?>
                <a href="?action=modifier&id=<?= $facture['id'] ?>" class="btn btn-warning document-action-btn"><i class="bi bi-pencil"></i> Modifier</a>
            <?php endif; ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette facture ?')">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="supprimer">
                <input type="hidden" name="id" value="<?= $facture['id'] ?>">
                <button class="btn btn-outline-danger document-action-btn document-action-btn--icon"><i class="bi bi-trash"></i></button>
            </form>
            <a href="factures.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
        </div>
    </div>
</div>

<!-- Statut -->
<div class="status-bar mb-4">
    <span class="fw-bold">Statut :</span>
    <span class="badge bg-<?= $s['class'] ?> badge-statut"><?= $s['label'] ?></span>
    <?php if ($resteAPayer > 0.01 && $facture['statut'] !== 'annulee'): ?>
    <div class="payment-progress flex-grow-1" style="max-width: 200px;">
        <div class="bar <?= $facture['statut'] === 'en_retard' ? 'overdue' : ($facture['montant_paye'] > 0 ? 'partial' : '') ?>" style="width: <?= min(100, round(($facture['montant_paye'] / max(1, $facture['montant_ttc'])) * 100)) ?>%"></div>
    </div>
    <small class="text-muted"><?= round(($facture['montant_paye'] / max(1, $facture['montant_ttc'])) * 100) ?>% payé</small>
    <?php endif; ?>
    <form method="post" class="d-flex gap-2 ms-auto">
        <?= csrfField() ?>
        <input type="hidden" name="post_action" value="changer_statut">
        <input type="hidden" name="id" value="<?= $facture['id'] ?>">
        <select name="statut" class="form-select form-select-sm" style="width: auto; border-radius: 0.5rem;">
            <?php foreach (['brouillon', 'envoyee', 'payee', 'partielle', 'en_retard', 'annulee'] as $st): $stl = getStatutFactureLabel($st); ?>
                <option value="<?= $st ?>" <?= $facture['statut'] === $st ? 'selected' : '' ?>><?= $stl['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary document-status-btn" style="border-radius: 0.5rem;">Mettre à jour</button>
    </form>
</div>

<div class="status-bar mb-4">
    <span class="fw-bold">E-facture :</span>
    <span class="badge bg-<?= $eStatus['class'] ?> badge-statut"><?= $eStatus['label'] ?></span>
    <?php if (empty($eMissing)): ?>
    <span class="text-success small"><i class="bi bi-check-circle"></i> Dossier prêt pour export / PA</span>
    <?php else: ?>
    <span class="text-warning small"><i class="bi bi-exclamation-triangle"></i> Champs à compléter : <?= e(implode(', ', $eMissing)) ?></span>
    <?php endif; ?>
    <form method="post" class="d-flex gap-2 ms-auto">
        <?= csrfField() ?>
        <input type="hidden" name="post_action" value="changer_statut_einvoice">
        <input type="hidden" name="id" value="<?= $facture['id'] ?>">
        <select name="einvoice_statut" class="form-select form-select-sm" style="width: auto; border-radius: 0.5rem;">
            <?php foreach (['non_preparee', 'prete', 'a_transmettre', 'transmise', 'rejetee'] as $estatus): $elabel = getEInvoiceStatusLabel($estatus); ?>
                <option value="<?= $estatus ?>" <?= ($facture['einvoice_statut'] ?? 'non_preparee') === $estatus ? 'selected' : '' ?>><?= e($elabel['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary document-status-btn" style="border-radius: 0.5rem;">Mettre à jour</button>
    </form>
</div>

<div class="doc-summary-grid mb-4">
    <div class="doc-summary-card">
        <small>Date d'émission</small>
        <strong><?= formatDate($facture['date_facture']) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Échéance</small>
        <strong><?= formatDate($facture['date_echeance']) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Total TTC</small>
        <strong><?= formatMontant($facture['montant_ttc']) ?></strong>
    </div>
    <div class="doc-summary-card <?= $resteAPayer > 0.01 ? 'doc-summary-card--alert' : '' ?>">
        <small>Reste à payer</small>
        <strong><?= formatMontant($resteAPayer) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Préparation e-facture</small>
        <strong><?= e($eStatus['label']) ?></strong>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card info-card h-100">
            <div class="card-header"><i class="bi bi-building"></i> Émetteur</div>
            <div class="card-body">
                <strong><?= e(getParam('nom_entreprise', 'Mon Activité')) ?></strong><br>
                <?php if (getParam('adresse_entreprise')): ?><?= nl2br(e(getParam('adresse_entreprise'))) ?><br><?php endif; ?>
                <?php if (getParam('siret')): ?><small class="text-muted">SIRET : <?= e(getParam('siret')) ?></small><br><?php endif; ?>
                <?php if (!$conf['tva_applicable']): ?><em class="text-muted small">TVA non applicable, art. 293 B du CGI</em><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card info-card h-100">
            <div class="card-header"><i class="bi bi-person"></i> Client</div>
            <div class="card-body">
                <strong><?= e($facture['client_entreprise'] ?: trim($facture['client_prenom'] . ' ' . $facture['client_nom'])) ?></strong><br>
                <?php if ($facture['client_adresse']): ?>
                    <?= e($facture['client_adresse']) ?><br>
                    <?= e($facture['client_cp'] . ' ' . $facture['client_ville']) ?><br>
                <?php endif; ?>
                <?php if ($facture['client_siret']): ?><small class="text-muted">SIRET : <?= e($facture['client_siret']) ?></small><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 mt-4">
    <div class="card-header"><i class="bi bi-cpu"></i> Préparation facturation électronique</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <small class="text-muted d-block">SIREN client</small>
                <strong><?= e($facture['client_siren'] ?: ($facture['client_siren_source'] ?? 'Non renseigné')) ?></strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Type d'opération</small>
                <strong><?= e(getFactureOperationTypeLabel($facture['type_operation'] ?? 'services')) ?></strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Circuit</small>
                <strong><?= e(getFactureCircuitLabel($facture['circuit_facturation'] ?? 'b2b_france')) ?></strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Format cible</small>
                <strong><?= e(strtoupper((string) ($pdpConfig['export_format'] ?: ($facture['einvoice_format'] ?? 'UBL')))) ?></strong>
            </div>
            <div class="col-md-8">
                <small class="text-muted d-block">Adresse de livraison</small>
                <strong>
                    <?= e(trim(implode(' ', array_filter([
                        $facture['adresse_livraison'] ?? '',
                        $facture['code_postal_livraison'] ?? '',
                        $facture['ville_livraison'] ?? '',
                        $facture['pays_livraison'] ?? '',
                    ])))) ?: 'Identique au client / non renseignée' ?>
                </strong>
            </div>
            <div class="col-md-2">
                <small class="text-muted d-block">Plateforme</small>
                <strong><?= e($facture['einvoice_plateforme'] ?: ($pdpConfig['provider'] ?: 'À définir')) ?></strong>
            </div>
            <div class="col-md-2">
                <small class="text-muted d-block">Référence</small>
                <strong><?= e($facture['einvoice_reference'] ?: '—') ?></strong>
            </div>
        </div>
    </div>
</div>

<?php if ($pdpConfig['enabled']): ?>
<div class="card border-0 mt-4">
    <div class="card-header"><i class="bi bi-hdd-network"></i> Journal PA</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Format</th>
                        <th>Statut</th>
                        <th>HTTP</th>
                        <th>Payload</th>
                        <th>Retour</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transmissions)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Aucune transmission PA enregistrée.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($transmissions as $tx): ?>
                    <tr>
                        <td><?= e(formatDate($tx['created_at'])) ?></td>
                        <td><?= e(strtoupper($tx['format'])) ?></td>
                        <td><span class="badge bg-<?= $tx['statut'] === 'success' ? 'success' : ($tx['statut'] === 'error' ? 'danger' : 'warning text-dark') ?>"><?= e($tx['statut']) ?></span></td>
                        <td><?= e((string) ($tx['http_code'] ?? '—')) ?></td>
                        <td><small class="text-muted"><?= e($tx['payload_path'] ?? '—') ?></small></td>
                        <td><small class="text-muted"><?= e($tx['response_excerpt'] ?? '—') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Détail facture -->
<div class="card border-0 mt-4 document-wrapper">
    <div class="document-header-bar">
        <div><strong>Objet :</strong> <?= e($facture['objet']) ?></div>
        <div><i class="bi bi-calendar-event"></i> Échéance : <?= formatDate($facture['date_echeance']) ?></div>
    </div>
    <div class="card-body p-0">
        <table class="table table-detail mb-0">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Description</th>
                    <th class="text-end" style="width: 80px;">Qté</th>
                    <th style="width: 60px;">Unité</th>
                    <th class="text-end" style="width: 120px;">P.U. HT</th>
                    <?php if ($conf['tva_applicable']): ?><th class="text-end" style="width: 80px;">TVA %</th><?php endif; ?>
                    <th class="text-end" style="width: 120px;">Total HT</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lignes as $i => $l): ?>
                <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td><?= e($l['description']) ?></td>
                    <td class="text-end"><?= rtrim(rtrim(number_format($l['quantite'], 3, ',', ' '), '0'), ',') ?></td>
                    <td><?= e($l['unite']) ?></td>
                    <td class="text-end"><?= formatMontant($l['prix_unitaire_ht']) ?></td>
                    <?php if ($conf['tva_applicable']): ?><td class="text-end"><?= number_format($l['taux_tva'], 1) ?>%</td><?php endif; ?>
                    <td class="text-end fw-bold"><?= formatMontant($l['montant_ht']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="<?= $conf['tva_applicable'] ? 6 : 5 ?>" class="text-end">Total HT</td><td class="text-end"><?= formatMontant($facture['montant_ht']) ?></td></tr>
                <?php if ($conf['tva_applicable']): ?>
                <tr><td colspan="6" class="text-end">TVA</td><td class="text-end"><?= formatMontant($facture['montant_tva']) ?></td></tr>
                <?php endif; ?>
                <tr><td colspan="<?= $conf['tva_applicable'] ? 6 : 5 ?>" class="text-end fs-5 fw-bold">Total TTC</td><td class="text-end fs-5 fw-bold"><?= formatMontant($facture['montant_ttc']) ?></td></tr>
            </tfoot>
        </table>
    </div>
    <?php $factureConditions = getConditionsDocumentVente('facture', $facture); ?>
    <?php if ($facture['notes'] || $factureConditions): ?>
    <div class="card-footer terms-card">
        <?php if ($facture['notes']): ?><p class="mb-3"><strong>Notes :</strong> <?= nl2br(e($facture['notes'])) ?></p><?php endif; ?>
        <?php if ($factureConditions): ?>
            <div class="terms-card__title"><i class="bi bi-shield-check"></i> Conditions générales de vente</div>
            <div class="terms-card__content"><?= nl2br(e($factureConditions)) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Paiements -->
<div class="card border-0 mt-4 commercial-table-card">
    <div class="card-header commercial-table-card__header">
        <div>
            <div class="commercial-table-card__eyebrow">Encaissements liés</div>
            <div class="commercial-table-card__title"><i class="bi bi-credit-card"></i> Paiements (<?= count($paiements) ?>)</div>
        </div>
        <div class="commercial-table-card__stats">
            <span class="commercial-table-pill"><i class="bi bi-check2-circle"></i> Payé : <strong><?= formatMontant($facture['montant_paye']) ?></strong></span>
            <span class="commercial-table-pill"><i class="bi bi-hourglass-split"></i> Reste : <strong><?= formatMontant($resteAPayer) ?></strong></span>
        </div>
    </div>
    <div class="card-body">
        <div class="payment-summary mb-3">
            <div>
                <div class="label">Payé</div>
                <div class="amount"><?= formatMontant($facture['montant_paye']) ?></div>
            </div>
            <div class="text-muted">/</div>
            <div>
                <div class="label">Total TTC</div>
                <div class="amount" style="color: var(--bleu-primary);"><?= formatMontant($facture['montant_ttc']) ?></div>
            </div>
            <?php if ($resteAPayer > 0.01): ?>
            <div class="ms-auto">
                <div class="label">Reste à payer</div>
                <div class="amount" style="color: #ef4444;"><?= formatMontant($resteAPayer) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="payment-progress mb-3">
            <div class="bar <?= $facture['statut'] === 'en_retard' ? 'overdue' : ($facture['montant_paye'] > 0 && $resteAPayer > 0.01 ? 'partial' : '') ?>" style="width: <?= min(100, round(($facture['montant_paye'] / max(1, $facture['montant_ttc'])) * 100)) ?>%"></div>
        </div>
    </div>
    <?php if (!empty($paiements)): ?>
    <div class="table-responsive">
        <table class="table table-sm table-commercial mb-0">
            <thead>
                <tr><th>Date</th><th>Mode</th><th>Référence</th><th class="text-end">Montant</th><th>Notes</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($paiements as $p): ?>
                <tr>
                    <td class="date-cell"><?= formatDate($p['date_paiement']) ?></td>
                    <td><span class="badge-statut bg-info"><?= e(getModePaiementLabel($p['mode_paiement'])) ?></span></td>
                    <td class="date-cell"><?= e($p['reference'] ?? '—') ?></td>
                    <td class="text-end montant-cell text-success"><?= formatMontant($p['montant']) ?></td>
                    <td><small class="text-muted"><?= e($p['notes'] ?? '') ?></small></td>
                    <td>
                        <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce paiement ?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="post_action" value="supprimer_paiement">
                            <input type="hidden" name="paiement_id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="facture_redirect" value="<?= $facture['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger document-action-btn document-action-btn--icon"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($resteAPayer > 0.01 && $facture['statut'] !== 'annulee'): ?>
    <div class="card-body border-top payment-entry-panel">
        <div class="payment-entry-panel__header">
            <div>
                <strong><i class="bi bi-plus-circle"></i> Enregistrer un paiement</strong>
                <small>Saisie rapide d'un règlement sur cette facture</small>
            </div>
        </div>
        <form method="post" class="row g-3 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="enregistrer_paiement">
            <input type="hidden" name="facture_id" value="<?= $facture['id'] ?>">
            <div class="col-md-2">
                <label class="form-label small">Date</label>
                <input type="date" name="date_paiement" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Montant</label>
                <input type="number" name="montant" class="form-control form-control-sm" step="0.01" required value="<?= number_format($resteAPayer, 2, '.', '') ?>" max="<?= number_format($resteAPayer, 2, '.', '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Mode</label>
                <select name="mode_paiement" class="form-select form-select-sm" required>
                    <option value="virement">Virement</option>
                    <option value="carte_bancaire">Carte bancaire</option>
                    <option value="especes">Espèces</option>
                    <option value="cheque">Chèque</option>
                    <option value="prelevement">Prélèvement</option>
                    <option value="paypal">PayPal</option>
                    <option value="autre">Autre</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Référence</label>
                <input type="text" name="reference" class="form-control form-control-sm" placeholder="N° chèque...">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Notes</label>
                <input type="text" name="paiement_notes" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-success w-100 document-action-btn"><i class="bi bi-check"></i> Enregistrer</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'nouvelle' || $action === 'modifier'): ?>
<?php
$facture = null;
$lignes = [];
if ($action === 'modifier') {
    $facture = getFacture((int)($_GET['id'] ?? 0));
    if (!$facture) { setFlash('danger', 'Facture introuvable.'); header('Location: factures.php'); exit; }
    $lignes = getLignesFacture($facture['id']);
}
$preselectedClient = (int)($_GET['client_id'] ?? ($facture['client_id'] ?? 0));
$echeanceJours = (int)getParam('validite_devis', '30');
$generatedConditions = genererConditionsDocumentVente('facture', $facture ?? [
    'date_echeance' => date('Y-m-d', strtotime("+{$echeanceJours} days")),
]);
$clientsById = [];
foreach ($clientsList as $clientItem) {
    $clientsById[(int) $clientItem['id']] = $clientItem;
}
$selectedClient = $clientsById[$preselectedClient] ?? null;
$defaultClientSiren = $facture['client_siren'] ?? ($selectedClient['siren'] ?? '');
$defaultAdresseLivraison = $facture['adresse_livraison'] ?? ($selectedClient['adresse'] ?? '');
$defaultCodePostalLivraison = $facture['code_postal_livraison'] ?? ($selectedClient['code_postal'] ?? '');
$defaultVilleLivraison = $facture['ville_livraison'] ?? ($selectedClient['ville'] ?? '');
$defaultPaysLivraison = $facture['pays_livraison'] ?? ($selectedClient['pays'] ?? 'France');
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-receipt"></i> <?= $facture ? 'Modifier facture ' . e($facture['numero']) : 'Nouvelle facture' ?></h2>
            <p class="text-muted mb-0"><?= $facture ? 'Modifiez les détails de la facture' : 'Renseignez les informations de la facture' ?></p>
        </div>
        <a href="factures.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<form method="post" id="formFacture" class="doc-workspace">
    <?= csrfField() ?>
    <input type="hidden" name="post_action" value="sauvegarder">
    <?php if ($facture): ?><input type="hidden" name="facture_id" value="<?= $facture['id'] ?>"><?php endif; ?>

    <div class="card border-0 mb-4 doc-form-section doc-form-section--meta">
        <div class="card-header doc-form-section__header">
            <div>
                <i class="bi bi-info-circle"></i> Informations
                <small class="doc-form-section__subtitle">En-tête de la pièce et paramètres de facturation</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 doc-meta-grid">
                <div class="col-md-6">
                    <label class="form-label">Client *</label>
                    <select name="client_id" id="clientSelectFacture" class="form-select" required>
                        <option value="">— Choisir un client —</option>
                        <?php foreach ($clientsList as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                    data-siren="<?= e($c['siren'] ?? '') ?>"
                                    data-adresse="<?= e($c['adresse'] ?? '') ?>"
                                    data-cp="<?= e($c['code_postal'] ?? '') ?>"
                                    data-ville="<?= e($c['ville'] ?? '') ?>"
                                    data-pays="<?= e($c['pays'] ?? 'France') ?>"
                                    <?= $preselectedClient === $c['id'] ? 'selected' : '' ?>><?= e(clientNomComplet($c)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date facture</label>
                    <input type="date" name="date_facture" class="form-control" required value="<?= e($facture['date_facture'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date échéance</label>
                    <input type="date" name="date_echeance" class="form-control" required value="<?= e($facture['date_echeance'] ?? date('Y-m-d', strtotime("+{$echeanceJours} days"))) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-select">
                        <?php foreach (['brouillon', 'envoyee'] as $st): $stl = getStatutFactureLabel($st); ?>
                            <option value="<?= $st ?>" <?= ($facture['statut'] ?? 'brouillon') === $st ? 'selected' : '' ?>><?= $stl['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-9">
                    <label class="form-label">Objet *</label>
                    <input type="text" name="objet" class="form-control" required value="<?= e($facture['objet'] ?? '') ?>" placeholder="Description de la facture">
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4 doc-form-section">
        <div class="card-header doc-form-section__header">
            <div>
                <i class="bi bi-cpu"></i> Préparation facturation électronique
                <small class="doc-form-section__subtitle">Champs utiles pour la réforme e-invoicing et l'intégration future avec une PA</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">SIREN client</label>
                    <input type="text" name="client_siren" id="clientSirenField" class="form-control" maxlength="20"
                           value="<?= e($defaultClientSiren) ?>" placeholder="SIREN du client">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type d'opération</label>
                    <select name="type_operation" class="form-select">
                        <option value="services" <?= ($facture['type_operation'] ?? 'services') === 'services' ? 'selected' : '' ?>>Prestations de services</option>
                        <option value="biens" <?= ($facture['type_operation'] ?? '') === 'biens' ? 'selected' : '' ?>>Livraisons de biens</option>
                        <option value="mixte" <?= ($facture['type_operation'] ?? '') === 'mixte' ? 'selected' : '' ?>>Mixte</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Circuit</label>
                    <select name="circuit_facturation" class="form-select">
                        <?php foreach (['b2b_france', 'b2c', 'international', 'secteur_public'] as $circuit): ?>
                        <option value="<?= $circuit ?>" <?= ($facture['circuit_facturation'] ?? 'b2b_france') === $circuit ? 'selected' : '' ?>><?= e(getFactureCircuitLabel($circuit)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Format cible</label>
                    <select name="einvoice_format" class="form-select">
                        <option value="factur-x" <?= ($facture['einvoice_format'] ?? 'factur-x') === 'factur-x' ? 'selected' : '' ?>>Factur-X</option>
                        <option value="ubl" <?= ($facture['einvoice_format'] ?? '') === 'ubl' ? 'selected' : '' ?>>UBL</option>
                        <option value="cii" <?= ($facture['einvoice_format'] ?? '') === 'cii' ? 'selected' : '' ?>>CII</option>
                        <option value="pdf" <?= ($facture['einvoice_format'] ?? '') === 'pdf' ? 'selected' : '' ?>>PDF simple</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Statut e-facture</label>
                    <select name="einvoice_statut" class="form-select">
                        <?php foreach (['non_preparee', 'prete', 'a_transmettre', 'transmise', 'rejetee'] as $estatus): $elabel = getEInvoiceStatusLabel($estatus); ?>
                        <option value="<?= $estatus ?>" <?= ($facture['einvoice_statut'] ?? 'non_preparee') === $estatus ? 'selected' : '' ?>><?= e($elabel['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Plateforme / PA</label>
                    <input type="text" name="einvoice_plateforme" class="form-control" value="<?= e($facture['einvoice_plateforme'] ?? '') ?>" placeholder="Nom de la PA ou solution cible">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Référence électronique</label>
                    <input type="text" name="einvoice_reference" class="form-control" value="<?= e($facture['einvoice_reference'] ?? '') ?>" placeholder="Référence technique ou dépôt">
                </div>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-md-6">
                    <label class="form-label">Adresse de livraison</label>
                    <input type="text" name="adresse_livraison" id="adresseLivraisonField" class="form-control"
                           value="<?= e($defaultAdresseLivraison) ?>" placeholder="Adresse de livraison si différente">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Code postal</label>
                    <input type="text" name="code_postal_livraison" id="cpLivraisonField" class="form-control"
                           value="<?= e($defaultCodePostalLivraison) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ville</label>
                    <input type="text" name="ville_livraison" id="villeLivraisonField" class="form-control"
                           value="<?= e($defaultVilleLivraison) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Pays</label>
                    <input type="text" name="pays_livraison" id="paysLivraisonField" class="form-control"
                           value="<?= e($defaultPaysLivraison) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Lignes -->
    <div class="card border-0 mb-4 doc-form-section doc-form-section--lines">
        <div class="card-header d-flex justify-content-between align-items-center doc-form-section__header">
            <div class="doc-lines-title">
                <span><i class="bi bi-list-ol"></i> Lignes de la facture</span>
                <small class="doc-form-section__subtitle">Saisie rapide des articles, prestations et quantités</small>
            </div>
            <div class="doc-lines-tools">
                <span class="doc-lines-badge"><i class="bi bi-hash"></i> <span id="lineCountBadge">1</span> ligne(s)</span>
                <span class="doc-lines-badge"><i class="bi bi-calculator"></i> Total HT <strong id="grandTotalHTBadge">0,00 €</strong></span>
            </div>
            <button type="button" class="btn btn-sm btn-success line-add-btn" onclick="ajouterLigne()">
                <span class="line-add-btn__icon"><i class="bi bi-plus-lg"></i></span>
                <span>Ajouter une ligne</span>
            </button>
        </div>
        <div class="card-body p-0">
            <table class="table mb-0" id="tableLignes">
                <thead class="table-light">
                    <tr>
                        <th>Description</th>
                        <th style="width: 80px;">Qté</th>
                        <th style="width: 80px;">Unité</th>
                        <th style="width: 120px;">P.U. HT</th>
                        <?php if ($conf['tva_applicable']): ?><th style="width: 80px;">TVA %</th><?php endif; ?>
                        <th style="width: 120px;">Total HT</th>
                        <th style="width: 40px;"></th>
                    </tr>
                </thead>
                <tbody id="lignesBody">
                    <?php if (!empty($lignes)): ?>
                        <?php foreach ($lignes as $l): ?>
                        <tr class="ligne-devis">
                            <td><input type="text" name="ligne_description[]" class="form-control form-control-sm" required value="<?= e($l['description']) ?>"></td>
                            <td><input type="number" name="ligne_quantite[]" class="form-control form-control-sm ligne-qte" step="0.001" min="0" value="<?= $l['quantite'] ?>" onchange="calcLigne(this)"></td>
                            <td><input type="text" name="ligne_unite[]" class="form-control form-control-sm" value="<?= e($l['unite']) ?>"></td>
                            <td><input type="number" name="ligne_prix[]" class="form-control form-control-sm ligne-prix" step="0.01" min="0" value="<?= $l['prix_unitaire_ht'] ?>" onchange="calcLigne(this)"></td>
                            <?php if ($conf['tva_applicable']): ?>
                            <td><input type="number" name="ligne_tva[]" class="form-control form-control-sm ligne-tva" step="0.1" value="<?= $l['taux_tva'] ?>" onchange="calcLigne(this)"></td>
                            <?php endif; ?>
                            <td><input type="text" class="form-control form-control-sm ligne-total fw-bold" readonly value="<?= number_format($l['montant_ht'], 2) ?>"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger line-delete-btn" onclick="this.closest('tr').remove(); calcTotal()"><i class="bi bi-trash"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="ligne-devis">
                            <td><input type="text" name="ligne_description[]" class="form-control form-control-sm" required placeholder="Description"></td>
                            <td><input type="number" name="ligne_quantite[]" class="form-control form-control-sm ligne-qte" step="0.001" min="0" value="1" onchange="calcLigne(this)"></td>
                            <td><input type="text" name="ligne_unite[]" class="form-control form-control-sm" value="unité"></td>
                            <td><input type="number" name="ligne_prix[]" class="form-control form-control-sm ligne-prix" step="0.01" min="0" value="0" onchange="calcLigne(this)"></td>
                            <?php if ($conf['tva_applicable']): ?>
                            <td><input type="number" name="ligne_tva[]" class="form-control form-control-sm ligne-tva" step="0.1" value="20" onchange="calcLigne(this)"></td>
                            <?php endif; ?>
                            <td><input type="text" class="form-control form-control-sm ligne-total fw-bold" readonly value="0.00"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger line-delete-btn" onclick="this.closest('tr').remove(); calcTotal()"><i class="bi bi-trash"></i></button></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="<?= $conf['tva_applicable'] ? 5 : 4 ?>" class="text-end fw-bold">Total HT :</td>
                        <td class="text-end fw-bold" id="grandTotalHT">0,00 €</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card border-0 mb-4 doc-form-section">
        <div class="card-header doc-form-section__header">
            <div>
                <strong><i class="bi bi-journal-text"></i> Notes et conditions</strong>
                <small class="doc-form-section__subtitle">Mentions commerciales, notes de facturation et conditions de règlement</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 doc-notes-grid">
                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3"><?= e($facture['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <label class="form-label mb-0">Conditions générales de vente</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick='document.querySelector("#formFacture textarea[name=&quot;conditions&quot;]").value = <?= json_encode($generatedConditions) ?>;'>
                            <i class="bi bi-magic"></i> Générer les CGV
                        </button>
                    </div>
                    <textarea name="conditions" class="form-control" rows="9"><?= e($facture['conditions'] ?? $generatedConditions) ?></textarea>
                    <small class="text-muted">Texte généré automatiquement avec échéance, pénalités de retard et mentions commerciales.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="doc-submit-bar">
        <div class="doc-submit-bar__summary">
            <span class="doc-submit-bar__label">Montant HT courant</span>
            <strong id="grandTotalHTBottom">0,00 €</strong>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary btn-lg document-action-btn"><i class="bi bi-check-lg"></i> Enregistrer la facture</button>
            <a href="factures.php" class="btn btn-outline-secondary btn-lg document-action-btn">Annuler</a>
        </div>
    </div>
</form>

<script>
const tvaApplicable = <?= $conf['tva_applicable'] ? 'true' : 'false' ?>;

function ajouterLigne() {
    const tbody = document.getElementById('lignesBody');
    const tr = document.createElement('tr');
    tr.className = 'ligne-devis';
    tr.innerHTML = `
        <td><input type="text" name="ligne_description[]" class="form-control form-control-sm" required placeholder="Description"></td>
        <td><input type="number" name="ligne_quantite[]" class="form-control form-control-sm ligne-qte" step="0.001" min="0" value="1" onchange="calcLigne(this)"></td>
        <td><input type="text" name="ligne_unite[]" class="form-control form-control-sm" value="unité"></td>
        <td><input type="number" name="ligne_prix[]" class="form-control form-control-sm ligne-prix" step="0.01" min="0" value="0" onchange="calcLigne(this)"></td>
        ${tvaApplicable ? '<td><input type="number" name="ligne_tva[]" class="form-control form-control-sm ligne-tva" step="0.1" value="20" onchange="calcLigne(this)"></td>' : ''}
        <td><input type="text" class="form-control form-control-sm ligne-total fw-bold" readonly value="0.00"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger line-delete-btn" onclick="this.closest('tr').remove(); calcTotal()"><i class="bi bi-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
    calcTotal();
}

function calcLigne(el) {
    const row = el.closest('tr');
    const qte = parseFloat(row.querySelector('.ligne-qte').value) || 0;
    const prix = parseFloat(row.querySelector('.ligne-prix').value) || 0;
    row.querySelector('.ligne-total').value = (qte * prix).toFixed(2);
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('.ligne-total').forEach(el => { total += parseFloat(el.value) || 0; });
    const totalFormatted = new Intl.NumberFormat('fr-FR', {style: 'currency', currency: 'EUR'}).format(total);
    document.getElementById('grandTotalHT').textContent = totalFormatted;
    document.getElementById('grandTotalHTBadge').textContent = totalFormatted;
    document.getElementById('grandTotalHTBottom').textContent = totalFormatted;
    document.getElementById('lineCountBadge').textContent = document.querySelectorAll('#lignesBody tr').length;
}

document.addEventListener('DOMContentLoaded', calcTotal);

document.addEventListener('DOMContentLoaded', function() {
    const clientSelect = document.getElementById('clientSelectFacture');
    const sirenField = document.getElementById('clientSirenField');
    const adresseField = document.getElementById('adresseLivraisonField');
    const cpField = document.getElementById('cpLivraisonField');
    const villeField = document.getElementById('villeLivraisonField');
    const paysField = document.getElementById('paysLivraisonField');

    function hydrateClientFields() {
        const option = clientSelect?.selectedOptions?.[0];
        if (!option) return;

        if (sirenField && !sirenField.value) sirenField.value = option.dataset.siren || '';
        if (adresseField && !adresseField.value) adresseField.value = option.dataset.adresse || '';
        if (cpField && !cpField.value) cpField.value = option.dataset.cp || '';
        if (villeField && !villeField.value) villeField.value = option.dataset.ville || '';
        if (paysField && !paysField.value) paysField.value = option.dataset.pays || 'France';
    }

    clientSelect?.addEventListener('change', hydrateClientFields);
    hydrateClientFields();
});
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>
