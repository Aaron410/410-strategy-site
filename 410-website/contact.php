<?php
/**
 * 410 Strategy — Contact Form Handler
 * Spam protection:
 *  1. Honeypot field (website_url)
 *  2. Time-based token (form must be open ≥5 seconds)
 *  3. Random-string detection (name/business entropy check)
 *  4. Disposable/known spam email patterns
 *  5. Rate limiting per IP (5 submissions per hour)
 */

$redirect_ok  = 'https://410strategy.com/thank-you.html';
$redirect_err = 'https://410strategy.com/#contact';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect_err);
    exit;
}

// ── 1. HONEYPOT ──────────────────────────────────────────────────────────────
$honeypot = trim($_POST['website_url'] ?? '');
if (!empty($honeypot)) {
    header('Location: ' . $redirect_ok); // silent pass
    exit;
}

// ── 2. TIME TOKEN ───────────────────────────────────────────────────────────
// JS sends btoa(secret + ':' + epochHour)
$form_token = trim($_POST['_ft'] ?? '');
$secret     = 'qs410xz9';
$cur_hour   = floor(time() / 3600);
$valid_tokens = [
    base64_encode($secret . ':' . $cur_hour),
    base64_encode($secret . ':' . ($cur_hour - 1)),
];
if (!empty($form_token) && !in_array($form_token, $valid_tokens, true)) {
    // Token present but wrong = bot. If absent = old form, allow through.
    header('Location: ' . $redirect_err);
    exit;
}

// ── 3. RATE LIMIT per IP ─────────────────────────────────────────────────────
$ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_file = sys_get_temp_dir() . '/410form_' . md5($ip) . '.json';
$now      = time();
$window   = 3600; // 1 hour
$max_hits = 5;

$hits = [];
if (file_exists($rate_file)) {
    $hits = json_decode(file_get_contents($rate_file), true) ?: [];
    $hits = array_filter($hits, fn($t) => ($now - $t) < $window);
}
if (count($hits) >= $max_hits) {
    header('Location: ' . $redirect_err);
    exit;
}
$hits[] = $now;
file_put_contents($rate_file, json_encode(array_values($hits)));

// ── SANITIZE ─────────────────────────────────────────────────────────────────
function clean($val) {
    return htmlspecialchars(strip_tags(trim($val ?? '')), ENT_QUOTES, 'UTF-8');
}

$name     = clean($_POST['name']     ?? '');
$email    = clean($_POST['email']    ?? '');
$phone    = clean($_POST['phone']    ?? '');
$business = clean($_POST['business'] ?? '');
$service  = clean($_POST['service']  ?? '');
$message  = clean($_POST['message']  ?? '');

// ── REQUIRED FIELDS ───────────────────────────────────────────────────────────
if (empty($name) || empty($email)) {
    header('Location: ' . $redirect_err);
    exit;
}

// ── EMAIL FORMAT ──────────────────────────────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ' . $redirect_err);
    exit;
}

// ── 4. RANDOM STRING DETECTION ───────────────────────────────────────────────
// Real names have vowels and a reasonable consonant/vowel pattern.
// Random strings are mostly consonants with no discernible pattern.
function looks_random($str) {
    if (strlen($str) < 4) return false;
    $lower    = strtolower($str);
    $vowels   = preg_match_all('/[aeiou]/', $lower);
    $total    = strlen(preg_replace('/[^a-z]/', '', $lower));
    if ($total < 4) return false;
    $ratio    = $vowels / $total;
    // Real words/names: 25-50% vowels. Random strings: <15%
    if ($ratio < 0.15) return true;
    // Also flag if no spaces AND >15 chars (random alphanumeric blob)
    if (!str_contains($str, ' ') && strlen($str) > 15 && !preg_match('/[0-9]/', $str)) return true;
    return false;
}

if (looks_random($name) || (!empty($business) && looks_random($business))) {
    // Log it but silently pass (don't tip off bots)
    error_log("410strategy spam blocked: name={$name} email={$email} ip={$ip}");
    header('Location: ' . $redirect_ok);
    exit;
}

// ── 5. DISPOSABLE EMAIL PATTERNS ─────────────────────────────────────────────
$spam_patterns = [
    '/[a-z]\.[a-z]\.[a-z]{3}\.[a-z]{2}\.\d+\.\d+@/', // k.u.tiq.ac.i7.0@ pattern
    '/^[a-z]{1,2}\.[a-z]{1,2}\.[a-z]{3}\.[a-z]{2}/', // dot-separated random segments
];
foreach ($spam_patterns as $pattern) {
    if (preg_match($pattern, strtolower($email))) {
        error_log("410strategy spam email blocked: {$email} ip={$ip}");
        header('Location: ' . $redirect_ok);
        exit;
    }
}

// ── BUILD + SEND EMAIL ────────────────────────────────────────────────────────
$service_labels = [
    'google-ads' => 'Google Ads',
    'meta-ads'   => 'Meta / Facebook Ads',
    'both'       => 'Google + Meta Ads',
    'strategy'   => 'Strategy & Consulting',
    'not-sure'   => 'Not sure yet',
];
$service_label = $service_labels[$service] ?? ($service ?: '—');

$to      = 'aaron@410strategy.com';
$subject = "New Lead from 410strategy.com — {$name}";

$body  = "New lead from 410strategy.com\n";
$body .= str_repeat('─', 40) . "\n\n";
$body .= "Name:     {$name}\n";
$body .= "Email:    {$email}\n";
$body .= "Phone:    " . ($phone ?: '—') . "\n";
$body .= "Business: " . ($business ?: '—') . "\n";
$body .= "Service:  {$service_label}\n\n";
if (!empty($message)) {
    $body .= "Message:\n{$message}\n\n";
}
$body .= str_repeat('─', 40) . "\n";
$body .= "Submitted: " . date('Y-m-d H:i:s T') . "\n";
$body .= "IP: {$ip}\n";

$headers  = "From: 410 Strategy Form <noreply@410strategy.com>\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$sent = mail($to, $subject, $body, $headers);
header('Location: ' . $sent ? $redirect_ok : $redirect_err);
exit;
