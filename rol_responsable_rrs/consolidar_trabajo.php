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

if ((int) $_SESSION['Rol'] !== 8) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Responsable de Registros y Reportes de Siembra.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Procesar consolidación y auditoría
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = $_POST['tipo'];
    $id   = intval($_POST['id']);

    if ($tipo === 'multiplicacion') {
        $tabla   = 'multiplicacion';
        $pkCampo = 'ID_Multiplicacion';
    } else {
        $tabla   = 'enraizamiento';
        $pkCampo = 'ID_Enraizamiento';
    }

    $stmtOld = $conn->prepare(
        "SELECT Estado_Revision 
           FROM $tabla 
          WHERE $pkCampo = ?"
    );
    $stmtOld->bind_param('i', $id);
    $stmtOld->execute();
    $old = $stmtOld->get_result()->fetch_assoc()['Estado_Revision'];

    $nuevo = 'Consolidado';
    $stmtUpd = $conn->prepare(
        "UPDATE $tabla
            SET Estado_Revision = ?
          WHERE $pkCampo = ?"
    );
    $stmtUpd->bind_param('si', $nuevo, $id);
    $stmtUpd->execute();

    if ($tipo === 'multiplicacion') {
      $sqlLog = "
        INSERT INTO consolidacion_log
          (ID_Multiplicacion, ID_Operador, Fecha_Hora, Estado_Anterior, Estado_Nuevo)
        VALUES (?, ?, ?, ?, ?)
      ";
      $stmtLog = $conn->prepare($sqlLog);
      $operadorId = $_SESSION['ID_Operador'];
      $fechaHora  = date('Y-m-d H:i:s');
      $stmtLog->bind_param('iisss', $id, $operadorId, $fechaHora, $old, $nuevo);
    } else {
      $sqlLog = "
        INSERT INTO consolidacion_log
          (ID_Enraizamiento, ID_Operador, Fecha_Hora, Estado_Anterior, Estado_Nuevo)
        VALUES (?, ?, ?, ?, ?)
      ";
      $stmtLog = $conn->prepare($sqlLog);
      $operadorId = $_SESSION['ID_Operador'];
      $fechaHora  = date('Y-m-d H:i:s');
      $stmtLog->bind_param('iisss', $id, $operadorId, $fechaHora, $old, $nuevo);
    }

    $stmtLog->execute();

    header('Location: consolidar_trabajo.php');
    exit();
}

$sql_mul = "
  SELECT 
    M.ID_Multiplicacion AS id,
    O.Nombre            AS operador,
    V.Codigo_Variedad,
    V.Nombre_Variedad,
    DATE(M.Fecha_Siembra) AS Fecha_Siembra,
    M.Cantidad_Dividida AS cantidad
  FROM multiplicacion M
  JOIN operadores O ON M.Operador_Responsable = O.ID_Operador
  JOIN variedades V ON M.ID_Variedad         = V.ID_Variedad
  WHERE M.Estado_Revision = 'Verificado'
  ORDER BY M.Fecha_Siembra DESC
";
$sql_enr = "
  SELECT 
    E.ID_Enraizamiento    AS id,
    O.Nombre             AS operador,
    V.Codigo_Variedad,
    V.Nombre_Variedad,
    DATE(E.Fecha_Siembra) AS Fecha_Siembra,
    E.Cantidad_Dividida   AS cantidad
  FROM enraizamiento E
  JOIN operadores O ON E.Operador_Responsable = O.ID_Operador
  JOIN variedades V ON E.ID_Variedad         = V.ID_Variedad
  WHERE E.Estado_Revision = 'Verificado'
  ORDER BY E.Fecha_Siembra DESC
";

$result_mul = $conn->query($sql_mul);
$result_enr = $conn->query($sql_enr);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Consolidar Trabajo</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-pagina">
    <header>
      <div class="encabezado d-flex align-items-center">
        <a class="navbar-brand me-3" href="dashboard_rrs.php">
          <img src="../logoplantulas.png" width="130" height="124" alt="Logo">
        </a>
        <div>
          <h2>Consolidar Trabajo</h2>
          <p class="mb-0">Marca como “Consolidado” los reportes verificados.</p>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_rrs.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <main class="container mt-4">
      <h4>Multiplicación</h4>
      <div class="table-responsive">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>Operador</th>
              <th>Variedad</th>
              <th>Fecha Siembra</th>
              <th>Cantidad</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $result_mul->fetch_assoc()): ?>
            <tr>
              <td data-label="Operador"><?= htmlspecialchars($row['operador']) ?></td>
              <td data-label="Variedad"><?= htmlspecialchars($row['Codigo_Variedad'].' – '.$row['Nombre_Variedad']) ?></td>
              <td data-label="Fecha Siembra"><?= htmlspecialchars($row['Fecha_Siembra']) ?></td>
              <td data-label="Cantidad"><?= htmlspecialchars($row['cantidad']) ?></td>
              <td data-label="Acción">
                <form method="POST" class="form-boton">
                  <input type="hidden" name="tipo" value="multiplicacion">
                  <input type="hidden" name="id"   value="<?= $row['id'] ?>">
                  <button type="submit" class="btn-consolidar btn-sm px-2 mb-2">✔ Consolidar</button>
                </form>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <h4 class="mt-5">Enraizamiento</h4>
      <div class="table-responsive">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>Operador</th>
              <th>Variedad</th>
              <th>Fecha Siembra</th>
              <th>Cantidad</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $result_enr->fetch_assoc()): ?>
            <tr>
              <td data-label="Operador"><?= htmlspecialchars($row['operador']) ?></td>
              <td data-label="Variedad"><?= htmlspecialchars($row['Codigo_Variedad'].' – '.$row['Nombre_Variedad']) ?></td>
              <td data-label="Fecha Siembra"><?= htmlspecialchars($row['Fecha_Siembra']) ?></td>
              <td data-label="Cantidad"><?= htmlspecialchars($row['cantidad']) ?></td>
              <td data-label="Acción">
                <form method="POST" class="form-boton">
                  <input type="hidden" name="tipo" value="enraizamiento">
                  <input type="hidden" name="id"   value="<?= $row['id'] ?>">
                  <button type="submit" class="btn-consolidar">✔ Consolidar</button>
                </form>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </main>

    <footer class="text-center py-3">
      &copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.
    </footer>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Modal de advertencia de sesión + Ping -->
  <script>
  (function(){
    let modalShown = false, warningTimer, expireTimer;
    function showModal() {
      modalShown = true;
      const modalHtml = `<div id="session-warning" class="modal-overlay">
        <div class="modal-box">
          <p>Tu sesión va a expirar pronto. ¿Deseas mantenerla activa?</p>
          <button id="keepalive-btn" class="btn-keepalive">Seguir activo</button>
        </div></div>`;
      document.body.insertAdjacentHTML('beforeend', modalHtml);
      document.getElementById('keepalive-btn').addEventListener('click', () => { cerrarModalYReiniciar(); });
    }
    function cerrarModalYReiniciar() {
      const modal = document.getElementById('session-warning');
      if (modal) modal.remove();
      reiniciarTimers();
      fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {});
    }
    function reiniciarTimers() {
      START_TS = Date.now(); modalShown = false;
      clearTimeout(warningTimer); clearTimeout(expireTimer); scheduleTimers();
    }
    function scheduleTimers() {
      const elapsed = Date.now() - START_TS;
      warningTimer = setTimeout(showModal, Math.max(SESSION_LIFETIME - WARNING_OFFSET - elapsed, 0));
      expireTimer = setTimeout(() => {
        if (!modalShown) { showModal(); }
        else { window.location.href = '/plantulas/login.php?mensaje=' + encodeURIComponent('Sesión caducada por inactividad'); }
      }, Math.max(SESSION_LIFETIME - elapsed, 0));
    }
    ['click', 'keydown'].forEach(event => {
      document.addEventListener(event, () => { reiniciarTimers(); fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {}); });
    });
    scheduleTimers();
  })();
  </script>
</body>
</html>
