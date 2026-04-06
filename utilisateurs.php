<?php
$titre = 'Gestion des utilisateurs';
require_once __DIR__ . '/functions.php';
requireLogin();
requireRole('admin');

$db = getDB();
$action = $_GET['action'] ?? 'liste';

// --- Traitement des formulaires (avant tout affichage HTML) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'utilisateurs.php');
        exit;
    }

    if ($action === 'ajouter' || $action === 'modifier') {
        $id = (int)($_POST['id'] ?? 0);
        $nom = trim($_POST['nom'] ?? '');
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? 'comptable';
        $actif = isset($_POST['actif']) ? 1 : 0;
        $password = $_POST['password'] ?? '';

        if ($nom === '' || $email === '') {
            setFlash('danger', 'Nom et email sont obligatoires.');
            header('Location: ' . BASE_URL . "utilisateurs.php?action=$action" . ($id ? "&id=$id" : ''));
            exit;
        }

        if (!in_array($role, ['admin', 'comptable', 'lecteur'])) {
            $role = 'comptable';
        }

        $emailCheck = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $emailCheck->execute([$email, $id]);
        if ($emailCheck->fetch()) {
            setFlash('danger', 'Cette adresse email est déjà utilisée.');
            header('Location: ' . BASE_URL . "utilisateurs.php?action=$action" . ($id ? "&id=$id" : ''));
            exit;
        }

        if ($action === 'ajouter') {
            if (strlen($password) < 8) {
                setFlash('danger', 'Le mot de passe doit contenir au moins 8 caractères.');
                header('Location: ' . BASE_URL . 'utilisateurs.php?action=ajouter');
                exit;
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare('INSERT INTO users (nom, email, password_hash, role, actif) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$nom, $email, $hash, $role, $actif]);
            setFlash('success', 'Utilisateur créé avec succès.');
        } else {
            // Modifier
            if ($password !== '') {
                if (strlen($password) < 8) {
                    setFlash('danger', 'Le mot de passe doit contenir au moins 8 caractères.');
                    header('Location: ' . BASE_URL . "utilisateurs.php?action=modifier&id=$id");
                    exit;
                }
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare('UPDATE users SET nom=?, email=?, password_hash=?, role=?, actif=? WHERE id=?');
                $stmt->execute([$nom, $email, $hash, $role, $actif, $id]);
            } else {
                $stmt = $db->prepare('UPDATE users SET nom=?, email=?, role=?, actif=? WHERE id=?');
                $stmt->execute([$nom, $email, $role, $actif, $id]);
            }
            setFlash('success', 'Utilisateur modifié avec succès.');
        }
        header('Location: ' . BASE_URL . 'utilisateurs.php');
        exit;
    }

    if ($action === 'supprimer') {
        $id = (int)($_POST['id'] ?? 0);
        $me = getCurrentUser();
        if ($id === (int)$me['id']) {
            setFlash('danger', 'Vous ne pouvez pas supprimer votre propre compte.');
        } else {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            setFlash('success', 'Utilisateur supprimé.');
        }
        header('Location: ' . BASE_URL . 'utilisateurs.php');
        exit;
    }
}

// --- Affichage ---
include __DIR__ . '/header.php';
$currentUser = getCurrentUser();
?>

<?php if ($action === 'liste'): ?>
    <!-- Hero -->
    <div class="hero-banner mb-4">
        <h1 class="mb-1"><i class="bi bi-people-fill"></i> Gestion des utilisateurs</h1>
        <p class="mb-0">Gérer les comptes et les droits d'accès</p>
    </div>

    <?php
    $users = $db->query('SELECT id, nom, email, role, actif, derniere_connexion, created_at FROM users ORDER BY created_at DESC')->fetchAll();
    ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people"></i> Utilisateurs (<?= count($users) ?>)</span>
            <a href="<?= BASE_URL ?>utilisateurs.php?action=ajouter" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> Nouvel utilisateur
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nom</th>
                            <th>Email</th>
                            <th>Rôle</th>
                            <th>Statut</th>
                            <th>Dernière connexion</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($u['nom']) ?></td>
                            <td><?= e($u['email']) ?></td>
                            <td>
                                <?php
                                $roleBadge = match($u['role']) {
                                    'admin' => 'bg-danger',
                                    'comptable' => 'bg-primary',
                                    'lecteur' => 'bg-secondary',
                                    default => 'bg-dark'
                                };
                                ?>
                                <span class="badge <?= $roleBadge ?>"><?= e(ucfirst($u['role'])) ?></span>
                            </td>
                            <td>
                                <?php if ($u['actif']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Actif</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-pause-circle"></i> Inactif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $u['derniere_connexion'] ? formatDate($u['derniere_connexion']) : '<span class="text-muted">Jamais</span>' ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= BASE_URL ?>utilisateurs.php?action=modifier&id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                                <form method="POST" action="<?= BASE_URL ?>utilisateurs.php?action=supprimer" class="d-inline"
                                      onsubmit="return confirm('Supprimer cet utilisateur ?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($action === 'ajouter' || $action === 'modifier'): ?>
    <?php
    $user = null;
    if ($action === 'modifier') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare('SELECT id, nom, email, role, actif FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            setFlash('danger', 'Utilisateur introuvable.');
            header('Location: ' . BASE_URL . 'utilisateurs.php');
            exit;
        }
    }
    $isEdit = $user !== null;
    ?>

    <div class="hero-banner mb-4">
        <h1 class="mb-1"><i class="bi bi-person-<?= $isEdit ? 'gear' : 'plus' ?>"></i> <?= $isEdit ? 'Modifier' : 'Nouvel' ?> utilisateur</h1>
        <p class="mb-0"><?= $isEdit ? 'Modifier les informations de ' . e($user['nom']) : 'Créer un nouveau compte utilisateur' ?></p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="<?= BASE_URL ?>utilisateurs.php?action=<?= $action ?>">
                        <?= csrfField() ?>
                        <?php if ($isEdit): ?>
                            <input type="hidden" name="id" value="<?= $user['id'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="nom">Nom complet</label>
                            <input type="text" class="form-control" id="nom" name="nom"
                                   value="<?= e($user['nom'] ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="email">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= e($user['email'] ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="password">
                                Mot de passe <?= $isEdit ? '<small class="text-muted">(laisser vide pour ne pas modifier)</small>' : '' ?>
                            </label>
                            <input type="password" class="form-control" id="password" name="password"
                                   <?= $isEdit ? '' : 'required' ?> minlength="8">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="role">Rôle</label>
                            <select class="form-select" id="role" name="role">
                                <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="comptable" <?= ($user['role'] ?? 'comptable') === 'comptable' ? 'selected' : '' ?>>Comptable</option>
                                <option value="lecteur" <?= ($user['role'] ?? '') === 'lecteur' ? 'selected' : '' ?>>Lecteur</option>
                            </select>
                        </div>

                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input" id="actif" name="actif"
                                   <?= ($user['actif'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="actif">Compte actif</label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Enregistrer' : 'Créer' ?>
                            </button>
                            <a href="<?= BASE_URL ?>utilisateurs.php" class="btn btn-outline-secondary">Annuler</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
