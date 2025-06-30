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

require __DIR__ . '/../libs/dompdf/autoload.inc.php';
use Dompdf\Dompdf;

$operador   = trim($_GET['operador']   ?? '');
$fechaDesde = $_GET['desde']          ?? '';
$fechaHasta = $_GET['hasta']          ?? '';
$type       = $_GET['type']           ?? '';

$opEsc = $conn->real_escape_string($operador);

$where = "WHERE 1=1";
if ($operador   !== '') $where .= " AND r.Operador_Original = '{$opEsc}'";
if ($fechaDesde !== '') $where .= " AND r.Fecha >= '{$fechaDesde}'";
if ($fechaHasta !== '') $where .= " AND r.Fecha <= '{$fechaHasta}'";

$sql = "
SELECT * FROM (
  -- Multiplicación
  SELECT
    'Multiplicación' AS Etapa,
    M.ID_Multiplicacion AS ID,
    -- Nombre del operador responsable de siembra
    CONCAT(O.Nombre,' ',O.Apellido_P,' ',O.Apellido_M) AS Operador_Original,
    -- Nombre del que consolida
    CONCAT(Oc.Nombre,' ',Oc.Apellido_P,' ',Oc.Apellido_M) AS Operador_Consolida,
    CONCAT(V.Codigo_Variedad,' – ',V.Nombre_Variedad)    AS Variedad,
    COALESCE(NULLIF(V.Color, ''), 'S/D')                 AS Color,
    M.Fecha_Siembra                                     AS Fecha,
    M.Cantidad_Dividida                                 AS Cantidad
  FROM multiplicacion M
  JOIN operadores O       ON M.Operador_Responsable = O.ID_Operador
  JOIN consolidacion_log CL ON CL.ID_Multiplicacion = M.ID_Multiplicacion
  JOIN operadores Oc      ON CL.ID_Operador        = Oc.ID_Operador
  JOIN variedades V       ON M.ID_Variedad        = V.ID_Variedad
  WHERE M.Estado_Revision = 'Consolidado'

  UNION ALL

  -- Enraizamiento
  SELECT
    'Enraizamiento' AS Etapa,
    E.ID_Enraizamiento AS ID,
    CONCAT(O.Nombre,' ',O.Apellido_P,' ',O.Apellido_M) AS Operador_Original,
    CONCAT(Oc2.Nombre,' ',Oc2.Apellido_P,' ',Oc2.Apellido_M) AS Operador_Consolida,
    CONCAT(V2.Codigo_Variedad,' – ',V2.Nombre_Variedad)       AS Variedad,
    COALESCE(NULLIF(V2.Color, ''), 'S/D')                      AS Color,
    E.Fecha_Siembra                                           AS Fecha,
    E.Cantidad_Dividida                                       AS Cantidad
  FROM enraizamiento E
  JOIN operadores O       ON E.Operador_Responsable = O.ID_Operador
  JOIN consolidacion_log CL2 ON CL2.ID_Enraizamiento = E.ID_Enraizamiento
  JOIN operadores Oc2     ON CL2.ID_Operador        = Oc2.ID_Operador
  JOIN variedades V2      ON E.ID_Variedad         = V2.ID_Variedad
  WHERE E.Estado_Revision = 'Consolidado'
) AS r
{$where}
ORDER BY r.Fecha DESC
";

$result = $conn->query($sql);

// Excel
if ($type === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reportes_consolidados.csv"');
    echo "\xEF\xBB\xBF";
    echo "Etapa,ID,Operador Original,Consolidado Por,Variedad,Color,Fecha de Siembra,Cantidad\n";
    while ($row = $result->fetch_assoc()) {
        $fields = array_map(fn($f) => '"'.str_replace('"','""',$f).'"', [
            $row['Etapa'],
            $row['ID'],
            $row['Operador_Original'],
            $row['Operador_Consolida'],
            $row['Variedad'],
            $row['Color'],
            $row['Fecha'],
            $row['Cantidad']
        ]);
        echo implode(',', $fields)."\n";
    }
    exit;
}


// Obtener operadores para filtro
$opsSql = "
  SELECT DISTINCT CONCAT(Nombre,' ',Apellido_P,' ',Apellido_M) AS Operador
    FROM operadores O
    JOIN (
      SELECT Operador_Responsable AS id FROM multiplicacion WHERE Estado_Revision='Consolidado'
      UNION ALL
      SELECT Operador_Responsable AS id FROM enraizamiento WHERE Estado_Revision='Consolidado'
    ) t ON O.ID_Operador = t.id
  ORDER BY Operador
";
$opsResult = $conn->query($opsSql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Exportar Reportes Consolidados</title>
  <link rel="stylesheet" href="../style.css?v=<?=time()?>"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
 <style>
@media screen and (max-width: 768px) {
  table.table thead { display: none; }
  table.table tbody tr {
    display: block;
    margin-bottom: 1rem;
    border: 1px solid #ddd;
    border-radius: 0.5rem;
    padding: 0.75rem;
    background-color: #f9f9f9;
  }
  table.table tbody td {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0;
    border: none;
  }
  table.table tbody td::before {
    content: attr(data-label);
    font-weight: bold;
  }
}
</style>

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
          <h2>Exportar Reportes Consolidados</h2>
          <p class="mb-0">Filtra antes de ver o descargar.</p>
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

<nav class="filter-toolbar row gx-2 gy-2 align-items-end mb-3">
  <div class="col-12 col-sm-5 col-md-auto">
    <label for="filtro-operador" class="form-label small">Operador</label>
    <select id="filtro-operador" name="operador" form="filtrosForm"
            class="form-select form-select-sm">
      <option value="">— Todos —</option>
      <?php while($o = $opsResult->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($o['Operador'])?>"
          <?= $o['Operador'] === $operador ? 'selected' : ''?>>
          <?= htmlspecialchars($o['Operador'])?>
        </option>
      <?php endwhile; ?>
    </select>
  </div>

  <div class="col-4 col-md-auto" style="min-width:100px;">
    <label for="filtro-desde" class="form-label small">Desde</label>
    <input id="filtro-desde" form="filtrosForm" type="date" name="desde"
           class="form-control form-control-sm"
           value="<?= $fechaDesde ?>">
  </div>

  <div class="col-4 col-md-auto" style="min-width:100px;">
    <label for="filtro-hasta" class="form-label small">Hasta</label>
    <input id="filtro-hasta" form="filtrosForm" type="date" name="hasta"
           class="form-control form-control-sm"
           value="<?= $fechaHasta ?>">
  </div>

  <div class="col-12 col-sm-auto d-flex gap-2">
    <button form="filtrosForm" type="submit"
            class="btn btn-success btn-sm">
      Aplicar filtros
    </button>
<a href="exportar_reportes.php" class="btn btn-outline-secondary btn-sm">
  Limpiar filtros
</a>
  </div>
</nav>

    </header>

    <main class="container-fluid mt-3 flex-grow-1">
      <form id="filtrosForm" method="GET" class="d-none"></form>

      <div class="table-responsive mb-4">
        <table class="table table-striped table-sm">
          <thead class="table-light">
            <tr>
              <th>Etapa</th><th>ID</th>
              <th>Operador Original</th><th>Consolidado Por</th>
              <th>Variedad</th><th>Color</th>
              <th>Fecha de Siembra</th><th>Cantidad</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $preview = $conn->query($sql);
            while($r = $preview->fetch_assoc()): ?>
              <tr>
<td data-label="Etapa"><?= $r['Etapa'] ?></td>
<td data-label="ID"><?= $r['ID'] ?></td>
<td data-label="Operador Original"><?= htmlspecialchars($r['Operador_Original']) ?></td>
<td data-label="Consolidado Por"><?= htmlspecialchars($r['Operador_Consolida']) ?></td>
<td data-label="Variedad"><?= htmlspecialchars($r['Variedad']) ?></td>
<td data-label="Color"><?= htmlspecialchars($r['Color']) ?></td>
<td data-label="Fecha de Siembra"><?= $r['Fecha'] ?></td>
<td data-label="Cantidad"><?= $r['Cantidad'] ?></td>

              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div class="text-center">
        <a href="?<?= http_build_query([
              'operador'=>$operador,
              'desde'=>$fechaDesde,
              'hasta'=>$fechaHasta,
              'type'=>'excel'
            ]) ?>"
           class="btn btn-success me-2 btn-sm">
          📊 Descargar Excel
        </a>

<a id="btn-pdf"   href="reporte_pdf.php?<?= http_build_query(['operador'=>$operador, 'desde'=>$fechaDesde, 'hasta'=>$fechaHasta]) ?>" target="_blank" class="btn btn-danger btn-sm" style="display:none">
  📄 Ver/Imprimir PDF
</a>
<a id="btn-vista" href="reporte_vista.php?<?= http_build_query(['operador'=>$operador, 'desde'=>$fechaDesde, 'hasta'=>$fechaHasta]) ?>" target="_blank" class="btn btn-primary btn-sm" style="display:none">
  👁️ Ver versión imprimible
</a>
</div>

    </main>

    <footer class="text-center py-3">&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</footer>
  </div>

<script>
document.getElementById('btn-limpiar').addEventListener('click', function () {
  document.getElementById('filtro-operador').value = '';
  document.getElementById('filtro-desde').value = '';
  document.getElementById('filtro-hasta').value = '';
  document.getElementById('filtrosForm').submit();
});
</script>

<script>
(function() {
  const btnPDF   = document.getElementById('btn-pdf');
  const btnVista = document.getElementById('btn-vista');

  // Detectar userAgent de KioWare o navegadores sin PDF embebido
  const ua = navigator.userAgent.toLowerCase();
  const isKioWare = ua.includes('kioware') || ua.includes('android') || !('application/pdf' in navigator.mimeTypes);

  // Mostrar solo el botón correspondiente
  if (isKioWare) {
    btnVista.style.display = 'inline-block';
  } else {
    btnPDF.style.display = 'inline-block';
  }
})();
</script>

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
