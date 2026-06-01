<?php
// ── Frisia Pensioenadvies – Contactformulier handler (Microsoft 365 SMTP) ──

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/mail-config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$settings = json_decode(file_get_contents(__DIR__ . '/cms-data/settings.json'), true);
$to_email = filter_var($settings['mail_to'] ?? 'jaap@frisiapensioenadvies.nl', FILTER_SANITIZE_EMAIL);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode niet toegestaan']);
    exit;
}

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
    echo json_encode(['success' => true, 'message' => 'Bedankt!']);
    exit;
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

// E-mail samenstellen en versturen via Microsoft 365 SMTP
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($to_email);
    $mail->addReplyTo($email, $naam);

    $mail->Subject = "Adviesaanvraag via website – " . ($bedrijf ?: $naam);

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

    $mail->Body = $body;

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Bedankt voor uw bericht! We nemen binnen één werkdag contact met u op.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Er ging iets mis bij het versturen. Probeer het later opnieuw of bel ons direct.']);
}
