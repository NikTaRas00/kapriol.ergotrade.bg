<?php
/**
 * send-mail.php — contact form handler for kapriol.bg
 *
 * Accepts a JSON (or form-encoded) POST with: name, email, phone, message, website.
 * - "website" is a honeypot: if it is filled, we return success WITHOUT sending.
 * - Validates every field server-side; email must be a valid address.
 * - Sends to nikolaos@nikarte.com via PHP mail().
 * - Always responds with JSON: {"success":true} or {"success":false,"error":"..."}.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---- Config ---------------------------------------------------------------
$TO      = 'info@kapriol.ergotrade.bg';
$SUBJECT = 'New enquiry from kapriol.ergotrade.bg';
// From must be a domain mailbox for SPF/DKIM deliverability on cPanel hosting.
$FROM    = 'no-reply@kapriol.ergotrade.bg';

// ---- Method guard ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'error' => 'Method not allowed.'));
    exit;
}

// ---- Read input (JSON first, fall back to form-encoded) --------------------
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

function field($data, $key) {
    return isset($data[$key]) ? trim((string) $data[$key]) : '';
}

$name    = field($data, 'name');
$email   = field($data, 'email');
$phone   = field($data, 'phone');
$message = field($data, 'message');
$website = field($data, 'website'); // honeypot

// ---- Honeypot: pretend success, send nothing ------------------------------
if ($website !== '') {
    echo json_encode(array('success' => true));
    exit;
}

// ---- Validate -------------------------------------------------------------
if ($name === '' || $phone === '' || $message === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(array(
        'success' => false,
        'error'   => 'Please complete every field with a valid email address.'
    ));
    exit;
}

// Basic abuse guard.
if (mb_strlen($message) > 5000 || mb_strlen($name) > 200) {
    http_response_code(422);
    echo json_encode(array('success' => false, 'error' => 'Message is too long.'));
    exit;
}

// ---- Store the enquiry ----------------------------------------------------
// Append to the private enquiries log so the admin can view submissions, even
// if the mail() call below fails. Self-contained (no session) and wrapped so a
// storage hiccup never breaks the visitor's form response.
store_enquiry(array(
    'ts'      => gmdate('c'),
    'name'    => $name,
    'email'   => $email,
    'phone'   => $phone,
    'message' => $message,
    'ip'      => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
));

// ---- Build the mail -------------------------------------------------------
// Strip CR/LF from anything that lands in a header to prevent header injection.
$safeName  = preg_replace('/[\r\n]+/', ' ', $name);
$safeEmail = preg_replace('/[\r\n]+/', ' ', $email);

$body =
    "New enquiry from the kapriol.bg website\n" .
    "----------------------------------------\n\n" .
    "Name:    " . $name . "\n" .
    "Email:   " . $email . "\n" .
    "Phone:   " . $phone . "\n\n" .
    "Message:\n" . $message . "\n";

$headers  = 'From: Kapriol.bg <' . $FROM . ">\r\n";
$headers .= 'Reply-To: ' . $safeName . ' <' . $safeEmail . ">\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=utf-8\r\n";

// RFC 2047-encode the subject so UTF-8 stays intact.
$encodedSubject = '=?UTF-8?B?' . base64_encode($SUBJECT) . '?=';

$sent = @mail($TO, $encodedSubject, $body, $headers);

if ($sent) {
    echo json_encode(array('success' => true));
} else {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error'   => 'Mail could not be sent right now. Please email info@kapriol.ergotrade.bg directly.'
    ));
}

/**
 * Append one enquiry to the private enquiries log (newest entries kept, capped).
 * Locates private/ the same way _guard.php does: sibling of the web root first,
 * then an in-web-root private/ fallback. Never throws to the caller.
 */
function store_enquiry($record) {
    try {
        $candidates = array(
            dirname(__DIR__) . '/private',   // sibling of public_html (best)
            __DIR__ . '/private',            // public_html/private/ (fallback)
        );
        $dir = '';
        foreach ($candidates as $c) { if (is_dir($c)) { $dir = $c; break; } }
        if ($dir === '') { return; }                 // no private store configured

        $path = $dir . '/enquiries.json';
        $fp = @fopen($path, 'c+');
        if (!$fp) { return; }
        if (flock($fp, LOCK_EX)) {
            $raw  = stream_get_contents($fp);
            $list = json_decode($raw, true);
            if (!is_array($list)) { $list = array(); }
            $list[] = $record;
            if (count($list) > 2000) { $list = array_slice($list, -2000); }  // bound file size
            $json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    } catch (Exception $e) {
        // Storage is best-effort; swallow so the form still responds.
    }
}
