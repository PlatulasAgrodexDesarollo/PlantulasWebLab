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

// 5) Obtener el ID del operador a editar
if (!isset($_GET['id'])) {
    echo "ID no especificado.";
    exit();
}
$id = intval($_GET['id']);
$mensaje = "";

// 6) Si llegan datos por POST, actualizamos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre       = $_POST['nombre'];
    $apellido_p   = $_POST['apellido_p'];
    $apellido_m   = $_POST['apellido_m'];
    $correo       = $_POST['correo'];
    $puesto       = $_POST['puesto'];
    $area         = $_POST['area_produccion'];
    $id_rol       = $_POST['id_rol'];

    $sql = "UPDATE operadores SET 
              Nombre              = ?,
              Apellido_P          = ?,
              Apellido_M          = ?,
              Correo_Electronico  = ?,
              Puesto              = ?,
              Area_Produccion     = ?,
              ID_Rol              = ?,
              Fecha_Actualizacion = NOW()
            WHERE ID_Operador = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
      'sssssiii',
      $nombre,
      $apellido_p,
      $apellido_m,
      $correo,
      $puesto,
      $area,
      $id_rol,
      $id
    );

    if ($stmt->execute()) {
        $mensaje = "<p class=\"error-message\" style=\"color: green;\">✅ Datos actualizados correctamente</p>";
    } else {
        $mensaje = "<p class=\"error-message\">❌ Error: " . htmlspecialchars($stmt->error) . "</p>";
    }
    $stmt->close();
}

// 7) Recuperar los datos actuales del operador
$res = $conn->prepare("
    SELECT Nombre, Apellido_P, Apellido_M, Correo_Electronico, Puesto, Area_Produccion, ID_Rol
      FROM operadores
     WHERE ID_Operador = ?
");
$res->bind_param('i', $id);
$res->execute();
$result   = $res->get_result();
$operador = $result->fetch_assoc();
$res->close();

if (!$operador) {
    echo "Operador no encontrado.";
    exit();
}

// 8) Obtener roles disponibles
$roles = $conn->query("SELECT ID_Rol, Nombre_Rol FROM roles");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Editar Operador</title>
  <link rel="stylesheet" href="../style.css">
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    const START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-pagina">
    <!-- HEADER -->
    <div class="encabezado">
      <div class="navbar-brand">🌱 Sistema Plantulas</div>
      <h2>Editar Operador</h2>
      <a href="gestionar_operadores.php">
        <button class="btn-inicio">Volver</button>
      </a>
    </div>

    <!-- FORMULARIO CENTRADO -->
    <main class="login-container">
      <?= $mensaje; ?>

      <form method="POST" action="">
        <label>
          Nombre:
          <input type="text" name="nombre"
                 value="<?= htmlspecialchars($operador['Nombre']); ?>"
                 required>
        </label>

        <label>
          Apellido Paterno:
          <input type="text" name="apellido_p"
                 value="<?= htmlspecialchars($operador['Apellido_P']); ?>"
                 required>
        </label>

        <label>
          Apellido Materno:
          <input type="text" name="apellido_m"
                 value="<?= htmlspecialchars($operador['Apellido_M']); ?>"
                 required>
        </label>

        <label>
          Correo Electrónico:
          <input type="email" name="correo"
                 value="<?= htmlspecialchars($operador['Correo_Electronico']); ?>">
        </label>

        <label>
          Puesto:
          <input type="text" name="puesto"
                 value="<?= htmlspecialchars($operador['Puesto']); ?>"
                 required>
        </label>

        <label>
          Área de Producción:
          <input type="text" name="area_produccion"
                 value="<?= htmlspecialchars($operador['Area_Produccion']); ?>">
        </label>

        <label>
          Rol del sistema:
          <select name="id_rol" required>
            <?php while ($rol = $roles->fetch_assoc()) : ?>
              <option value="<?= $rol['ID_Rol']; ?>"
                <?= $rol['ID_Rol'] == $operador['ID_Rol'] ? 'selected' : ''; ?>>
                <?= htmlspecialchars($rol['Nombre_Rol']); ?>
              </option>
            <?php endwhile; ?>
          </select>
        </label>

        <button type="submit" class="btn-inicio">Guardar Cambios</button>
      </form>
    </main>

    <!-- FOOTER -->
    <footer>
      Sistema de Producción de Plantas &copy; <?= date("Y"); ?>
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
