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

// Filtro por estado
$estado_filtro = $_GET['estado'] ?? 'todos';
$where = '';
if ($estado_filtro === 'activos') {
    $where = "WHERE o.Activo = 1";
} elseif ($estado_filtro === 'inactivos') {
    $where = "WHERE o.Activo = 0";
}

// Obtener lista de operadores
$sql = "
    SELECT o.*, r.Nombre_Rol 
    FROM operadores o
    LEFT JOIN roles r ON o.ID_Rol = r.ID_Rol
    $where
    ORDER BY o.ID_Operador DESC
";
$operadores = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestionar Operadores</title>
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
      <h2>Gestionar Operadores</h2>
      <a href="panel_admin.php">
        <button class="btn-inicio">Volver al Panel</button>
      </a>
    </div>

    <!-- CONTENIDO -->
    <main>
      <!-- Filtro de estado -->
      <form method="GET" class="form-inline">
        <label for="estado"><strong>Filtrar por estado:</strong></label>
        <select name="estado" id="estado" onchange="this.form.submit()">
          <option value="todos"     <?= $estado_filtro==='todos'     ? 'selected' : '' ?>>Todos</option>
          <option value="activos"   <?= $estado_filtro==='activos'   ? 'selected' : '' ?>>Activos</option>
          <option value="inactivos" <?= $estado_filtro==='inactivos' ? 'selected' : '' ?>>Inactivos</option>
        </select>
      </form>

      <!-- Tabla responsive -->
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nombre completo</th>
              <th>Correo</th>
              <th>Puesto</th>
              <th>Área</th>
              <th>Rol</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($op = $operadores->fetch_assoc()) : ?>
              <tr>
                <td data-label="ID"><?= htmlspecialchars($op['ID_Operador']) ?></td>
                <td data-label="Nombre completo">
                  <?= htmlspecialchars("{$op['Nombre']} {$op['Apellido_P']} {$op['Apellido_M']}") ?>
                </td>
                <td data-label="Correo"><?= htmlspecialchars($op['Correo_Electronico']) ?></td>
                <td data-label="Puesto"><?= htmlspecialchars($op['Puesto']) ?></td>
                <td data-label="Área"><?= htmlspecialchars($op['Area_Produccion']) ?></td>
                <td data-label="Rol"><?= htmlspecialchars($op['Nombre_Rol']) ?></td>
                <td data-label="Estado"><?= $op['Activo'] ? 'Activo' : 'Inactivo' ?></td>
                <td data-label="Acciones" class="botones-contenedor">
                  <a href="editar_operador.php?id=<?= $op['ID_Operador'] ?>">
                    <button class="save-button">✏️ Editar</button>
                  </a>
                  <?php if ($op['Activo']) : ?>
                    <a href="cambiar_estado.php?id=<?= $op['ID_Operador'] ?>&estado=0">
                      <button class="btn-anular">Desactivar</button>
                    </a>
                  <?php else : ?>
                    <a href="cambiar_estado.php?id=<?= $op['ID_Operador'] ?>&estado=1">
                      <button class="btn-inicio">Activar</button>
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </main>

    <!-- FOOTER -->
    <footer>
      Sistema de Producción de Plantas &copy; <?= date("Y") ?>
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
