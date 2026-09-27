<?php
declare(strict_types=1);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function largo(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

$correoRecibe = 'festivaldecinecaricuao@gmail.com';

$db = ['host' => '', 'nombre' => '', 'usuario' => '', 'clave' => ''];
$listo = $db['host'] !== '';

$opciones = [
    'Largometraje de ficción',
    'Largometraje documental',
    'Cortometraje de ficción',
    'Cortometraje documental',
    'Laboratorio',
];

$campos = [
    ['n' => 'categoria', 'l' => 'Categoría del proyecto', 't' => 'select', 'o' => $opciones],
    ['n' => 'responsable', 'l' => 'Nombre del responsable', 't' => 'text', 'ac' => 'name'],
    ['n' => 'telefono', 'l' => 'Teléfono (WhatsApp)', 't' => 'tel', 'ac' => 'tel', 'h' => 'Escríbelo con el código de país y el signo +, por ejemplo +58 412 1234567.'],
    ['n' => 'correo', 'l' => 'Correo electrónico', 't' => 'email', 'ac' => 'email'],
    ['n' => 'obra', 'l' => 'Nombre de la obra', 't' => 'text'],
    ['n' => 'anio', 'l' => 'Año', 't' => 'number', 'min' => '1900', 'max' => '2100', 'h' => 'Año de realización de la obra.'],
    ['n' => 'director', 'l' => 'Director', 't' => 'text'],
    ['n' => 'productor', 'l' => 'Productor', 't' => 'text'],
    ['n' => 'guionista', 'l' => 'Guionista', 't' => 'text'],
    ['n' => 'equipo', 'l' => 'Equipo técnico', 't' => 'area', 'full' => 1, 'h' => 'Cámara, sonido, arte, montaje, música, lo que aplique.'],
    ['n' => 'elenco', 'l' => 'Elenco', 't' => 'area', 'full' => 1],
    ['n' => 'sinopsis', 'l' => 'Sinopsis', 't' => 'area', 'full' => 1, 'h' => 'Máximo 500 caracteres.'],
    ['n' => 'poster', 'l' => 'Link del póster (JPEG/JPG, 300 DPI)', 't' => 'url', 'full' => 1, 'h' => 'Súbelo a imgBB y pega aquí el enlace que te da.'],
    ['n' => 'f1', 'l' => 'Link de fotograma (1)', 't' => 'url', 'full' => 1, 'h' => 'Súbelo a imgBB y pega aquí el enlace.'],
    ['n' => 'f2', 'l' => 'Link de fotograma (2)', 't' => 'url', 'full' => 1, 'h' => 'Súbelo a imgBB y pega aquí el enlace.'],
    ['n' => 'f3', 'l' => 'Link de fotograma (3)', 't' => 'url', 'full' => 1, 'h' => 'Súbelo a imgBB y pega aquí el enlace.'],
    ['n' => 'youtube', 'l' => 'Link de YouTube (no listado, 1920 × 1080)', 't' => 'url', 'full' => 1, 'h' => 'En YouTube, comparte en modo "No listado" y confirma que el video se vea en 1920 × 1080.'],
];

$grupos = [
    ['l' => 'Quién inscribe', 'n' => ['categoria', 'responsable', 'telefono', 'correo']],
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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $bot = trim((string) ($_POST['sitio_web'] ?? '')) !== '';

    if ($bot) {
        $estado = 'ok';
    } else {
        foreach ($campos as $c) {
            $v = trim((string) ($_POST[$c['n']] ?? ''));
            $val[$c['n']] = $v;

            if ($v === '') {
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
            if ($c['n'] === 'sinopsis' && largo($v) > 500) {
                $err[$c['n']] = 'La sinopsis es muy larga: máximo 500 caracteres.';
            }
        }

        if (!$err) {
            if ($listo) {
                try {
                    $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['nombre'] . ';charset=utf8mb4';
                    $pdo = new PDO($dsn, $db['usuario'], $db['clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $cols = array_keys($val);
                    $sql = 'INSERT INTO inscripciones (`' . implode('`,`', $cols) . '`) VALUES ('
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
        <a href="index.html#inicio">Inicio</a><a href="index.html#festival">Festival</a><a href="index.html#competencia">Competencia</a><a href="index.html#formacion">Formación</a><a href="index.html#jurados">Jurados</a><a href="index.html#aliados">Aliados</a><a href="index.html#contacto">Contacto</a><a class="nav-inscripcion" href="inscripcion.php">Inscripción</a>
      </nav>
      <button class="menu-button" type="button" aria-label="Abrir menú" aria-expanded="false"><span></span><span></span></button>
    </header>
<main class="inscripcion">
      <section class="page-hero"><p class="eyebrow">Festival de Cine Caricuao 2026</p><h1>Inscripción.</h1><p>Completa el formulario para inscribir tu obra en la primera edición del festival. Los campos marcados son obligatorios.</p></section>
      <div class="form-block">
        <?php if ($estado === 'ok'): ?>
        <div class="aviso ok" role="status"><h2>Recibimos tu inscripción.</h2><p>Gracias, <?= e($val['responsable'] ?? '') ?>. Tu obra <strong><?= e($val['obra'] ?? '') ?></strong> quedó registrada en la categoría <?= e($val['categoria'] ?? '') ?>. Te escribimos a <?= e($val['correo'] ?? '') ?> si hace falta algo.</p><p><a class="button" href="inscripcion.php">Enviar otra inscripción</a></p></div>
        <?php elseif ($estado === 'error'): ?>
        <div class="aviso bad" role="alert"><h2>No pudimos guardar el formulario.</h2><p>Hubo un problema al escribir en la base de datos. Inténtalo de nuevo en un momento, o escríbenos a <a href="mailto:<?= e($correoRecibe) ?>"><?= e($correoRecibe) ?></a>.</p></div>
        <?php elseif ($estado === 'sin-configurar'): ?>
        <div class="aviso warn" role="alert"><h2>Todavía no se guardan los datos.</h2><p>La página y el formulario ya funcionan, pero falta conectar la base de datos MySQL. Mientras tanto <strong>no se está guardando nada</strong>: si querés que tu inscripción quede registrada, escríbenos a <a href="mailto:<?= e($correoRecibe) ?>"><?= e($correoRecibe) ?></a>.</p></div>
        <?php elseif ($err): ?>
        <div class="aviso bad" role="alert"><h2>Faltan datos para enviar.</h2><p>Revisa los campos marcados abajo.</p></div>
        <?php endif; ?>

        <form method="post" action="" novalidate>
          <input type="text" name="sitio_web" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <?php foreach ($grupos as $g): ?>
          <fieldset>
            <legend><?= e($g['l']) ?></legend>
            <div class="form-grid">
              <?php foreach ($g['n'] as $n): $c = $porNombre[$n]; $mal = isset($err[$n]); $id = 'f-' . $n; ?>
              <div class="field<?= !empty($c['full']) ? ' field-full' : '' ?>">
                <label for="<?= e($id) ?>"><?= e($c['l']) ?> <span class="req" aria-hidden="true">*</span></label>
                <?php if ($c['t'] === 'select'): ?>
                <select id="<?= e($id) ?>" name="<?= e($n) ?>" required aria-required="true"<?= $mal ? ' aria-invalid="true"' : '' ?>>
                  <option value="">Elige una categoría</option>
                  <?php foreach ($c['o'] as $op): ?><option value="<?= e($op) ?>"<?= ($val[$n] ?? '') === $op ? ' selected' : '' ?>><?= e($op) ?></option><?php endforeach; ?>
                </select>
                <?php elseif ($c['t'] === 'area'): ?>
                <textarea id="<?= e($id) ?>" name="<?= e($n) ?>" rows="4" required aria-required="true"<?= $mal ? ' aria-invalid="true"' : '' ?>><?= e($val[$n] ?? '') ?></textarea>
                <?php else: ?>
                <input id="<?= e($id) ?>" type="<?= e($c['t']) ?>" name="<?= e($n) ?>" value="<?= e($val[$n] ?? '') ?>" required aria-required="true"<?= isset($c['ac']) ? ' autocomplete="' . e($c['ac']) . '"' : '' ?><?= isset($c['min']) ? ' min="' . e($c['min']) . '"' : '' ?><?= isset($c['max']) ? ' max="' . e($c['max']) . '"' : '' ?><?= $mal ? ' aria-invalid="true"' : '' ?>>
                <?php endif; ?>
                <?php if (isset($c['h'])): ?><span class="hint"><?= e($c['h']) ?></span><?php endif; ?>
                <?php if ($mal): ?><span class="error"><?= e($err[$n]) ?></span><?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <?php endforeach; ?>
          <p class="enviar"><button class="button" type="submit">Enviar inscripción</button></p>
          <p class="nota">Al enviar aceptas que el festival use los datos de esta inscripción solo para organizar la competencia. Para cualquier duda escribe a <a href="mailto:<?= e($correoRecibe) ?>"><?= e($correoRecibe) ?></a>.</p>
        </form>
      </div>
    </main>
<footer><div><h2>Caricuao proyecta<br><em>sus historias.</em></h2><p>Festival de Cine Caricuao 2026 · Primera edición · Caracas, Venezuela.</p></div><div><img class="footer-logo" src="recursos/loguinho.webp" width="353" height="96" alt="Festival de Cine Caricuao"><div class="footer-icons"><a href="mailto:festivaldecinecaricuao@gmail.com" aria-label="Enviar correo">✉</a><a href="https://instagram.com/festivaldecinecaricuao" target="_blank" rel="noreferrer" aria-label="Abrir Instagram"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/></svg></a></div></div></footer>
<script src="js/main.js"></script>
</body>
</html>
