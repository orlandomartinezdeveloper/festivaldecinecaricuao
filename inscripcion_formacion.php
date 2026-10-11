<?php
declare(strict_types=1);

error_reporting(0);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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

$db = ['host' => '', 'nombre' => '', 'usuario' => '', 'clave' => ''];
if (is_file(__DIR__ . '/config.local.php')) include __DIR__ . '/config.local.php';
$listo = $db['host'] !== '';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    exit;
}

$categoria = limpiar((string) ($input['categoria'] ?? ''));
$nombre = limpiar((string) ($input['nombre'] ?? ''));
$cedula = limpiar((string) ($input['cedula'] ?? ''));
$nacimiento = limpiar((string) ($input['nacimiento'] ?? ''));
$nacionalidad = limpiar((string) ($input['nacionalidad'] ?? ''));
$telefono = limpiar((string) ($input['telefono'] ?? ''));
$correo = limpiar((string) ($input['correo'] ?? ''));
$rep_parentesco = limpiar((string) ($input['rep_parentesco'] ?? ''));
$rep_nombre = limpiar((string) ($input['rep_nombre'] ?? ''));
$rep_nacionalidad = limpiar((string) ($input['rep_nacionalidad'] ?? ''));
$rep_cedula = limpiar((string) ($input['rep_cedula'] ?? ''));

$errores = [];

if ($categoria === '') $errores[] = 'Categoría requerida';
if ($nombre === '') $errores[] = 'Nombre completo requerido';
if ($cedula === '') $errores[] = 'Cédula requerida';
if ($nacimiento === '') $errores[] = 'Fecha de nacimiento requerida';
if ($telefono === '') $errores[] = 'Celular requerido';

$edad = edadDe($nacimiento);
$esMenor = $edad !== null && $edad < 18;

if ($esMenor) {
    if ($rep_parentesco === '') $errores[] = 'Parentesco del representante requerido';
    if ($rep_nombre === '') $errores[] = 'Nombre del representante requerido';
    if ($rep_nacionalidad === '') $errores[] = 'Nacionalidad del representante requerida';
    if ($rep_cedula === '') $errores[] = 'Cédula del representante requerida';
} else {
    $rep_parentesco = '';
    $rep_nombre = '';
    $rep_nacionalidad = '';
    $rep_cedula = '';
}

if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'Correo inválido';
}

if ($errores) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => implode(' ', $errores)]);
    exit;
}

if (!$listo) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'Base de datos no configurada']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $db['host'] . ';dbname=' . $db['nombre'] . ';charset=utf8mb4',
        $db['usuario'],
        $db['clave'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->prepare(
        'INSERT INTO interesados_formacion (categoria, nombre, cedula, nacimiento, nacionalidad, telefono, correo, representante_parentesco, representante_nombre, representante_nacionalidad, representante_cedula) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $categoria, $nombre, $cedula, $nacimiento, $nacionalidad, $telefono, $correo,
        $rep_parentesco, $rep_nombre, $rep_nacionalidad, $rep_cedula
    ]);
    ob_clean();
    echo json_encode(['ok' => true]);
} catch (Throwable $ex) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'Error al guardar en la base de datos']);
}