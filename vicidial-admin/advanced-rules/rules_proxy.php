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
  '/rules',   // includes /rules/meta/...
  '/dry-run', // (legacy)
];

$allowedOk = false;
foreach ($allowedPrefixes as $p) {
  if (strpos($path, $p) === 0) {
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

// Build target URL safely (path may include querystring)
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
  CURLOPT_RETURNTRANSFER    => true,
  CURLOPT_CUSTOMREQUEST     => $method,
  CURLOPT_HTTPHEADER        => $headers,

  // More explicit + reliable timeouts
  CURLOPT_CONNECTTIMEOUT_MS => 8000,   // connect phase (8s)
  CURLOPT_TIMEOUT_MS        => 30000,  // total time (30s)

  // Helps in some envs / future-proofing
  CURLOPT_FOLLOWLOCATION    => true,
  CURLOPT_MAXREDIRS         => 3,
]);

$response = curl_exec($ch);
$errno    = curl_errno($ch);
$err      = curl_error($ch);
$code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($errno) {
  http_response_code(502);
  echo json_encode([
    "error"      => "Upstream request failed",
    "upstream"   => $target,
    "curl_errno" => $errno,
    "curl_error" => $err,
  ]);
  exit;
}

// If upstream gave no HTTP code, treat as bad gateway
if ($code <= 0) {
  http_response_code(502);
  echo json_encode([
    "error"    => "Upstream returned no HTTP status",
    "upstream" => $target,
    "response" => ($response === false ? null : $response),
  ]);
  exit;
}

// Pass upstream status + body through
http_response_code($code);
echo ($response === false || $response === null) ? '' : $response;
