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

if ((int) $_SESSION['Rol'] !== 5) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado General de Producción.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;
$warningOffset   = 60 * 1;
$nowTs           = time();

// Autocompletado para medios ECAS
if (isset($_GET['action']) && $_GET['action'] === 'buscar_medio') {
    $term = $_GET['term'] ?? '';
    $sql = "SELECT DISTINCT Codigo_Medio FROM medios_nutritivos 
            WHERE Codigo_Medio LIKE ? AND Etapa_Destinada = 'ECAS' AND Estado = 'Activo' LIMIT 10";
    $stmt = $conn->prepare($sql);
    $like = "%$term%";
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $res = [];
    while ($row = $result->fetch_assoc()) {
        $res[] = ['id' => $row['Codigo_Medio'], 'label' => $row['Codigo_Medio'], 'value' => $row['Codigo_Medio']];
    }
    echo json_encode($res);
    exit;
}

// Obtener siembras y divisiones disponibles
$sql = "
(
  SELECT 
    S.ID_Siembra,
    NULL AS ID_Division,
    S.ID_Lote,
    S.Brotes_Disponibles,
    S.Tuppers_Disponibles,
    V.Codigo_Variedad,
    V.Nombre_Variedad,
    S.Fecha_Siembra,
    'Siembra' AS Tipo,
    COALESCE(DE.Origen_Explantes, 'Sin datos') AS Origen
  FROM siembra_ecas S
  JOIN variedades V ON V.ID_Variedad = S.ID_Variedad
  LEFT JOIN desinfeccion_explantes DE ON S.ID_Desinfeccion = DE.ID_Desinfeccion
  WHERE S.Brotes_Disponibles > 0
)
UNION ALL
(
  SELECT 
    D.ID_Siembra,
    D.ID_Division,
    NULL AS ID_Lote,
    D.Brotes_Totales AS Brotes_Disponibles,
    D.Tuppers_Disponibles,
    V.Codigo_Variedad,
    V.Nombre_Variedad,
    D.Fecha_Division AS Fecha_Siembra,
    'Division' AS Tipo,
    COALESCE(D.Origen_Explantes, 'Sin datos') AS Origen
  FROM division_ecas D
  JOIN siembra_ecas S ON S.ID_Siembra = D.ID_Siembra
  JOIN variedades V ON V.ID_Variedad = S.ID_Variedad
  WHERE D.Brotes_Totales > 0
)
ORDER BY Fecha_Siembra DESC
";

$res_siembras = $conn->query($sql);
$siembras = $res_siembras ? $res_siembras->fetch_all(MYSQLI_ASSOC) : [];

// Guardar disección
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_diseccion"])) {
    $id_siembra         = (int) $_POST["id_siembra"];
    $id_division        = $_POST["id_division"] ?: null;
    $fecha_diseccion    = date("Y-m-d H:i:s");
    $cantidad_hojas     = (int) $_POST["cantidad_hojas"];
    $medio_usado        = trim($_POST["medio_usado"]);
    $brotes_generados   = isset($_POST["brotes_explante"]) ? (int) $_POST["brotes_explante"] : 0;
    $observaciones_raw  = $_POST["observaciones"] ?? '';
    $observaciones = isset($_POST["observaciones"]) && trim($_POST["observaciones"]) !== ''
    ? htmlspecialchars(strip_tags(trim($_POST["observaciones"])), ENT_QUOTES, 'UTF-8')
    : null;
    $brotes_disponibles = $brotes_generados;
    $tuppers_llenos      = (int) $_POST["tuppers_llenos"];
    $tuppers_disponibles = $tuppers_llenos;
    $tuppers_desocupados = (int) $_POST["tuppers_desocupados"];
    $total_tuppers       = $tuppers_llenos + $tuppers_desocupados;


// Validaciones
if ($cantidad_hojas < 1 || $cantidad_hojas > $brotes_disponibles) {
    $mensaje = "❌ La cantidad de explantes diseccionadas debe ser de 1 a $brotes_disponibles.";
} elseif (isset($_POST["tuppers_disponibles"]) && (int)$_POST["tuppers_disponibles"] === 1 && $cantidad_hojas !== $brotes_disponibles) {
    $mensaje = "⚠️ Como solo queda 1 tupper disponible, debes usar los $brotes_disponibles explantes restantes.";
} elseif ($brotes_generados < 1 || $brotes_generados > 150) {
    $mensaje = "❌ Los brotes generados deben estar entre 1 y 150.";
} elseif ($tuppers_llenos < 1 || $tuppers_llenos > 300) {
    $mensaje = "❌ Los tuppers llenos deben estar entre 1 y 300.";
} elseif ($tuppers_desocupados < 1) {
    $mensaje = "❌ Los tuppers desocupados deben ser al menos 0.";
} else {
    // Validar medio nutritivo con BD
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total FROM medios_nutritivos 
        WHERE Codigo_Medio = ? AND Etapa_Destinada = 'ECAS' AND Estado = 'Activo'
    ");
    $stmt->bind_param("s", $medio_usado);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res['total'] == 0) {
        $mensaje = "❌ El código del medio nutritivo no está registrado o no es válido para ECAS.";
    }
}


    if (empty($mensaje)) {
$sql_insert = "INSERT INTO diseccion_hojas_ecas 
(ID_Siembra, ID_Lote, Origen_Explantes, Fecha_Diseccion, N_Hojas_Diseccionadas, 
 Medio_Usado, Brotes_Generados, Brotes_Disponibles, Observaciones, 
 Operador_Responsable, Tuppers_Llenos, Tuppers_Disponibles, Tuppers_Desocupados)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $id_lote_real = null;

if ($id_division) {
    // Viene de una división → buscar ID_Lote a través de ID_Siembra
    $res_lote = $conn->prepare("
        SELECT S.ID_Lote
        FROM division_ecas D
        JOIN siembra_ecas S ON S.ID_Siembra = D.ID_Siembra
        WHERE D.ID_Division = ?
    ");
    $res_lote->bind_param("i", $id_division);
    $res_lote->execute();
    $res = $res_lote->get_result()->fetch_assoc();
    if ($res) {
        $id_lote_real = $res['ID_Lote'];
    }
} else {
    // Viene de siembra directa
    $res_lote = $conn->prepare("
        SELECT ID_Lote FROM siembra_ecas WHERE ID_Siembra = ?
    ");
    $res_lote->bind_param("i", $id_siembra);
    $res_lote->execute();
    $res = $res_lote->get_result()->fetch_assoc();
    if ($res) {
        $id_lote_real = $res['ID_Lote'];
    }
}

$origen_explantes = '—';

if ($id_division) {
    // Buscar origen desde la división
    $res_origen = $conn->prepare("SELECT Origen_Explantes FROM division_ecas WHERE ID_Division = ?");
    $res_origen->bind_param("i", $id_division);
    $res_origen->execute();
    $origen = $res_origen->get_result()->fetch_assoc();
    if ($origen) {
        $origen_explantes = strtoupper(trim($origen['Origen_Explantes'] ?? '-'));
    }
} else {
    // Buscar origen desde la siembra (vía desinfección)
    $res_origen = $conn->prepare("
        SELECT DE.Origen_Explantes
        FROM siembra_ecas S
        JOIN desinfeccion_explantes DE ON S.ID_Desinfeccion = DE.ID_Desinfeccion
        WHERE S.ID_Siembra = ?
    ");
    $res_origen->bind_param("i", $id_siembra);
    $res_origen->execute();
    $origen = $res_origen->get_result()->fetch_assoc();
    if ($origen) {
$origen_explantes = strtoupper(trim($origen['Origen_Explantes'] ?? ''));
    }
}

$stmt_insert = $conn->prepare($sql_insert);
$stmt_insert->bind_param(
    "iisssisiiiiii",
    $id_siembra,
    $id_lote_real,
    $origen_explantes,
    $fecha_diseccion,
    $cantidad_hojas,
    $medio_usado,
    $brotes_generados,
    $brotes_disponibles,
    $observaciones,
    $ID_Operador,
    $tuppers_llenos,
    $tuppers_disponibles,
    $tuppers_desocupados
);

if ($stmt_insert->execute()) {
    // Actualizar brotes disponibles
    if ($id_division) {
        $update = $conn->prepare("UPDATE division_ecas SET Brotes_Totales = Brotes_Totales - ? WHERE ID_Division = ?");
        $update->bind_param("ii", $cantidad_hojas, $id_division);
    } else {
        $update = $conn->prepare("UPDATE siembra_ecas SET Brotes_Disponibles = Brotes_Disponibles - ? WHERE ID_Siembra = ?");
        $update->bind_param("ii", $cantidad_hojas, $id_siembra);
    }
    $update->execute();
// SUMAR brotes generados a los disponibles
if ($brotes_generados > 0) {
    if ($id_division) {
        $stmt = $conn->prepare("UPDATE division_ecas SET Brotes_Totales = Brotes_Totales + ? WHERE ID_Division = ?");
        $stmt->bind_param("ii", $brotes_generados, $id_division);
    } else {
        $stmt = $conn->prepare("UPDATE siembra_ecas SET Brotes_Disponibles = Brotes_Disponibles + ? WHERE ID_Siembra = ?");
        $stmt->bind_param("ii", $brotes_generados, $id_siembra);
    }
    $stmt->execute();
}

// Restar SOLO los tuppers desocupados de los disponibles, según la tabla origen
if ($id_division) {
    $stmt = $conn->prepare("UPDATE division_ecas SET Tuppers_Disponibles = Tuppers_Disponibles - ? WHERE ID_Division = ?");
    $stmt->bind_param("ii", $tuppers_desocupados, $id_division);
} else {
    $stmt = $conn->prepare("UPDATE siembra_ecas SET Tuppers_Disponibles = Tuppers_Disponibles - ? WHERE ID_Siembra = ?");
    $stmt->bind_param("ii", $tuppers_desocupados, $id_siembra);
}
$stmt->execute();

echo "<script>alert('✅ Disección registrada correctamente.'); window.location.href='dashboard_egp.php';</script>";
exit();
}

    }
  }
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Disección de Hojas - ECAS</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
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
      <a class="navbar-brand" href="#"><img src="../logoplantulas.png" alt="Logo" width="130" height="124"></a>
      <h2>Disección de Hojas - ECAS</h2>
    </div>
    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_egp.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
  </header>

  <main class="container mt-4">
    <?php if (!empty($mensaje)): ?>
      <div class="alert alert-info"> <?= $mensaje ?> </div>
    <?php endif; ?>

    <form method="POST" class="form-doble-columna">
      <div class="mb-3">
  <label for="tipo_etapa" class="form-label">Selecciona la etapa de origen:</label>
  <select id="tipo_etapa" class="form-select" required>
    <option value="">-- Selecciona etapa --</option>
    <option value="Siembra">Siembra de explantes</option>
    <option value="Division">División de brotes</option>
  </select>
</div>

<div class="mb-3" id="contenedor_siembra" style="display:none;">
  <label for="id_siembra" class="form-label">Selecciona una Fuente de Propágulos:</label>
  <select name="id_siembra" id="id_siembra" class="form-select" required>
    <option value="">-- Selecciona una opción --</option>
    <?php foreach ($siembras as $s): ?>
      <option value="<?= $s['ID_Siembra'] ?>"
              data-division="<?= $s['ID_Division'] ?>"
              data-tipo="<?= $s['Tipo'] ?>"
              data-variedad="<?= $s['Nombre_Variedad'] ?>"
              data-codigo="<?= $s['Codigo_Variedad'] ?>"
              data-fecha="<?= $s['Fecha_Siembra'] ?>"
              data-tuppers="<?= $s['Tuppers_Disponibles'] ?>"
              data-brotes="<?= $s['Brotes_Disponibles'] ?>"
              data-origen="<?= htmlspecialchars($s['Origen'] ?? '—') ?>">
        <?= "({$s['Tipo']}) {$s['Codigo_Variedad']} - {$s['Nombre_Variedad']} (Fecha: {$s['Fecha_Siembra']})" ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>

<div id="formulario_diseccion" style="display:none;">
      <input type="hidden" name="id_division" id="id_division">
      <input type="hidden" name="tuppers_disponibles" id="input_tuppers_disponibles">
      <input type="hidden" name="brotes_disponibles" id="brotes_disponibles">
      <input type="hidden" name="fecha_diseccion" value="<?= date('Y-m-d H:i:s') ?>">

      <div id="info-siembra" style="margin-bottom:20px;">
        <p><strong>📋 Información seleccionada:</strong></p>
        <p><strong>Fecha:</strong> <span id="fecha_siembra"></span></p>
        <p><strong>Variedad:</strong> <span id="variedad_siembra"></span></p>
        <p><strong>Código:</strong> <span id="codigo_variedad"></span></p>
        <p><strong>Origen de explantes:</strong> <span id="origen_siembra">—</span></p>
        <p><strong>Tuppers Disponibles:</strong> <span id="tuppers_disponibles">—</span></p>
        <p><strong>Explantes Disponibles:</strong> <span id="brotes_siembra"></span></p>
      </div>

      <div class="mb-3">
        <label for="cantidad_hojas" class="form-label">Cantidad de Explantes Diseccionadas:</label>
        <input type="number" name="cantidad_hojas" id="cantidad_hojas" class="form-control" min="1" required>
      </div>

      <div class="mb-3">
        <label for="brotes_explante" class="form-label">Brotes generados:</label>
        <input type="number" name="brotes_explante" id="brotes_explante" class="form-control" min="0">
      </div>

<div class="mb-3">
  <label for="tuppers_llenos" class="form-label">Tuppers llenos:</label>
  <input type="number" name="tuppers_llenos" id="tuppers_llenos" class="form-control" min="1" max="300" required>
</div>

<div class="mb-3">
  <label for="tuppers_desocupados" class="form-label">Tuppers desocupados:</label>
  <input type="number" name="tuppers_desocupados" id="tuppers_desocupados" class="form-control" min="1" required>
</div>

      <div class="mb-3">
        <label for="medio_usado" class="form-label">Código del Nuevo Medio Nutritivo:</label>
        <input type="text" name="medio_usado" id="medio_usado" class="form-control" required>
      </div>

      <div class="mb-3">
        <label for="observaciones" class="form-label">Observaciones:</label>
        <textarea name="observaciones" id="observaciones" class="form-control" rows="3"></textarea>
      </div>

      <button type="submit" name="guardar_diseccion" class="btn btn-primary">Guardar Disección</button>
    </div>
    </form>
  </main>

  <footer class="text-center mt-5">
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
document.getElementById('tipo_etapa')?.addEventListener('change', function () {
  const tipo = this.value;
  const siembraSelect = document.getElementById('id_siembra');
  const opciones = siembraSelect.options;
  document.getElementById('contenedor_siembra').style.display = tipo ? 'block' : 'none';

  for (let i = 0; i < opciones.length; i++) {
    const opt = opciones[i];
    if (!opt.value) continue;
    opt.style.display = (opt.getAttribute('data-tipo') === tipo) ? 'block' : 'none';
  }

  siembraSelect.selectedIndex = 0;
  document.getElementById('info-siembra').style.display = 'none';
});

document.getElementById('id_siembra')?.addEventListener('change', function () {
  const opt = this.options[this.selectedIndex];
  const tieneValor = opt && opt.value;

  // Mostrar info
  document.getElementById('fecha_siembra').innerText     = opt.getAttribute('data-fecha') || '';
  document.getElementById('variedad_siembra').innerText  = opt.getAttribute('data-variedad') || '';
  document.getElementById('codigo_variedad').innerText   = opt.getAttribute('data-codigo') || '';
  document.getElementById('brotes_siembra').innerText    = opt.getAttribute('data-brotes') || '0';
  document.getElementById('origen_siembra').innerText    = opt.getAttribute('data-origen') || '—';

  document.getElementById('id_division').value            = opt.getAttribute('data-division') || '';
  document.getElementById('input_tuppers_disponibles').value = opt.getAttribute('data-tuppers') || '0';
  document.getElementById('tuppers_disponibles').innerText    = opt.getAttribute('data-tuppers') || '—';
  document.getElementById('brotes_disponibles').value         = opt.getAttribute('data-brotes') || '0';

  document.getElementById('info-siembra').style.display = 'block';

  // Mostrar formulario solo si hay una opción seleccionada
  document.getElementById('formulario_diseccion').style.display = tieneValor ? 'block' : 'none';
});

$(function () {
  $("#medio_usado").autocomplete({
    source: function (request, response) {
      $.getJSON("diseccion_hojas_ecas.php?action=buscar_medio", { term: request.term }, response);
    },
    minLength: 0,
    select: function (event, ui) {
      $("#medio_usado").val(ui.item.value);
    }
  }).focus(function () {
    $(this).autocomplete("search", "");
  });
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
