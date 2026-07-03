<?php
/**
 * Gestion des clients
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Clients';

$action = $_GET['action'] ?? 'liste';

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Erreur de sécurité. Veuillez réessayer.');
        header('Location: clients.php');
        exit;
    }
    
    $postAction = $_POST['action'] ?? '';
    
    if ($postAction === 'ajouter' || $postAction === 'modifier') {
        $data = [
            'type'        => $_POST['type'] ?? 'professionnel',
            'nom'         => trim($_POST['nom'] ?? ''),
            'prenom'      => trim($_POST['prenom'] ?? ''),
            'entreprise'  => trim($_POST['entreprise'] ?? ''),
            'email'       => trim($_POST['email'] ?? ''),
            'telephone'   => trim($_POST['telephone'] ?? ''),
            'adresse'     => trim($_POST['adresse'] ?? ''),
            'code_postal' => trim($_POST['code_postal'] ?? ''),
            'ville'       => trim($_POST['ville'] ?? ''),
            'pays'        => trim($_POST['pays'] ?? 'France'),
            'siret'       => trim($_POST['siret'] ?? ''),
            'siren'       => trim($_POST['siren'] ?? ''),
            'numero_tva'  => trim($_POST['numero_tva'] ?? ''),
            'notes'       => trim($_POST['notes'] ?? ''),
        ];

        if (empty($data['nom'])) {
            setFlash('danger', 'Le nom est obligatoire.');
            header('Location: clients.php?action=' . ($postAction === 'modifier' ? 'modifier&id=' . (int)$_POST['id'] : 'ajouter'));
            exit;
        }

        if ($postAction === 'modifier') {
            modifierClient((int)$_POST['id'], $data);
            setFlash('success', 'Client modifié avec succès.');
        } else {
            ajouterClient($data);
            setFlash('success', 'Client ajouté avec succès.');
        }
        header('Location: clients.php');
        exit;
    }

    if ($postAction === 'supprimer') {
        $hard = supprimerClient((int)$_POST['id']);
        setFlash('success', $hard ? 'Client supprimé.' : 'Client désactivé (il a des documents liés).');
        header('Location: clients.php');
        exit;
    }
}

// --- Affichage ---
$recherche = $_GET['recherche'] ?? '';
$clients = getClients(['recherche' => $recherche]);

include 'header.php';
include 'commercial_header.php';
?>

<?php if ($action === 'liste'): ?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-people"></i> Clients</h2>
            <p class="text-muted mb-0"><?= count($clients) ?> client<?= count($clients) > 1 ? 's' : '' ?> enregistré<?= count($clients) > 1 ? 's' : '' ?></p>
        </div>
        <a href="?action=ajouter" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nouveau client</a>
    </div>
</div>

<!-- Stat cards -->
<?php
$nbPro = count(array_filter($clients, fn($c) => $c['type'] === 'professionnel'));
$nbParticulier = count($clients) - $nbPro;
?>
<div class="row g-3 mb-4">
    <div class="col-md-4 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Total clients</div>
                        <div class="fs-4 fw-bold"><?= count($clients) ?></div>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Professionnels</div>
                        <div class="fs-4 fw-bold text-info"><?= $nbPro ?></div>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-building"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Particuliers</div>
                        <div class="fs-4 fw-bold text-secondary"><?= $nbParticulier ?></div>
                    </div>
                    <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                        <i class="bi bi-person"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recherche -->
<div class="card border-0 mb-4 commercial-filter-card">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-auto flex-grow-1">
                <input type="text" name="recherche" class="form-control form-control-sm" placeholder="Rechercher un client (nom, entreprise, email, ville)..." value="<?= e($recherche) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Rechercher</button>
                <?php if ($recherche): ?>
                    <a href="clients.php" class="btn btn-sm btn-outline-secondary">Effacer</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (empty($clients)): ?>
    <div class="card border-0">
        <div class="card-body text-center empty-state">
            <div class="empty-state-icon">
                <i class="bi bi-people"></i>
            </div>
            <h3 class="empty-state-title">Aucun client enregistré</h3>
            <p class="empty-state-text">
                Crée une première fiche client pour préparer tes devis, factures et retrouver rapidement ses coordonnées.
            </p>
            <div class="empty-state-actions">
                <a href="?action=ajouter" class="btn btn-primary empty-state-btn"><i class="bi bi-person-plus"></i> Ajouter un client</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 commercial-table-card">
        <div class="card-header commercial-table-card__header">
            <div>
                <div class="commercial-table-card__eyebrow">Fichier clients</div>
                <div class="commercial-table-card__title">Liste des clients</div>
            </div>
            <div class="commercial-table-card__stats">
                <span class="commercial-table-pill"><i class="bi bi-building"></i> Professionnels : <strong><?= $nbPro ?></strong></span>
                <span class="commercial-table-pill"><i class="bi bi-person"></i> Particuliers : <strong><?= $nbParticulier ?></strong></span>
                <span class="commercial-table-pill"><i class="bi bi-collection"></i> Total : <strong><?= count($clients) ?></strong></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-commercial mb-0">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Email</th>
                        <th>Téléphone</th>
                        <th>Ville</th>
                        <th>Type</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $c): ?>
                    <tr>
                        <td>
                            <div class="client-name"><?= e($c['entreprise'] ?: $c['nom']) ?></div>
                            <?php if ($c['entreprise'] && $c['nom']): ?>
                                <br><small class="text-muted"><?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?></small>
                            <?php elseif ($c['prenom']): ?>
                                <br><small class="text-muted"><?= e($c['prenom']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="date-cell"><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="date-cell"><?= e($c['telephone'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="date-cell"><?= e($c['ville'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                        <td><span class="badge-statut bg-<?= $c['type'] === 'professionnel' ? 'primary' : 'info' ?>"><?= ucfirst(e($c['type'])) ?></span></td>
                        <td class="text-end text-nowrap">
                            <a href="?action=voir&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info table-action-btn" title="Voir"><i class="bi bi-eye"></i></a>
                            <a href="?action=modifier&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary table-action-btn" title="Modifier"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce client ?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger table-action-btn" title="Supprimer"><i class="bi bi-trash"></i></button>
                            </form>
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
$client = getClient((int)($_GET['id'] ?? 0));
if (!$client) { setFlash('danger', 'Client introuvable.'); header('Location: clients.php'); exit; }
$devisClient = getDevisList(['client_id' => $client['id']]);
$facturesClient = getFacturesList(['client_id' => $client['id']]);
$commandesClient = getCommandesList(['client_id' => $client['id']]);
$totalFacture = array_sum(array_map(fn($f) => (float)$f['montant_ttc'], $facturesClient));
$totalPaye = array_sum(array_map(fn($f) => (float)$f['montant_paye'], $facturesClient));
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-person"></i> <?= e(clientNomComplet($client)) ?></h2>
            <p class="text-muted mb-0">
                <span class="badge bg-<?= $client['type'] === 'professionnel' ? 'primary' : 'info' ?>"><?= ucfirst(e($client['type'])) ?></span>
                <?php if ($client['ville']): ?> — <?= e($client['code_postal'] . ' ' . $client['ville']) ?><?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap document-actions">
            <a href="devis.php?action=nouveau&client_id=<?= $client['id'] ?>" class="btn btn-primary document-action-btn"><i class="bi bi-file-earmark-plus"></i> Nouveau devis</a>
            <a href="factures.php?action=nouvelle&client_id=<?= $client['id'] ?>" class="btn btn-success document-action-btn"><i class="bi bi-receipt-cutoff"></i> Nouvelle facture</a>
            <a href="?action=modifier&id=<?= $client['id'] ?>" class="btn btn-warning document-action-btn"><i class="bi bi-pencil"></i> Modifier</a>
            <a href="clients.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
        </div>
    </div>
</div>

<div class="doc-summary-grid mb-4">
    <div class="doc-summary-card">
        <small>Type de client</small>
        <strong><?= ucfirst(e($client['type'])) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>Pièces commerciales</small>
        <strong><?= count($devisClient) + count($facturesClient) + count($commandesClient) ?></strong>
    </div>
    <div class="doc-summary-card">
        <small>CA facturé</small>
        <strong><?= formatMontant($totalFacture) ?></strong>
    </div>
    <div class="doc-summary-card <?= ($totalFacture - $totalPaye) > 0.01 ? 'doc-summary-card--alert' : '' ?>">
        <small>Reste à encaisser</small>
        <strong><?= formatMontant(max(0, $totalFacture - $totalPaye)) ?></strong>
    </div>
</div>

<!-- Stat cards client -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Devis</div>
                        <div class="fs-4 fw-bold"><?= count($devisClient) ?></div>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-file-earmark-text"></i>
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
                        <div class="text-muted small text-uppercase">Factures</div>
                        <div class="fs-4 fw-bold"><?= count($facturesClient) ?></div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-receipt"></i>
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
                        <div class="text-muted small text-uppercase">CA facturé</div>
                        <div class="fs-4 fw-bold text-primary"><?= formatMontant($totalFacture) ?></div>
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
                        <div class="text-muted small text-uppercase">Payé</div>
                        <div class="fs-4 fw-bold text-success"><?= formatMontant($totalPaye) ?></div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 info-card h-100">
            <div class="card-header"><i class="bi bi-info-circle"></i> Informations</div>
            <div class="card-body">
                <?php if ($client['entreprise']): ?><p class="mb-2"><strong>Entreprise :</strong> <?= e($client['entreprise']) ?></p><?php endif; ?>
                <p class="mb-2"><strong>Nom :</strong> <?= e(trim($client['prenom'] . ' ' . $client['nom'])) ?></p>
                <?php if ($client['email']): ?><p class="mb-2"><strong>Email :</strong> <a href="mailto:<?= e($client['email']) ?>"><?= e($client['email']) ?></a></p><?php endif; ?>
                <?php if ($client['telephone']): ?><p class="mb-2"><strong>Tél :</strong> <?= e($client['telephone']) ?></p><?php endif; ?>
                <?php if ($client['adresse']): ?>
                    <hr>
                    <p class="mb-1"><strong>Adresse :</strong></p>
                    <p class="mb-0 text-muted">
                        <?= e($client['adresse']) ?><br>
                        <?= e($client['code_postal'] . ' ' . $client['ville']) ?>
                        <?php if ($client['pays'] !== 'France'): ?><br><?= e($client['pays']) ?><?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($client['siret'] || $client['numero_tva']): ?>
                    <hr>
                    <?php if ($client['siret']): ?><p class="mb-2"><strong>SIRET :</strong> <?= e($client['siret']) ?></p><?php endif; ?>
                    <?php if ($client['numero_tva']): ?><p class="mb-2"><strong>N° TVA :</strong> <?= e($client['numero_tva']) ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($client['notes']): ?>
                    <hr>
                    <p class="mb-0"><strong>Notes :</strong><br><small class="text-muted"><?= nl2br(e($client['notes'])) ?></small></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <!-- Devis -->
        <div class="card border-0 mb-3 commercial-table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-text"></i> Devis (<?= count($devisClient) ?>)</span>
                <a href="devis.php?action=nouveau&client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-primary document-status-btn"><i class="bi bi-plus"></i> Nouveau</a>
            </div>
            <?php if (!empty($devisClient)): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-commercial mb-0">
                    <thead><tr><th>N°</th><th>Date</th><th>Objet</th><th class="text-end">TTC</th><th>Statut</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($devisClient, 0, 5) as $d): $s = getStatutDevisLabel($d['statut']); ?>
                        <tr>
                            <td><a href="devis.php?action=voir&id=<?= $d['id'] ?>" class="numero-link"><?= e($d['numero']) ?></a></td>
                            <td class="date-cell"><?= formatDate($d['date_devis']) ?></td>
                            <td><?= e(mb_strimwidth($d['objet'], 0, 40, '...')) ?></td>
                            <td class="text-end montant-cell"><?= formatMontant($d['montant_ttc']) ?></td>
                            <td><span class="badge-statut bg-<?= $s['class'] ?>"><?= $s['label'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card-body text-muted text-center py-3"><i class="bi bi-inbox"></i> Aucun devis</div>
            <?php endif; ?>
        </div>

        <!-- Factures -->
        <div class="card border-0 mb-3 commercial-table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-receipt"></i> Factures (<?= count($facturesClient) ?>)</span>
                <a href="factures.php?action=nouvelle&client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-success document-status-btn"><i class="bi bi-plus"></i> Nouvelle</a>
            </div>
            <?php if (!empty($facturesClient)): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-commercial mb-0">
                    <thead><tr><th>N°</th><th>Date</th><th>Objet</th><th class="text-end">TTC</th><th class="text-end">Payé</th><th>Statut</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($facturesClient, 0, 5) as $f): $s = getStatutFactureLabel($f['statut']); ?>
                        <tr>
                            <td><a href="factures.php?action=voir&id=<?= $f['id'] ?>" class="numero-link"><?= e($f['numero']) ?></a></td>
                            <td class="date-cell"><?= formatDate($f['date_facture']) ?></td>
                            <td><?= e(mb_strimwidth($f['objet'], 0, 40, '...')) ?></td>
                            <td class="text-end montant-cell"><?= formatMontant($f['montant_ttc']) ?></td>
                            <td class="text-end montant-cell text-success"><?= formatMontant($f['montant_paye']) ?></td>
                            <td><span class="badge-statut bg-<?= $s['class'] ?>"><?= $s['label'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card-body text-muted text-center py-3"><i class="bi bi-inbox"></i> Aucune facture</div>
            <?php endif; ?>
        </div>

        <!-- Commandes -->
        <?php if (!empty($commandesClient)): ?>
        <div class="card border-0 commercial-table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cart-check"></i> Commandes (<?= count($commandesClient) ?>)</span>
                <a href="commandes.php?action=nouvelle&client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-warning document-status-btn"><i class="bi bi-plus"></i> Nouvelle</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-commercial mb-0">
                    <thead><tr><th>N°</th><th>Date</th><th>Objet</th><th class="text-end">TTC</th><th>Statut</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($commandesClient, 0, 5) as $cmd): $s = getStatutCommandeLabel($cmd['statut']); ?>
                        <tr>
                            <td><a href="commandes.php?action=voir&id=<?= $cmd['id'] ?>" class="numero-link"><?= e($cmd['numero']) ?></a></td>
                            <td class="date-cell"><?= formatDate($cmd['date_commande']) ?></td>
                            <td><?= e(mb_strimwidth($cmd['objet'], 0, 40, '...')) ?></td>
                            <td class="text-end montant-cell"><?= formatMontant($cmd['montant_ttc']) ?></td>
                            <td><span class="badge-statut bg-<?= $s['class'] ?>"><?= $s['label'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'ajouter' || $action === 'modifier'): ?>
<?php
$client = null;
if ($action === 'modifier') {
    $client = getClient((int)($_GET['id'] ?? 0));
    if (!$client) { setFlash('danger', 'Client introuvable.'); header('Location: clients.php'); exit; }
}
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-person-<?= $client ? 'gear' : 'plus' ?>"></i> <?= $client ? 'Modifier le client' : 'Nouveau client' ?></h2>
            <p class="text-muted mb-0"><?= $client ? e(clientNomComplet($client)) : 'Renseignez les informations du client' ?></p>
        </div>
        <a href="clients.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<form method="post" class="doc-workspace">
    <div class="card border-0 mb-4 doc-form-section">
        <div class="card-header doc-form-section__header">
            <div>
                <strong><i class="bi bi-person-vcard"></i> Identité et contact</strong>
                <small class="doc-form-section__subtitle">Coordonnées principales et qualification du tiers</small>
            </div>
        </div>
        <div class="card-body">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $client ? 'modifier' : 'ajouter' ?>">
            <?php if ($client): ?><input type="hidden" name="id" value="<?= $client['id'] ?>"><?php endif; ?>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label">Type de client *</label>
                    <select name="type" id="clientTypeSelect" class="form-select">
                        <option value="professionnel" <?= ($client['type'] ?? '') === 'professionnel' ? 'selected' : '' ?>>Professionnel</option>
                        <option value="particulier" <?= ($client['type'] ?? '') === 'particulier' ? 'selected' : '' ?>>Particulier</option>
                    </select>
                </div>
                <div class="col-md-5 position-relative">
                    <label class="form-label">Entreprise / Société</label>
                    <input type="text" name="entreprise" id="entrepriseField" class="form-control" value="<?= e($client['entreprise'] ?? '') ?>" placeholder="Nom de l'entreprise">
                    <div class="form-text">Vous pouvez saisir ici le nom de la société pour lancer la recherche automatique.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" id="nomLabel">Nom *</label>
                    <input type="text" name="nom" id="nomField" class="form-control" required value="<?= e($client['nom'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" id="prenomLabel">Prénom</label>
                    <input type="text" name="prenom" id="prenomField" class="form-control" value="<?= e($client['prenom'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($client['email'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Téléphone</label>
                    <input type="text" name="telephone" class="form-control" value="<?= e($client['telephone'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4 doc-form-section">
        <div class="card-header doc-form-section__header">
            <div>
                <strong><i class="bi bi-geo-alt"></i> Adresse</strong>
                <small class="doc-form-section__subtitle">Données utiles pour la facturation et l'envoi des documents</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-4">
                <div class="col-md-12">
                    <label class="form-label">Adresse</label>
                    <input type="text" name="adresse" class="form-control" value="<?= e($client['adresse'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Code postal</label>
                    <input type="text" name="code_postal" class="form-control" value="<?= e($client['code_postal'] ?? '') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Ville</label>
                    <input type="text" name="ville" class="form-control" value="<?= e($client['ville'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Pays</label>
                    <input type="text" name="pays" class="form-control" value="<?= e($client['pays'] ?? 'France') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4 doc-form-section">
        <div class="card-header doc-form-section__header">
            <div>
                <strong><i class="bi bi-bank"></i> Informations légales</strong>
                <small class="doc-form-section__subtitle">Recherche société, SIREN, SIRET et TVA intracommunautaire</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-4">
                <div class="col-md-6 position-relative">
                    <label class="form-label">Recherche SIREN / SIRET / Nom d'entreprise</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="rechercheSiret" class="form-control" placeholder="Tapez un nom, SIREN ou SIRET..." autocomplete="off">
                        <button type="button" class="btn btn-outline-primary" id="btnRechercheSiret" title="Rechercher">
                            <i class="bi bi-building"></i> Rechercher
                        </button>
                        <button type="button" class="btn btn-outline-secondary d-none" id="btnAnnulerRechercheSiret" title="Annuler la recherche">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div id="resultsSiret" class="list-group position-absolute shadow-lg w-100" style="z-index:1050; display:none; max-height:300px; overflow-y:auto; left:0; right:0; top:100%; margin-top:4px;"></div>
                    <div class="form-text"><i class="bi bi-info-circle"></i> Recherche sécurisée via le serveur local et les données publiques de l'État.</div>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div id="siretInfoCard" class="d-none w-100">
                        <div class="alert alert-info py-2 mb-0 small">
                            <i class="bi bi-check-circle-fill"></i> <strong id="siretInfoNom"></strong><br>
                            <span id="siretInfoDetails"></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">SIRET</label>
                    <input type="text" name="siret" id="siretField" class="form-control" value="<?= e($client['siret'] ?? '') ?>" maxlength="20">
                </div>
                <div class="col-md-4">
                    <label class="form-label">SIREN</label>
                    <input type="text" name="siren" id="sirenField" class="form-control" value="<?= e($client['siren'] ?? '') ?>" maxlength="14" readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label">N° TVA intracommunautaire</label>
                    <input type="text" name="numero_tva" id="tvaField" class="form-control" value="<?= e($client['numero_tva'] ?? '') ?>" maxlength="20">
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4 doc-form-section">
        <div class="card-header doc-form-section__header">
            <div>
                <strong><i class="bi bi-journal-text"></i> Notes internes</strong>
                <small class="doc-form-section__subtitle">Historique, consignes ou contexte commercial</small>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3"><?= e($client['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="doc-submit-bar">
        <div class="doc-submit-bar__summary">
            <span class="doc-submit-bar__label">Fiche client</span>
            <strong><?= $client ? 'Modification du tiers' : 'Création d\'un nouveau client' ?></strong>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary document-action-btn"><i class="bi bi-check-lg"></i> <?= $client ? 'Enregistrer' : 'Ajouter le client' ?></button>
            <a href="clients.php" class="btn btn-outline-secondary document-action-btn">Annuler</a>
        </div>
    </div>
</form>

<?php endif; ?>

<!-- Libellés Nom/Prénom adaptés au type de client -->
<script>
(function() {
    const typeSelect = document.getElementById('clientTypeSelect');
    const nomLabel = document.getElementById('nomLabel');
    const prenomLabel = document.getElementById('prenomLabel');
    const nomField = document.getElementById('nomField');
    const prenomField = document.getElementById('prenomField');
    if (!typeSelect || !nomLabel || !prenomLabel) return;

    function updateNomLabels() {
        const isPro = typeSelect.value === 'professionnel';
        nomLabel.textContent = isPro ? 'Nom du contact *' : 'Nom *';
        prenomLabel.textContent = isPro ? 'Prénom du contact' : 'Prénom';
        if (nomField) nomField.placeholder = isPro ? 'Nom du référent dans l\'entreprise' : '';
        if (prenomField) prenomField.placeholder = isPro ? 'Prénom du référent' : '';
    }

    typeSelect.addEventListener('change', updateNomLabels);
    updateNomLabels();
})();
</script>

<!-- Script recherche SIREN/SIRET via API gouv.fr -->
<script>
(function() {
    const input = document.getElementById('rechercheSiret');
    const entrepriseInput = document.getElementById('entrepriseField');
    const btn = document.getElementById('btnRechercheSiret');
    const cancelBtn = document.getElementById('btnAnnulerRechercheSiret');
    const resultsDiv = document.getElementById('resultsSiret');
    if (!input || !resultsDiv) return;

    const searchInputs = [input, entrepriseInput].filter(Boolean);
    let debounceTimer;
    let searchController;
    let searchSeq = 0;
    const API_URL = 'api_entreprise.php';

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatSiret(s) {
        if (!s) return '';
        return s.replace(/(\d{3})(\d{3})(\d{3})(\d{5})/, '$1 $2 $3 $4');
    }

    function formatSiren(s) {
        if (!s) return '';
        return s.replace(/(\d{3})(\d{3})(\d{3})/, '$1 $2 $3');
    }

    function setSearching(isSearching) {
        if (btn) btn.disabled = isSearching;
        if (cancelBtn) cancelBtn.classList.toggle('d-none', !isSearching);
    }

    function cancelActiveSearch(clearResults = false) {
        clearTimeout(debounceTimer);
        if (searchController) {
            searchController.abort();
            searchController = null;
        }
        searchSeq++;
        setSearching(false);
        if (clearResults) {
            resultsDiv.style.display = 'none';
            resultsDiv.innerHTML = '';
        }
    }

    function anchorResultsTo(field) {
        const target = field || input;
        if (!target) return;
        const container = target.parentElement;
        if (container && container !== resultsDiv.parentElement) {
            container.appendChild(resultsDiv);
        }
    }

    async function searchEntreprise(query, field) {
        cancelActiveSearch(false);
        anchorResultsTo(field);
        const seq = ++searchSeq;
        if (query.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }
        searchController = new AbortController();
        setSearching(true);

        resultsDiv.innerHTML = '<div class="list-group-item text-muted py-2"><i class="bi bi-arrow-repeat"></i> Recherche en cours...</div>';
        resultsDiv.style.display = 'block';

        try {
            const url = `${API_URL}?q=${encodeURIComponent(query)}&per_page=8`;
            const resp = await fetch(url, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                signal: searchController.signal
            });
            const data = await resp.json();
            if (seq !== searchSeq) return;
            if (!resp.ok || !data.success) throw new Error(data.message || 'Erreur API');

            if (!data.results || data.results.length === 0) {
                resultsDiv.innerHTML = '<div class="list-group-item text-muted py-2"><i class="bi bi-x-circle"></i> Aucun résultat</div>';
                resultsDiv.style.display = 'block';
                return;
            }

            resultsDiv.innerHTML = data.results.map(item => {
                const ville = [item.code_postal, item.ville].filter(Boolean).join(' ');
                const naf = item.naf ? `NAF ${item.naf}` : '';
                const siret = item.siret || '';
                const siren = item.siren || '';
                const nom = item.nom || '';
                const adresse = item.adresse || '';

                return `<a href="#" class="list-group-item list-group-item-action py-2 result-siret"
                    data-siren="${escHtml(siren)}"
                    data-siret="${escHtml(siret)}"
                    data-nom="${escHtml(nom)}"
                    data-adresse="${escHtml(adresse)}"
                    data-cp="${escHtml(item.code_postal || '')}"
                    data-ville="${escHtml(item.ville || '')}"
                    data-naf="${escHtml(naf)}">
                    <div class="fw-semibold">${escHtml(nom || 'N/A')}</div>
                    <div class="d-flex justify-content-between">
                        <small class="text-muted">SIRET: ${formatSiret(siret)}</small>
                        <small class="text-muted">SIREN: ${formatSiren(siren)}</small>
                    </div>
                    <small class="text-muted">${escHtml(ville)}${naf ? ' · ' + escHtml(naf) : ''}</small>
                </a>`;
            }).join('');
        } catch (err) {
            if (err && err.name === 'AbortError') return;
            if (seq !== searchSeq) return;
            const message = err && err.message ? err.message : 'Erreur de connexion à l\'API';
            resultsDiv.innerHTML = `<div class="list-group-item text-danger py-2"><i class="bi bi-exclamation-triangle"></i> ${escHtml(message)}</div>`;
            resultsDiv.style.display = 'block';
        } finally {
            if (seq === searchSeq) {
                searchController = null;
                setSearching(false);
            }
        }
    }

    // Autocomplétion avec debounce sur les deux champs
    searchInputs.forEach(field => {
        field.addEventListener('input', function() {
            const value = this.value.trim();
            if (field === entrepriseInput && input) {
                input.value = value;
            }
            clearTimeout(debounceTimer);
            if (value.length < 2) {
                cancelActiveSearch(true);
                return;
            }
            debounceTimer = setTimeout(() => searchEntreprise(value, field), 350);
        });

        field.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchEntreprise(this.value.trim(), this);
            }
        });
    });

    // Bouton recherche
    if (btn) {
        btn.addEventListener('click', () => searchEntreprise((input.value || (entrepriseInput ? entrepriseInput.value : '')).trim(), input));
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => cancelActiveSearch(true));
    }

    // Sélection d'un résultat → remplir les champs du formulaire
    resultsDiv.addEventListener('click', function(e) {
        const item = e.target.closest('.result-siret');
        if (!item) return;
        e.preventDefault();
        cancelActiveSearch(false);

        const siren = item.dataset.siren || '';
        const siret = item.dataset.siret || '';
        const nom = item.dataset.nom || '';
        const adresse = item.dataset.adresse || '';
        const cp = item.dataset.cp || '';
        const ville = item.dataset.ville || '';
        const naf = item.dataset.naf || '';

        // Remplir les champs
        const set = (name, val) => { const el = document.querySelector(`[name="${name}"]`); if (el) el.value = val; };
        set('entreprise', nom);
        set('adresse', adresse);
        set('code_postal', cp);
        set('ville', ville);

        // SIRET / SIREN
        const siretF = document.getElementById('siretField');
        const sirenF = document.getElementById('sirenField');
        const tvaF = document.getElementById('tvaField');
        if (siretF) siretF.value = siret;
        if (sirenF) sirenF.value = siren;

        // Calcul TVA intracommunautaire (FR + clé + SIREN)
        if (tvaF && siren.length === 9 && !tvaF.value) {
            const cle = (12 + 3 * (parseInt(siren) % 97)) % 97;
            tvaF.value = 'FR' + String(cle).padStart(2, '0') + siren;
        }

        // Info card
        const card = document.getElementById('siretInfoCard');
        const infoNom = document.getElementById('siretInfoNom');
        const infoDetails = document.getElementById('siretInfoDetails');
        if (card && infoNom && infoDetails) {
            infoNom.textContent = nom;
            infoDetails.textContent = `SIRET: ${formatSiret(siret)} · ${ville}${naf ? ' · ' + naf : ''}`;
            card.classList.remove('d-none');
        }

        resultsDiv.style.display = 'none';
        if (input) input.value = nom;
        if (entrepriseInput) entrepriseInput.value = nom;
    });

    // Fermer les résultats quand on clique ailleurs
    document.addEventListener('click', function(e) {
        if (!resultsDiv.contains(e.target) && e.target !== input && e.target !== entrepriseInput && e.target !== btn && e.target !== cancelBtn) {
            resultsDiv.style.display = 'none';
        }
    });

    window.addEventListener('beforeunload', () => cancelActiveSearch(false));
})();
</script>

<?php include 'footer.php'; ?>
