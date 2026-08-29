<?php
/**
 * send-mail.php — contact form handler.
 *
 * Accepts a JSON (or form-encoded) POST with: name, email, phone, message, website.
 * - "website" is a honeypot: if filled, return success WITHOUT sending.
 * - Validates every field server-side; email must be valid.
 * - Logs to private/enquiries.json, then sends via PHP mail().
 * - Always responds JSON: {"success":true} or {"success":false,"error":"..."}.
 *
 * ADAPT: $TO, $FROM, $SUBJECT, and the required-field set.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$TO      = 'info@REPLACE-WITH-DOMAIN';
$SUBJECT = 'New enquiry from REPLACE-WITH-DOMAIN';
// From MUST be a domain mailbox for SPF/DKIM deliverability on shared hosting.
$FROM    = 'no-reply@REPLACE-WITH-DOMAIN';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'error' => 'Method not allowed.'));
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }

function field($data, $key) {
    return isset($data[$key]) ? trim((string) $data[$key]) : '';
}

$name    = field($data, 'name');
$email   = field($data, 'email');
$phone   = field($data, 'phone');
$message = field($data, 'message');
$website = field($data, 'website'); // honeypot

// Honeypot: pretend success, send nothing.
if ($website !== '') { echo json_encode(array('success' => true)); exit; }

if ($name === '' || $phone === '' || $message === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(array('success' => false,
        'error' => 'Please complete every field with a valid email address.'));
    exit;
}
if (mb_strlen($message) > 5000 || mb_strlen($name) > 200) {
    http_response_code(422);
    echo json_encode(array('success' => false, 'error' => 'Message is too long.'));
    exit;
}

// Log first (so the admin sees it even if mail() fails).
store_enquiry(array(
    'ts'      => gmdate('c'),
    'name'    => $name, 'email' => $email, 'phone' => $phone, 'message' => $message,
    'ip'      => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
));

// Strip CR/LF from header values to prevent header injection.
$safeName  = preg_replace('/[\r\n]+/', ' ', $name);
$safeEmail = preg_replace('/[\r\n]+/', ' ', $email);

$body =
    "New enquiry\n----------------------------------------\n\n" .
    "Name:    " . $name . "\n" .
    "Email:   " . $email . "\n" .
    "Phone:   " . $phone . "\n\n" .
    "Message:\n" . $message . "\n";

$headers  = 'From: Website <' . $FROM . ">\r\n";
$headers .= 'Reply-To: ' . $safeName . ' <' . $safeEmail . ">\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=utf-8\r\n";

$encodedSubject = '=?UTF-8?B?' . base64_encode($SUBJECT) . '?=';
$sent = @mail($TO, $encodedSubject, $body, $headers);

if ($sent) {
    echo json_encode(array('success' => true));
} else {
    http_response_code(500);
    echo json_encode(array('success' => false,
        'error' => 'Mail could not be sent right now. Please email ' . $TO . ' directly.'));
}

/**
 * Append one enquiry to the private log (bounded, locked). Locates private/ the
 * same way _guard.php does. Never throws to the caller.
 */
function store_enquiry($record) {
    try {
        $candidates = array(dirname(__DIR__) . '/private', __DIR__ . '/private');
        $dir = '';
        foreach ($candidates as $c) { if (is_dir($c)) { $dir = $c; break; } }
        if ($dir === '') { return; }

        $path = $dir . '/enquiries.json';
        $fp = @fopen($path, 'c+');
        if (!$fp) { return; }
        if (flock($fp, LOCK_EX)) {
            $list = json_decode(stream_get_contents($fp), true);
            if (!is_array($list)) { $list = array(); }
            $list[] = $record;
            if (count($list) > 2000) { $list = array_slice($list, -2000); }
            $json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            ftruncate($fp, 0); rewind($fp); fwrite($fp, $json); fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    } catch (Exception $e) { /* best-effort */ }
}
