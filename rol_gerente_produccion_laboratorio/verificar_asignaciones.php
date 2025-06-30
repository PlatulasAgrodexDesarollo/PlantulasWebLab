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

if ((int) $_SESSION['Rol'] !== 6) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Gerente de Producción de Laboratorio.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Eliminar asignación
if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    $id_eliminar = intval($_GET['eliminar']);
    $delete = $conn->prepare("DELETE FROM asignacion_lavado WHERE ID = ?");
    $delete->bind_param("i", $id_eliminar);
    $delete->execute();
    echo "<script>alert('✅ Asignación eliminada correctamente.'); window.location.href='verificar_asignaciones.php';</script>";
    exit();
}

// Filtros
$operador = $_GET['operador'] ?? '';
$variedad = $_GET['variedad'] ?? '';
$estado = $_GET['estado'] ?? '';
$fecha = $_GET['fecha'] ?? '';

// Consulta de asignaciones
$query = "
    SELECT 
        a.ID,
        a.Fecha,
        o.Nombre AS Operador,
        v.Nombre_Variedad AS Variedad,
        a.Rol,
        a.Cantidad_Tuppers,
        a.Estado_Final
    FROM asignacion_lavado a
    JOIN operadores o ON a.ID_Operador = o.ID_Operador
    JOIN variedades v ON a.ID_Variedad = v.ID_Variedad
    WHERE 1=1
";

$params = [];
$types = '';

if (!empty($operador)) {
    $query .= " AND o.Nombre LIKE ?";
    $params[] = "%$operador%";
    $types .= 's';
}

if (!empty($variedad)) {
    $query .= " AND v.Nombre_Variedad LIKE ?";
    $params[] = "%$variedad%";
    $types .= 's';
}

if (!empty($estado)) {
    if ($estado == 'Sin Cierre') {
        $query .= " AND (a.Estado_Final IS NULL OR a.Estado_Final = '')";
    } else {
        $query .= " AND a.Estado_Final = ?";
        $params[] = $estado;
        $types .= 's';
    }
}

if (!empty($fecha)) {
    $query .= " AND a.Fecha = ?";
    $params[] = $fecha;
    $types .= 's';
}

$query .= " ORDER BY a.Fecha DESC, o.Nombre ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$resultado = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verificación de Asignaciones</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    #filtros {
      display: none;
      margin-bottom: 20px;
      padding: 20px;
      border: 1px solid #ccc;
      border-radius: 10px;
      background-color: #f8f9fa;
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
    <a class="navbar-brand me-3" href="dashboard_gpl.php">
      <img src="../logoplantulas.png" width="130" height="124" alt="Logo">
    </a>
    <h2 class="mb-0">📋 Verificación de Asignaciones de Lavado</h2>
  </div>

  <div class="barra-navegacion">
    <nav class="navbar bg-body-tertiary">
      <div class="container-fluid">
        <div class="Opciones-barra">
          <button onclick="window.location.href='dashboard_gpl.php'">
            🏠 Volver al Inicio
          </button>
        </div>
      </div>
    </nav>
  </div>

<nav class="filter-toolbar d-flex flex-wrap align-items-center gap-2 px-3 py-2 mb-3" style="overflow-x:auto;">
  <div class="d-flex flex-column" style="min-width:140px;">
    <label for="filtro-operador" class="small mb-1">Operador</label>
    <input id="filtro-operador" type="text" name="operador" form="filtrosForm"
           class="form-control form-control-sm"
           placeholder="Operador…" value="<?= htmlspecialchars($operador) ?>">
  </div>

  <div class="d-flex flex-column" style="min-width:140px;">
    <label for="filtro-variedad" class="small mb-1">Variedad</label>
    <input id="filtro-variedad" type="text" name="variedad" form="filtrosForm"
           class="form-control form-control-sm"
           placeholder="Variedad…" value="<?= htmlspecialchars($variedad) ?>">
  </div>

  <div class="d-flex flex-column" style="min-width:140px;">
    <label for="filtro-estado" class="small mb-1">Estado</label>
    <select id="filtro-estado" name="estado" form="filtrosForm" class="form-select form-select-sm">
      <option value="">— Todos —</option>
      <option value="Completada" <?= $estado==='Completada' ? 'selected':''?>>✅ Completada</option>
      <option value="Incompleta" <?= $estado==='Incompleta' ? 'selected':''?>>⚠️ Incompleta</option>
      <option value="Sin Cierre" <?= $estado==='Sin Cierre' ? 'selected':''?>>⏳ Sin Cierre</option>
    </select>
  </div>

  <div class="d-flex flex-column" style="min-width:140px;">
    <label for="filtro-fecha" class="small mb-1">Fecha</label>
    <input id="filtro-fecha" type="date" name="fecha" form="filtrosForm"
           class="form-control form-control-sm"
           value="<?= htmlspecialchars($fecha) ?>">
  </div>

  <button form="filtrosForm" type="submit" class="btn-inicio btn btn-success btn-sm ms-auto">Filtrar</button>
  <button type="button" onclick="limpiarFiltros()" class="btn btn-limpiar btn-sm ms-2">Limpiar filtros</button>
</nav>
<form id="filtrosForm" method="GET" class="d-none"></form>

</header>

<main class="container mt-4">
  <div class="table-responsive">
    <table class="table table-bordered table-hover table-striped align-middle text-nowrap">
            <thead class="table-dark">
                <tr>
                    <th>Fecha</th>
                    <th>Operador</th>
                    <th>Variedad</th>
                    <th>Rol</th>
                    <th>Cantidad de Tuppers</th>
                    <th>Estado Final</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($fila = $resultado->fetch_assoc()) : ?>
                    <tr>
  <td data-label="Fecha"><?= htmlspecialchars($fila['Fecha']) ?></td>
  <td data-label="Operador"><?= htmlspecialchars($fila['Operador']) ?></td>
  <td data-label="Variedad"><?= htmlspecialchars($fila['Variedad']) ?></td>
  <td data-label="Rol"><?= htmlspecialchars($fila['Rol']) ?></td>
  <td data-label="Cantidad de Tuppers"><?= htmlspecialchars($fila['Cantidad_Tuppers']) ?></td>
  <td data-label="Estado Final">
    <?php 
      if ($fila['Estado_Final'] === 'Completada') {
          echo '✅ Completada';
      } elseif ($fila['Estado_Final'] === 'Incompleta') {
          echo '⚠️ Incompleta';
      } else {
          echo '⏳ Sin Cierre';
      }
    ?>
  </td>
  <td data-label="Acciones">
    <a href="verificar_asignaciones.php?eliminar=<?= $fila['ID'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Seguro que deseas eliminar esta asignación?')">🗑️ Eliminar</a>
  </td>
</tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </main>

    <footer class="text-center mt-4 mb-3">
        <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
</div>

<script>
function limpiarFiltros() {
  document.getElementById('filtro-operador').value = '';
  document.getElementById('filtro-variedad').value = '';
  document.getElementById('filtro-estado').selectedIndex = 0;
  document.getElementById('filtro-fecha').value = '';
  document.getElementById('filtrosForm').submit();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function mostrarFiltros() {
    var filtros = document.getElementById('filtros');
    filtros.style.display = (filtros.style.display === 'none') ? 'block' : 'none';
}
</script>

<!-- Modal de advertencia de sesión + Ping por interacción que reinicia timers -->
<script>
(function(){
  let modalShown = false,
      warningTimer,
      expireTimer;

  function showModal() {
    modalShown = true;
    const modalHtml = `
      <div id="session-warning" class="modal-overlay">
        <div class="modal-box">
          <p>Tu sesión va a expirar pronto. ¿Deseas mantenerla activa?</p>
          <button id="keepalive-btn" class="btn-keepalive">Seguir activo</button>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    document.getElementById('keepalive-btn').addEventListener('click', () => {
      cerrarModalYReiniciar(); // 🔥 Aquí aplicamos el cambio
    });
  }

  function cerrarModalYReiniciar() {
    // 🔥 Cerrar modal inmediatamente
    const modal = document.getElementById('session-warning');
    if (modal) modal.remove();
    reiniciarTimers(); // Reinicia el temporizador visual

    // 🔄 Enviar ping a la base de datos en segundo plano
    fetch('../keepalive.php', { credentials: 'same-origin' })
      .then(res => res.json())
      .then(data => {
        if (data.status !== 'OK') {
          alert('No se pudo extender la sesión');
        }
      })
      .catch(() => {}); // Silenciar errores de red
  }

  function reiniciarTimers() {
    START_TS   = Date.now();
    modalShown = false;
    clearTimeout(warningTimer);
    clearTimeout(expireTimer);
    scheduleTimers();
  }

  function scheduleTimers() {
    const elapsed     = Date.now() - START_TS;
    const warnAfter   = SESSION_LIFETIME - WARNING_OFFSET;
    const expireAfter = SESSION_LIFETIME;

    warningTimer = setTimeout(showModal, Math.max(warnAfter - elapsed, 0));

    expireTimer = setTimeout(() => {
      if (!modalShown) {
        showModal();
      } else {
        window.location.href = '/plantulas/login.php?mensaje='
          + encodeURIComponent('Sesión caducada por inactividad');
      }
    }, Math.max(expireAfter - elapsed, 0));
  }

  ['click', 'keydown'].forEach(event => {
    document.addEventListener(event, () => {
      reiniciarTimers();
      fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {});
    });
  });

  scheduleTimers();
})();
</script>

</body>
</html>
