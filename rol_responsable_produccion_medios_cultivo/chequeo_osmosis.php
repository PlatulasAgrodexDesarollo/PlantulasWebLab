<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 7) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Responsable de Producción de Medios de Cultivo.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id_operador = $_SESSION['ID_Operador'];

if (isset($_POST['guardar_diario'])) {
    // 1) Validar y sanitizar Litros y PPM
    $litros = floatval($_POST['litros']);
    $ppm    = floatval($_POST['ppm']);
    $observaciones_raw = $_POST['observaciones_diario'];
 $observaciones      = htmlspecialchars(strip_tags(trim($observaciones_raw)), ENT_QUOTES, 'UTF-8');
    if ($litros < 1 || $litros > 100) {
        $mensaje = "❌ Litros debe estar entre 1 y 100.";
    }
    elseif ($ppm < 1 || $ppm > 100) {
        $mensaje = "❌ PPM debe estar entre 1 y 100.";
    }
    else {
        // 2) Limitar y limpiar Observaciones
        $obs_raw   = $_POST['observaciones_diario'];
        $obs_trim  = trim($obs_raw);
        $obs_lim   = substr($obs_trim, 0, 255);
        $obs_clean = strip_tags($obs_lim);

        // 3) Inserción segura con prepared statement
        $stmt = $conn->prepare("
            INSERT INTO osmosis_chequeo_diario
              (FechaHora, Litros_Tratados, PPM, Observaciones, ID_Operador)
            VALUES
              (NOW(), ?, ?, ?, ?)
        ");
        $stmt->bind_param("ddsi",
            $litros,
            $ppm,
            $obs_clean,
            $id_operador
        );

        $mensaje = $stmt->execute()
            ? "✅ Registro diario guardado correctamente."
            : "❌ Error al guardar el registro diario.";
    }
}
}

if (isset($_POST['guardar_retrolavado'])) {
  // 1) Rango de Sal y Nivel
  $sal   = floatval($_POST['sal']);
  $nivel = floatval($_POST['nivel']);
  $observaciones_raw = $_POST['observaciones_diario'];
   $observaciones      = htmlspecialchars(strip_tags(trim($observaciones_raw)), ENT_QUOTES, 'UTF-8');
  if ($sal < 1 || $sal > 100) {
    $mensaje = "❌ Sal debe estar entre 1 y 100 kg.";
  }
  elseif ($nivel < 1 || $nivel > 100) {
    $mensaje = "❌ Nivel de agua debe estar entre 1 y 100%.";
  }
  else {
    // 2) Limpieza de Observaciones
    $obs_raw   = $_POST['observaciones_retrolavado'];
    $obs_trim  = trim($obs_raw);
    $obs_lim   = substr($obs_trim, 0, 255);
    $obs_clean = strip_tags($obs_lim);

    // 3) Inserción segura
    $stmt = $conn->prepare("
      INSERT INTO osmosis_retrolavado
      (FechaHora, Sal_Utilizada_Kg, Nivel_Agua_Porc, Observaciones, ID_Operador)
      VALUES (NOW(), ?, ?, ?, ?)
    ");
    $stmt->bind_param("ddsi", $sal, $nivel, $obs_clean, $id_operador);
    $mensaje = $stmt->execute()
      ? "✅ Retrolavado registrado correctamente."
      : "❌ Error al guardar el retrolavado.";
  }
}


if (isset($_POST['guardar_mantenimiento'])) {
  // 1) Sanitizar campos
  $empresa      = trim($_POST['empresa']);
  $lavados      = isset($_POST['filtros_lavados'])    ? 1 : 0;
  $reemplazados = isset($_POST['filtros_reemplazados']) ? 1 : 0;
  $observaciones_raw = $_POST['observaciones_diario'];
   $observaciones      = htmlspecialchars(strip_tags(trim($observaciones_raw)), ENT_QUOTES, 'UTF-8');

  // 2) Limpieza de Observaciones
  $obs_raw   = $_POST['observaciones_mantenimiento'];
  $obs_trim  = trim($obs_raw);
  $obs_lim   = substr($obs_trim, 0, 255);
  $obs_clean = strip_tags($obs_lim);

  // 3) Inserción segura
  $stmt = $conn->prepare("
    INSERT INTO osmosis_mantenimiento
    (FechaHora, Empresa_Responsable, Filtros_Lavados, Filtros_Reemplazados, Observaciones, ID_Operador)
    VALUES (NOW(), ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param("sbbsi", $empresa, $lavados, $reemplazados, $obs_clean, $id_operador);
  $mensaje = $stmt->execute()
    ? "✅ Mantenimiento registrado correctamente."
    : "❌ Error al guardar el mantenimiento.";
}


function mostrarTabla($query, $encabezados, $campos) {
  global $conn;
  $result = $conn->query($query);

  if ($result->num_rows > 0) {
    echo "<div class='table-responsive'><table class='table table-bordered table-striped'>";
    echo "<thead><tr>";
    foreach ($encabezados as $encabezado) {
      echo "<th>{$encabezado}</th>";
    }
    echo "</tr></thead><tbody>";

    while ($row = $result->fetch_assoc()) {
      echo "<tr>";
      foreach ($campos as $campo) {
        echo "<td>" . (!empty($row[$campo]) ? htmlspecialchars($row[$campo]) : 'Sin información') . "</td>";
      }
      echo "</tr>";
    }

    echo "</tbody></table></div>";
  } else {
    echo "<p class='text-muted'>No hay registros disponibles.</p>";
  }
}

$tipo_historial = $_GET['tipo'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Chequeo de Ósmosis Inversa</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
<div class="contenedor-pagina">
  <header>
    <div class="encabezado">
      <a class="navbar-brand" href="#">
        <img src="../logoplantulas.png" alt="Logo" width="130" height="124" class="d-inline-block align-text-center" />
      </a>
      <div>
        <h2>Chequeo de Ósmosis Inversa</h2>
        <p>Registro de producción diaria, retrolavado y mantenimiento del sistema.</p>
      </div>
    </div>

    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_rpmc.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
  </header>

  <main class="container py-4">
    <?php if (!empty($mensaje)): ?>
      <div class="alert alert-info text-center" role="alert">
        <?= $mensaje ?>
      </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4" id="osmosisTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="diario-tab" data-bs-toggle="tab" data-bs-target="#diario" type="button" role="tab">Chequeo Diario</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="retrolavado-tab" data-bs-toggle="tab" data-bs-target="#retrolavado" type="button" role="tab">Retrolavado</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="mantenimiento-tab" data-bs-toggle="tab" data-bs-target="#mantenimiento" type="button" role="tab">Mantenimiento</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="historial-tab" data-bs-toggle="tab" data-bs-target="#historial" type="button" role="tab">Historial</button>
      </li>
    </ul>

    <div class="tab-content" id="osmosisTabContent">
      <div class="tab-pane fade show active" id="diario" role="tabpanel">
        <form method="POST" class="row g-3">

          <div class="col-md-6">
            <label for="litros" class="form-label">Litros Tratados</label>
            <input type="number" class="form-control" id="litros" name="litros" min="1" max="100" required>
          </div>
          <div class="col-md-6">
            <label for="ppm" class="form-label">PPM</label>
            <input type="number" class="form-control" id="ppm" name="ppm" min="1" max="100" required>
          </div>
          <div class="col-12">
            <label for="observaciones_diario" class="form-label">Observaciones</label>
            <textarea class="form-control" id="observaciones_diario" name="observaciones_diario" rows="2" maxlength="255"></textarea>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="guardar_diario" class="btn btn-success">Guardar Registro Diario</button>
          </div>

        </form>
      </div>

      <div class="tab-pane fade" id="retrolavado" role="tabpanel">
        <form method="POST" class="row g-3">
          <div class="col-md-6">
            <label for="sal" class="form-label">Sal Utilizada (kg)</label>
            <input type="number" step="0.1" class="form-control" id="sal" name="sal" min="0.1" max="100" required>
          </div>
          <div class="col-md-6">
            <label for="nivel" class="form-label">Nivel de Agua Estimado (%)</label>
            <input type="number" min="1" max="100" class="form-control" id="nivel" name="nivel" required>
          </div>
          <div class="col-12">
            <label for="observaciones_retrolavado" class="form-label">Observaciones</label>
            <textarea class="form-control" id="observaciones_retrolavado" name="observaciones_retrolavado" rows="2" maxlength="255"></textarea>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="guardar_retrolavado" class="btn btn-success">Guardar Retrolavado</button>
          </div>
        </form>
      </div>

      <div class="tab-pane fade" id="mantenimiento" role="tabpanel">
        <form method="POST" class="row g-3">
          <div class="col-md-6">
            <label for="empresa" class="form-label">Empresa Responsable</label>
            <input type="text" class="form-control" id="empresa" name="empresa">
          </div>
          <div class="col-md-6">
            <label class="form-label d-block">Filtros</label>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" id="lavados" name="filtros_lavados">
              <label class="form-check-label" for="lavados">Lavados</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" id="reemplazados" name="filtros_reemplazados">
              <label class="form-check-label" for="reemplazados">Reemplazados</label>
            </div>
          </div>
          <div class="col-12">
            <label for="observaciones_mantenimiento" class="form-label">Observaciones</label>
            <textarea class="form-control" id="observaciones_mantenimiento" name="observaciones_mantenimiento" rows="2" maxlength="255"></textarea>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="guardar_mantenimiento" class="btn btn-success">Guardar Mantenimiento</button>
          </div>
        </form>
      </div>

      <div class="tab-pane fade" id="historial" role="tabpanel">
        <form method="GET" class="mb-3">
        <div class="row g-3 mb-3">
  <div class="col-md-8">
    <select id="selectorHistorial" class="form-select">
      <option disabled selected>-- Selecciona un historial --</option>
      <option value="diario" <?= ($tipo_historial === 'diario') ? 'selected' : '' ?>>Chequeo Diario</option>
      <option value="retrolavado" <?= ($tipo_historial === 'retrolavado') ? 'selected' : '' ?>>Retrolavado</option>
      <option value="mantenimiento" <?= ($tipo_historial === 'mantenimiento') ? 'selected' : '' ?>>Mantenimiento</option>
    </select>
  </div>
</div>


        <?php
        if ($tipo_historial === 'diario') {
          mostrarTabla("SELECT FechaHora, Litros_Tratados, PPM, Observaciones FROM osmosis_chequeo_diario ORDER BY FechaHora DESC", ['Fecha y Hora', 'Litros Tratados', 'PPM', 'Observaciones'], ['FechaHora', 'Litros_Tratados', 'PPM', 'Observaciones']);
        } elseif ($tipo_historial === 'retrolavado') {
          mostrarTabla("SELECT FechaHora, Sal_Utilizada_Kg, Nivel_Agua_Porc, Observaciones FROM osmosis_retrolavado ORDER BY FechaHora DESC", ['Fecha y Hora', 'Sal (kg)', 'Nivel de Agua (%)', 'Observaciones'], ['FechaHora', 'Sal_Utilizada_Kg', 'Nivel_Agua_Porc', 'Observaciones']);
        } elseif ($tipo_historial === 'mantenimiento') {
          mostrarTabla("SELECT FechaHora, Empresa_Responsable, Filtros_Lavados, Filtros_Reemplazados, Observaciones FROM osmosis_mantenimiento ORDER BY FechaHora DESC", ['Fecha y Hora', 'Empresa', 'Lavados', 'Reemplazados', 'Observaciones'], ['FechaHora', 'Empresa_Responsable', 'Filtros_Lavados', 'Filtros_Reemplazados', 'Observaciones']);
        }
        ?>
      </div>
    </div>
  </main>

  <footer class="text-center p-3">
    &copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    const select = document.getElementById("selectorHistorial");
    if (select) {
      select.addEventListener("change", () => {
        const tipo = select.value;
        const url = new URL(window.location.href);
        url.searchParams.set("tipo", tipo);
        history.replaceState(null, "", url.toString());
        location.reload();
      });
    }

    // Activar pestaña de historial si viene con ?tipo=
    const tipo = new URLSearchParams(window.location.search).get("tipo");
    if (tipo) {
      const historialTab = new bootstrap.Tab(document.querySelector('#historial-tab'));
      historialTab.show();
    }
  });
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