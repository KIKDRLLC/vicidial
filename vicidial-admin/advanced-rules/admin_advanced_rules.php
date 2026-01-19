<?php
# admin_advanced_rules.php
# VICIdial Lead Automation Rules – UI only, backed by NestJS service (via proxy)

$startMS    = microtime();
$php_script = 'admin_advanced_rules.php';

require("dbconnect_mysqli.php");
require("functions.php");
require("admin_header.php");

// Proxy endpoint (same-origin)
$RULES_API_BASE = '/vicidial/rules_proxy.php';
$RULES_API_KEY  = '';  // optional
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Lead Automation Rules</title>

  <link rel="stylesheet" type="text/css" href="vicidial_stylesheet.php">
  <link rel="stylesheet" type="text/css" href="advanced_rules/rules_ui.css?v=1">
</head>
<body>

<script>
  window.RULES_API_BASE = <?php echo json_encode($RULES_API_BASE); ?>;
  window.RULES_API_KEY  = <?php echo json_encode($RULES_API_KEY); ?>;
</script>

<?php require(__DIR__ . '/advanced_rules/rules_ui.php'); ?>

<script src="advanced_rules/rules_ui.js?v=1"></script>

</body>
</html>
