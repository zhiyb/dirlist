<?php
// $dbuser = "user";
// $dbhost = "localhost";
// $dbpw = "password";
// $dbname = "database";
require('dbconf.php');

if (isset($argv)) {
  $admin = true;
} else if (!isset($admin)) {
  # Only allow LAN IP address to access admin features
  $lan = "192.168.";
  $admin = substr($_SERVER['REMOTE_ADDR'], 0, strlen($lan)) === $lan;
}

$db = new mysqli($dbhost, $dbuser, $dbpw, $dbname);
if ($db->connect_error) {
  http_response_code(500);
  die("Connection failed: " . $db->connect_error . "\n");
}
$db->set_charset('utf8mb4');

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
function get_from_path($path, $new = true) {
  $stmt = $GLOBALS["db"]->prepare('SELECT * FROM `paths` WHERE `path` = ?');
  $stmt->bind_param('s', $path);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  $res = $stmt->get_result()->fetch_assoc();
  if ($res === null && $new)
    return get_from_new_path($path);
  return $res;
}

# _fileinode using lstat
function _fileinode($path)
{
  $res = lstat($path);
  if ($res === false)
    return false;
  return $res['ino'];
}

if (isset($argv)) {
  # Command line access
  if (count($argv) < 2)
    exit(1);
  $op = $argv[1];
  if ($op == 'update') {
    $stmt = $GLOBALS["db"]->prepare('SELECT `pid`, `path` FROM `paths`');
    if ($stmt->execute() !== true) {
      echo($stmt->error);
      exit(1);
    }

    foreach ($stmt->get_result()->fetch_all() as $entry) {
      $pid = $entry[0];
      $path = $entry[1];
      if (!is_dir($path)) {
        echo("Non-existent path deleted: " . $path . "\n");
        $stmt = $GLOBALS["db"]->prepare('DELETE FROM `paths` WHERE `pid` = ?');
        $stmt->bind_param('i', $pid);
        if ($stmt->execute() !== true) {
          echo($stmt->error);
          exit(1);
        }
      } else {
        $stmt = $GLOBALS["db"]->prepare('SELECT `inode`, `name` FROM `files` WHERE `pid` = ?');
        $stmt->bind_param('i', $pid);
        if ($stmt->execute() !== true) {
          echo($stmt->error);
          exit(1);
        }

        $inodes = [];
        $files = [];
        foreach (scandir($path) as $name) {
          if ($name === "." || $name == "..")
            continue;
          $inode = _fileinode($path . DIRECTORY_SEPARATOR . $name);
          if ($inode === false)
            continue;
          array_push($inodes, $inode);
          $files[$inode] = $name;
        }

        foreach ($stmt->get_result()->fetch_all() as $entry) {
          $inode = $entry[0];
          $name = $entry[1];
          $file = $path . DIRECTORY_SEPARATOR . $name;
          if (!in_array($inode, $inodes)) {
            echo("Non-existent file deleted: " . $inode . "\t" . $file . "\n");
            $stmt = $GLOBALS["db"]->prepare('DELETE FROM `files` WHERE `pid` = ? AND `inode` = ?');
            $stmt->bind_param('ii', $pid, $inode);
            if ($stmt->execute() !== true) {
              echo($stmt->error);
              exit(1);
            }
          } else if ($name != $files[$inode]) {
            $fname = $files[$inode];
            echo("Mismatched file entry corrected: " . $inode . "\t" . $file . " -> " . $fname . "\n");
            $stmt = $GLOBALS["db"]->prepare('UPDATE `files` SET `name` = ? WHERE `pid` = ? AND `inode` = ?');
            $stmt->bind_param('sii', $fname, $pid, $inode);
            if ($stmt->execute() !== true) {
              echo($stmt->error);
              exit(1);
            }
          }
        }
      }
    }
    exit(0);
  } else if ($op == 'thumb-check') {
    #exit(1);  # Force update
    # Check thumbnail exists
    $path = $argv[2];
    $inode = _fileinode($path);
    if ($inode === false)
      exit(1);
    $pid = get_from_path(dirname($path))['pid'];

    $stmt = $GLOBALS["db"]->prepare('SELECT LENGTH(`thumb`) FROM `files` WHERE `pid` = ? AND `inode` = ? AND `thumb` IS NOT NULL');
    $stmt->bind_param('ii', $pid, $inode);
    if ($stmt->execute() !== true) {
      echo($stmt->error);
      exit(1);
    }
    $res = $stmt->get_result()->fetch_row();
    exit((int)($res == null || $res[0] == 0));
  } else if ($op == 'thumb-update') {
    # Update thumbnail
    $path = $argv[2];
    $name = basename($path);
    $inode = _fileinode($path);
    if ($inode === false)
      exit(1);
    $pid = get_from_path(dirname($path))['pid'];

    $thumb = file_get_contents("php://stdin");
    if (empty($thumb)) {
      echo("Empty data");
      exit(1);
    }

    $stmt = $GLOBALS["db"]->prepare('INSERT INTO `files` (`pid`, `inode`, `name`, `thumb`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `thumb` = VALUES(`thumb`)');
    $stmt->bind_param('iisb', $pid, $inode, $name, $null);
    $stmt->send_long_data(3, $thumb);
    if ($stmt->execute() !== true) {
      echo($stmt->error);
      exit(1);
    }
    exit(0);
  }
  exit(1);
}

if (isset($_GET['access'])) {
  # Change file access control
  if (!$admin) {
    http_response_code(403);
    die();
  }
  if (!isset($_GET['pid']) || !isset($_GET['inode']) || !isset($_GET['name'])) {
    http_response_code(500);
    die("Invalid parameters");
  }
  $access = $_GET['access'] == 'true';
  $pid = $_GET['pid'];
  $inode = $_GET['inode'];
  $name = $_GET['name'];
  $res = get_from_pid($pid);
  if ($res == null) {
    http_response_code(404);
    die();
  }
  # TODO Check file existance

  # Update database
  $stmt = $GLOBALS["db"]->prepare('INSERT INTO `files` (`pid`, `inode`, `name`, `access`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE access = ?');
  $stmt->bind_param('iisii', $pid, $inode, $name, $access, $access);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  die();
}

# Check for valid path and path ID
$pid = 0;
if (isset($_GET['pid']))
  $pid = $_GET['pid'];
if ($pid != 0) {
  $res = get_from_pid($pid);
  if ($res == null) {
    http_response_code(404);
    die();
  }
  $path = $res['path'];
} else if (isset($_GET['p'])) {
  // Legacy direct path query support, only allow known path records
  $path = $_GET['p'];
  $res = get_from_path($path, false);
  if ($res == null) {
    http_response_code(404);
    die();
  }
  $pid = $res['pid'];
} else {
  $path = '.';
  $pid = get_from_path($path)['pid'];
}

if (!file_exists($path)) {
  http_response_code(404);
  die();
}

if (!is_readable($path)) {
  http_response_code(403);
  die();
}

if (!is_dir($path)) {
  http_response_code(400);
  die();
}

function check_access($pid, $inode) {
  $stmt = $GLOBALS["db"]->prepare('SELECT `access` FROM `files` WHERE `pid` = ? AND `inode` = ?');
  $stmt->bind_param('ii', $pid, $inode);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  $res = $stmt->get_result()->fetch_row();
  if ($res == null)
    # Default value
    return true;
  return $res[0];
}

if (!$admin) {
  $objpath = $path;
  while ($objpath != '.') {
    $inode = _fileinode($objpath);
    $object = basename($objpath);
    $objpath = dirname($objpath);
    $objpid = get_from_path($objpath)['pid'];
    if (!check_access($objpid, $inode)) {
      http_response_code(403);
      die();
    }
  }
}

# HTML start
ob_start("ob_gzhandler", 4 * 1024 * 1024);
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php echo('<script>path = "'.$path.'";</script>'); ?>
  <script>document.write("<title>Index of "+path+"</title>");</script>
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet" integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">
</head>

<body>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.2.1/jquery.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.3/umd/popper.min.js" integrity="sha384-vFJXuSJphROIrBnz7yo7oB41mKfc8JzQZiCq4NCceLEaO4IHwicKwpJf9c9IpFgh" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-csv/0.8.3/jquery.csv.min.js" integrity="sha256-xKWJpqP3ZjhipWOyzFuNmG2Zkp1cW4nhUREGBztcSXs=" crossorigin="anonymous"></script>

<div class="container theme-showcase" role="main">
<script>document.write("<h1>Index of "+path+"</h1>");</script>

<div class="table-responsive">
<table class="table table-sm table-bordered table-hover">
  <thead><tr>
<?php
if ($admin)
  echo('<th scope="col" style="width: 0" class="text-right">Op</th>');
?>
    <th scope="col" style="width: 0"></th>
    <th scope="col" class="text-left">Name</th>
    <th scope="col" style="width: 0" class="text-right">Last modified</th>
    <th scope="col" style="width: 0" class="text-right">Size</th>
  </tr></thead>
  <tbody>

<script>
function ts_str(ts) {
  var a = new Date(ts * 1000);
  return a.toLocaleDateString() + ' ' + a.toLocaleTimeString();
}

// https://stackoverflow.com/questions/10420352/converting-file-size-in-bytes-to-human-readable-string
function fs_str(bytes, si) {
    var thresh = si ? 1000 : 1024;
    if(Math.abs(bytes) < thresh) {
        return bytes + ' B';
    }
    var units = si
        ? ['kB','MB','GB','TB','PB','EB','ZB','YB']
        : ['KiB','MiB','GiB','TiB','PiB','EiB','ZiB','YiB'];
    var u = -1;
    do {
        bytes /= thresh;
        ++u;
    } while(Math.abs(bytes) >= thresh && u < units.length - 1);
    return bytes.toFixed(1)+' '+units[u];
}
</script>

<?php
$dirs = [];
$files = [];

# Create a new file entry in database
function get_from_new_inode($pid, $inode, $name) {
  $stmt = $GLOBALS["db"]->prepare('INSERT INTO `files` (`pid`, `inode`, `name`) VALUES (?, ?, ?)');
  $stmt->bind_param('iis', $pid, $inode, $name);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  return get_from_inode($pid, $inode, $name);
}

# Query file entry from database
function get_from_inode($pid, $inode, $name) {
  $stmt = $GLOBALS["db"]->prepare('SELECT * FROM `files` WHERE `pid` = ? AND `inode` = ?');
  $stmt->bind_param('ii', $pid, $inode);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  return $stmt->get_result()->fetch_assoc();
}

function print_object($pid, $objpath, $name, $target, $table, $size, $ignore = false) {
  $time = filemtime($objpath);
  $inode = _fileinode($objpath);
  if ($ignore) {
    $thumb = null;
  } else {
    $res = get_from_inode($pid, $inode, $name);
    if ($res == null) {
      # Default values
      $access = true;
      $thumb = null;
    } else {
      $access = $res['access'];
      $thumb = $res['thumb'];
      if ($thumb != null)
        $thumb = '<img src="data:image/jpg;base64,' . base64_encode($thumb) . '"/>';
    }
    if ($access) {
      $btn = "btn-success";
      $eye = "fa-eye";
    } else {
      $btn = "btn-danger";
      $eye = "fa-eye-slash";
    }
  }

  echo('<tr class="' . $table . '">');
  if ($GLOBALS['admin']) {
    echo('<td class="text-right align-middle"><div class="btn-group btn-group-sm" role="group">');
    if (!$ignore)
      echo('<button pid="' . $pid . '" inode="' . $inode . '" role="button" class="op_access btn ' . $btn . '"><i class="fa ' . $eye . '"></i></button>');
      //echo('<a href="?toggle&pid=' . $pid . '&inode=' . $inode . '" role="button" class="btn ' . $btn . '"><i class="fa ' . $eye . '"></i></a>');
    echo('</div></td>');
  }
  echo('<td class="text-right">' . $thumb . '</td><td class="text-left align-middle"><a href="' . $target . '">' . $name . '</a></td><td class="text-right align-middle"><script>document.write(ts_str(' . $time . '));</script></td><td class="text-right align-middle">' . $size . '</td>');
  echo('</tr>');
}

function check_denied($pid) {
  $stmt = $GLOBALS["db"]->prepare('SELECT `inode` FROM `files` WHERE `pid` = ? AND `access` = 0');
  $stmt->bind_param('i', $pid);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  return $stmt->get_result()->fetch_all();
}

if (!$admin) {
  $access = check_denied($pid);
  $denied = [];
  foreach ($access as $inode)
    array_push($denied, $inode[0]);
}

foreach (scandir($path) as $object) {
  if ($object === ".")
    continue;
  if ($path === ".")
    $objpath = $object;
  else
    $objpath = $path . DIRECTORY_SEPARATOR . $object;
  # Check file permissions
  if (!is_readable($objpath))
    continue;
  $inode = _fileinode($objpath);
  if (!$admin && in_array($inode, $denied))
    continue;
  if(is_dir($objpath))
    array_push($dirs, $object);
  else
    array_push($files, $object);
}

$ndirs = count($dirs) - 1;
$nfiles = count($files);
echo($ndirs . ($ndirs == 1 ? " directory, " : " directories, ") . $nfiles . ($nfiles == 1 ? " file" : " files"));

foreach ($dirs as $object) {
  if ($path === ".")
    $objpath = $object;
  else
    $objpath = $path . DIRECTORY_SEPARATOR . $object;
  if ($object === "..") {
    $target = $path == "." ? $path : "?pid=" . get_from_path(dirname($path))['pid'];
    $name = "Parent Directory";
    $table = "table-info";
    $size = "-";
    $ignore = true;
  } else {
    $target = "?pid=" . get_from_path($objpath)['pid'];
    $name = $object;
    $table = "table-warning";
    $size = "-";
    $ignore = false;
  }
  print_object($pid, $objpath, $name, $target, $table, $size, $ignore);
}

foreach ($files as $object) {
  if ($path === ".")
    $objpath = $object;
  else
    $objpath = $path . DIRECTORY_SEPARATOR . $object;
  $target = $objpath;
  $name = $object;
  $table = "table-default";
  $size = "<script>document.write(fs_str(" . filesize($objpath) . "));</script>";
  print_object($pid, $objpath, $name, $target, $table, $size);
}
?>

</tbody></table>
</div>

</div>

<?php
if ($admin) {
  echo('<script>
  $(".op_access").click(function() {
    btn = $(this);
    access = btn.hasClass("btn-danger");
    pid = btn.attr("pid");
    inode = btn.attr("inode");
    name = btn.closest("tr").find("a").text();
    $.get("?access=" + access + "&pid=" + pid + "&inode=" + inode + "&name=" + name, function() {
      if (access) {
        i = btn.find("i");
        btn.removeClass("btn-danger");
        btn.addClass("btn-success");
        i.removeClass("fa-eye-slash");
        i.addClass("fa-eye");
      } else {
        i = btn.find("i");
        btn.addClass("btn-danger");
        btn.removeClass("btn-success");
        i.addClass("fa-eye-slash");
        i.removeClass("fa-eye");
      }
    });
  });
  </script>');
}
?>

</body>
</html>
