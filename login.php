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
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
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
const VISTAS = ['inscripciones', 'talleres', 'mensajes'];

$correoRecibe = 'festivaldecinecaricuao@gmail.com';

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

$entrado = (($_SESSION['privado'] ?? false) === true);
$vista = (string) ($_GET['vista'] ?? 'inscripciones');
if (!in_array($vista, VISTAS, true)) {
    $vista = 'inscripciones';
}

$filas = [];
$totalInscritas = 0;
if ($entrado && $pdo) {
    try {
        $filas = $pdo->query('SELECT * FROM inscripcion ORDER BY creado ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $totalInscritas = count($filas);
    } catch (Throwable $ex) {
        $filas = [];
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
      <section class="page-hero"><p class="eyebrow">Zona privada</p><h1>Inscripciones.</h1><p><?= e((string) $totalInscritas) ?> proyecto<?= $totalInscritas === 1 ? '' : 's' ?> recibido<?= $totalInscritas === 1 ? '' : 's' ?> hasta ahora.</p></section>

      <div class="privado">
      <form class="salir" method="post" action="login.php">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="salir">
        <button class="button" type="submit">Cerrar sesión</button>
      </form>

      <nav class="panel-tabs" aria-label="Secciones de la zona privada">
        <a class="panel-tab<?= $vista === 'inscripciones' ? ' active' : '' ?>" href="login.php?vista=inscripciones"<?= $vista === 'inscripciones' ? ' aria-current="page"' : '' ?>>Inscripciones al Festival</a>
        <a class="panel-tab<?= $vista === 'talleres' ? ' active' : '' ?>" href="login.php?vista=talleres"<?= $vista === 'talleres' ? ' aria-current="page"' : '' ?>>Interesados en Talleres</a>
        <a class="panel-tab<?= $vista === 'mensajes' ? ' active' : '' ?>" href="login.php?vista=mensajes"<?= $vista === 'mensajes' ? ' aria-current="page"' : '' ?>>Mensajes</a>
      </nav>

      <?php if (!$pdo): ?>
      <div class="aviso warn" role="alert"><h2>Sin conexión a la base.</h2><p><?= e($errorBd !== '' ? $errorBd : 'No pudimos leer los datos.') ?></p></div>
      <?php elseif ($vista === 'inscripciones'): ?>
        <?php if ($totalInscritas === 0): ?>
        <div class="aviso" role="status"><h2>No hay inscripciones todavía.</h2><p>Cuando alguien envíe el formulario de inscripción, su proyecto aparecerá aquí.</p></div>
        <?php else: ?>
        <div class="sub-list">
          <div class="sub-head"><b>Nº</b><span>Título de la obra</span><span>Categoría</span><span>Director(es)</span><span>Año</span></div>
          <?php $n = 0; foreach ($filas as $r): $n++; $id = (string) $r['id']; $num = str_pad((string) $n, 2, '0', STR_PAD_LEFT); ?>
          <article class="sub-item">
            <button class="sub-open" type="button" data-sub="<?= e($id) ?>">
              <b><?= e($num) ?></b>
              <span class="sub-titulo"><?= e((string) $r['obra']) ?></span>
              <span class="sub-tipo"><?= e((string) $r['categoria']) ?></span>
              <span class="sub-director"><?= e((string) $r['director']) ?></span>
              <span class="sub-edicion"><?= e((string) $r['edicion']) ?></span>
            </button>
            <div class="sub-full" id="sub-full-<?= e($id) ?>" hidden>
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
              <details class="sub-more">
                <summary>Ver ficha completa</summary>
                <dl class="sub-data">
                  <div><dt>Director</dt><dd><?= e((string) $r['director']) ?></dd></div>
                  <div><dt>Productor</dt><dd><?= e((string) $r['productor']) ?></dd></div>
                  <div><dt>Guionista</dt><dd><?= e((string) $r['guionista']) ?></dd></div>
                  <div><dt>Equipo</dt><dd><?= nl2br(e((string) $r['equipo'])) ?></dd></div>
                  <div><dt>Elenco</dt><dd><?= nl2br(e((string) $r['elenco'])) ?></dd></div>
                  <div><dt>Sinopsis</dt><dd><?= nl2br(e((string) $r['sinopsis'])) ?></dd></div>
                </dl>
              </details>
              <?php
              $enlaces = [];
              foreach ([['poster', 'Póster'], ['f1', 'Fotograma 1'], ['f2', 'Fotograma 2'], ['f3', 'Fotograma 3'], ['youtube', 'YouTube']] as $lk) {
                  $u = urlSegura((string) ($r[$lk[0]] ?? ''));
                  if ($u !== '') {
                      $enlaces[] = '<a class="sub-link" href="' . e($u) . '" target="_blank" rel="noopener noreferrer">' . e($lk[1]) . '</a>';
                  }
              }
              ?>
              <?php if ($enlaces): ?>
              <div class="sub-links"><?= implode('', $enlaces) ?></div>
              <?php endif; ?>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php elseif ($vista === 'talleres'): ?>
      <div class="aviso" role="status"><h2>Interesados en Talleres.</h2><p>Todavía no hay registros de interesados en los talleres. Cuando exista el formulario, las solicitudes aparecerán en esta sección.</p></div>
      <?php else: ?>
      <div class="aviso" role="status"><h2>Mensajes.</h2><p>Todavía no hay mensajes recibidos. Cuando el formulario de contacto empiece a enviarlos, se verán en esta sección.</p></div>
      <?php endif; ?>
      </div>
    </main>
<?php endif; ?>
<footer><div><h2>Caricuao proyecta<br><em>sus historias.</em></h2><p>Festival de Cine Caricuao 2026 · Primera edición · Caracas, Venezuela.</p><p class="footer-credit"><img src="recursos/aliados/rickyfilms.webp" width="180" height="55" alt="" loading="lazy" decoding="async">Concepto y desarrollo: Producciones Rickyfilms, C.A.</p></div><div><img class="footer-logo" src="recursos/loguinho.webp" width="353" height="96" alt="Festival de Cine Caricuao"><div class="footer-icons"><a href="mailto:festivaldecinecaricuao@gmail.com" aria-label="Enviar correo">✉</a><a href="https://instagram.com/festivaldecinecaricuao" target="_blank" rel="noreferrer" aria-label="Abrir Instagram"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/></svg></a></div></div></footer>
<dialog class="modal" id="modal" aria-labelledby="modal-title"><div class="modal-box"><div class="modal-head"><b class="modal-number"></b><button class="modal-close" type="button" aria-label="Cerrar">&#215;</button></div><img class="modal-photo" alt=""><h2 class="modal-title" id="modal-title" tabindex="-1"></h2><div class="modal-text"></div></div></dialog>
<script src="js/main.js"></script>
</body>
</html>
