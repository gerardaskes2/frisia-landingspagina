<?php
// ── Frisia CMS – API endpoint ──
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

define('DATA_DIR', __DIR__ . '/cms-data');
define('IMG_DIR',  __DIR__ . '/img');

// ── Helper: JSON response ──
function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// ── Actie ophalen ──
$action = $_GET['action'] ?? '';

// ── Publieke endpoints (geen auth vereist) ──

if ($action === 'check-auth') {
    $auth = isset($_SESSION['cms_auth']) && $_SESSION['cms_auth'] === true;
    respond([
        'auth' => $auth,
        'csrf' => $auth ? ($_SESSION['csrf'] ?? '') : ''
    ]);
}

if ($action === 'get-content') {
    $file = DATA_DIR . '/content.json';
    if (!file_exists($file)) respond((object)[]);
    $data = json_decode(file_get_contents($file), true);
    respond(is_array($data) ? $data : (object)[]);
}

if ($action === 'get-form-public') {
    $file = DATA_DIR . '/form-config.json';
    if (!file_exists($file)) respond(['fields' => []]);
    $data = json_decode(file_get_contents($file), true);
    respond(is_array($data) ? $data : ['fields' => []]);
}

// ── Alle andere endpoints: auth vereist ──
if (!isset($_SESSION['cms_auth']) || $_SESSION['cms_auth'] !== true) {
    respond(['error' => 'Niet ingelogd'], 403);
}

// ── CSRF validatie ──
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf'] ?? '', $csrfHeader)) {
    respond(['error' => 'Ongeldige CSRF token'], 403);
}

// ── Data directory aanmaken indien nodig ──
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// ── Routing ──
switch ($action) {

    // ── GET: instellingen ophalen ──
    case 'get-settings':
        $file = DATA_DIR . '/settings.json';
        $defaults = ['mail_to' => 'gerardaskes@gmail.com'];
        if (!file_exists($file)) {
            respond($defaults);
        }
        $data = json_decode(file_get_contents($file), true);
        respond(is_array($data) ? $data : $defaults);

    // ── POST: instellingen opslaan ──
    case 'save-settings':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) respond(['error' => 'Ongeldige invoer'], 400);
        $mail = filter_var(trim($body['mail_to'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$mail) respond(['error' => 'Ongeldig e-mailadres'], 422);
        $settings = ['mail_to' => $mail];
        file_put_contents(DATA_DIR . '/settings.json', json_encode($settings, JSON_PRETTY_PRINT));
        respond(['ok' => true]);

    // ── GET: formulier ophalen ──
    case 'get-form':
        $file = DATA_DIR . '/form-config.json';
        if (!file_exists($file)) respond(['fields' => []]);
        $data = json_decode(file_get_contents($file), true);
        respond(is_array($data) ? $data : ['fields' => []]);

    // ── POST: formulier opslaan ──
    case 'save-form':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['fields']) || !is_array($body['fields'])) {
            respond(['error' => 'Ongeldige invoer'], 400);
        }
        $allowedTypes = ['text', 'email', 'tel', 'select', 'textarea', 'honeypot', 'number', 'date', 'url'];
        $allowedWidths = ['half', 'full'];
        $cleanFields = [];
        foreach ($body['fields'] as $f) {
            if (!is_array($f)) continue;
            $type = $f['type'] ?? 'text';
            if (!in_array($type, $allowedTypes, true)) $type = 'text';
            $width = $f['width'] ?? 'full';
            if (!in_array($width, $allowedWidths, true)) $width = 'full';
            $clean = [
                'id'          => preg_replace('/[^a-z0-9_-]/i', '', $f['id'] ?? 'veld_' . time()),
                'label'       => strip_tags($f['label'] ?? ''),
                'type'        => $type,
                'placeholder' => strip_tags($f['placeholder'] ?? ''),
                'required'    => (bool)($f['required'] ?? false),
                'width'       => $width,
            ];
            if ($type === 'select' && isset($f['options']) && is_array($f['options'])) {
                $clean['options'] = array_map('strip_tags', $f['options']);
            }
            $cleanFields[] = $clean;
        }
        file_put_contents(DATA_DIR . '/form-config.json', json_encode(['fields' => $cleanFields], JSON_PRETTY_PRINT));
        respond(['ok' => true]);

    // ── POST: content opslaan ──
    case 'save-content':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) respond(['error' => 'Ongeldige invoer'], 400);
        $allowedTags = '<br><strong><em><a><b><i><u><span>';
        $existing = [];
        $contentFile = DATA_DIR . '/content.json';
        if (file_exists($contentFile)) {
            $existing = json_decode(file_get_contents($contentFile), true) ?? [];
        }
        foreach ($body as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_-]/i', '', $key);
            if ($cleanKey === '') continue;
            $existing[$cleanKey] = strip_tags((string)$value, $allowedTags);
        }
        file_put_contents($contentFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        respond(['ok' => true]);

    // ── POST: afbeelding uploaden ──
    case 'upload-image':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST vereist'], 405);
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            respond(['error' => 'Geen bestand ontvangen of uploadfout'], 400);
        }
        $target = $_POST['target'] ?? '';
        // Valideer doelbestandsnaam (alleen bestandsnaam, geen paden)
        $target = basename($target);
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$/i', $target)) {
            respond(['error' => 'Ongeldige bestandsnaam. Gebruik alleen letters, cijfers, - en _ met extensie jpg/png/gif/webp'], 422);
        }
        // Bestandsgrootte max 8MB
        if ($_FILES['image']['size'] > 8 * 1024 * 1024) {
            respond(['error' => 'Bestand is te groot (max. 8 MB)'], 422);
        }
        // MIME verificatie via finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['image']['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            respond(['error' => 'Ongeldig bestandstype. Alleen JPG, PNG, GIF en WebP toegestaan'], 422);
        }
        if (!is_dir(IMG_DIR)) mkdir(IMG_DIR, 0755, true);
        $dest = IMG_DIR . '/' . $target;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            respond(['error' => 'Bestand opslaan mislukt'], 500);
        }
        respond(['ok' => true, 'url' => 'img/' . $target]);

    default:
        respond(['error' => 'Onbekende actie: ' . htmlspecialchars($action)], 404);
}
