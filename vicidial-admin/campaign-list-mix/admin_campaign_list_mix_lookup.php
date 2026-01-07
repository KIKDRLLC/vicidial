<?php
// admin_campaign_list_mix_lookup.php
// READ-ONLY: returns list_name for a list_id (for live UI description fill)

header('Content-Type: application/json; charset=utf-8');

require("dbconnect_mysqli.php");
require("functions.php");

$list_id = isset($_GET['list_id']) ? preg_replace('/[^0-9]/', '', $_GET['list_id']) : '';

if ($list_id === '') {
  echo json_encode(['ok' => false, 'error' => 'missing_list_id']);
  exit;
}

$sql = "SELECT list_name FROM vicidial_lists WHERE list_id=? LIMIT 1";
$stmt = mysqli_prepare($link, $sql);
if (!$stmt) {
  echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
  exit;
}

mysqli_stmt_bind_param($stmt, "s", $list_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $list_name);

if (mysqli_stmt_fetch($stmt)) {
  mysqli_stmt_close($stmt);
  echo json_encode(['ok' => true, 'list_id' => $list_id, 'list_name' => $list_name]);
  exit;
}

mysqli_stmt_close($stmt);
echo json_encode(['ok' => false, 'list_id' => $list_id, 'error' => 'not_found']);
