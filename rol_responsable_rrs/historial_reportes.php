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
$sessionLifetime = 60 * 3;
$warningOffset   = 60 * 1;
$nowTs           = time();

// Procesar consolidación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tipo'], $_POST['id'])) {
    $tipo = $_POST['tipo'];
    $id   = intval($_POST['id']);
    if ($tipo === 'multiplicacion') {
        $stmt = $conn->prepare("UPDATE multiplicacion SET Estado_Revision = 'Consolidado' WHERE ID_Multiplicacion = ?");
    } else {
        $stmt = $conn->prepare("UPDATE enraizamiento SET Estado_Revision = 'Consolidado' WHERE ID_Enraizamiento = ?");
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    header('Location: historial_reportes.php');
    exit();
}

// Filtros
$filterOp     = trim($_GET['operador']   ?? '');
$filterEstado = $_GET['estado']          ?? '';
$filterOpEsc  = $conn->real_escape_string($filterOp);

$whereOp = $filterOp ? " AND O.Nombre LIKE '%{$filterOpEsc}%'" : '';

$whereMul = $whereEnr = '';
if ($filterEstado === 'Pendiente') {
    $whereMul = " AND M.Estado_Revision = 'Verificado'";
    $whereEnr = " AND E.Estado_Revision = 'Verificado'";
} elseif ($filterEstado === 'Consolidado') {
    $whereMul = " AND M.Estado_Revision = 'Consolidado'";
    $whereEnr = " AND E.Estado_Revision = 'Consolidado'";
}

// Consultas
$sql_mul = "
  SELECT M.ID_Multiplicacion AS id, O.Nombre AS operador,
         V.Codigo_Variedad, V.Nombre_Variedad,
         DATE(M.Fecha_Siembra) AS Fecha_Siembra, M.Tasa_Multiplicacion,
         M.Cantidad_Dividida, M.Tuppers_Llenos, M.Tuppers_Desocupados,
         M.Estado_Revision
    FROM multiplicacion M
    JOIN operadores O ON M.Operador_Responsable = O.ID_Operador
    JOIN variedades V ON M.ID_Variedad         = V.ID_Variedad
   WHERE 1=1
     {$whereOp}
     {$whereMul}
   ORDER BY M.Fecha_Siembra DESC
";
$sql_enr = "
  SELECT E.ID_Enraizamiento AS id, O.Nombre AS operador,
         V.Codigo_Variedad, V.Nombre_Variedad,
         DATE(E.Fecha_Siembra) AS Fecha_Siembra, E.Tasa_Multiplicacion,
         E.Cantidad_Dividida, E.Tuppers_Llenos, E.Tuppers_Desocupados,
         E.Estado_Revision
    FROM enraizamiento E
    JOIN operadores O ON E.Operador_Responsable = O.ID_Operador
    JOIN variedades V ON E.ID_Variedad         = V.ID_Variedad
   WHERE 1=1
     {$whereOp}
     {$whereEnr}
   ORDER BY E.Fecha_Siembra DESC
";

$hist_mul = $conn->query($sql_mul);
$hist_enr = $conn->query($sql_enr);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Historial de Reportes</title>
  <link rel="stylesheet" href="../style.css?v=<?=time()?>">
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
          <h2>Historial de Reportes</h2>
          <p class="mb-0">Filtra por operador o estado.</p>
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

<!-- Filtros compactos -->
<nav class="filter-toolbar d-flex flex-wrap align-items-center gap-2 px-3 py-2" style="overflow-x:auto;">
  <div class="d-flex flex-column" style="min-width:160px;">
    <label for="filtro-operador" class="small mb-1">Operador</label>
    <input id="filtro-operador" type="text" name="operador" form="filtrosForm"
          class="form-control form-control-sm"
          placeholder="Operador…" value="<?= htmlspecialchars($filterOp) ?>">
  </div>

  <div class="d-flex flex-column" style="min-width:140px;">
    <label for="filtro-estado" class="small mb-1">Estado</label>
    <select id="filtro-estado" name="estado" form="filtrosForm"
            class="form-select form-select-sm">
      <option value="">— Todos —</option>
      <option value="Pendiente"   <?= $filterEstado==='Pendiente'   ? 'selected':''?>>Pendiente</option>
      <option value="Consolidado" <?= $filterEstado==='Consolidado' ? 'selected':''?>>Consolidado</option>
    </select>
  </div>

  <button form="filtrosForm" type="submit"
          class="btn-inicio btn btn-success btn-sm ms-auto">
    Filtrar
  </button>

  <a href="historial_reportes.php"
     class="btn btn-outline-secondary btn-sm">
    Limpiar filtros
  </a>
</nav>
</nav>

      <form id="filtrosForm" method="GET" class="d-none"></form>
    </header>

    <main class="container mt-4">
      <h4>Multiplicación</h4>
      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th><th>Operador</th><th>Variedad</th><th>Fecha</th>
              <th>Tasa</th><th>Cant.</th><th>LLenos</th><th>Vacíos</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php while($r=$hist_mul->fetch_assoc()):
              $estado = $r['Estado_Revision']==='Consolidado'
                ? '<span class="badge bg-success">Consolidado</span>'
                : '<span class="badge bg-warning text-dark">Pendiente</span>';
            ?>
            <tr>
              <td data-label="ID"><?=$r['id']?></td>
              <td data-label="Operador"><?=htmlspecialchars($r['operador'])?></td>
              <td data-label="Variedad"><?=htmlspecialchars("{$r['Codigo_Variedad']} – {$r['Nombre_Variedad']}")?></td>
              <td data-label="Fecha"><?=$r['Fecha_Siembra']?></td>
              <td data-label="Tasa"><?=$r['Tasa_Multiplicacion']?></td>
              <td data-label="Cant."><?=$r['Cantidad_Dividida']?></td>
              <td data-label="LLenos"><?=$r['Tuppers_Llenos']?></td>
              <td data-label="Vacíos"><?=$r['Tuppers_Desocupados']?></td>
              <td data-label="Estado"><?=$estado?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <h4 class="mt-5">Enraizamiento</h4>
      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th><th>Operador</th><th>Variedad</th><th>Fecha</th>
              <th>Tasa</th><th>Cant.</th><th>LLenos</th><th>Vacíos</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php while($r=$hist_enr->fetch_assoc()):
              $estado = $r['Estado_Revision']==='Consolidado'
                ? '<span class="badge bg-success">Consolidado</span>'
                : '<span class="badge bg-warning text-dark">Pendiente</span>';
            ?>
            <tr>
              <td data-label="ID"><?=$r['id']?></td>
              <td data-label="Operador"><?=htmlspecialchars($r['operador'])?></td>
              <td data-label="Variedad"><?=htmlspecialchars("{$r['Codigo_Variedad']} – {$r['Nombre_Variedad']}")?></td>
              <td data-label="Fecha"><?=$r['Fecha_Siembra']?></td>
              <td data-label="Tasa"><?=$r['Tasa_Multiplicacion']?></td>
              <td data-label="Cant."><?=$r['Cantidad_Dividida']?></td>
              <td data-label="LLenos"><?=$r['Tuppers_Llenos']?></td>
              <td data-label="Vacíos"><?=$r['Tuppers_Desocupados']?></td>
              <td data-label="Estado"><?=$estado?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </main>

    <footer class="text-center py-3">&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</footer>
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
