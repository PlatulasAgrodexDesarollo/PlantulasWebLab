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
// Funciones
function generarUsuario($nombre, $apellido_p) {
  global $conn;
  $base = strtolower(trim($nombre)) . '.' . strtolower(trim($apellido_p));
  $usuario = $base;
  $i = 1;

  while (mysqli_num_rows(mysqli_query($conn, "SELECT * FROM operadores WHERE Usuario='$usuario'")) > 0) {
    $usuario = $base . $i;
    $i++;
  }

  return $usuario;
}

function generarContrasena($longitud = 8) {
  $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
  return substr(str_shuffle($caracteres), 0, $longitud);
}

// Procesar formulario si se ha enviado
$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nombre = $_POST['nombre'];
  $apellido_p = $_POST['apellido_p'];
  $apellido_m = $_POST['apellido_m'];
  $area = $_POST['area_produccion'];
  $puesto = $_POST['puesto'];
  $fecha_ingreso = $_POST['fecha_ingreso'];
  $correo = $_POST['correo'];
  $id_rol = $_POST['id_rol'];

  $usuario = generarUsuario($nombre, $apellido_p);
  $contrasena = generarContrasena();
  $hash = password_hash($contrasena, PASSWORD_DEFAULT);

  $sql = "INSERT INTO operadores (
    Nombre, Apellido_P, Apellido_M, Area_Produccion, Puesto, Fecha_Ingreso,
    Correo_Electronico, Usuario, Contrasena_Hash, Activo, ID_Rol, Fecha_Registro
  ) VALUES (
    '$nombre', '$apellido_p', '$apellido_m', '$area', '$puesto', '$fecha_ingreso',
    '$correo', '$usuario', '$hash', TRUE, $id_rol, CURDATE()
  )";

  if (mysqli_query($conn, $sql)) {
    $mensaje = "<div style='color: green;'>
                  <h3>✅ Operador registrado correctamente</h3>
                  <p><strong>Usuario:</strong> $usuario</p>
                  <p><strong>Contraseña:</strong> $contrasena</p>
                </div>";
  } else {
    $mensaje = "<p style='color: red;'>❌ Error al registrar operador: " . mysqli_error($conn) . "</p>";
  }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registro de Operador</title>
  <link rel="stylesheet" href="../style.css">
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    const START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
<div class="contenedor-pagina">
  <div class="encabezado">
    <div class="navbar-brand">🌱 Sistema Plantulas</div>
    <h2>Registro de Operadores</h2>
    <div>
      <a href="panel_admin.php"><button>Volver al Panel</button></a>
    </div>
  </div>

  <main>
    <?php echo $mensaje; ?>

    <form method="POST">
      <label>Nombre:
        <input type="text" name="nombre" required>
      </label>

      <label>Apellido Paterno:
        <input type="text" name="apellido_p" required>
      </label>

      <label>Apellido Materno:
        <input type="text" name="apellido_m" required>
      </label>

      <label>Área de Producción:
        <input type="text" name="area_produccion">
      </label>

      <label>Puesto:
        <input type="text" name="puesto" required>
      </label>

      <label>Fecha de Ingreso:
        <input type="date" name="fecha_ingreso" required>
      </label>

      <label>Correo Electrónico:
        <input type="email" name="correo">
      </label>

      <label>Rol del sistema:
  <select name="id_rol" required>
    <option value="">-- Selecciona un rol --</option>
    <?php
    // Consulta todos los roles de la tabla roles
    $result = mysqli_query($conn, "SELECT ID_Rol, Nombre_Rol FROM roles ORDER BY ID_Rol ASC");
    while ($row = mysqli_fetch_assoc($result)) {
      echo "<option value='" . $row['ID_Rol'] . "'>" . htmlspecialchars($row['Nombre_Rol']) . "</option>";
    }
    ?>
  </select>
</label>

      <button type="submit">Registrar Operador</button>
    </form>
  </main>

  <footer>
    Sistema de Producción de Plantas &copy; <?php echo date("Y"); ?>
  </footer>
  </div>
    <!-- Modal de advertencia de sesión -->
    <script>
  (function(){
    const elapsed     = Date.now() - START_TS;
    const warnAfter   = SESSION_LIFETIME - WARNING_OFFSET;
    const expireAfter = SESSION_LIFETIME;
    let modalShown = false;

    const modalHtml = `
      <div id="session-warning" class="modal-overlay">
        <div class="modal-box">
          <p>Tu sesión va a expirar pronto. ¿Deseas mantenerla activa?</p>
          <button id="keepalive-btn" class="btn-keepalive">Seguir activo</button>
        </div>
      </div>`;

    setTimeout(() => {
      modalShown = true;
      document.body.insertAdjacentHTML('beforeend', modalHtml);
      document.getElementById('keepalive-btn').addEventListener('click', () => {
        fetch('../keepalive.php', { credentials:'same-origin' })
          .then(r => r.text())
          .then(txt => {
            if (txt.trim() === 'OK') location.reload();
            else alert('Error al mantener la sesión');
          });
      });
    }, Math.max(warnAfter - elapsed, 0));

    setTimeout(() => {
      if (modalShown) {
        location.href = '../login.php?mensaje=Sesión caducada por inactividad';
      }
    }, Math.max(expireAfter - elapsed, 0));
  })();
  </script>
</body>
</html>
