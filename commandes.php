<?php
/**
 * Gestion des commandes
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Commandes';

$action = $_GET['action'] ?? 'liste';
$conf = getRegimeConfig();

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Erreur de sécurité.');
        header('Location: commandes.php');
        exit;
    }
    
    $postAction = $_POST['post_action'] ?? '';
    
    if ($postAction === 'sauvegarder') {
        $data = [
            'client_id'     => (int)$_POST['client_id'],
            'devis_id'      => (int)($_POST['devis_id'] ?? 0) ?: null,
            'date_commande' => $_POST['date_commande'],
            'statut'        => $_POST['statut'] ?? 'en_attente',
            'objet'         => trim($_POST['objet'] ?? ''),
            'notes'         => trim($_POST['notes'] ?? ''),
        ];

        if (empty($data['objet']) || empty($data['client_id'])) {
            setFlash('danger', 'L\'objet et le client sont obligatoires.');
            header('Location: commandes.php?action=nouvelle');
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

        $editId = !empty($_POST['commande_id']) ? (int)$_POST['commande_id'] : null;
        if (!$editId) {
            $data['numero'] = genererNumero('commande');
        }
        $id = sauvegarderCommande($data, $lignes, $editId);
        setFlash('success', $editId ? 'Commande modifiée.' : 'Commande créée.');
        header('Location: commandes.php?action=voir&id=' . $id);
        exit;
    }

    if ($postAction === 'supprimer') {
        supprimerCommande((int)$_POST['id']);
        setFlash('success', 'Commande supprimée.');
        header('Location: commandes.php');
        exit;
    }

    if ($postAction === 'changer_statut') {
        $db = getDB();
        $db->prepare('UPDATE commandes SET statut = ? WHERE id = ?')->execute([$_POST['statut'], (int)$_POST['id']]);
        setFlash('success', 'Statut mis à jour.');
        header('Location: commandes.php?action=voir&id=' . (int)$_POST['id']);
        exit;
    }

if ($postAction === 'convertir_facture') {
        $cmd = getCommande((int)$_POST['id']);
        $lignesCmd = getLignesCommande($cmd['id']);
        $factureData = [
            'numero'       => genererNumero('facture'),
            'client_id'    => $cmd['client_id'],
            'commande_id'  => $cmd['id'],
            'date_facture'  => date('Y-m-d'),
            'date_echeance' => date('Y-m-d', strtotime('+' . (int)getParam('validite_devis', '30') . ' days')),
            'statut'       => 'brouillon',
            'objet'        => $cmd['objet'],
            'notes'        => '',
            'conditions'   => genererConditionsDocumentVente('facture', $cmd),
        ];
        $factureId = sauvegarderFacture($factureData, $lignesCmd);
        setFlash('success', 'Facture créée à partir de la commande.');
        header('Location: factures.php?action=voir&id=' . $factureId);
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
$commandesStats = getCommandesStats($listFiltres);
$totalPages = max(1, (int) ceil($commandesStats['nb'] / $perPage));
$page = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));
$commandesList = getCommandesList($listFiltres + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);
$totalHT = $commandesStats['total_ttc'];
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-cart-check"></i> Commandes</h2>
            <p class="text-muted mb-0"><?= $commandesStats['nb'] ?> commande<?= $commandesStats['nb'] > 1 ? 's' : '' ?></p>
        </div>
        <a href="?action=nouvelle" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouvelle commande</a>
    </div>
</div>

<!-- Stat cards -->
<?php
$nbEnAttente = $commandesStats['nb_en_attente'];
$nbConfirmee = $commandesStats['nb_confirmee'];
$nbLivree = $commandesStats['nb_livree'];
?>
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Total TTC</div>
                        <div class="fs-4 fw-bold text-primary"><?= formatMontant($totalHT) ?></div>
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
                        <div class="text-muted small text-uppercase">En attente</div>
                        <div class="fs-4 fw-bold text-warning"><?= $nbEnAttente ?></div>
                    </div>
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-hourglass-split"></i>
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
                        <div class="text-muted small text-uppercase">Confirmées</div>
                        <div class="fs-4 fw-bold text-info"><?= $nbConfirmee ?></div>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-check2-circle"></i>
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
                        <div class="text-muted small text-uppercase">Livrées</div>
                        <div class="fs-4 fw-bold text-success"><?= $nbLivree ?></div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-truck"></i>
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
                    <?php foreach (['en_attente', 'confirmee', 'en_cours', 'livree', 'annulee'] as $s): $sl = getStatutCommandeLabel($s); ?>
                        <option value="<?= $s ?>" <?= $filtreStatut === $s ? 'selected' : '' ?>><?= $sl['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrer</button>
                <?php if ($filtreStatut || $filtreRecherche): ?>
                    <a href="commandes.php" class="btn btn-sm btn-outline-secondary">Effacer</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($commandesStats['nb'] === 0): ?>
    <div class="card border-0">
        <div class="card-body text-center empty-state">
            <div class="empty-state-icon">
                <i class="bi bi-cart-check"></i>
            </div>
            <h3 class="empty-state-title">Aucune commande trouvée</h3>
            <p class="empty-state-text">
                Ajoute une commande pour suivre les ventes confirmées et les transformer plus facilement en factures.
            </p>
            <div class="empty-state-actions">
                <a href="?action=nouvelle" class="btn btn-primary empty-state-btn"><i class="bi bi-plus-lg"></i> Créer une commande</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 commercial-table-card">
        <div class="card-header commercial-table-card__header">
            <div>
                <div class="commercial-table-card__eyebrow">Suivi des commandes</div>
                <div class="commercial-table-card__title">Liste des commandes</div>
            </div>
            <div class="commercial-table-card__stats">
                <span class="commercial-table-pill"><i class="bi bi-hourglass-split"></i> En attente : <strong><?= $nbEnAttente ?></strong></span>
                <span class="commercial-table-pill"><i class="bi bi-box-seam"></i> Confirmées : <strong><?= $nbConfirmee ?></strong></span>
                <span class="commercial-table-pill"><i class="bi bi-collection"></i> Total : <strong><?= $commandesStats['nb'] ?></strong></span>
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
                    <?php foreach ($commandesList as $c): $s = getStatutCommandeLabel($c['statut']); ?>
                    <tr>
                        <td><a href="?action=voir&id=<?= $c['id'] ?>" class="numero-link"><?= e($c['numero']) ?></a></td>
                        <td class="date-cell"><?= formatDate($c['date_commande']) ?></td>
                        <td class="client-name"><?= e($c['client_entreprise'] ?: trim($c['client_prenom'] . ' ' . $c['client_nom'])) ?></td>
                        <td class="text-muted"><?= e(mb_strimwidth($c['objet'], 0, 50, '...')) ?></td>
                        <td class="text-end montant-cell"><?= formatMontant($c['montant_ttc']) ?></td>
                        <td><span class="badge-statut bg-<?= $s['class'] ?>"><?= $s['label'] ?></span></td>
                        <td class="text-end">
                            <div class="btn-actions justify-content-end">
                                <a href="?action=voir&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info table-action-btn" title="Voir"><i class="bi bi-eye"></i></a>
                                <a href="pdf_generator.php?type=commande&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger table-action-btn" target="_blank" title="PDF"><i class="bi bi-file-pdf"></i></a>
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
$commande = getCommande((int)($_GET['id'] ?? 0));
if (!$commande) { setFlash('danger', 'Commande introuvable.'); header('Location: commandes.php'); exit; }
$lignes = getLignesCommande($commande['id']);
$s = getStatutCommandeLabel($commande['statut']);
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-cart-check"></i> Commande <?= e($commande['numero']) ?></h2>
            <p class="text-muted mb-0">
                <span class="badge bg-<?= $s['class'] ?>"><?= $s['label'] ?></span>
                — <?= e($commande['client_entreprise'] ?: trim($commande['client_prenom'] . ' ' . $commande['client_nom'])) ?>
                — <?= formatDate($commande['date_commande']) ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap document-actions">
            <a href="pdf_generator.php?type=commande&id=<?= $commande['id'] ?>" class="btn btn-danger document-action-btn" target="_blank"><i class="bi bi-file-pdf"></i> PDF</a>
            <?php if (in_array($commande['statut'], ['en_attente', 'confirmee'])): ?>
                <a href="?action=modifier&id=<?= $commande['id'] ?>" class="btn btn-warning document-action-btn"><i class="bi bi-pencil"></i> Modifier</a>
            <?php endif; ?>
            <?php if (in_array($commande['statut'], ['confirmee', 'livree'])): ?>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_action" value="convertir_facture">
                    <input type="hidden" name="id" value="<?= $commande['id'] ?>">
                    <button class="btn btn-success document-action-btn"><i class="bi bi-receipt"></i> Créer facture</button>
                </form>
            <?php endif; ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette commande ?')">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="supprimer">
                <input type="hidden" name="id" value="<?= $commande['id'] ?>">
                <button class="btn btn-outline-danger document-action-btn document-action-btn--icon"><i class="bi bi-trash"></i></button>
            </form>
            <a href="commandes.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
        </div>
    </div>
</div>

<div class="doc-summary-grid mb-4">
    <div class="doc-summary-card">
        <small>Date de commande</small>
        <strong><?= formatDate($commande['date_commande']) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Statut</small>
        <strong><?= e($s['label']) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Total TTC</small>
        <strong><?= formatMontant($commande['montant_ttc']) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Client</small>
        <strong><?= e($commande['client_entreprise'] ?: trim($commande['client_prenom'] . ' ' . $commande['client_nom'])) ?></strong>
    </div>
</div>

<!-- Changement de statut -->
<div class="status-bar mb-4">
    <span class="fw-bold">Statut :</span>
    <span class="badge bg-<?= $s['class'] ?> badge-statut"><?= $s['label'] ?></span>
    <form method="post" class="d-flex gap-2 ms-auto">
        <?= csrfField() ?>
        <input type="hidden" name="post_action" value="changer_statut">
        <input type="hidden" name="id" value="<?= $commande['id'] ?>">
        <select name="statut" class="form-select form-select-sm document-status-control">
            <?php foreach (['en_attente', 'confirmee', 'en_cours', 'livree', 'annulee'] as $st): $stl = getStatutCommandeLabel($st); ?>
                <option value="<?= $st ?>" <?= $commande['statut'] === $st ? 'selected' : '' ?>><?= $stl['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary document-status-btn document-status-radius">Mettre à jour</button>
    </form>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card info-card h-100">
            <div class="card-header"><i class="bi bi-info-circle"></i> Informations</div>
            <div class="card-body">
                <p class="mb-2"><strong>Date :</strong> <?= formatDate($commande['date_commande']) ?></p>
                <p class="mb-2"><strong>Objet :</strong> <?= e($commande['objet']) ?></p>
                <?php if ($commande['devis_id']): ?>
                    <p class="mb-0"><strong>Devis lié :</strong> <a href="devis.php?action=voir&id=<?= $commande['devis_id'] ?>">Voir le devis</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card info-card h-100">
            <div class="card-header"><i class="bi bi-person"></i> Client</div>
            <div class="card-body">
                <p class="mb-2"><strong><?= e($commande['client_entreprise'] ?: trim($commande['client_prenom'] . ' ' . $commande['client_nom'])) ?></strong></p>
                <?php if ($commande['client_adresse']): ?>
                    <p class="mb-0 text-muted">
                        <?= e($commande['client_adresse']) ?><br>
                        <?= e($commande['client_cp'] . ' ' . $commande['client_ville']) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 mt-4 document-wrapper">
    <div class="document-header-bar">
        <div><strong>Objet :</strong> <?= e($commande['objet']) ?></div>
        <div><i class="bi bi-calendar-event"></i> Commande du <?= formatDate($commande['date_commande']) ?></div>
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
                <tr><td colspan="<?= $conf['tva_applicable'] ? 6 : 5 ?>" class="text-end">Total HT</td><td class="text-end"><?= formatMontant($commande['montant_ht']) ?></td></tr>
                <?php if ($conf['tva_applicable']): ?>
                <tr><td colspan="6" class="text-end">TVA</td><td class="text-end"><?= formatMontant($commande['montant_tva']) ?></td></tr>
                <?php endif; ?>
                <tr><td colspan="<?= $conf['tva_applicable'] ? 6 : 5 ?>" class="text-end fs-5 fw-bold">Total TTC</td><td class="text-end fs-5 fw-bold"><?= formatMontant($commande['montant_ttc']) ?></td></tr>
            </tfoot>
        </table>
    </div>
    <?php $commandeConditions = getConditionsDocumentVente('commande', $commande); ?>
    <?php if ($commande['notes'] || $commandeConditions): ?>
    <div class="card-footer terms-card">
        <?php if ($commande['notes']): ?><p class="mb-3"><strong>Notes :</strong> <?= nl2br(e($commande['notes'])) ?></p><?php endif; ?>
        <?php if ($commandeConditions): ?>
            <div class="terms-card__title"><i class="bi bi-shield-check"></i> Conditions générales de vente</div>
            <div class="terms-card__content"><?= nl2br(e($commandeConditions)) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'nouvelle' || $action === 'modifier'): ?>
<?php
$commande = null;
$lignes = [];
if ($action === 'modifier') {
    $commande = getCommande((int)($_GET['id'] ?? 0));
    if (!$commande) { setFlash('danger', 'Commande introuvable.'); header('Location: commandes.php'); exit; }
    $lignes = getLignesCommande($commande['id']);
}
$preselectedClient = (int)($_GET['client_id'] ?? ($commande['client_id'] ?? 0));
$generatedConditions = genererConditionsDocumentVente('commande', $commande ?? []);
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-cart-check"></i> <?= $commande ? 'Modifier commande ' . e($commande['numero']) : 'Nouvelle commande' ?></h2>
            <p class="text-muted mb-0"><?= $commande ? 'Modifiez les détails de la commande' : 'Renseignez les informations de la commande' ?></p>
        </div>
        <a href="commandes.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<form method="post" id="formCommande" class="doc-workspace">
    <?= csrfField() ?>
    <input type="hidden" name="post_action" value="sauvegarder">
    <?php if ($commande): ?><input type="hidden" name="commande_id" value="<?= $commande['id'] ?>"><?php endif; ?>

    <div class="card border-0 mb-4 doc-form-section doc-form-section--meta">
        <div class="card-header doc-form-section__header">
            <div>
                <i class="bi bi-info-circle"></i> Informations
                <small class="doc-form-section__subtitle">Contexte commercial et suivi de la commande</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 doc-meta-grid">
                <div class="col-md-5">
                    <label class="form-label">Client *</label>
                    <select name="client_id" class="form-select" required>
                        <option value="">— Choisir un client —</option>
                        <?php foreach ($clientsList as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $preselectedClient === $c['id'] ? 'selected' : '' ?>><?= e(clientNomComplet($c)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="date_commande" class="form-control" required value="<?= e($commande['date_commande'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-select">
                        <?php foreach (['en_attente', 'confirmee', 'en_cours'] as $st): $stl = getStatutCommandeLabel($st); ?>
                            <option value="<?= $st ?>" <?= ($commande['statut'] ?? 'en_attente') === $st ? 'selected' : '' ?>><?= $stl['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Objet *</label>
                    <input type="text" name="objet" class="form-control" required value="<?= e($commande['objet'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4 doc-form-section doc-form-section--lines">
        <div class="card-header d-flex justify-content-between align-items-center doc-form-section__header">
            <div class="doc-lines-title">
                <span><i class="bi bi-list-ol"></i> Lignes de la commande</span>
                <small class="doc-form-section__subtitle">Prépare les éléments à livrer ou à facturer</small>
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
                    <?php $items = !empty($lignes) ? $lignes : [['description' => '', 'quantite' => 1, 'unite' => 'unité', 'prix_unitaire_ht' => 0, 'taux_tva' => 20, 'montant_ht' => 0]]; ?>
                    <?php foreach ($items as $l): ?>
                    <tr class="ligne-devis">
                        <td><input type="text" name="ligne_description[]" class="form-control form-control-sm" required value="<?= e($l['description']) ?>" placeholder="Description"></td>
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
                <strong><i class="bi bi-journal-text"></i> Notes de commande</strong>
                <small class="doc-form-section__subtitle">Consignes internes, informations de livraison ou contexte commercial</small>
            </div>
        </div>
        <div class="card-body">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="3"><?= e($commande['notes'] ?? '') ?></textarea>
            <div class="generated-terms-preview mt-3">
                <div class="terms-card__title"><i class="bi bi-shield-check"></i> CGV générées automatiquement sur le document</div>
                <div class="terms-card__content"><?= nl2br(e($generatedConditions)) ?></div>
            </div>
        </div>
    </div>

    <div class="doc-submit-bar">
        <div class="doc-submit-bar__summary">
            <span class="doc-submit-bar__label">Montant HT courant</span>
            <strong id="grandTotalHTBottom">0,00 €</strong>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary btn-lg document-action-btn"><i class="bi bi-check-lg"></i> Enregistrer</button>
            <a href="commandes.php" class="btn btn-outline-secondary btn-lg document-action-btn">Annuler</a>
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
