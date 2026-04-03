<?php
/**
 * 410 Strategy — Stripe Webhook Handler
 * Endpoint: https://410strategy.com/webhook.php
 * Event: checkout.session.completed
 * 
 * Set this URL in Stripe Dashboard → Webhooks → Add endpoint
 * Webhook secret: STRIPE_WEBHOOK_SECRET_PLACEHOLDER
 */

// ─── CONFIG ────────────────────────────────────────────────────────────────────
define('STRIPE_SECRET_KEY',  'STRIPE_SECRET_KEY_PLACEHOLDER');
define('STRIPE_WEBHOOK_SECRET', 'STRIPE_WEBHOOK_SECRET_PLACEHOLDER');
define('AIRTABLE_TOKEN',     'AIRTABLE_API_KEY_PLACEHOLDER');
define('AIRTABLE_BASE_ID',   'AIRTABLE_BASE_ID_PLACEHOLDER');
define('AIRTABLE_TABLE',     'Orders');
define('SLACK_WEBHOOK_URL',  ''); // Optional: set Slack incoming webhook URL here for Stripe events
define('LOG_FILE', __DIR__ . '/webhook_log.txt');

// ─── HELPERS ───────────────────────────────────────────────────────────────────
function log_event(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function airtable_create(array $fields): array|false {
    $url = 'https://api.airtable.com/v0/' . AIRTABLE_BASE_ID . '/' . urlencode(AIRTABLE_TABLE);
    $payload = json_encode(['fields' => $fields]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . AIRTABLE_TOKEN,
            'Content-Type: application/json',
        ],
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log_event("Airtable create HTTP $code: $result");
    return $code === 200 ? json_decode($result, true) : false;
}

function stripe_retrieve_session(string $session_id): array|false {
    $url = "https://api.stripe.com/v1/checkout/sessions/{$session_id}?expand[]=customer_details";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true) ?: false;
}

function product_label_from_metadata(array $session): string {
    $meta = $session['metadata'] ?? [];
    if (!empty($meta['product'])) {
        $map = [
            'gbp-audit'         => 'GBP Audit',
            'website-critique'  => 'Website Critique',
            'bundle'            => 'Bundle',
        ];
        return $map[$meta['product']] ?? ucwords(str_replace('-', ' ', $meta['product']));
    }
    // Fallback: derive from amount
    $amount = $session['amount_total'] ?? 0;
    if ($amount <= 9700)  return 'GBP Audit';
    if ($amount <= 19700) return 'Website Critique';
    return 'Bundle';
}

// ─── MAIN ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$payload   = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify Stripe signature
$tolerance = 300; // 5 minutes
$parts = [];
foreach (explode(',', $sig_header) as $part) {
    [$k, $v] = explode('=', $part, 2);
    $parts[$k] = $v;
}
$timestamp = (int)($parts['t'] ?? 0);
$signed_payload = $timestamp . '.' . $payload;
$expected = hash_hmac('sha256', $signed_payload, STRIPE_WEBHOOK_SECRET);

if (!hash_equals($expected, $parts['v1'] ?? '')) {
    log_event("SIGNATURE MISMATCH — rejecting webhook");
    http_response_code(400);
    exit('Invalid signature');
}
if (abs(time() - $timestamp) > $tolerance) {
    log_event("TIMESTAMP TOO OLD — rejecting webhook");
    http_response_code(400);
    exit('Timestamp out of tolerance');
}

$event = json_decode($payload, true);
log_event("Event received: " . $event['type']);

if ($event['type'] !== 'checkout.session.completed') {
    http_response_code(200);
    exit('OK - ignored');
}

$session = $event['data']['object'];
$session_id = $session['id'];
$customer = $session['customer_details'] ?? [];
$email    = $customer['email'] ?? '';
$name     = $customer['name'] ?? '';
$phone    = $customer['phone'] ?? '';
$amount   = ($session['amount_total'] ?? 0) / 100;
$product  = product_label_from_metadata($session);

// Create Airtable record
$fields = [
    'Contact Name'     => $name,
    'Email'            => $email,
    'Phone'            => $phone,
    'Product'          => $product,
    'Amount'           => (float)$amount,
    'Status'           => 'Awaiting Intake',
    'Stripe Session ID'=> $session_id,
    'Assigned To'      => 'Jarvis',
    'Created'          => date('Y-m-d'),
];

$record = airtable_create($fields);
if ($record) {
    log_event("Airtable record created: " . ($record['id'] ?? 'unknown'));
} else {
    log_event("ERROR: Failed to create Airtable record for session $session_id");
}

http_response_code(200);
echo json_encode(['status' => 'received']);
