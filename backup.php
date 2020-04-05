<?php
# Only allow LAN IP address to access
$lan = "192.168.";
if (substr($_SERVER['REMOTE_ADDR'], 0, strlen($lan)) !== $lan) {
  http_response_code(403);
  die();
}

if (isset($_GET['p']))
  $path = $_GET['p'];
else
  $path = ".";

# Do not allow parent directories, but allow symlinks
// https://www.php.net/manual/en/function.realpath.php#84012
function get_abs_path($path) {
  $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
  $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
  $absolutes = array();
  foreach ($parts as $part) {
      if ('.' == $part) continue;
      if ('..' == $part) {
          array_pop($absolutes);
      } else {
          $absolutes[] = $part;
      }
  }
  return implode(DIRECTORY_SEPARATOR, $absolutes);
}

$pwd = get_abs_path("");
$cwd = get_abs_path($path);
if (substr($cwd, 0, strlen($pwd)) !== $pwd) {
  http_response_code(403);
  die();
}
$cwd = substr($cwd, strlen($pwd));
if (empty($cwd))
  $cwd = ".";

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

$db = new mysqli($dbhost, $dbuser, $dbpw, $dbname);
if ($db->connect_error) {
  http_response_code(500);
  die("Connection failed: " . $db->connect_error . "\n");
}
$db->query('SET CHARACTER SET utf8');

function db_select($p, $t = "access") {
  $stmt = $GLOBALS["db"]->prepare('SELECT * FROM '.$t.' WHERE `path` = ?');
  $stmt->bind_param('s', $p);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
  return $stmt->get_result()->fetch_assoc();
}

function db_access_check($p) {
  $res = db_select($p);
  if ($res === null)
    return true;
  return $res["allow"];
}

function db_hide($d, $o, $hide) {
  $p = $d === "." ? $o : $d.'/'.$o;
  if ($hide) {
    $res = db_select($p);
    if ($res === null)
      $stmt = $GLOBALS["db"]->prepare('INSERT INTO `access` (`path`, `allow`) VALUES (?, "0")');
    else
      $stmt = $GLOBALS["db"]->prepare('UPDATE `access` SET `allow` = "0" WHERE `path` = ?');
  } else {
    $stmt = $GLOBALS["db"]->prepare('DELETE FROM `access` WHERE `path` = ?');
  }
  $stmt->bind_param('s', $p);
  if ($stmt->execute() !== true) {
    http_response_code(500);
    die($stmt->error);
  }
}

function db_thumbnail($p, $v = null) {
  if ($v === null) {
    $img = db_select($p, "thumbnail");
    if ($img === null)
      return null;
    return $img["img"];
  } else {
    $stmt = $GLOBALS["db"]->prepare('INSERT INTO `thumbnail` (`path`, `img`) VALUES (?, ?)');
    $stmt->bind_param('ss', $p, $v);
    if ($stmt->execute() !== true) {
      http_response_code(500);
      die($stmt->error);
    }
  }
}

function thumbnail($p) {
  $src = db_thumbnail($p);
  if ($src)
    return '"'.$src.'"';
  if (strtolower(substr($p, -4)) === ".jpg")
    $src = imagecreatefromjpeg($p);
  else if (strtolower(substr($p, -4)) === ".png")
    $src = imagecreatefrompng($p);
  else if (strtolower(substr($p, -4)) === ".bmp")
    $src = imagecreatefrombmp($p);
  else if (strtolower(substr($p, -4)) === ".gif")
    $src = imagecreatefromgif($p);
  else
    $src = FALSE;
  if ($src === FALSE)
    return "null";
  $w = imagesx($src);
  $h = imagesy($src);
  $s = max($w, $h);
  $ds = 128;
  $nw = $ds * $w / $s;
  $nh = $ds * $h / $s;
  $dst = imagecreatetruecolor((int)$nw, (int)$nh);
  imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
  ob_start();
  imagejpeg($dst, null, 90);
  $img = ob_get_clean();
  $img = base64_encode($img);
  db_thumbnail($p, $img);
  return '"'.$img.'"';
}

# Do database operation
if (isset($_GET['op']) && isset($_GET['v'])) {
  $op = $_GET['op'];
  $v = $_GET['v'];
  if ($op === "show")
    db_hide($cwd, $v, false);
  else if ($op === "hide")
    db_hide($cwd, $v, true);
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php echo('<script>path = "'.$path.'"</script>'); ?>
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

<script>
parent = "<?php echo(dirname($path)); ?>";
rawlist = [
<?php
foreach (scandir($path) as $object) {
  if ($object === ".")
    continue;
  if ($path === ".")
    $p = $object;
  else
    $p = $path."/".$object;
  # Check file permissions
  if (is_readable($p))
    echo('{name:"'.$object.'"'
         .',size:'.(is_dir($p) ? -1 : filesize($p))
         .',time:'.filemtime($p)
         .',img:'.thumbnail($p)
         .',access:'.db_access_check($p)
         .'},');
}
?>
];
list = [];
for (o of rawlist)
  if (o.size === -1)
    list.push(o);
for (o of rawlist)
  if (o.size !== -1)
    list.push(o);
document.write("<h1>Index of "+path+"</h1>");
</script>

<div class="table-responsive">
<table class="table table-sm table-bordered table-hover">
  <thead><tr>
    <th scope="col" style="width: 0" class="text-right">Op</th>
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

prefix = "";
if (path != ".")
  prefix = path + "/";

for (f of list) {
  if (f.name === "..") {
    target = "?p=" + encodeURIComponent(parent);
    name = "Parent Directory";
    table = "table-info";
    size = "-";
  } else if (f.size === -1) {
    target = "?p=" + encodeURIComponent(prefix + f.name);
    name = f.name;
    table = "table-warning";
    size = "-";
  } else {
    target = prefix + f.name;
    name = f.name;
    table = "table-default";
    size = fs_str(f.size);
  }
  if (f.access) {
    btn = "btn-success";
    eye = "fa-eye";
    hide = "&op=hide";
  } else {
    btn = "btn-danger";
    eye = "fa-eye-slash";
    hide = "&op=show";
  }
  if (f.img) {
    thumb = '<img src="data:image/jpg;base64,' + f.img + '"/>';
  } else {
    thumb = '';
  }
  document.write('<tr class="' + table + '"><td class="text-right align-middle"><div class="btn-group btn-group-sm" role="group">');
  if (f.name !== "..")
    document.write('<a href="?p=' + encodeURIComponent(path) + '&v=' + encodeURIComponent(f.name) + hide + '" role="button" class="btn ' + btn + '"><i class="fa ' + eye + '"></i></a>');
  document.write('</div></td><td>' + thumb + '</td><td class="text-left align-middle"><a href="' + target + '">' + name + '</a></td><td class="text-right align-middle">' + ts_str(f.time) + '</td><td class="text-right align-middle">' + size + '</td>');
  document.write('</tr>');
}
</script>
</tbody></table>
</div>

</div>

</body>
</html>
