<?php
/**
 * Gestion des devis
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Devis';

$action = $_GET['action'] ?? 'liste';
$conf = getRegimeConfig();

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Erreur de sécurité.');
        header('Location: devis.php');
        exit;
    }
    
    $postAction = $_POST['post_action'] ?? '';
    
    if ($postAction === 'sauvegarder') {
        $data = [
            'client_id'     => (int)$_POST['client_id'],
            'date_devis'    => $_POST['date_devis'],
            'date_validite' => $_POST['date_validite'],
            'statut'        => $_POST['statut'] ?? 'brouillon',
            'objet'         => trim($_POST['objet'] ?? ''),
            'notes'         => trim($_POST['notes'] ?? ''),
            'conditions'    => trim($_POST['conditions'] ?? ''),
        ];

        if (empty($data['objet']) || empty($data['client_id'])) {
            setFlash('danger', 'L\'objet et le client sont obligatoires.');
            header('Location: devis.php?action=nouveau');
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

        $editId = !empty($_POST['devis_id']) ? (int)$_POST['devis_id'] : null;
        if (!$editId) {
            $data['numero'] = genererNumero('devis');
        }
        $id = sauvegarderDevis($data, $lignes, $editId);
        setFlash('success', $editId ? 'Devis modifié.' : 'Devis créé : ' . ($data['numero'] ?? ''));
        header('Location: devis.php?action=voir&id=' . $id);
        exit;
    }

    if ($postAction === 'supprimer') {
        supprimerDevis((int)$_POST['id']);
        setFlash('success', 'Devis supprimé.');
        header('Location: devis.php');
        exit;
    }

    if ($postAction === 'convertir_facture') {
        $factureId = convertirDevisEnFacture((int)$_POST['id']);
        $db = getDB();
        $db->prepare("UPDATE devis SET statut = 'accepte' WHERE id = ?")->execute([(int)$_POST['id']]);
        setFlash('success', 'Facture créée à partir du devis.');
        header('Location: factures.php?action=voir&id=' . $factureId);
        exit;
    }

    if ($postAction === 'convertir_commande') {
        $cmdId = convertirDevisEnCommande((int)$_POST['id']);
        $db = getDB();
        $db->prepare("UPDATE devis SET statut = 'accepte' WHERE id = ?")->execute([(int)$_POST['id']]);
        setFlash('success', 'Commande créée à partir du devis.');
        header('Location: commandes.php?action=voir&id=' . $cmdId);
        exit;
    }

    if ($postAction === 'changer_statut') {
        $db = getDB();
        $db->prepare('UPDATE devis SET statut = ? WHERE id = ?')->execute([$_POST['statut'], (int)$_POST['id']]);
        setFlash('success', 'Statut mis à jour.');
        header('Location: devis.php?action=voir&id=' . (int)$_POST['id']);
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
$listFiltres = ['statut' => $filtreStatut, 'recherche' => $filtreRecherche];
$perPage = 30;
$devisStats = getDevisStats($listFiltres);
$totalPages = max(1, (int) ceil($devisStats['nb'] / $perPage));
$page = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));
$devisList = getDevisList($listFiltres + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);
$totalTTC = $devisStats['total_ttc'];
$nbAccepte = $devisStats['nb_accepte'];
$nbEnvoye = $devisStats['nb_envoye'];
$nbBrouillon = $devisStats['nb_brouillon'];
$tauxAcceptation = $devisStats['nb'] > 0 ? round(($nbAccepte / $devisStats['nb']) * 100) : 0;
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-file-earmark-text"></i> Devis</h2>
            <p class="text-muted mb-0"><?= $devisStats['nb'] ?> devis</p>
        </div>
        <a href="?action=nouveau" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouveau devis</a>
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
                        <div class="text-muted small text-uppercase">Brouillons</div>
                        <div class="fs-4 fw-bold text-secondary"><?= $nbBrouillon ?></div>
                    </div>
                    <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                        <i class="bi bi-pencil-square"></i>
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
                        <div class="text-muted small text-uppercase">Envoyés</div>
                        <div class="fs-4 fw-bold text-info"><?= $nbEnvoye ?></div>
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
                        <div class="text-muted small text-uppercase">Acceptés</div>
                        <div class="fs-4 fw-bold text-success"><?= $nbAccepte ?></div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle"></i>
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
                    <?php foreach (['brouillon', 'envoye', 'accepte', 'refuse', 'expire'] as $s): $sl = getStatutDevisLabel($s); ?>
                        <option value="<?= $s ?>" <?= $filtreStatut === $s ? 'selected' : '' ?>><?= $sl['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrer</button>
                <?php if ($filtreStatut || $filtreRecherche): ?>
                    <a href="devis.php" class="btn btn-sm btn-outline-secondary">Effacer</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($devisStats['nb'] === 0): ?>
    <div class="card border-0">
        <div class="card-body text-center empty-state">
            <div class="empty-state-icon">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <h3 class="empty-state-title">Aucun devis trouvé</h3>
            <p class="empty-state-text">
                Commence par créer ton premier devis pour préparer une proposition commerciale et la convertir ensuite en facture ou commande.
            </p>
            <div class="empty-state-actions">
                <a href="?action=nouveau" class="btn btn-primary empty-state-btn">
                    <i class="bi bi-plus-lg"></i> Créer un devis
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 commercial-table-card">
        <div class="card-header commercial-table-card__header">
            <div>
                <div class="commercial-table-card__eyebrow">Pipeline commercial</div>
                <div class="commercial-table-card__title">Liste des devis</div>
            </div>
            <div class="commercial-table-card__stats">
                <span class="commercial-table-pill"><i class="bi bi-check2-circle"></i> Taux d'acceptation : <strong><?= $tauxAcceptation ?>%</strong></span>
                <span class="commercial-table-pill"><i class="bi bi-send"></i> Envoyés : <strong><?= $nbEnvoye ?></strong></span>
                <span class="commercial-table-pill"><i class="bi bi-collection"></i> Total : <strong><?= $devisStats['nb'] ?></strong></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-commercial mb-0">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Objet</th>
                        <th class="text-end">Montant TTC</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devisList as $d): $s = getStatutDevisLabel($d['statut']); ?>
                    <tr>
                        <td><a href="?action=voir&id=<?= $d['id'] ?>" class="numero-link"><?= e($d['numero']) ?></a></td>
                        <td class="date-cell"><?= formatDate($d['date_devis']) ?></td>
                        <td class="client-name"><?= e($d['client_entreprise'] ?: trim($d['client_prenom'] . ' ' . $d['client_nom'])) ?></td>
                        <td class="text-muted"><?= e(mb_strimwidth($d['objet'], 0, 50, '...')) ?></td>
                        <td class="text-end montant-cell"><?= formatMontant($d['montant_ttc']) ?></td>
                        <td><span class="badge-statut bg-<?= $s['class'] ?>"><?= $s['label'] ?></span></td>
                        <td class="text-end">
                            <div class="btn-actions justify-content-end">
                                <a href="?action=voir&id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-info table-action-btn" title="Voir"><i class="bi bi-eye"></i></a>
                                <a href="pdf_generator.php?type=devis&id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger table-action-btn" target="_blank" title="PDF"><i class="bi bi-file-pdf"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white py-3">
            <?= renderPagination($page, $totalPages, $listFiltres) ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php elseif ($action === 'voir'): ?>
<?php
$devis = getDevis((int)($_GET['id'] ?? 0));
if (!$devis) { setFlash('danger', 'Devis introuvable.'); header('Location: devis.php'); exit; }
$lignes = getLignesDevis($devis['id']);
$s = getStatutDevisLabel($devis['statut']);
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-file-earmark-text"></i> Devis <?= e($devis['numero']) ?></h2>
            <p class="text-muted mb-0">
                <span class="badge bg-<?= $s['class'] ?>"><?= $s['label'] ?></span>
                — <?= e($devis['client_entreprise'] ?: trim($devis['client_prenom'] . ' ' . $devis['client_nom'])) ?>
                — Valable jusqu'au <?= formatDate($devis['date_validite']) ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap document-actions">
            <a href="pdf_generator.php?type=devis&id=<?= $devis['id'] ?>" class="btn btn-danger document-action-btn" target="_blank"><i class="bi bi-file-pdf"></i> PDF</a>
            <?php if ($devis['statut'] === 'brouillon' || $devis['statut'] === 'envoye'): ?>
                <a href="?action=modifier&id=<?= $devis['id'] ?>" class="btn btn-warning document-action-btn"><i class="bi bi-pencil"></i> Modifier</a>
            <?php endif; ?>
            <?php if ($devis['statut'] === 'accepte'): ?>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_action" value="convertir_facture">
                    <input type="hidden" name="id" value="<?= $devis['id'] ?>">
                    <button class="btn btn-success document-action-btn"><i class="bi bi-receipt"></i> Facture</button>
                </form>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_action" value="convertir_commande">
                    <input type="hidden" name="id" value="<?= $devis['id'] ?>">
                    <button class="btn btn-info text-white document-action-btn"><i class="bi bi-cart-check"></i> Commande</button>
                </form>
            <?php endif; ?>
            <a href="devis.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
        </div>
    </div>
</div>

<!-- Statut -->
<div class="status-bar mb-4">
    <span class="fw-bold">Statut :</span>
    <span class="badge bg-<?= $s['class'] ?> badge-statut"><?= $s['label'] ?></span>
    <form method="post" class="d-flex gap-2 ms-auto">
        <?= csrfField() ?>
        <input type="hidden" name="post_action" value="changer_statut">
        <input type="hidden" name="id" value="<?= $devis['id'] ?>">
        <select name="statut" class="form-select form-select-sm document-status-control">
            <?php foreach (['brouillon', 'envoye', 'accepte', 'refuse', 'expire'] as $st): $stl = getStatutDevisLabel($st); ?>
                <option value="<?= $st ?>" <?= $devis['statut'] === $st ? 'selected' : '' ?>><?= $stl['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary document-status-btn document-status-radius">Mettre à jour</button>
    </form>
</div>

<div class="doc-summary-grid mb-4">
    <div class="doc-summary-card">
        <small>Date du devis</small>
        <strong><?= formatDate($devis['date_devis']) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Validité</small>
        <strong><?= formatDate($devis['date_validite']) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Total TTC</small>
        <strong><?= formatMontant($devis['montant_ttc']) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Client</small>
        <strong><?= e($devis['client_entreprise'] ?: trim($devis['client_prenom'] . ' ' . $devis['client_nom'])) ?></strong>
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
                <strong><?= e($devis['client_entreprise'] ?: trim($devis['client_prenom'] . ' ' . $devis['client_nom'])) ?></strong><br>
                <?php if ($devis['client_adresse']): ?>
                    <?= e($devis['client_adresse']) ?><br>
                    <?= e($devis['client_cp'] . ' ' . $devis['client_ville']) ?><br>
                <?php endif; ?>
                <?php if ($devis['client_email']): ?><small class="text-muted"><?= e($devis['client_email']) ?></small><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 mt-4 document-wrapper">
    <div class="document-header-bar">
        <div><strong>Objet :</strong> <?= e($devis['objet']) ?></div>
        <div><i class="bi bi-calendar-event"></i> Valable jusqu'au <?= formatDate($devis['date_validite']) ?></div>
    </div>
    <div class="card-body p-0">
        <table class="table table-detail mb-0">
            <thead>
                <tr>
                    <th class="col-w-40">#</th>
                    <th>Description</th>
                    <th class="text-end col-w-80">Qté</th>
                    <th class="col-w-60">Unité</th>
                    <th class="text-end col-w-120">P.U. HT</th>
                    <?php if ($conf['tva_applicable']): ?><th class="text-end col-w-80">TVA %</th><?php endif; ?>
                    <th class="text-end col-w-120">Total HT</th>
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
                <tr><td colspan="<?= $conf['tva_applicable'] ? 6 : 5 ?>" class="text-end">Total HT</td><td class="text-end"><?= formatMontant($devis['montant_ht']) ?></td></tr>
                <?php if ($conf['tva_applicable']): ?>
                <tr><td colspan="6" class="text-end">TVA</td><td class="text-end"><?= formatMontant($devis['montant_tva']) ?></td></tr>
                <?php endif; ?>
                <tr><td colspan="<?= $conf['tva_applicable'] ? 6 : 5 ?>" class="text-end fs-5 fw-bold">Total TTC</td><td class="text-end fs-5 fw-bold"><?= formatMontant($devis['montant_ttc']) ?></td></tr>
            </tfoot>
        </table>
    </div>
    <?php $devisConditions = getConditionsDocumentVente('devis', $devis); ?>
    <?php if ($devis['notes'] || $devisConditions): ?>
    <div class="card-footer terms-card">
        <?php if ($devis['notes']): ?><p class="mb-3"><strong>Notes :</strong> <?= nl2br(e($devis['notes'])) ?></p><?php endif; ?>
        <?php if ($devisConditions): ?>
            <div class="terms-card__title"><i class="bi bi-shield-check"></i> Conditions générales de vente</div>
            <div class="terms-card__content"><?= nl2br(e($devisConditions)) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'nouveau' || $action === 'modifier'): ?>
<?php
$devis = null;
$lignes = [];
if ($action === 'modifier') {
    $devis = getDevis((int)($_GET['id'] ?? 0));
    if (!$devis) { setFlash('danger', 'Devis introuvable.'); header('Location: devis.php'); exit; }
    $lignes = getLignesDevis($devis['id']);
}
$defaultValidite = (int)getParam('validite_devis', '30');
$preselectedClient = (int)($_GET['client_id'] ?? ($devis['client_id'] ?? 0));
$generatedConditions = genererConditionsDocumentVente('devis', $devis ?? [
    'date_validite' => date('Y-m-d', strtotime("+{$defaultValidite} days")),
]);
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-file-earmark-text"></i> <?= $devis ? 'Modifier le devis ' . e($devis['numero']) : 'Nouveau devis' ?></h2>
            <p class="text-muted mb-0"><?= $devis ? 'Modifiez les détails du devis' : 'Renseignez les informations du devis' ?></p>
        </div>
        <a href="devis.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<form method="post" id="formDevis" class="doc-workspace">
    <?= csrfField() ?>
    <input type="hidden" name="post_action" value="sauvegarder">
    <?php if ($devis): ?><input type="hidden" name="devis_id" value="<?= $devis['id'] ?>"><?php endif; ?>

    <div class="card border-0 mb-4 doc-form-section doc-form-section--meta">
        <div class="card-header doc-form-section__header">
            <div>
                <i class="bi bi-info-circle"></i> Informations générales
                <small class="doc-form-section__subtitle">Client, statut et validité du devis</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 doc-meta-grid">
                <div class="col-md-6">
                    <label class="form-label">Client *</label>
                    <select name="client_id" class="form-select" required>
                        <option value="">— Choisir un client —</option>
                        <?php foreach ($clientsList as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $preselectedClient === $c['id'] ? 'selected' : '' ?>>
                                <?= e(clientNomComplet($c)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-select">
                        <?php foreach (['brouillon', 'envoye', 'accepte', 'refuse'] as $st): $stl = getStatutDevisLabel($st); ?>
                            <option value="<?= $st ?>" <?= ($devis['statut'] ?? 'brouillon') === $st ? 'selected' : '' ?>><?= $stl['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date du devis</label>
                    <input type="date" name="date_devis" class="form-control" required value="<?= e($devis['date_devis'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date de validité</label>
                    <input type="date" name="date_validite" class="form-control" required value="<?= e($devis['date_validite'] ?? date('Y-m-d', strtotime("+{$defaultValidite} days"))) ?>">
                </div>
                <div class="col-md-9">
                    <label class="form-label">Objet *</label>
                    <input type="text" name="objet" class="form-control" required value="<?= e($devis['objet'] ?? '') ?>" placeholder="Description du devis">
                </div>
            </div>
        </div>
    </div>

    <!-- Lignes du devis -->
    <div class="card border-0 mb-4 doc-form-section doc-form-section--lines">
        <div class="card-header d-flex justify-content-between align-items-center doc-form-section__header">
            <div class="doc-lines-title">
                <span><i class="bi bi-list-ol"></i> Lignes du devis</span>
                <small class="doc-form-section__subtitle">Prépare une proposition claire et chiffrée</small>
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
                        <th class="col-w-80">Qté</th>
                        <th class="col-w-80">Unité</th>
                        <th class="col-w-120">P.U. HT</th>
                        <?php if ($conf['tva_applicable']): ?><th class="col-w-80">TVA %</th><?php endif; ?>
                        <th class="col-w-120">Total HT</th>
                        <th class="col-w-40"></th>
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
                <small class="doc-form-section__subtitle">Commentaires internes, mentions et conditions commerciales</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 doc-notes-grid">
                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3"><?= e($devis['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <label class="form-label mb-0">Conditions générales de vente</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick='document.querySelector("#formDevis textarea[name=&quot;conditions&quot;]").value = <?= json_encode($generatedConditions) ?>;'>
                            <i class="bi bi-magic"></i> Générer les CGV
                        </button>
                    </div>
                    <textarea name="conditions" class="form-control" rows="9"><?= e($devis['conditions'] ?? $generatedConditions) ?></textarea>
                    <small class="text-muted">Texte automatique premium, modifiable avant enregistrement.</small>
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
            <button type="submit" class="btn btn-primary btn-lg document-action-btn"><i class="bi bi-check-lg"></i> Enregistrer le devis</button>
            <a href="devis.php" class="btn btn-outline-secondary btn-lg document-action-btn">Annuler</a>
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
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>
