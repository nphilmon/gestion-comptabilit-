<?php
/**
 * Script d'installation de la base de données
 * Accessible uniquement en local et uniquement si l'application n'est pas déjà initialisée.
 */

require_once __DIR__ . '/config.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true);

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$charset = DB_CHARSET;

echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><title>Installation</title>";
echo "<link href='https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap' rel='stylesheet'>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "<style>body{font-family:'Roboto',system-ui,sans-serif;background:#FAFAFA;color:#191C20} .card{border:none;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,0.3),0 2px 6px 2px rgba(0,0,0,0.15)} .alert{border:none;border-radius:12px}</style></head>";
echo "<body class='p-5'><div class='container'>";
echo "<h1>🔧 Installation - Gestion Comptable Pro</h1><hr>";

if (!$isLocal) {
    http_response_code(403);
    echo "<div class='alert alert-danger'><strong>Accès refusé :</strong> ce script est accessible uniquement depuis localhost.</div>";
    echo "</div></body></html>";
    exit;
}

try {
    // Connexion sans base de données
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Vérifier si déjà installé
    $existsStmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . DB_NAME . "'");
    if ($existsStmt->fetchColumn()) {
        $checkPdo = new PDO("mysql:host=$host;dbname=" . DB_NAME . ";charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $hasUsersTable = $checkPdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'users'")->fetchColumn();
        if ($hasUsersTable) {
            $userCount = (int) $checkPdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($userCount > 0) {
                echo "<div class='alert alert-info'><h4>ℹ️ Installation déjà effectuée</h4><p>L'application est déjà initialisée. Pour des raisons de sécurité, ce script ne peut pas être rejoué.</p></div>";
                echo "<a href='./' class='btn btn-primary'>Accéder à l'application</a>";
                echo "</div></body></html>";
                exit;
            }
        }
    }

    $sqlFiles = [
        __DIR__ . '/database/schema.sql',
        __DIR__ . '/database/migration_multi_regimes.sql',
        __DIR__ . '/database/migration_commercial.sql',
        __DIR__ . '/database/migration_caisse.sql',
        __DIR__ . '/database/migration_cp.sql',
        __DIR__ . '/database/migration_paie.sql',
    ];

    foreach ($sqlFiles as $sqlFile) {
        if (!file_exists($sqlFile)) {
            throw new Exception('Fichier SQL introuvable : ' . basename($sqlFile));
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new Exception('Impossible de lire : ' . basename($sqlFile));
        }
        $pdo->exec($sql);
    }

    echo "<div class='alert alert-success'>";
    echo "<h4>✅ Installation réussie !</h4>";
    echo "<ul>";
    echo "<li>Base de données <strong>" . htmlspecialchars(DB_NAME) . "</strong> créée/initialisée</li>";
    echo "<li>Migrations principales, commerciales, caisse, congés et paie appliquées</li>";
    echo "<li>Vous pouvez maintenant finaliser la configuration via l'application</li>";
    echo "</ul>";
    echo "</div>";

    echo "<a href='./' class='btn btn-primary btn-lg'>Accéder à l'application →</a>";
    echo "<br><br><div class='alert alert-warning'>";
    echo "<strong>⚠️ Sécurité :</strong> supprimez ou renommez ce fichier <code>install.php</code> après l'installation.";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Erreur de base de données</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Vérifiez que MySQL/MariaDB est démarré dans WAMP et que les identifiants dans <code>config.php</code> sont corrects.</p>";
    echo "</div>";
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Erreur</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "</div></body></html>";
