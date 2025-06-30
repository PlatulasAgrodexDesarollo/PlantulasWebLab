<?php
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1) Incluir gestor de sesión
require_once __DIR__ . '/session_manager.php';

// 2) Conexión a la base de datos
require_once __DIR__ . '/db.php';

$error   = '';
$usuario = '';

// 3) Procesar formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario    = trim($_POST['usuario']    ?? '');
    $contrasena =           $_POST['contrasena'] ?? '';

    // 3a) Consultar usuario activo
    $stmt = $conn->prepare("
        SELECT `ID_Operador`, `Contrasena_Hash`, `ID_Rol`, `Nombre`
          FROM `operadores`
         WHERE LOWER(TRIM(`Usuario`)) = LOWER(?)
           AND `Activo` = 1
         LIMIT 1
    ");
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $stmt->bind_result($idOp, $hash, $rol, $nombre);
    $found = $stmt->fetch();
    $stmt->close();

    // 3b) Validaciones
    if (! $found) {
        $error = '❌ Usuario «'.htmlspecialchars($usuario).'» no encontrado o inactivo.';
    } elseif (! password_verify($contrasena, $hash)) {
        $error = '❌ Contraseña incorrecta.';
    } else {
        // 3b1) Credenciales OK: regenerar sesión
        session_regenerate_id(true);

        // 3b2) Guardar ID_Operador en sesión (coincide con session_manager.php)
        $_SESSION['ID_Operador'] = $idOp;
        $_SESSION['Rol']         = $rol;
        $_SESSION['Nombre']      = $nombre;

        // 3b3) Guardar session_id en BD
        $sid = session_id();
        $upd = $conn->prepare("
            UPDATE `operadores`
               SET `current_session_id` = ?,
                   `last_activity`       = NOW(),
                   `Ultimo_Acceso`       = NOW()
             WHERE `ID_Operador` = ?
        ");
        $upd->bind_param('si', $sid, $idOp);
        $upd->execute();
        $upd->close();

        // 3b4) Rutas según rol
        $rutas = [
            1  => 'rol_administrador/panel_admin.php',
            2  => 'rol_operador/dashboard_cultivo.php',
            3  => 'rol_supervisor/panel_supervisor.php',
            4  => 'rol_supervisora_incubadora/dashboard_supervisora.php',
            5  => 'rol_encargado_general_produccion/dashboard_egp.php',
            6  => 'rol_gerente_produccion_laboratorio/dashboard_gpl.php',
            7  => 'rol_responsable_produccion_medios_cultivo/dashboard_rpmc.php',
            8  => 'rol_responsable_rrs/dashboard_rrs.php',
            //9  => 'rol_encargado_incubadora/dashboard_eism.php',
            //10 => 'rol_encargado_oli/dashboard_eol.php',
        ];

        $destino = $rutas[$rol] ?? 'panel.php';
        header('Location: ' . $destino);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inicio de Sesión — Plantulas Agrodex</title>
  <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="login-page">
  <main class="login-container">
    <form class="login-card" method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
      <h1 class="login-title">Bienvenid@ a Plantulas Agrodex</h1>

      <?php if ($error): ?>
        <div class="error-message"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="input-group">
        <label for="usuario">Usuario</label>
        <input type="text" id="usuario" name="usuario" value="<?= htmlspecialchars($usuario) ?>" required autofocus>
      </div>

<div class="input-group">
  <label for="contrasena">Contraseña</label>
  <input type="password" id="contrasena" name="contrasena" required>
</div>
<div class="input-group mostrar-pass">
  <label>
    <input type="checkbox" onclick="togglePassword()"> Mostrar contraseña
  </label>
</div>

<style>
.mostrar-pass {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: -10px;
  margin-bottom: 15px;
  font-size: 14px;
}
.mostrar-pass input[type="checkbox"] {
  width: 16px;
  height: 16px;
  accent-color: green; /* compatible con navegadores modernos */
  cursor: pointer;
}
</style>

<script>
function togglePassword() {
  const input = document.getElementById("contrasena");
  input.type = input.type === "password" ? "text" : "password";
}
</script>


      <button type="submit" class="btn-login">Ingresar</button>
    </form>
