<?php
# Only allow LAN IP address to access
$lan = "192.168.";
if (substr($_SERVER['REMOTE_ADDR'], 0, strlen($lan)) !== $lan) {
  http_response_code(403);
  die();
}

http_response_code(500);
die();

$dbuser = "dirlist";
$dbhost = "localhost";
$dbpw = "DVzZ1Hpdz3VYJ3Nd";
$dbname = "dirlist";

$db = new mysqli($dbhost, $dbuser, $dbpw, $dbname);
if ($db->connect_error) {
  http_response_code(500);
  die("Connection failed: " . $db->connect_error . "\n");
}
$db->query('SET CHARACTER SET utf8');

# Query path from database
function get_from_pid($pid) {
  $stmt = $GLOBALS["db"]->prepare('SELECT * FROM `paths` WHERE `pid` = ?');
  $stmt->bind_param('i', $pid);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  return $stmt->get_result()->fetch_assoc();
}

# Create a new path ID in database
function get_from_new_path($path) {
  $stmt = $GLOBALS["db"]->prepare('INSERT INTO `paths` (`path`) VALUES (?)');
  $stmt->bind_param('s', $path);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  return get_from_pid($stmt->insert_id);
}

# Query path ID from database
function get_from_path($path) {
  $stmt = $GLOBALS["db"]->prepare('SELECT * FROM `paths` WHERE `path` = ?');
  $stmt->bind_param('s', $path);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  $res = $stmt->get_result()->fetch_assoc();
  if ($res == null)
    return get_from_new_path($path);
  return $res;
}

# Convert thumbnail records
$stmt = $GLOBALS["db"]->prepare('SELECT * FROM `thumbnail`');
if ($stmt->execute() !== true) {
  http_response_code(500);
  die($stmt->error);
}
$res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($res as $entry) {
  $pid = get_from_path(dirname($entry['path']))['pid'];
  $name = basename($entry['path']);
  $inode = fileinode($entry['path']);
  if ($inode === false)
    continue;
  $stmt = $GLOBALS["db"]->prepare('INSERT INTO `files` (`pid`, `inode`, `name`, `thumb`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE thumb = ?');
  $stmt->bind_param('iisss', $pid, $inode, $name, $entry['img'], $entry['img']);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
}

# Convert access records
$stmt = $GLOBALS["db"]->prepare('SELECT * FROM `access` WHERE allow = 0');
if ($stmt->execute() !== true) {
  http_response_code(500);
  die($stmt->error);
}
$res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($res as $entry) {
  $pid = get_from_path(dirname($entry['path']))['pid'];
  $name = basename($entry['path']);
  $inode = fileinode($entry['path']);
  if ($inode === false)
    continue;
  $stmt = $GLOBALS["db"]->prepare('INSERT INTO `files` (`pid`, `inode`, `name`, `access`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE access = ?');
  $stmt->bind_param('iisii', $pid, $inode, $name, $entry['allow'], $entry['allow']);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
}
?>
