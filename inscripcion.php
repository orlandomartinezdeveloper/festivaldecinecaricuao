<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'none'; form-action 'self'");

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function largo(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function limpiar(string $s): string
{
    return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s));
}

function edadDe(string $fecha): ?int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if ($d === false || $d->format('Y-m-d') !== $fecha) {
        return null;
    }
    $hoy = new DateTimeImmutable('today');
    if ($d > $hoy) {
        return null;
    }
    return (int) $d->diff($hoy)->y;
}

function geoIndice(): ?array
{
    static $idx = false;
    if ($idx !== false) {
        return $idx;
    }
    $idx = null;
    $crudo = @file_get_contents(__DIR__ . '/js/venezuela.js');
    if ($crudo !== false) {
        $crudo = preg_replace('/^\s*\/\*.*?\*\/\s*/s', '', $crudo);
        $crudo = preg_replace('/^\s*window\.VENEZUELA\s*=\s*/', '', $crudo);
        $crudo = rtrim(trim((string) $crudo), ';');
    }
    $datos = $crudo === false ? null : json_decode($crudo, true);
    if (is_array($datos)) {
        $sep = "\x1f";
        $idx = ['estados' => [], 'ciudades' => [], 'municipios' => [], 'parroquias' => [], 'capitalDe' => []];
        foreach ($datos as $e) {
            if (!isset($e['estado']) || !is_array($e['municipios'] ?? null)) {
                continue;
            }
            $est = trim((string) $e['estado']);
            $muns = [];
            $deCapital = [];
            foreach ($e['municipios'] as $m) {
                $mun = trim((string) ($m['municipio'] ?? ''));
                if ($mun === '') {
                    continue;
                }
                $pars = [];
                foreach (($m['parroquias'] ?? []) as $p) {
                    $p = trim((string) $p);
                    if ($p !== '') {
                        $pars[] = $p;
                    }
                }
                $muns[$mun] = $pars;
                $cap = trim((string) ($m['capital'] ?? ''));
                if ($cap !== '') {
                    $deCapital[$cap][] = $mun;
                }
            }
            $ciuds = [];
            foreach (($e['ciudades'] ?? []) as $c) {
                $c = trim((string) $c);
                if ($c !== '' && !in_array($c, $ciuds, true)) {
                    $ciuds[] = $c;
                }
            }
            foreach (array_keys($deCapital) as $c) {
                if (!in_array($c, $ciuds, true)) {
                    $ciuds[] = $c;
                }
            }
            sort($ciuds, SORT_NATURAL | SORT_FLAG_CASE);
            $idx['estados'][$est] = true;
            $idx['ciudades'][$est] = $ciuds;
            $idx['municipios'][$est] = array_keys($muns);
            $idx['capitalDe'][$est] = $deCapital;
            foreach ($muns as $mun => $pars) {
                $idx['parroquias'][$est . $sep . $mun] = $pars;
            }
        }
    }
    return $idx;
}

$correoRecibe = 'festivaldecinecaricuao@gmail.com';

$db = ['host' => '', 'nombre' => '', 'usuario' => '', 'clave' => ''];
if (is_file(__DIR__ . '/config.local.php')) include __DIR__ . '/config.local.php';
$listo = $db['host'] !== '';

$modo = (string) ($_POST['modo'] ?? '');
$msgEstado = '';
$msgErrores = [];
$msg = ['nombre' => '', 'telefono' => '', 'correo' => '', 'mensaje' => ''];
if ($modo === 'mensaje' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $msg = [
        'nombre' => limpiar((string) ($_POST['nombre'] ?? '')),
        'telefono' => limpiar((string) ($_POST['telefono'] ?? '')),
        'correo' => limpiar((string) ($_POST['correo'] ?? '')),
        'mensaje' => limpiar((string) ($_POST['mensaje'] ?? '')),
    ];
    if (trim((string) ($_POST['sitio_web'] ?? '')) !== '') {
        $msgEstado = 'ok';
    } else {
        if ($msg['nombre'] === '') {
            $msgErrores[] = 'El nombre es obligatorio.';
        } elseif (largo($msg['nombre']) > 180) {
            $msgErrores[] = 'El nombre es muy largo: máximo 180 caracteres.';
        }
        if ($msg['telefono'] === '') {
            $msgErrores[] = 'El celular es obligatorio.';
        } elseif ((int) strlen((string) preg_replace('/\D/', '', $msg['telefono'])) < 7) {
            $msgErrores[] = 'Revisa el celular: debería tener al menos 7 dígitos.';
        } elseif (largo($msg['telefono']) > 40) {
            $msgErrores[] = 'El celular es muy largo: máximo 40 caracteres.';
        }
        if ($msg['correo'] === '') {
            $msgErrores[] = 'El correo es obligatorio.';
        } elseif (!filter_var($msg['correo'], FILTER_VALIDATE_EMAIL)) {
            $msgErrores[] = 'Revisa el correo: no parece una dirección válida.';
        } elseif (largo($msg['correo']) > 180) {
            $msgErrores[] = 'El correo es muy largo: máximo 180 caracteres.';
        }
        if ($msg['mensaje'] === '') {
            $msgErrores[] = 'El mensaje es obligatorio.';
        } elseif (largo($msg['mensaje']) > 2000) {
            $msgErrores[] = 'El mensaje es muy largo: máximo 2000 caracteres.';
        }
        if (!$msgErrores) {
            if (!$listo) {
                $msgEstado = 'sin-configurar';
            } else {
                try {
                    $pdoM = new PDO(
                        'mysql:host=' . $db['host'] . ';dbname=' . $db['nombre'] . ';charset=utf8mb4',
                        $db['usuario'],
                        $db['clave'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    $pdoM->prepare('INSERT INTO mensajes (nombre, telefono, correo, mensaje) VALUES (?, ?, ?, ?)')
                        ->execute([$msg['nombre'], $msg['telefono'], $msg['correo'], $msg['mensaje']]);
                    $msgEstado = 'ok';
                } catch (Throwable $ex) {
                    $msgEstado = 'error';
                }
            }
        } else {
            $msgEstado = 'bad';
        }
    }
}

$opciones = [
    'Largometraje de ficción',
    'Largometraje documental',
    'Cortometraje de ficción',
    'Cortometraje documental',
    'Laboratorio',
];

$opcionesNac = ['Venezolano', 'Extranjero'];
$opcionesParentesco = ['Padre', 'Madre', 'Otro'];
$nacMin = date('Y-m-d', strtotime('-120 years'));
$nacMax = date('Y-m-d');

$campos = [
    ['n' => 'categoria', 'l' => 'Categoría del proyecto', 't' => 'select', 'o' => $opciones],
    ['n' => 'responsable', 'l' => 'Nombre del responsable', 't' => 'text', 'ac' => 'name', 'lmax' => 180],
    ['n' => 'telefono', 'l' => 'Celular (WhatsApp)', 't' => 'text', 'ac' => 'tel'],
    ['n' => 'correo', 'l' => 'Correo electrónico', 't' => 'email', 'ac' => 'email', 'lmax' => 180],
    ['n' => 'nacimiento', 'l' => 'Fecha de nacimiento', 't' => 'date', 'min' => $nacMin, 'max' => $nacMax, 'h' => 'Si tienes menos de 18 años, al elegir esta fecha se abrirán aquí abajo los datos de tu representante legal.'],
    ['n' => 'nacionalidad', 'l' => '¿Venezolano o extranjero?', 't' => 'select', 'o' => $opcionesNac, 'e' => 'Elige una opción'],
    ['n' => 'cedula', 'l' => 'Cédula', 't' => 'cedula', 'lmax' => 18],
    ['n' => 'estado', 'l' => 'Estado', 't' => 'geo', 'e' => 'Elige un estado'],
    ['n' => 'ciudad', 'l' => 'Ciudad', 't' => 'geo', 'e' => 'Elige una ciudad'],
    ['n' => 'municipio', 'l' => 'Municipio', 't' => 'geo', 'e' => 'Elige un municipio'],
    ['n' => 'parroquia', 'l' => 'Parroquia', 't' => 'geo', 'e' => 'Elige una parroquia'],
    ['n' => 'edicion', 'l' => 'Edición del festival', 't' => 'text', 'edicion' => 1, 'h' => 'Primera edición - 2026'],
    ['n' => 'parentesco', 'l' => 'Es el padre, la madre o el tutor', 't' => 'select', 'o' => $opcionesParentesco, 'menor' => 1, 'e' => 'Elige una opción'],
    ['n' => 'nombre_representante', 'l' => 'Nombre completo del representante', 't' => 'text', 'ac' => 'name', 'lmax' => 180, 'menor' => 1],
    ['n' => 'nacionalidad_representante', 'l' => 'Nacionalidad del representante', 't' => 'select', 'o' => $opcionesNac, 'menor' => 1, 'e' => 'Elige una opción'],
    ['n' => 'cedula_representante', 'l' => 'Cédula del representante', 't' => 'cedula', 'lmax' => 18, 'menor' => 1, 'h' => 'Igual que la tuya: números, y al salir del campo el formulario pone los puntos de miles.'],
    ['n' => 'obra', 'l' => 'Nombre de la obra', 't' => 'text', 'lmax' => 180],
    ['n' => 'anio', 'l' => 'Año', 't' => 'number', 'min' => '2025', 'max' => '2100', 'h' => 'Año de realización de la obra. Las obras deben ser del 2025 en adelante.'],
    ['n' => 'director', 'l' => 'Director', 't' => 'text', 'lmax' => 180],
    ['n' => 'productor', 'l' => 'Productor', 't' => 'text', 'lmax' => 180],
    ['n' => 'guionista', 'l' => 'Guionista', 't' => 'text', 'lmax' => 180],
    ['n' => 'equipo', 'l' => 'Equipo técnico', 't' => 'area', 'full' => 1, 'lmax' => 3000, 'h' => 'Escribe los nombres y apellidos de las personas que integran el equipo técnico y, junto a cada uno, describe su función (ej.: Juan Pérez – Director de fotografía, María López – Sonido).'],
    ['n' => 'elenco', 'l' => 'Elenco', 't' => 'area', 'full' => 1, 'lmax' => 3000, 'h' => 'Escribe los nombres y apellidos de actores y actrices, uno por línea o separados por punto y coma (ej.: Ana Torres – Protagonista, Carlos Rivas – Personaje secundario).'],
    ['n' => 'sinopsis', 'l' => 'Sinopsis', 't' => 'area', 'full' => 1, 'lmax' => 500, 'h' => 'Máximo 500 caracteres.'],
    ['n' => 'poster', 'l' => 'Link del póster (JPEG/JPG, 300 DPI)', 't' => 'url', 'full' => 1, 'lmax' => 500, 'h' => 'Súbelo a imgBB y pega aquí el enlace que te da.', 'imgbb' => 1],
    ['n' => 'f1', 'l' => 'Link de fotograma (1)', 't' => 'url', 'full' => 1, 'lmax' => 500, 'h' => 'Súbelo a imgBB y pega aquí el enlace.', 'imgbb' => 1],
    ['n' => 'f2', 'l' => 'Link de fotograma (2)', 't' => 'url', 'full' => 1, 'lmax' => 500, 'h' => 'Súbelo a imgBB y pega aquí el enlace.', 'imgbb' => 1],
    ['n' => 'f3', 'l' => 'Link de fotograma (3)', 't' => 'url', 'full' => 1, 'lmax' => 500, 'h' => 'Súbelo a imgBB y pega aquí el enlace.', 'imgbb' => 1],
    ['n' => 'youtube', 'l' => 'Link de YouTube (no listado, 1920 × 1080)', 't' => 'url', 'full' => 1, 'lmax' => 500, 'h' => 'En YouTube, comparte en modo "No listado" y confirma que el video se vea en 1920 × 1080.'],
];

$grupos = [
    ['l' => 'Quién inscribe', 'n' => ['categoria', 'responsable', 'telefono', 'correo']],
    ['l' => 'Datos personales', 'n' => ['nacimiento', 'nacionalidad', 'cedula', 'estado', 'ciudad', 'municipio', 'parroquia', 'edicion']],
    ['l' => 'Representante legal', 'menor' => 1, 'n' => ['parentesco', 'nombre_representante', 'nacionalidad_representante', 'cedula_representante']],
    ['l' => 'La obra', 'n' => ['obra', 'anio', 'director', 'productor', 'guionista']],
    ['l' => 'Equipo y contenido', 'n' => ['equipo', 'elenco', 'sinopsis']],
    ['l' => 'Links de imágenes y video', 'n' => ['poster', 'f1', 'f2', 'f3', 'youtube']],
];

$porNombre = [];
foreach ($campos as $c) {
    $porNombre[$c['n']] = $c;
}

$err = [];
$val = [];
$estado = '';
$esMenor = false;

if ($modo !== 'mensaje' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $bot = trim((string) ($_POST['sitio_web'] ?? '')) !== '';

    if ($bot) {
        $estado = 'ok';
    } else {
        $edad = edadDe(limpiar((string) ($_POST['nacimiento'] ?? '')));
        $esMenor = $edad !== null && $edad < 18;

        foreach ($campos as $c) {
            $v = limpiar((string) ($_POST[$c['n']] ?? ''));
            if (!empty($c['edicion'])) {
                $v = 'Primera Edición - 2026';
            }
            $val[$c['n']] = $v;

            if ($v === '') {
                if (!empty($c['menor']) && !$esMenor) {
                    continue;
                }
                $err[$c['n']] = 'Este campo es obligatorio.';
                continue;
            }
            if ($c['t'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $err[$c['n']] = 'Revisa el correo: no parece una dirección válida.';
            }
            if ($c['t'] === 'url') {
                $ok = (bool) filter_var($v, FILTER_VALIDATE_URL) && (bool) preg_match('~^https?://~i', $v);
                if (!$ok) {
                    $err[$c['n']] = 'Pega un enlace completo, que empiece por https://';
                }
            }
            if ($c['t'] === 'number') {
                if (!ctype_digit($v) || (int) $v < (int) $c['min'] || (int) $v > (int) $c['max']) {
                    $err[$c['n']] = 'Escribe un año de cuatro cifras, entre ' . $c['min'] . ' y ' . $c['max'] . '.';
                }
            }
            if ($c['t'] === 'tel' && strlen((string) preg_replace('/\D/', '', $v)) < 7) {
                $err[$c['n']] = 'Revisa el número: debería tener al menos 7 dígitos.';
            }
            if ($c['t'] === 'date') {
                $ed = edadDe($v);
                if ($ed === null || $ed > 120) {
                    $err[$c['n']] = 'Revisa la fecha: escribe una fecha de nacimiento real.';
                }
            }
            if ($c['t'] === 'cedula') {
                // El punto es separador de MILES, no decimal (V.-12.345.678), asi que
                // el texto que llega NO es solo digitos: se separa en letra y digitos y
                // se valida sobre eso, para que el servidor acepte lo que el navegador
                // muestra en vez de rechazarlo. Lo que se GUARDA va normalizado, sin
                // puntos, porque un numero con signo no se compara bien ni se ordena
                // como numero. El tope de 12 digitos es el que recorta la mascara, y
                // "1.000.000.000.000" con el prefijo "V.-" da 18 caracteres: el lmax.
                $letra = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $v));
                $digitos = (string) preg_replace('/[^0-9]/', '', $v);
                $exterior = $c['n'] === 'cedula_representante'
                    ? (($val['nacionalidad_representante'] ?? '') === 'Extranjero')
                    : (($val['nacionalidad'] ?? '') === 'Extranjero');
                $mal = largo($digitos) < 6 || largo($digitos) > 12;
                if ($exterior) {
                    // Solo una letra, la que lleve su documento (E, P, etc.).
                    if ($mal || strlen($letra) > 1) {
                        $err[$c['n']] = 'Cédula de extranjero: una letra y de 6 a 12 dígitos, por ejemplo E.-12.345.678.';
                    } else {
                        $val[$c['n']] = $letra . ($letra !== '' ? '-' : '') . $digitos;
                    }
                } elseif ($mal || ($letra !== '' && $letra !== 'V')) {
                    $err[$c['n']] = 'La cédula son de 6 a 12 números. Se escribe como V.-12.345.678.';
                } else {
                    $val[$c['n']] = $digitos;
                }
            }
            if ($c['t'] === 'select' && !in_array($v, $c['o'], true)) {
                $err[$c['n']] = $c['n'] === 'categoria' ? 'Elige una categoría de la lista.' : 'Elige una de las opciones de la lista.';
            }
            if ($c['n'] === 'sinopsis' && largo($v) > 500) {
                $err[$c['n']] = 'La sinopsis es muy larga: máximo 500 caracteres.';
            }
            if (!isset($err[$c['n']]) && isset($c['lmax']) && largo($v) > $c['lmax']) {
                $err[$c['n']] = 'Este campo es muy largo: máximo ' . $c['lmax'] . ' caracteres.';
            }
        }

        if (!$esMenor) {
            foreach (['parentesco', 'nombre_representante', 'nacionalidad_representante', 'cedula_representante'] as $rk) {
                $val[$rk] = '';
                unset($err[$rk]);
            }
        }

        if (!$err) {
            $idx = geoIndice();
            if ($idx === null) {
                $err['estado'] = 'No pudimos cargar la lista de estados. Escríbenos y registramos tu inscripción a mano.';
            } else {
                $est = $val['estado'];
                if (!isset($idx['estados'][$est])) {
                    $err['estado'] = 'Elige un estado de la lista.';
                } elseif (!in_array($val['ciudad'], $idx['ciudades'][$est], true)) {
                    $err['ciudad'] = 'Esa ciudad no está en la lista del estado que elegiste.';
                } elseif (!in_array($val['municipio'], $idx['municipios'][$est], true)) {
                    $err['municipio'] = 'Ese municipio no está en la lista del estado que elegiste.';
                } else {
                    $pars = $idx['parroquias'][$est . "\x1f" . $val['municipio']] ?? [];
                    if (count($pars) > 0 && !in_array($val['parroquia'], $pars, true)) {
                        $err['parroquia'] = 'Esa parroquia no está en la lista del municipio que elegiste.';
                    }
                    // si no hay parroquias, permitimos dejarlo vacío (estado/ciudad/municipio sin parroquia)
                }
            }
        }

        if (!$err) {
            if ($listo) {
                try {
                    $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['nombre'] . ';charset=utf8mb4';
                    $pdo = new PDO($dsn, $db['usuario'], $db['clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $cols = array_keys($val);
                    $sql = 'INSERT INTO inscripcion (`' . implode('`,`', $cols) . '`) VALUES ('
                        . implode(',', array_fill(0, count($cols), '?')) . ')';
                    $pdo->prepare($sql)->execute(array_values($val));
                    $estado = 'ok';
                } catch (Throwable $ex) {
                    $estado = 'error';
                }
            } else {
                $estado = 'sin-configurar';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Inscripción al Festival de Cine Caricuao 2026.">
  <meta name="robots" content="noindex">
  <title>Inscripción · Festival de Cine Caricuao 2026</title>
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
<main class="inscripcion">
<?php if ($modo === 'mensaje'): ?>
      <section class="page-hero"><p class="eyebrow">Festival de Cine Caricuao 2026</p><h1>Contacto.</h1><p>El estado de tu mensaje al festival.</p></section>
      <div class="form-block">
        <?php if ($msgEstado === 'ok'): ?>
        <div class="aviso ok" role="status"><h2>Recibimos tu mensaje.</h2><p>Gracias, <?= e($msg['nombre']) ?>. Te responderemos a <a href="mailto:<?= e($msg['correo']) ?>"><?= e($msg['correo']) ?></a> lo antes posible.</p></div>
        <?php elseif ($msgEstado === 'bad'): ?>
        <div class="aviso bad" role="alert"><h2>Faltan datos para enviar el mensaje.</h2><p><?= e(implode(' ', $msgErrores)) ?></p></div>
        <?php elseif ($msgEstado === 'error'): ?>
        <div class="aviso bad" role="alert"><h2>No pudimos guardar el mensaje.</h2><p>Hubo un problema al escribir en la base de datos. Inténtalo de nuevo o escríbenos a <a href="mailto:<?= e($correoRecibe) ?>"><?= e($correoRecibe) ?></a>.</p></div>
        <?php elseif ($msgEstado === 'sin-configurar'): ?>
        <div class="aviso warn" role="alert"><h2>Todavía no se guardan los mensajes.</h2><p>La página y el formulario ya funcionan, pero falta conectar la base de datos MySQL. Mientras tanto <strong>no se está guardando nada</strong>: escríbenos a <a href="mailto:<?= e($correoRecibe) ?>"><?= e($correoRecibe) ?></a>.</p></div>
        <?php endif; ?>
        <p class="enviar"><a class="button" href="index.html#contacto">Volver al sitio</a></p>
      </div>
<?php else: ?>
      <section class="page-hero"><p class="eyebrow">Festival de Cine Caricuao 2026</p><h1>Inscripción.</h1><p>Completa el formulario para inscribir tu obra en la primera edición del festival. Los campos marcados son obligatorios.</p></section>
      <div class="form-block">
        <?php if ($estado === 'ok'): ?>
        <div class="aviso ok" role="status"><h2>Recibimos tu inscripción.</h2><p>Gracias, <?= e($val['responsable'] ?? '') ?>. Tu obra <strong><?= e($val['obra'] ?? '') ?></strong> quedó registrada en la categoría <?= e($val['categoria'] ?? '') ?>. Ahora pasará a evaluación para confirmar que cumple con los requisitos de esta primera edición. Si hace falta algo o tenemos observaciones, entraremos en contacto contigo a través de <?= e($val['correo'] ?? '') ?>.</p></div>
        <?php elseif ($estado === 'error'): ?>
        <div class="aviso bad" role="alert"><h2>No pudimos guardar el formulario.</h2><p>Hubo un problema al escribir en la base de datos. Inténtalo de nuevo en un momento, o escríbenos a <a href="mailto:<?= e($correoRecibe) ?>"><?= e($correoRecibe) ?></a>.</p></div>
        <?php elseif ($estado === 'sin-configurar'): ?>
        <div class="aviso warn" role="alert"><h2>Todavía no se guardan los datos.</h2><p>La página y el formulario ya funcionan, pero falta conectar la base de datos MySQL. Mientras tanto <strong>no se está guardando nada</strong>: si querés que tu inscripción quede registrada, escríbenos a <a href="mailto:<?= e($correoRecibe) ?>"><?= e($correoRecibe) ?></a>.</p></div>
        <?php elseif ($err): ?>
        <div class="aviso bad" role="alert"><h2>Faltan datos para enviar.</h2><p>Revisa los campos marcados abajo.</p></div>
        <?php endif; ?>

        <?php if ($estado !== 'ok'): ?>
        <form method="post" action="" novalidate>
          <input type="text" name="sitio_web" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <?php foreach ($grupos as $g): $esRep = !empty($g['menor']); ?>
          <fieldset<?= $esRep ? ' class="rep-block" data-hoy="' . e(date('Y-m-d')) . '"' . ($esMenor ? '' : ' hidden') : '' ?>>
            <legend><?= e($g['l']) ?></legend>
            <?php if ($esRep): ?>
            <div class="aviso warn rep-aviso"<?= $esMenor ? '' : ' hidden' ?>>
              Al ser menor de edad, debes agregar los datos del representante legal para completar la inscripción.
            </div>
            <?php endif; ?>
            <div class="form-grid">
              <?php foreach ($g['n'] as $n): $c = $porNombre[$n]; $mal = isset($err[$n]); $id = 'f-' . $n; ?>
              <div class="field<?= !empty($c['full']) ? ' field-full' : '' ?>">
                <label for="<?= e($id) ?>"><?= e($c['l']) ?> <span class="req" aria-hidden="true">*</span></label>
                <?php if ($c['t'] === 'geo'): ?>
                <select id="<?= e($id) ?>" name="<?= e($n) ?>" data-geo="<?= e($n) ?>" data-elegido="<?= e($val[$n] ?? '') ?>" required aria-required="true"<?= $mal ? ' aria-invalid="true"' : '' ?> disabled>
                  <option value=""><?= e($c['e'] ?? 'Elige una opción') ?></option>
                </select>
                <?php elseif ($c['t'] === 'select'): ?>
                <select id="<?= e($id) ?>" name="<?= e($n) ?>" required aria-required="true"<?= $mal ? ' aria-invalid="true"' : '' ?>>
                  <option value=""><?= e($c['e'] ?? 'Elige una categoría') ?></option>
                  <?php foreach ($c['o'] as $op): ?><option value="<?= e($op) ?>"<?= ($val[$n] ?? '') === $op ? ' selected' : '' ?>><?= e($op) ?></option><?php endforeach; ?>
                </select>
                <?php elseif ($c['t'] === 'area'): ?>
                <textarea id="<?= e($id) ?>" name="<?= e($n) ?>" rows="4" required aria-required="true"<?= isset($c['lmax']) ? ' maxlength="' . e((string) $c['lmax']) . '"' : '' ?><?= $mal ? ' aria-invalid="true"' : '' ?>><?= e($val[$n] ?? '') ?></textarea>
                <?php else: ?>
                <input id="<?= e($id) ?>" type="<?= $c['t'] === 'cedula' ? 'text' : e($c['t']) ?>" name="<?= e($n) ?>" value="<?= e($val[$n] ?? (isset($c['edicion']) ? 'Primera Edición - 2026' : '')) ?>" required aria-required="true"<?= isset($c['edicion']) ? ' class="fijo" disabled' : '' ?><?= $c['t'] === 'cedula' ? ' inputmode="numeric" autocomplete="off" data-cedula="' . e($n) . '"' : '' ?><?= isset($c['ac']) ? ' autocomplete="' . e($c['ac']) . '"' : '' ?><?= isset($c['min']) ? ' min="' . e($c['min']) . '"' : '' ?><?= isset($c['max']) ? ' max="' . e($c['max']) . '"' : '' ?><?= isset($c['lmax']) ? ' maxlength="' . e((string) $c['lmax']) . '"' : '' ?><?= $mal ? ' aria-invalid="true"' : '' ?>>
                <?php endif; ?>
                <?php if (isset($c['h'])): ?><span class="hint"><?= e($c['h']) ?><?= !empty($c['imgbb']) ? ' <a class="imgbb-link" href="https://imgbb.com/" target="_blank" rel="noopener noreferrer">Subir a imgBB ↗</a>' : '' ?></span><?php endif; ?>
                <?php if ($mal): ?><span class="error"><?= e($err[$n]) ?></span><?php endif; ?>
                <?php if ($n === 'correo'): ?><span class="error" id="f-correo-error" hidden>Revisa el correo: debe incluir el arroba (@).</span><?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <?php endforeach; ?>
          <p class="enviar"><button class="button" type="submit">Enviar inscripción</button></p>
          <p class="nota">Al enviar aceptas que el festival use los datos de esta inscripción solo para organizar la competencia. También autorizas y consientes que tu obra audiovisual sea publicada en este sitio y mostrada tanto en el marco de este evento como en los que se realizaren en el futuro. Para cualquier duda escribe a <a href="mailto:<?= e($correoRecibe) ?>"><?= e($correoRecibe) ?></a>.</p>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </main>
<footer><div><h2>Caricuao proyecta<br><em>sus historias.</em></h2><p>Festival de Cine Caricuao 2026 · Primera edición · Caracas, Venezuela.</p><p class="footer-credit"><img src="recursos/aliados/rickyfilms.webp" width="180" height="55" alt="" loading="lazy" decoding="async">Concepto y desarrollo: Producciones Rickyfilms, C.A.</p></div><div><img class="footer-logo" src="recursos/loguinho.webp" width="353" height="96" alt="Festival de Cine Caricuao"><div class="footer-icons"><a href="login.php" aria-label="Acceder a la zona privada"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></a><a href="mailto:festivaldecinecaricuao@gmail.com" aria-label="Enviar correo">✉</a><a href="https://instagram.com/festivaldecinecaricuao" target="_blank" rel="noreferrer" aria-label="Abrir Instagram"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/></svg></a></div></div></footer>
<dialog class="modal modal-anio" id="modal-anio" aria-labelledby="modal-anio-title"><div class="modal-box"><div class="modal-head"><b class="modal-number">!</b><button class="modal-close" type="button" aria-label="Cerrar">&#215;</button></div><h2 class="modal-title" id="modal-anio-title" tabindex="-1">Obras del 2025 en adelante.</h2><p class="modal-text">Solo serán aceptadas las obras realizadas <strong>a partir del año 2025</strong>. Es una de las reglas del festival, así que cuando tengas listo el año de tu obra, puedes volver a escribirlo.</p></div></dialog>
<script src="js/venezuela.js"></script>
  <script src="js/main.js"></script>
</body>
</html>
