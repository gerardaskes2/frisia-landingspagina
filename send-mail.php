<?php
// ── Frisia Pensioenadvies – Contactformulier handler ──

// Configuratie
$to_email    = 'info@frisiapensioenadvies.nl';
$from_domain = 'frisiapensioenadvies.nl';

// Alleen POST-verzoeken toestaan
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode niet toegestaan']);
    exit;
}

// CSRF / origin check (optioneel maar aanbevolen)
header('Content-Type: application/json; charset=utf-8');

// Formuliervelden ophalen en opschonen
$naam        = htmlspecialchars(trim($_POST['naam'] ?? ''), ENT_QUOTES, 'UTF-8');
$bedrijf     = htmlspecialchars(trim($_POST['bedrijf'] ?? ''), ENT_QUOTES, 'UTF-8');
$medewerkers = htmlspecialchars(trim($_POST['medewerkers'] ?? ''), ENT_QUOTES, 'UTF-8');
$telefoon    = htmlspecialchars(trim($_POST['telefoon'] ?? ''), ENT_QUOTES, 'UTF-8');
$email       = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$bericht     = htmlspecialchars(trim($_POST['bericht'] ?? ''), ENT_QUOTES, 'UTF-8');

// Validatie
$errors = [];
if (empty($naam))  $errors[] = 'Naam is verplicht';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Geldig e-mailadres is verplicht';

// Honeypot check (anti-spam)
if (!empty($_POST['website_url'])) {
    // Bot detected — doe alsof het gelukt is
    echo json_encode(['success' => true, 'message' => 'Bedankt!']);
    exit;
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

// E-mail samenstellen
$subject = "Adviesaanvraag via website - " . ($bedrijf ?: $naam);

$body  = "Nieuw contactformulier inzending\n";
$body .= "================================\n\n";
$body .= "Naam:               {$naam}\n";
$body .= "Bedrijf:            {$bedrijf}\n";
$body .= "Aantal medewerkers: {$medewerkers}\n";
$body .= "Telefoon:           {$telefoon}\n";
$body .= "E-mail:             {$email}\n\n";
$body .= "Bericht:\n";
$body .= "--------\n";
$body .= $bericht . "\n\n";
$body .= "================================\n";
$body .= "Verzonden op: " . date('d-m-Y H:i') . "\n";

$headers  = "From: noreply@{$from_domain}\r\n";
$headers .= "Reply-To: {$email}\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

// Versturen
$sent = mail($to_email, $subject, $body, $headers);

if ($sent) {
    echo json_encode(['success' => true, 'message' => 'Bedankt voor uw bericht! We nemen binnen \u00e9\u00e9n werkdag contact met u op.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Er ging iets mis bij het versturen. Probeer het later opnieuw of bel ons direct.']);
}
