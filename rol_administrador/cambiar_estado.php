<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 1) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Gerente de Producción de Laboratorio.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Validar datos recibidos
if (!isset($_GET['id']) || !isset($_GET['estado'])) {
  echo "Datos incompletos.";
  exit();
}

$id = intval($_GET['id']);
$nuevo_estado = intval($_GET['estado']); // 1 = activo, 0 = inactivo

// Actualizar estado
$sql = "UPDATE operadores SET Activo = $nuevo_estado, Fecha_Actualizacion = NOW() WHERE ID_Operador = $id";

if (mysqli_query($conn, $sql)) {
  header("Location: gestionar_operadores.php");
  exit();
} else {
  echo "❌ Error al actualizar estado: " . mysqli_error($conn);
}
?>
