<?php
// rules_proxy.php
header('Content-Type: application/json; charset=utf-8');

$RULES_API_BASE = 'http://10.0.1.216:3000';
$RULES_API_KEY  = ''; // optional

$path = $_GET['path'] ?? '';
if (!$path || $path[0] !== '/') {
  http_response_code(400);
  echo json_encode(["error" => "Invalid path"]);
  exit;
}

// Allowlist to prevent abuse
$allowedPrefixes = [
  '/rules',
  '/dry-run',
];

$allowedOk = false;
foreach ($allowedPrefixes as $p) {
  if (strpos($path, $p) === 0) { // this includes /rules/meta/...
    $allowedOk = true;
    break;
  }
}

if (!$allowedOk) {
  http_response_code(400);
  echo json_encode(["error" => "Path not allowed"]);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Build target URL safely (keep querystring if included in path)
$target = rtrim($RULES_API_BASE, '/') . $path;

$ch = curl_init($target);

$headers = [
  'Accept: application/json',
];

if ($RULES_API_KEY) {
  $headers[] = 'x-api-key: ' . $RULES_API_KEY;
}

// Forward JSON body for write methods
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
  $body = file_get_contents('php://input');
  if ($body !== false && strlen($body) > 0) {
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }
}

curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CUSTOMREQUEST  => $method,
  CURLOPT_HTTPHEADER     => $headers,
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$errno = curl_errno($ch);
$err   = curl_error($ch);
$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($errno) {
  http_response_code(502);
  echo json_encode(["error" => $err]);
  exit;
}

http_response_code($code ?: 500);
echo $response;
