<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf()) {
    http_response_code(405);
    setFlash('danger', 'Déconnexion invalide ou expirée.');
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

logout();

header('Location: ' . BASE_URL . 'login.php');
exit;
