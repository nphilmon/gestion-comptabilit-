<?php
/**
 * Profil utilisateur — Changement de mot de passe
 */
require_once __DIR__ . '/functions.php';
requireLogin();
$titre = 'Mon profil';

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'profil.php');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $db = getDB();
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            setFlash('danger', 'Mot de passe actuel incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('danger', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
        } elseif ($new !== $confirm) {
            setFlash('danger', 'Les mots de passe ne correspondent pas.');
        } else {
            $newHash = password_hash($new, PASSWORD_BCRYPT);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $user['id']]);
            logActivity($user['id'], 'mdp_modifie', 'Mot de passe modifié');
            setFlash('success', 'Mot de passe modifié avec succès.');
        }
        header('Location: ' . BASE_URL . 'profil.php');
        exit;
    }

    if ($action === 'infos') {
        $nom = trim($_POST['nom'] ?? '');
        $email = mb_strtolower(trim($_POST['email'] ?? ''));

        if ($nom === '' || $email === '') {
            setFlash('danger', 'Nom et email sont obligatoires.');
        } else {
            $db = getDB();
            // Vérifier unicité email
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch()) {
                setFlash('danger', 'Cet email est déjà utilisé.');
            } else {
                $db->prepare('UPDATE users SET nom = ?, email = ? WHERE id = ?')->execute([$nom, $email, $user['id']]);
                $_SESSION['user_nom'] = $nom;
                logActivity($user['id'], 'profil_modifie', 'Profil mis à jour');
                setFlash('success', 'Profil mis à jour.');
            }
        }
        header('Location: ' . BASE_URL . 'profil.php');
        exit;
    }
}

include 'header.php';
?>

<div class="hero-banner mb-4">
    <h1 class="mb-1"><i class="bi bi-person-gear"></i> Mon profil</h1>
    <p class="mb-0">Gérer vos informations personnelles et votre mot de passe</p>
</div>

<div class="row g-4">
    <!-- Informations personnelles -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-person-vcard"></i> Informations personnelles
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="infos">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="nom">Nom complet</label>
                        <input type="text" class="form-control" id="nom" name="nom" value="<?= e($user['nom']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= e($user['email']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rôle</label>
                        <input type="text" class="form-control" value="<?= e(ucfirst($user['role'])) ?>" disabled>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Changement de mot de passe -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-shield-lock"></i> Changer le mot de passe
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="password">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="current_password">Mot de passe actuel</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="new_password">Nouveau mot de passe</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                        <div class="form-text">Minimum 8 caractères</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="confirm_password">Confirmer le mot de passe</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>

                    <button type="submit" class="btn btn-warning"><i class="bi bi-key"></i> Modifier le mot de passe</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Activité récente -->
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clock-history"></i> Mon activité récente
    </div>
    <div class="card-body p-0">
        <?php $logs = getActivityLog(15, $user['id']); ?>
        <?php if (empty($logs)): ?>
            <p class="text-muted text-center py-3">Aucune activité enregistrée.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Action</th>
                        <th>Détails</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><small><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></small></td>
                        <td><span class="badge bg-secondary"><?= e($log['action']) ?></span></td>
                        <td><small><?= e($log['details']) ?></small></td>
                        <td><small class="text-muted"><?= e($log['ip_address']) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
