<?php
// Ajustar zona horaria local
date_default_timezone_set('America/Mexico_City');

// Mostrar errores en desarrollo
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Iniciar o reanudar la sesión
session_start();

// Definiciones de timeout y regeneración
define('SESSION_LIFETIME', 60 * 3);           // 3 minutos = 180 segundos
define('SESSION_REGENERATE_INTERVAL', 60 * 5); // 5 minutos

// Si no hay sesión iniciada, salimos (no hacemos nada más)
if (!isset($_SESSION['ID_Operador'])) {
    return;
}

// Ya hay sesión, obtenemos el operador
$ID_Operador = (int) $_SESSION['ID_Operador'];

require_once __DIR__ . '/db.php'; // Conexión ($conn)

// —— VALIDACIÓN DE SID EN BASE DE DATOS ——

// SID actual de PHP
$currentSid = session_id();

// Traer de BD el session_id y el tiempo de inactividad
$stmt = $conn->prepare("
    SELECT 
      `current_session_id`,
      TIMESTAMPDIFF(SECOND, `last_activity`, NOW()) AS inactivity
    FROM `operadores`
    WHERE `ID_Operador` = ?
    LIMIT 1
");
$stmt->bind_param('i', $ID_Operador);
$stmt->execute();
$stmt->bind_result($dbSid, $inactivity);
$stmt->fetch();
$stmt->close();

// Si el SID en BD no coincide, destruimos la sesión
if ($dbSid !== $currentSid) {
    session_unset();
    session_destroy();
    header('Location: /plantulas/login.php?mensaje='
           . urlencode('Otra sesión iniciada'));
    exit;
}

// Si ha superado los 3 min de inactividad, destruimos la sesión
if ($inactivity > SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    header('Location: /plantulas/login.php?mensaje='
    . urlencode('Sesión caducada por inactividad'));
    exit;
}

// Actualizar last_activity al momento actual
$upd = $conn->prepare("
    UPDATE `operadores`
       SET `last_activity` = NOW()
     WHERE `ID_Operador` = ?
");
$upd->bind_param('i', $ID_Operador);
$upd->execute();
$upd->close();

// —— REGENERACIÓN PERIÓDICA DE SESSION ID ——

if (!isset($_SESSION['last_regenerated'])) {
    $_SESSION['last_regenerated'] = time();
}
if (time() - $_SESSION['last_regenerated'] > SESSION_REGENERATE_INTERVAL) {
    session_regenerate_id(true);
    $_SESSION['last_regenerated'] = time();

    // Guardar nuevo SID en BD
    $newSid = session_id();
    $upd2 = $conn->prepare("
        UPDATE `operadores`
           SET `current_session_id` = ?
         WHERE `ID_Operador` = ?
    ");
    $upd2->bind_param('si', $newSid, $ID_Operador);
    $upd2->execute();
    $upd2->close();
}

// Si llegamos aquí, la sesión es válida y continúa la carga normal
