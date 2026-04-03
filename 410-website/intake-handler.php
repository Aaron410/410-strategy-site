<?php
/**
 * 410 Strategy — Intake Form Handler
 * Endpoint: https://410strategy.com/intake-handler.php
 * Receives POST from /intake.html after Stripe payment
 * Updates matching Airtable record and fires Slack notification
 */

// ─── CONFIG ────────────────────────────────────────────────────────────────────
define('AIRTABLE_TOKEN',    'AIRTABLE_API_KEY_PLACEHOLDER');
define('AIRTABLE_BASE_ID',  'appGQNIHNAGrOuj1l');
define('AIRTABLE_TABLE',    'Orders');
define('SLACK_BOT_TOKEN',   'SLACK_BOT_TOKEN_PLACEHOLDER');
define('SLACK_CHANNEL',     'C0AMJ90F7L0');
define('LOG_FILE', __DIR__ . '/intake_log.txt');
define('SUCCESS_REDIRECT', 'https://410strategy.com/thank-you-complete.html');
define('ERROR_REDIRECT',   'https://410strategy.com/intake.html?error=1');

// ─── HELPERS ───────────────────────────────────────────────────────────────────
function log_event(string $msg): void {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function sanitize(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function find_airtable_record_by_session(string $session_id): array|null {
    $filter = urlencode("{Stripe Session ID}='{$session_id}'");
    $url = 'https://api.airtable.com/v0/' . AIRTABLE_BASE_ID . '/' . urlencode(AIRTABLE_TABLE)
         . '?filterByFormula=' . $filter . '&maxRecords=1';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . AIRTABLE_TOKEN],
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($result, true);
    $records = $data['records'] ?? [];
    return !empty($records) ? $records[0] : null;
}

function update_airtable_record(string $record_id, array $fields): bool {
    $url = 'https://api.airtable.com/v0/' . AIRTABLE_BASE_ID . '/' . urlencode(AIRTABLE_TABLE) . '/' . $record_id;
    $payload = json_encode(['fields' => $fields]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . AIRTABLE_TOKEN,
            'Content-Type: application/json',
        ],
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log_event("Airtable PATCH $record_id HTTP $code");
    return $code === 200;
}

function create_airtable_record(array $fields): array|false {
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

    log_event("Airtable CREATE HTTP $code");
    return $code === 200 ? json_decode($result, true) : false;
}

function send_slack_notification(string $business_name, string $product, string $gbp_url, string $city, string $state): void {
    $text = "🎯 *New 410 Strategy Order!*\n"
          . "*Business:* {$business_name} — {$city}, {$state}\n"
          . "*Product:* {$product}\n"
          . "*GBP:* {$gbp_url}\n"
          . "_Check the <https://410strategy.com|CRM dashboard> to assign and start._";

    $payload = json_encode([
        'channel' => SLACK_CHANNEL,
        'text'    => $text,
    ]);

    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SLACK_BOT_TOKEN,
            'Content-Type: application/json',
        ],
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    log_event("Slack notification sent: $result");
}

// ─── MAIN ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Read + sanitize inputs
$business_name = sanitize($_POST['business_name'] ?? '');
$website_url   = filter_var(trim($_POST['website_url'] ?? ''), FILTER_VALIDATE_URL) ?: '';
$gbp_url       = filter_var(trim($_POST['gbp_url'] ?? ''), FILTER_VALIDATE_URL) ?: sanitize($_POST['gbp_url'] ?? '');
$city          = sanitize($_POST['city'] ?? '');
$state         = sanitize($_POST['state'] ?? '');
$category      = sanitize($_POST['category'] ?? '');
$notes         = sanitize($_POST['notes'] ?? '');
$product       = sanitize($_POST['product'] ?? '');
$session_id    = sanitize($_POST['session_id'] ?? '');

log_event("Intake received: business=$business_name session=$session_id product=$product");

// Validate required fields
if (!$business_name || !$gbp_url || !$city || !$state || !$category) {
    log_event("ERROR: Missing required fields");
    header('Location: ' . ERROR_REDIRECT);
    exit;
}

// Map product slug to label
$product_labels = [
    'gbp-audit'        => 'GBP Audit',
    'website-critique' => 'Website Critique',
    'bundle'           => 'Bundle',
];
$product_label = $product_labels[$product] ?? ucwords(str_replace('-', ' ', $product)) ?: 'Unknown';

// Airtable fields to update/create
$fields = [
    'Business Name' => $business_name,
    'Website URL'   => $website_url ?: null,
    'GBP URL'       => $gbp_url,
    'City/State'    => "$city, $state",
    'Category'      => $category,
    'Notes'         => $notes,
    'Status'        => 'New Order',
    'Product'       => $product_label,
];
// Remove null values
$fields = array_filter($fields, fn($v) => $v !== null);

// Try to find existing record (created by webhook)
if ($session_id) {
    $record = find_airtable_record_by_session($session_id);
    if ($record) {
        $updated = update_airtable_record($record['id'], $fields);
        log_event("Updated existing record " . $record['id'] . ": " . ($updated ? 'OK' : 'FAIL'));
    } else {
        // No webhook record yet — create fresh
        $fields['Stripe Session ID'] = $session_id;
        $fields['Created'] = date('Y-m-d');
        $fields['Assigned To'] = 'Jarvis';
        $created = create_airtable_record($fields);
        log_event("Created new record (no webhook match): " . ($created['id'] ?? 'FAIL'));
    }
} else {
    // No session ID — create record anyway
    $fields['Created'] = date('Y-m-d');
    $fields['Assigned To'] = 'Jarvis';
    $created = create_airtable_record($fields);
    log_event("Created record (no session): " . ($created['id'] ?? 'FAIL'));
}

// Fire Slack notification
send_slack_notification($business_name, $product_label, $gbp_url, $city, $state);

// Redirect to confirmation page
header('Location: ' . SUCCESS_REDIRECT);
exit;
