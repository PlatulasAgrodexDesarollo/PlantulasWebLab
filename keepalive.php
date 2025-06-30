<?php
// keepalive.php

// 1) Iniciar sesión
session_start();

// 2) Indicamos que la respuesta será JSON
header('Content-Type: application/json; charset=utf-8');

// 3) Verificar que haya sesión válida
if (!isset($_SESSION['ID_Operador'])) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'ERROR',
        'message' => 'No autenticado'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4) Conexión a BD
require_once __DIR__ . '/db.php';

// 5) Obtener el ID desde la sesión
$ID_Operador = (int) $_SESSION['ID_Operador'];

// 6) Actualizar last_activity en la tabla operadores
$stmt = $conn->prepare("
    UPDATE operadores
       SET last_activity = NOW()
     WHERE ID_Operador = ?
");
$stmt->bind_param('i', $ID_Operador);
if (! $stmt->execute()) {
    // Si falla la actualización en BD
    http_response_code(500);
    echo json_encode([
        'status'  => 'ERROR',
        'message' => 'No se pudo actualizar en la base de datos'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$stmt->close();

// 7) Actualizamos también la marca de actividad en la sesión (opcional)
$_SESSION['LAST_ACTIVITY'] = time();

// 8) Devolvemos el resultado OK en JSON
http_response_code(200);
echo json_encode([
    'status'       => 'OK',
    'lastActivity' => $_SESSION['LAST_ACTIVITY']
], JSON_UNESCAPED_UNICODE);
exit;
