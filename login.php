<?php
declare(strict_types=1);

$seguro = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $seguro,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; frame-src https://www.youtube-nocookie.com; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Cache-Control: no-store, max-age=0');

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function largo(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function urlSegura(?string $u): string
{
    $u = trim((string) $u);
    return ($u !== '' && preg_match('~^https?://~i', $u)) ? $u : '';
}

function esImagen(?string $u): bool
{
    $u = trim((string) $u);
    if ($u === '') {
        return false;
    }
    if (preg_match('~^https?://~i', $u)) {
        if (strtolower((string) parse_url($u, PHP_URL_HOST)) === 'i.ibb.co') {
            return true;
        }
        return (bool) preg_match('~\.(jpe?g|png|webp|gif|avif|bmp|svg)(\?.*)?$~i', $u);
    }
    return (bool) preg_match('~^recursos/posterscap/[^/]+\.(webp|jpe?g|png|gif|avif)$~i', $u);
}

function subirImagen(array $file, string $tipo): string
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return '';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    if ($file['size'] < 1024 || $file['size'] > 15 * 1024 * 1024) {
        return '';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($mime !== 'image/jpeg') {
        return '';
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || $info[0] < 50 || $info[1] < 50) {
        return '';
    }
    if ($info[0] > 8000 || $info[1] > 8000) {
        return '';
    }
    $src = @imagecreatefromjpeg($file['tmp_name']);
    if ($src === false) {
        return '';
    }
    @unlink($file['tmp_name']);
    $dir = __DIR__ . '/recursos/posterscap';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $nombre = bin2hex(random_bytes(8)) . '.webp';
    $destino = $dir . '/' . $nombre;
    $maxDim = $tipo === 'poster' ? 2400 : 1920;
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w > $maxDim || $h > $maxDim) {
        $ratio = min($maxDim / $w, $maxDim / $h);
        $nw = (int) round($w * $ratio);
        $nh = (int) round($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }
    $calidad = $tipo === 'poster' ? 92 : 82;
    imagewebp($src, $destino, $calidad);
    imagedestroy($src);
    if (!is_file($destino)) {
        return '';
    }
    return 'recursos/posterscap/' . $nombre;
}

function imgbbDirecto(?string $u): string
{
    $u = trim((string) $u);
    if (!preg_match('~^https?://(?:www\.)?ibb\.co/([A-Za-z0-9]+)/?$~i', $u, $m)) {
        return $u;
    }
    static $cache = [];
    $id = $m[1];
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $directa = '';
    if (function_exists('curl_init')) {
        for ($i = 0; $i < 3 && $directa === ''; $i++) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FestivalCineCaricuao/1.0)',
            ]);
            $html = curl_exec($ch);
            curl_close($ch);
            if (is_string($html) && preg_match('~property="og:image"\s+content="(https://i\.ibb\.co/[^"]+)"~i', $html, $n)) {
                $directa = html_entity_decode($n[1], ENT_QUOTES, 'UTF-8');
            }
        }
    }
    return $cache[$id] = ($directa !== '' ? $directa : $u);
}

function embedYt(?string $u): string
{
    $u = trim((string) $u);
    if ($u === '') {
        return '';
    }
    $id = '';
    if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*[?&])?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})~i', $u, $m)) {
        $id = $m[1];
    }
    return $id === '' ? '' : 'https://www.youtube-nocookie.com/embed/' . $id;
}

function syncObras(): bool
{
    global $pdo;
    if (!$pdo) {
        return false;
    }
    try {
        $st = $pdo->query('SELECT id, categoria, obra, director, youtube FROM inscripcion WHERE publicado = 1 ORDER BY publicado_creado ASC, id ASC');
        $obras = [];
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
            $v = embedYt((string) ($r['youtube'] ?? ''));
            if ($v === '') {
                continue;
            }
            $obras[] = [
                'id' => (int) $r['id'],
                'categoria' => (string) $r['categoria'],
                'obra' => (string) $r['obra'],
                'director' => (string) $r['director'],
                'video' => $v,
            ];
        }
        $json = json_encode($obras, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return @file_put_contents(__DIR__ . '/js/obras.js', 'window.OBRAS=' . $json . ";\n") !== false;
    } catch (Throwable $ex) {
        return false;
    }
}

function fechaHora(string $v): string
{
    $t = strtotime($v);
    return $t ? date('d/m/Y H:i', $t) : '';
}

function fechaDia(?string $v): string
{
    $v = (string) $v;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return '';
    }
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

function telLimpio(?string $v): string
{
    return (string) preg_replace('/[^0-9+]/', '', (string) $v);
}

const HASH_FALSO = '$2y$10$iJnZloytsXSFQM1P/L6Sw.tjlxquKXoQBTQrYrRg.l2Pi93Zy/4JG';
const MAX_INTENTOS = 8;
const MIN_CLAVE = 10;
const MIN_USUARIO = 4;
const VISTAS = ['inscripciones', 'mensajes', 'formacion'];

$correoRecibe = 'festivaldecinecaricuao@gmail.com';

$opciones = [
    'Largometraje de ficción',
    'Largometraje documental',
    'Cortometraje de ficción',
    'Cortometraje documental',
    'Laboratorio',
];

$editables = [
    ['categoria', 'Categoría', 'select'],
    ['obra', 'Título de la obra', 'text'],
    ['director', 'Director', 'text'],
    ['productor', 'Productor', 'text'],
    ['guionista', 'Guionista', 'text'],
    ['anio', 'Año', 'number'],
    ['responsable', 'Responsable', 'text'],
    ['telefono', 'Teléfono', 'text'],
    ['correo', 'Correo', 'email'],
    ['nacimiento', 'Nacimiento', 'date'],
    ['nacionalidad', 'Nacionalidad', 'text'],
    ['cedula', 'Cédula', 'text'],
    ['estado', 'Estado', 'text'],
    ['ciudad', 'Ciudad', 'text'],
    ['municipio', 'Municipio', 'text'],
    ['parroquia', 'Parroquia', 'text'],
    ['parentesco', 'Parentesco', 'text'],
    ['nombre_representante', 'Nombre del representante', 'text'],
    ['nacionalidad_representante', 'Nacionalidad del representante', 'text'],
    ['cedula_representante', 'Cédula del representante', 'text'],
    ['equipo', 'Equipo', 'textarea'],
    ['elenco', 'Elenco', 'textarea'],
    ['sinopsis', 'Sinopsis', 'textarea'],
    ['poster', 'Póster (JPG)', 'file'],
    ['f1', 'Fotograma 1 (JPG)', 'file'],
    ['f2', 'Fotograma 2 (JPG)', 'file'],
    ['f3', 'Fotograma 3 (JPG)', 'file'],
    ['youtube', 'YouTube (URL)', 'text'],
];

$db = ['host' => '', 'nombre' => '', 'usuario' => '', 'clave' => ''];
if (is_file(__DIR__ . '/config.local.php')) include __DIR__ . '/config.local.php';
$listo = $db['host'] !== '';
$pdo = null;
$errorBd = '';

if ($listo) {
    try {
        $pdo = new PDO(
            'mysql:host=' . $db['host'] . ';dbname=' . $db['nombre'] . ';charset=utf8mb4',
            $db['usuario'],
            $db['clave'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $ex) {
        $pdo = null;
        $errorBd = 'No pudimos conectar con la base de datos.';
    }
} else {
    $errorBd = 'La zona privada todavía no está configurada.';
}

if (empty($_SESSION['token']) || !is_string($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}
$token = (string) $_SESSION['token'];

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$mensaje = '';
$usuarioVal = '';

$hayTablaUsuarios = false;
if ($pdo) {
    try {
        $hayTablaUsuarios = count($pdo->query("SHOW TABLES LIKE 'usuarios'")->fetchAll()) > 0;
    } catch (Throwable $ex) {
        $hayTablaUsuarios = false;
    }
}

$accion = (string) ($_POST['accion'] ?? '');
$tokenPost = (string) ($_POST['token'] ?? '');
$tokenOk = $tokenPost !== '' && hash_equals($token, $tokenPost);

if ($pdo && $hayTablaUsuarios && $tokenOk && $accion === 'salir') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

$hayCuenta = false;
$hayInscripcion = -1;
if ($pdo && $hayTablaUsuarios) {
    try {
        $hayCuenta = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
    } catch (Throwable $ex) {
        $hayCuenta = false;
    }
    try {
        $hayInscripcion = (int) $pdo->query('SELECT COUNT(*) FROM inscripcion')->fetchColumn();
    } catch (Throwable $ex) {
        $hayInscripcion = -1;
    }
}

$bloqueado = false;
if ($pdo && $hayTablaUsuarios) {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM intentos_login WHERE ip = ? AND creado > (NOW() - INTERVAL 15 MINUTE)');
        $st->execute([$ip]);
        $bloqueado = (int) $st->fetchColumn() >= MAX_INTENTOS;
    } catch (Throwable $ex) {
        $bloqueado = false;
    }
}

if ($pdo && $hayTablaUsuarios && $tokenOk && $accion === 'configurar' && !$hayCuenta) {
    $usuarioVal = trim((string) ($_POST['usuario'] ?? ''));
    $clave = (string) ($_POST['clave'] ?? '');
    $clave2 = (string) ($_POST['clave2'] ?? '');
    if (largo($usuarioVal) < MIN_USUARIO) {
        $mensaje = 'El usuario debe tener al menos ' . MIN_USUARIO . ' caracteres.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $usuarioVal)) {
        $mensaje = 'El usuario solo admite letras, números, punto, guion y guion bajo.';
    } elseif (largo($clave) < MIN_CLAVE) {
        $mensaje = 'La contraseña debe tener al menos ' . MIN_CLAVE . ' caracteres.';
    } elseif ($clave !== $clave2) {
        $mensaje = 'Las dos contraseñas no coinciden.';
    } else {
        try {
            $st = $pdo->prepare('INSERT INTO usuarios (usuario, clave) VALUES (?, ?)');
            $st->execute([$usuarioVal, password_hash($clave, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['privado'] = true;
            $_SESSION['token'] = bin2hex(random_bytes(32));
            header('Location: login.php');
            exit;
        } catch (Throwable $ex) {
            $mensaje = 'No pudimos crear la cuenta. Prueba con otro nombre de usuario.';
        }
    }
} elseif ($pdo && $hayTablaUsuarios && $tokenOk && $accion === 'entrar') {
    $usuarioVal = trim((string) ($_POST['usuario'] ?? ''));
    $clave = (string) ($_POST['clave'] ?? '');
    if ($bloqueado) {
        $mensaje = 'Demasiados intentos fallidos. Espera 15 minutos y vuelve a probar.';
    } else {
        $existe = false;
        $hash = HASH_FALSO;
        if ($usuarioVal !== '') {
            try {
                $st = $pdo->prepare('SELECT clave FROM usuarios WHERE usuario = ? LIMIT 1');
                $st->execute([$usuarioVal]);
                $fila = $st->fetch(PDO::FETCH_ASSOC);
                if ($fila) {
                    $existe = true;
                    $hash = (string) $fila['clave'];
                }
            } catch (Throwable $ex) {
            }
        }
        if ($existe && password_verify($clave, $hash)) {
            try {
                $pdo->prepare('DELETE FROM intentos_login WHERE ip = ?')->execute([$ip]);
            } catch (Throwable $ex) {
            }
            session_regenerate_id(true);
            $_SESSION['privado'] = true;
            $_SESSION['token'] = bin2hex(random_bytes(32));
            header('Location: login.php');
            exit;
        }
        try {
            $pdo->prepare('INSERT INTO intentos_login (ip, usuario) VALUES (?, ?)')->execute([$ip, $usuarioVal]);
        } catch (Throwable $ex) {
        }
        $mensaje = 'Usuario o contraseña incorrectos.';
        if ($bloqueado) {
            $mensaje = 'Demasiados intentos fallidos. Espera 15 minutos y vuelve a probar.';
        }
    }
}

if ($pdo && $tokenOk && (($_SESSION['privado'] ?? false) === true) && ($accion === 'borrar' || $accion === 'actualizar')) {
    $tabla = $_POST['tabla'] ?? 'inscripcion';
    $destino = 'login.php?vista=' . ($tabla === 'formacion' ? 'formacion' : 'inscripciones') . '&ok=';
    $res = 'error';
    if ($accion === 'borrar') {
        $bid = (int) ($_POST['id'] ?? 0);
        if ($bid > 0) {
            try {
                $pdo->prepare('DELETE FROM ' . ($tabla === 'formacion' ? 'interesados_formacion' : 'inscripcion') . ' WHERE id = ?')->execute([$bid]);
                $res = 'borrado';
            } catch (Throwable $ex) {
            }
        }
    } else {
        $aid = (int) ($_POST['id'] ?? 0);
        if ($aid > 0) {
            $sets = [];
            $args = [];
            foreach ($editables as $f) {
                $n = $f[0];
                $v = trim((string) ($_POST[$n] ?? ''));
                if ($f[2] === 'file') {
                    $tipo = $n === 'poster' ? 'poster' : 'fotograma';
                    $nuevaRuta = '';
                    if (isset($_FILES[$n]) && is_array($_FILES[$n])) {
                        $nuevaRuta = subirImagen($_FILES[$n], $tipo);
                    }
                    if ($nuevaRuta !== '') {
                        $sets[] = '`' . $n . '` = ?';
                        $args[] = $nuevaRuta;
                    }
                    continue;
                }
                if ($n === 'categoria' && !in_array($v, $opciones, true)) {
                    $v = $opciones[0];
                }
                if ($n === 'anio') {
                    $v = (string) preg_replace('/[^0-9]/', '', $v);
                    if ($v === '') {
                        $v = '0';
                    }
                }
                $sets[] = '`' . $n . '` = ?';
                $args[] = ($n === 'nacimiento' && $v === '') ? null : $v;
            }
            $args[] = $aid;
            try {
                $pdo->prepare('UPDATE inscripcion SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
                $res = 'guardado';
            } catch (Throwable $ex) {
            }
        }
    }
    syncObras();
    header('Location: ' . $destino . $res);
    exit;
}
if ($pdo && $tokenOk && (($_SESSION['privado'] ?? false) === true) && $accion === 'publicar') {
    $destino = 'login.php?vista=inscripciones&ok=';
    $res = 'error';
    $pid = (int) ($_POST['id'] ?? 0);
    $publicado = (string) ($_POST['publicado'] ?? '') === '1' ? 1 : 0;
    if ($pid > 0) {
        try {
            $pdo->prepare('UPDATE inscripcion SET publicado = ?, publicado_creado = IF(?, NOW(), NULL) WHERE id = ?')->execute([$publicado, $publicado, $pid]);
            $res = $publicado ? 'publicado' : 'despublicado';
        } catch (Throwable $ex) {
        }
    }
    syncObras();
    header('Location: ' . $destino . $res);
    exit;
}
if ($pdo && $tokenOk && (($_SESSION['privado'] ?? false) === true) && $accion === 'borrar_mensaje') {
    $mid = (int) ($_POST['id'] ?? 0);
    $res = 'error';
    if ($mid > 0) {
        try {
            $pdo->prepare('DELETE FROM mensajes WHERE id = ?')->execute([$mid]);
            $res = 'borrado';
        } catch (Throwable $ex) {
        }
    }
    header('Location: login.php?vista=mensajes&ok=' . $res);
    exit;
}

$entrado = (($_SESSION['privado'] ?? false) === true);
$vista = (string) ($_GET['vista'] ?? 'inscripciones');
if (!in_array($vista, VISTAS, true)) {
    $vista = 'inscripciones';
}

$filas = [];
$totalRecibidas = 0;
$q = trim((string) ($_GET['q'] ?? ''));
$cat = trim((string) ($_GET['cat'] ?? ''));
if ($cat !== '' && !in_array($cat, $opciones, true)) {
    $cat = '';
}
if ($entrado && $pdo) {
    try {
        $totalRecibidas = (int) $pdo->query('SELECT COUNT(*) FROM inscripcion')->fetchColumn();
    } catch (Throwable $ex) {
        $totalRecibidas = 0;
    }
    try {
        $cond = [];
        $args = [];
        if ($cat !== '') {
            $cond[] = 'categoria = ?';
            $args[] = $cat;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $cond[] = '(obra LIKE ? OR director LIKE ? OR responsable LIKE ? OR correo LIKE ?)';
            array_push($args, $like, $like, $like, $like);
        }
        $sql = 'SELECT * FROM inscripcion';
        if ($cond) {
            $sql .= ' WHERE ' . implode(' AND ', $cond);
        }
        $sql .= ' ORDER BY creado ASC, id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        $filas = [];
    }
}

$hayTablaMensajes = false;
$mensajes = [];
$hayTablaFormacion = false;
$interesados = [];
if ($entrado && $pdo) {
    try {
        $hayTablaMensajes = count($pdo->query("SHOW TABLES LIKE 'mensajes'")->fetchAll()) > 0;
    } catch (Throwable $ex) {
        $hayTablaMensajes = false;
    }
    if ($hayTablaMensajes) {
        try {
            $ms = $pdo->query('SELECT * FROM mensajes ORDER BY creado ASC, id ASC');
            $mensajes = $ms ? $ms->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $ex) {
            $mensajes = [];
        }
    }
    try {
        $hayTablaFormacion = count($pdo->query("SHOW TABLES LIKE 'interesados_formacion'")->fetchAll()) > 0;
    } catch (Throwable $ex) {
        $hayTablaFormacion = false;
    }
    if ($hayTablaFormacion) {
        try {
            $isf = $pdo->query('SELECT * FROM interesados_formacion ORDER BY categoria ASC, creado ASC, id ASC');
            $interesados = $isf ? $isf->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $ex) {
            $interesados = [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Zona privada del Festival de Cine Caricuao 2026.">
  <meta name="robots" content="noindex, nofollow">
  <title>Zona privada · Festival de Cine Caricuao 2026</title>
  <link rel="icon" type="image/png" sizes="32x32" href="recursos/favicon-32.png">
  <link rel="icon" type="image/png" sizes="180x180" href="recursos/favicon.png">
  <link rel="apple-touch-icon" href="recursos/favicon.png">
  <link rel="preload" href="recursos/oswald-latin-500.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="recursos/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<header class="header">
      <a class="brand" href="index.html"><span>I</span><span class="brand-label"><span class="brand-long">Festival de </span>Cine Caricuao <b>2026</b></span></a>
      <nav id="menu" aria-label="Navegación principal">
        <a href="index.html#inicio">Inicio</a><a href="index.html#festival">Festival</a><a href="index.html#competencia">Competencia</a><a href="index.html#formacion">Formación</a><a href="index.html#organizadores">Organizadores</a><a href="index.html#jurados">Jurados</a><a href="index.html#aliados">Aliados</a><a href="index.html#contacto">Contacto</a><a class="nav-inscripcion" href="inscripcion.php">Inscripción</a>
      </nav>
      <button class="menu-button" type="button" aria-label="Abrir menú" aria-expanded="false"><span></span><span></span></button>
    </header>
<?php if (!$entrado): ?>
<main>
      <section class="page-hero"><p class="eyebrow">Zona privada</p><h1><?= $hayCuenta ? 'Entrar.' : 'Crear acceso.' ?></h1><p><?= $hayCuenta ? 'Escribe tus datos para ver las inscripciones del festival.' : 'Todavía no existe una cuenta de administración. Crea la primera con el usuario y la contraseña que quieras usar.' ?></p></section>
      <div class="form-block">
        <?php if ($mensaje !== ''): ?>
        <div class="aviso bad" role="alert"><h2>No se pudo continuar.</h2><p><?= e($mensaje) ?></p></div>
        <?php endif; ?>
        <?php if (!$pdo || !$hayTablaUsuarios): ?>
        <div class="aviso warn" role="alert"><h2>Todavía no está lista.</h2><p><?= e($errorBd !== '' ? $errorBd : 'Falta crear las tablas de la zona privada.') ?></p></div>
        <?php elseif (!$hayCuenta): ?>
          <?php if ($hayInscripcion > 0): ?>
          <div class="aviso warn" role="status"><h2>Hay proyectos sin cuenta.</h2><p>Se recibieron <?= e((string) $hayInscripcion) ?> inscripción<?= $hayInscripcion === 1 ? '' : 'es' ?> y todavía no hay una cuenta de administración. Crea el acceso para poder verlas.</p></div>
          <?php endif; ?>
          <form method="post" action="login.php">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="accion" value="configurar">
            <div class="form-grid">
              <div class="field field-full">
                <label for="usuario">Usuario <span class="req" aria-hidden="true">*</span></label>
                <input id="usuario" name="usuario" type="text" autocomplete="username" required value="<?= e($usuarioVal) ?>">
              </div>
              <div class="field">
                <label for="clave">Contraseña <span class="req" aria-hidden="true">*</span></label>
                <input id="clave" name="clave" type="password" autocomplete="new-password" required>
                <span class="hint">Mínimo <?= e((string) MIN_CLAVE) ?> caracteres.</span>
              </div>
              <div class="field">
                <label for="clave2">Repite la contraseña <span class="req" aria-hidden="true">*</span></label>
                <input id="clave2" name="clave2" type="password" autocomplete="new-password" required>
              </div>
            </div>
            <p class="enviar"><button class="button" type="submit">Crear acceso</button></p>
            <p class="nota">Esta cuenta es para el equipo del festival. La contraseña se guarda cifrada en la base de datos.</p>
          </form>
        <?php else: ?>
          <form method="post" action="login.php">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="accion" value="entrar">
            <div class="form-grid">
              <div class="field field-full">
                <label for="usuario">Usuario <span class="req" aria-hidden="true">*</span></label>
                <input id="usuario" name="usuario" type="text" autocomplete="username" required value="<?= e($usuarioVal) ?>">
              </div>
              <div class="field field-full">
                <label for="clave">Contraseña <span class="req" aria-hidden="true">*</span></label>
                <input id="clave" name="clave" type="password" autocomplete="current-password" required>
              </div>
            </div>
            <p class="enviar"><button class="button" type="submit">Entrar</button></p>
          </form>
        <?php endif; ?>
      </div>
    </main>
<?php else: ?>
<main>
      <section class="page-hero"><p class="eyebrow">Zona privada</p><h1>Inscripciones.</h1><p><?= e((string) $totalRecibidas) ?> proyecto<?= $totalRecibidas === 1 ? '' : 's' ?> recibido<?= $totalRecibidas === 1 ? '' : 's' ?> hasta ahora.</p></section>

      <div class="privado">
      <form class="salir" method="post" action="login.php">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="salir">
        <button class="button" type="submit">Cerrar sesión</button>
      </form>

      <nav class="panel-tabs" aria-label="Secciones de la zona privada">
        <a class="panel-tab<?= $vista === 'inscripciones' ? ' active' : '' ?>" href="login.php?vista=inscripciones"<?= $vista === 'inscripciones' ? ' aria-current="page"' : '' ?>>Inscripciones al Festival</a>
        <a class="panel-tab<?= $vista === 'mensajes' ? ' active' : '' ?>" href="login.php?vista=mensajes"<?= $vista === 'mensajes' ? ' aria-current="page"' : '' ?>>Mensajes</a>
        <a class="panel-tab<?= $vista === 'formacion' ? ' active' : '' ?>" href="login.php?vista=formacion"<?= $vista === 'formacion' ? ' aria-current="page"' : '' ?>>Interesados en Formación</a>
      </nav>

      <?php if (!$pdo): ?>
      <div class="aviso warn" role="alert"><h2>Sin conexión a la base.</h2><p><?= e($errorBd !== '' ? $errorBd : 'No pudimos leer los datos.') ?></p></div>
      <?php elseif ($vista === 'inscripciones'): ?>
        <?php $ok = (string) ($_GET['ok'] ?? ''); ?>
        <?php if ($ok === 'guardado'): ?><div class="aviso ok" role="status"><p>Se guardaron los cambios.</p></div><?php endif; ?>
        <?php if ($ok === 'borrado'): ?><div class="aviso ok" role="status"><p>La inscripción se eliminó.</p></div><?php endif; ?>
        <?php if ($ok === 'publicado'): ?><div class="aviso ok" role="status"><p>La obra quedó publicada en su eje de la página de Competencia.</p></div><?php endif; ?>
        <?php if ($ok === 'despublicado'): ?><div class="aviso ok" role="status"><p>La obra se ocultó de la página de Competencia.</p></div><?php endif; ?>
        <?php if ($ok === 'error'): ?><div class="aviso bad" role="alert"><p>No se pudo completar la operación.</p></div><?php endif; ?>
        <?php if ($totalRecibidas === 0): ?>
        <div class="aviso" role="status"><h2>No hay inscripciones todavía.</h2><p>Cuando alguien envíe el formulario de inscripción, su proyecto aparecerá aquí.</p></div>
        <?php else: ?>
        <form class="sub-tools" method="get" action="login.php">
          <input type="hidden" name="vista" value="inscripciones">
          <div class="sub-buscar">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Título, director, responsable o correo" aria-label="Buscar inscripciones">
            <button class="button" type="submit">Buscar</button>
          </div>
          <div class="sub-cat-filter">
            <label for="sub-cat">Mostrar por categorías</label>
            <select id="sub-cat" name="cat" data-subcat>
              <option value="">Todas las categorías</option>
              <?php foreach ($opciones as $op): ?>
              <option value="<?= e($op) ?>"<?= $cat === $op ? ' selected' : '' ?>><?= e($op) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($q !== '' || $cat !== ''): ?><a class="sub-clear" href="login.php?vista=inscripciones">Ver todas</a><?php endif; ?>
        </form>
        <?php if (!$filas): ?>
        <div class="aviso" role="status"><h2>Sin resultados.</h2><p>No hay inscripciones que coincidan con la búsqueda.</p></div>
        <?php else: ?>
        <div class="sub-list">
          <div class="<div class="<div class="<div class="<div class="sub-head"><b>Nº</b><span>Nombre</span><span>Teléfono</span><span>Fecha de nacimiento</span><span class="sub-head-acc">Acciones</span></div>
          <?php $n = 0; foreach ($filas as $r): $n++; $id = (string) $r['id']; $num = str_pad((string) $n, 2, '0', STR_PAD_LEFT); ?>
          <article class="sub-item">
            <div class="sub-row">
              <button class="sub-open" type="button" data-sub="<?= e($id) ?>">
                <b><?= e($num) ?></b>
                <span class="sub-titulo"><?= e((string) $r['obra']) ?></span>
                <span class="sub-tipo"><?= e((string) $r['categoria']) ?></span>
                <span class="sub-director"><?= e((string) $r['director']) ?></span>
                <span class="sub-edicion"><?= e((string) $r['edicion']) ?><?= !empty($r['publicado']) ? ' · Publicada' : '' ?></span>
              </button>
              <div class="sub-actions">
                <button class="sub-edit-open" type="button" data-edit="<?= e($id) ?>" aria-label="Modificar la inscripción de <?= e((string) $r['obra']) ?>">Modificar</button>
                <button class="sub-eval" type="button" data-eval="<?= e($id) ?>">Evaluar</button>
                <form class="pub-form" method="post" action="login.php">
                  <input type="hidden" name="token" value="<?= e($token) ?>">
                  <input type="hidden" name="accion" value="publicar">
                  <input type="hidden" name="id" value="<?= e($id) ?>">
                  <input type="hidden" name="publicado" value="<?= !empty($r['publicado']) ? '0' : '1' ?>">
                  <button class="sub-pub<?= !empty($r['publicado']) ? ' on' : '' ?>" type="submit"><?= !empty($r['publicado']) ? 'Ocultar' : 'Publicar' ?></button>
                </form>
                <button class="sub-del" type="button" data-del="<?= e($id) ?>" data-obra="<?= e((string) $r['obra']) ?>" aria-label="Eliminar la inscripción de <?= e((string) $r['obra']) ?>">Eliminar</button>
              </div>
            </div>
                        <div class="            <div class="sub-full" id="sub-full-<?= e($id) ?>" hidden>
              <dl class="sub-data">
                <div><dt>Nombre</dt><dd><?= e((string) $r['nombre']) ?></dd></div>
                <div><dt>Categoría</dt><dd><?= e((string) $r['categoria']) ?></dd></div>
                <div><dt>Cédula</dt><dd><?= e(trim((string) $r['nacionalidad'] . ' ' . (string) $r['cedula'])) ?></dd></div>
                <div><dt>Fecha de nacimiento</dt><dd><?= e(fechaDia((string) $r['nacimiento'])) ?></dd></div>
                <div><dt>Celular (WhatsApp)</dt><dd><a href="tel:<?= e(telLimpio((string) $r['telefono'])) ?>"><?= e((string) $r['telefono']) ?></a></dd></div>
                <div><dt>Correo</dt><dd><a href="mailto:<?= e((string) $r['correo']) ?>"><?= e((string) $r['correo']) ?></a></dd></div>
                <?php if (trim((string) $r['representante_nombre']) !== ''): ?>
                <div><dt>Representante</dt><dd><?= e(trim((string) $r['representante_parentesco'] . ': ' . (string) $r['representante_nombre'] . ' · ' . trim((string) $r['representante_nacionalidad'] . ' ' . (string) $r['representante_cedula']))) ?></dd></div>
                <?php endif; ?>
                <div><dt>Fecha de inscripción</dt><dd><?= e(fechaHora((string) $r['creado'])) ?></dd></div>
              </dl>
            </div>'] ?? ''));
              $obraMod = ($obraPoster !== '' && $obraSin !== '') ? '' : ($obraPoster !== '' ? ' solo-poster' : ' solo-texto');
              ?>
              <?php if ($obraPoster !== '' || $obraSin !== ''): ?>
              <div class="obra-media<?= $obraMod ?>">
                <?php if ($obraPoster !== ''): ?><div class="obra-poster"><img src="<?= e($obraPoster) ?>" alt="Póster de la obra <?= e((string) $r['obra']) ?>" decoding="async"></div><?php endif; ?>
                <?php if ($obraSin !== ''): ?><div class="obra-sinopsis"><h3>Sinopsis</h3><p><?= nl2br(e($obraSin)) ?></p></div><?php endif; ?>
              </div>
              <?php endif; ?>
              <dl class="sub-data">
                <div><dt>Director</dt><dd><?= e((string) $r['director']) ?></dd></div>
                <div><dt>Productor</dt><dd><?= e((string) $r['productor']) ?></dd></div>
                <div><dt>Guionista</dt><dd><?= e((string) $r['guionista']) ?></dd></div>
                <div><dt>Equipo</dt><dd><?= nl2br(e((string) $r['equipo'])) ?></dd></div>
                <div><dt>Elenco</dt><dd><?= nl2br(e((string) $r['elenco'])) ?></dd></div>
              </dl>
              <?php if ($obraCaps): ?>
              <div class="obra-caps">
                <?php foreach ($obraCaps as $ci => $cu): ?><?php if (esImagen($cu)): ?><img src="<?= e($cu) ?>" alt="Fotograma <?= ($ci + 1) ?> de <?= e((string) $r['obra']) ?>" decoding="async"><?php else: ?><a class="obra-ext" href="<?= e($cu) ?>" target="_blank" rel="noopener noreferrer">Fotograma <?= ($ci + 1) ?></a><?php endif; ?><?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if ($obraVideo !== ''): ?>
              <div class="obra-video"><iframe src="<?= e($obraVideo) ?>" title="Video de <?= e((string) $r['obra']) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>
              <?php endif; ?>
              <p class="sub-time">Inscrita el <?= e(fechaHora((string) $r['creado'])) ?></p>
              <dl class="sub-data">
                <div><dt>Responsable</dt><dd><?= e((string) $r['responsable']) ?></dd></div>
                <?php if (fechaDia($r['nacimiento'] ?? '') !== ''): ?>
                <div><dt>Nacimiento</dt><dd><?= e(fechaDia((string) $r['nacimiento'])) ?></dd></div>
                <?php endif; ?>
                <div><dt>Cédula</dt><dd><?= e(trim((string) $r['nacionalidad'] . ' ' . (string) $r['cedula'])) ?></dd></div>
                <div><dt>Ubicación</dt><dd><?= e(implode(' / ', array_filter([(string) $r['estado'], (string) $r['ciudad'], (string) $r['municipio'], (string) $r['parroquia']]))) ?></dd></div>
                <?php if (trim((string) $r['nombre_representante']) !== ''): ?>
                <div><dt>Representante</dt><dd><?= e(trim((string) $r['parentesco'] . ': ' . (string) $r['nombre_representante'] . ' · ' . trim((string) $r['nacionalidad_representante'] . ' ' . (string) $r['cedula_representante']))) ?></dd></div>
                <?php endif; ?>
                <div><dt>Correo</dt><dd><a href="mailto:<?= e((string) $r['correo']) ?>"><?= e((string) $r['correo']) ?></a></dd></div>
                <div><dt>Teléfono</dt><dd><a href="tel:<?= e(telLimpio((string) $r['telefono'])) ?>"><?= e((string) $r['telefono']) ?></a></dd></div>
                <div><dt>Año</dt><dd><?= e((string) $r['anio']) ?></dd></div>
                <div><dt>Edición</dt><dd><?= e((string) $r['edicion']) ?></dd></div>
              </dl>
            </div>
            <div class="sub-edit" id="sub-edit-<?= e($id) ?>" hidden>
              <form class="sub-edit-form" method="post" action="login.php" enctype="multipart/form-data">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="accion" value="actualizar">
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <div class="form-grid">
                  <?php foreach ($editables as $f): $fn = $f[0]; $ft = $f[2]; $fv = (string) ($r[$fn] ?? ''); ?>
                  <div class="field<?= $ft === 'textarea' ? ' field-full' : '' ?>">
                    <label for="e-<?= e($id) ?>-<?= e($fn) ?>"><?= e($f[1]) ?></label>
                    <?php if ($ft === 'textarea'): ?>
                    <textarea id="e-<?= e($id) ?>-<?= e($fn) ?>" name="<?= e($fn) ?>"><?= e($fv) ?></textarea>
                    <?php elseif ($ft === 'select'): ?>
                    <select id="e-<?= e($id) ?>-<?= e($fn) ?>" name="<?= e($fn) ?>">
                      <?php foreach ($opciones as $op): ?><option value="<?= e($op) ?>"<?= $fv === $op ? ' selected' : '' ?>><?= e($op) ?></option><?php endforeach; ?>
                    </select>
                    <?php elseif ($ft === 'file'): ?>
                    <input type="hidden" name="<?= e($fn) ?>_prev" value="<?= e($fv) ?>">
                    <input id="e-<?= e($id) ?>-<?= e($fn) ?>" type="file" name="<?= e($fn) ?>" accept=".jpg,image/jpeg" class="file-input">
                    <label class="file-btn" for="e-<?= e($id) ?>-<?= e($fn) ?>"><span class="file-icon">+</span><span class="file-label"><?= $f[1] ?></span><span class="file-name"></span></label>
                    <?php else: ?>
                    <input id="e-<?= e($id) ?>-<?= e($fn) ?>" type="<?= $ft === 'number' ? 'number' : ($ft === 'date' ? 'date' : ($ft === 'email' ? 'email' : 'text')) ?>" name="<?= e($fn) ?>" value="<?= e($fv) ?>">
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
                <p class="enviar"><button class="button" type="submit">Guardar cambios</button></p>
              </form>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
       <?php elseif ($vista === 'formacion'): ?>
       <?php if (!$hayTablaFormacion): ?>
       <div class="aviso warn" role="alert"><h2>La tabla de interesados no existe.</h2><p>Hay que crear la tabla <strong>interesados_formacion</strong> en la base de datos.</p></div>
       <?php elseif (!$interesados): ?>
       <div class="aviso" role="status"><h2>No hay interesados todavía.</h2><p>Cuando alguien se inscriba desde la página de Formación, aparecerá aquí agrupado por categoría.</p></div>
       <?php else: ?>
       <form class="sub-tools" method="get" action="login.php">
         <input type="hidden" name="vista" value="formacion">
         <div class="sub-buscar">
           <input type="search" name="qf" value="<?= e($qf ?? '') ?>" placeholder="Nombre, teléfono, correo o cédula" aria-label="Buscar interesados en formación">
           <button class="button" type="submit">Buscar</button>
         </div>
         <a class="sub-clear" href="login.php?vista=formacion">Ver todas</a>
       </form>
       <div class="sub-list">
        <div class="sub-head"><b>Nº</b><span>Categoría</span><span>Nombre</span><span>Cédula</span><span>Nacimiento</span><span>Celular</span><span>Correo</span><span>Representante</span><span class="sub-head-acc">Fecha</span></div>
        <?php 
        $n = 0; 
        $currentCat = '';
        foreach ($interesados as $r): $n++; $id = (string) $r['id']; $num = str_pad((string) $n, 2, '0', STR_PAD_LEFT); 
          $cat = (string) $r['categoria'];
          $showCat = $cat !== $currentCat;
          $currentCat = $cat;
        ?>
        <article class="sub-item<?= $showCat ? ' cat-break' : '' ?>">
          <div class="sub-row">
             <button class="sub-open" type="button" data-sub="<?= e($id) ?>">
               <b><?= e($num) ?></b>
               <span class="sub-nombre"><?= e((string) $r['nombre']) ?></span>
               <span class="sub-tel"><?= e((string) $r['telefono']) ?></span>
               <span class="sub-nac"><?= e(fechaDia((string) $r['nacimiento'])) ?></span>
             </button>
            <div class="sub-actions single">
              <button class="sub-del" type="button" data-del="<?= e($id) ?>" data-obra="<?= e((string) $r['nombre']) ?>" data-tabla="formacion" aria-label="Eliminar el interesado <?= e((string) $r['nombre']) ?>">Eliminar</button>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <?php $msjOk = (string) ($_GET['ok'] ?? ''); ?>
      <?php if ($msjOk === 'borrado'): ?><div class="aviso ok" role="status"><p>El mensaje se eliminó.</p></div><?php endif; ?>
      <?php if ($msjOk === 'error'): ?><div class="aviso bad" role="alert"><p>No se pudo eliminar el mensaje.</p></div><?php endif; ?>
            <?php if (!$hayTablaMensajes): ?>
      <div class="aviso warn" role="alert"><h2>La tabla de mensajes todavía no existe.</h2><p>Hay que crear la tabla <strong>mensajes</strong> en la base de datos para poder guardar y leer los mensajes de contacto.</p></div>
      <?php elseif (!$mensajes): ?>
      <div class="aviso" role="status"><h2>No hay mensajes todavía.</h2><p>Cuando alguien envíe el formulario de contacto, su mensaje aparecerá aquí en orden de llegada.</p></div>
      <?php else: ?>
      <div class="sub-list msgs">
        <div class="sub-head msgs"><b>Nº</b><span>Remitente</span><span>Celular</span><span>Correo</span><span class="sub-head-acc">Acción</span></div>
        <?php $n = 0; foreach ($mensajes as $m): $n++; $mid = (string) $m['id']; $mnum = str_pad((string) $n, 2, '0', STR_PAD_LEFT); ?>
        <article class="sub-item">
          <div class="sub-row">
            <button class="msg-open" type="button" data-sub="<?= e($mid) ?>">
              <b><?= e($mnum) ?></b>
              <span class="msg-nombre"><?= e((string) $m['nombre']) ?></span>
              <span class="msg-tipo"><?= e((string) $m['telefono']) ?></span>
              <span class="msg-director"><?= e((string) $m['correo']) ?></span>
            </button>
            <div class="sub-actions single">
              <button class="msg-view" type="button" data-view="<?= e($mid) ?>" aria-label="Ver mensaje de <?= e((string) $m['nombre']) ?>">Ver mensaje</button>
              <button class="msg-del" type="button" data-msg="<?= e($mid) ?>" data-nombre="<?= e((string) $m['nombre']) ?>" aria-label="Eliminar el mensaje de <?= e((string) $m['nombre']) ?>">Eliminar</button>
            </div>
          </div>
          <div class="sub-full" id="sub-full-<?= e($mid) ?>" hidden>
            <p class="sub-time">Recibido el <?= e(fechaHora((string) $m['creado'])) ?></p>
            <dl class="sub-data">
              <div><dt>Nombre</dt><dd><?= e((string) $m['nombre']) ?></dd></div>
              <div><dt>Celular</dt><dd><a href="tel:<?= e(telLimpio((string) $m['telefono'])) ?>"><?= e((string) $m['telefono']) ?></a></dd></div>
              <div><dt>Correo</dt><dd><a href="mailto:<?= e((string) $m['correo']) ?>"><?= e((string) $m['correo']) ?></a></dd></div>
            </dl>
            <div class="sub-mensaje"><span>Mensaje</span><?= nl2br(e((string) $m['mensaje'])) ?></div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
      </div>
    </main>
<?php endif; ?>
<footer><div><h2>Caricuao proyecta<br><em>sus historias.</em></h2><p>Festival de Cine Caricuao 2026 · Primera edición · Caracas, Venezuela.</p><p class="footer-credit"><img src="recursos/aliados/rickyfilms.webp" width="180" height="55" alt="" loading="lazy" decoding="async">Concepto y desarrollo: Producciones Rickyfilms, C.A.</p></div><div><img class="footer-logo" src="recursos/loguinho.webp" width="353" height="96" alt="Festival de Cine Caricuao"><div class="footer-icons"><a href="login.php" aria-label="Acceder a la zona privada"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></a><a href="mailto:festivaldecinecaricuao@gmail.com" aria-label="Enviar correo">✉</a><a href="https://instagram.com/festivaldecinecaricuao" target="_blank" rel="noreferrer" aria-label="Abrir Instagram"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/></svg></a></div></div></footer>
<dialog class="modal" id="modal" aria-labelledby="modal-title"><div class="modal-box"><div class="modal-head"><b class="modal-number"></b><button class="modal-close" type="button" aria-label="Cerrar">&#215;</button></div><img class="modal-photo" alt=""><h2 class="modal-title" id="modal-title" tabindex="-1"></h2><div class="modal-text"></div></div></dialog>
<?php if ($entrado): ?>
<dialog class="modal modal-confirm" id="modal-borrar" aria-labelledby="borrar-title"><div class="modal-box"><div class="modal-head"><b class="modal-number"></b><button class="modal-close" type="button" data-cerrar-borrar aria-label="Cerrar">&#215;</button></div><h2 class="modal-title" id="borrar-title">Eliminar inscripción.</h2><div class="modal-text"><p>¿Seguro que quieres eliminar <strong class="borrar-obra"></strong>? Esta acción no se puede deshacer.</p></div><form class="modal-actions" method="post" action="login.php"><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value=""><input type="hidden" name="tabla" value=""><button class="button ghost" type="button" data-cerrar-borrar>Cancelar</button><button class="button danger" type="submit">Eliminar</button></form></div></dialog>
<dialog class="modal modal-confirm" id="modal-borrar-msg" aria-labelledby="borrar-msg-title"><div class="modal-box"><div class="modal-head"><b class="modal-number"></b><button class="modal-close" type="button" data-cerrar-borrar-msg aria-label="Cerrar">&#215;</button></div><h2 class="modal-title" id="borrar-msg-title">Eliminar mensaje.</h2><div class="modal-text"><p>¿Seguro que quieres eliminar el mensaje de <strong class="borrar-msg"></strong>? Esta acción no se puede deshacer.</p></div><form class="modal-actions" method="post" action="login.php"><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="accion" value="borrar_mensaje"><input type="hidden" name="id" value=""><button class="button ghost" type="button" data-cerrar-borrar-msg>Cancelar</button><button class="button danger" type="submit">Eliminar</button></form></div></dialog>
<?php endif; ?>
<script src="js/main.js"></script>
</body>
</html>
