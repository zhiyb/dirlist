<?php
# Only allow LAN IP address to access
$lan = "192.168.";
if (substr($_SERVER['REMOTE_ADDR'], 0, strlen($lan)) !== $lan) {
  http_response_code(403);
  die();
}

$admin = true;

require('index.php');
?>
