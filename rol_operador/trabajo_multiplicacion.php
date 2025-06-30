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

if ((int) $_SESSION['Rol'] !== 2) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Operador.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

$ID_Operador = $_SESSION['ID_Operador'] ?? null;
$fecha_actual = (new DateTime('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');

// Buscar la asignacion activa de este operador
$sql = "SELECT * FROM asignaciones_multiplicacion 
        WHERE Operador_Asignado = ? AND Estado = 'Asignado'
        ORDER BY Fecha_Registro DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $ID_Operador);
$stmt->execute();
$result = $stmt->get_result();
$asignaciones = $result->fetch_all(MYSQLI_ASSOC);

// Buscar asignación seleccionada por GET
$asignacion = null;
if (isset($_GET['asignacion'])) {
    $idSeleccionada = intval($_GET['asignacion']);
    foreach ($asignaciones as $a) {
        if ($a['ID_Asignacion'] == $idSeleccionada) {
            $asignacion = $a;
            break;
        }
    }
}

$ID_Diseccion = $asignacion['ID_Diseccion'] ?? null;
$Codigo_Variedad = $asignacion['Codigo_Variedad'] ?? '';
$Nombre_Variedad = $asignacion['Nombre_Variedad'] ?? '';
$Brotes_Asignados = $asignacion['Brotes_Asignados'] ?? 0;
$editable = true;

// Obtener especie de la variedad
$especie = '';
if (!empty($Codigo_Variedad)) {
    $stmt_especie = $conn->prepare("SELECT Especie FROM variedades WHERE Codigo_Variedad = ? LIMIT 1");
    $stmt_especie->bind_param("s", $Codigo_Variedad);
    $stmt_especie->execute();
    $result_especie = $stmt_especie->get_result();
    if ($row = $result_especie->fetch_assoc()) {
        $especie = $row['Especie'];
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'buscar_medio') {
    $term = $_GET['term'] ?? '';
    $etapa = $_GET['etapa'] ?? '';
    $especie_busqueda = $_GET['especie'] ?? '';

    $sql = "SELECT ID_MedioNutritivo, Codigo_Medio 
            FROM medios_nutritivos 
            WHERE Codigo_Medio LIKE ? 
              AND Etapa_Destinada = ? 
              AND Especie = ? 
              AND Estado = 'Activo'
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    $like = "%$term%";
    $stmt->bind_param("sss", $like, $etapa, $especie_busqueda);
    $stmt->execute();
    $result = $stmt->get_result();

    $res = [];
    while ($row = $result->fetch_assoc()) {
        $res[] = [
            'id' => $row['ID_MedioNutritivo'],
            'label' => $row['Codigo_Medio'],
            'value' => $row['Codigo_Medio']
        ];
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["asignacion_a_trabajar"]) && isset($_POST["numero_brotes"])) {
    $id_asignacion = intval($_POST["asignacion_a_trabajar"]);
    $num_brotes = intval($_POST["numero_brotes"]);
    $tasa = floatval($_POST["tasa_multiplicacion"]);
    $id_medio = intval($_POST["id_medio_nutritivo"]);
    $tupper_lleno = intval($_POST["tupper_lleno"]);
    $tupper_vacio = intval($_POST["tupper_vacios"]);
    $brotes_iniciales = intval($_POST["brotes_iniciales"]);

    // Obtener la asignación para validar
    $stmt = $conn->prepare("SELECT * FROM asignaciones_multiplicacion WHERE ID_Asignacion = ? AND Operador_Asignado = ?");
    $stmt->bind_param("ii", $id_asignacion, $ID_Operador);
    $stmt->execute();
    $res = $stmt->get_result();
    $asignacion = $res->fetch_assoc();

    if (!$asignacion) {
        echo "<script>alert('❌ Asignación no válida.'); window.history.back();</script>";
        exit;
    }

    // Validar que el medio nutritivo existe y es válido
    $stmt_medio = $conn->prepare("SELECT COUNT(*) AS total FROM medios_nutritivos WHERE ID_MedioNutritivo = ? AND Estado = 'Activo' AND Etapa_Destinada = 'Multiplicación' AND Especie = ?");
    $stmt_medio->bind_param("is", $id_medio, $especie);
    $stmt_medio->execute();
    $res_medio = $stmt_medio->get_result();
    $row_medio = $res_medio->fetch_assoc();

    if ($row_medio['total'] == 0) {
        echo "<script>alert('❌ El medio nutritivo seleccionado no es válido para la especie {$especie} o no está activo.'); window.history.back();</script>";
        exit;
    }

    // Sumar lo trabajado
    $stmt_sum = $conn->prepare("SELECT 
        COALESCE(SUM(Brotes_Iniciales), 0) AS Total_Brotes_Trabajados,
        COALESCE(SUM(Tuppers_Desocupados), 0) AS Total_Tuppers_Trabajados
    FROM multiplicacion
    WHERE ID_Asignacion = ?");
    $stmt_sum->bind_param("i", $id_asignacion);
    $stmt_sum->execute();
    $res_sum = $stmt_sum->get_result();
    $trabajado = $res_sum->fetch_assoc();

    $brotes_restantes = max($asignacion['Brotes_Asignados'] - $trabajado['Total_Brotes_Trabajados'], 0);
    $tuppers_restantes = max($asignacion['Tuppers_Asignados'] - $trabajado['Total_Tuppers_Trabajados'], 0);

// Validación brotes generados > brotes iniciales
if ($num_brotes <= $brotes_iniciales) {
    echo "<script>alert('❌ El número de brotes generados ({$num_brotes}) debe ser mayor que el número de brotes iniciales ({$brotes_iniciales}).'); window.history.back();</script>";
    exit;
}

// Validación adicional: no permitir exceder brotes asignados
if ($num_brotes > ($brotes_restantes + $brotes_iniciales)) {
    echo "<script>alert('❌ El número de brotes generados ({$num_brotes}) excede los brotes permitidos por la asignación.'); window.history.back();</script>";
    exit;
}

    // Validación de brotes iniciales y tuppers
    if ($asignacion['Tuppers_Asignados'] == 1) {
        if ($brotes_iniciales != $asignacion['Brotes_Asignados']) {
            echo "<script>alert('❌ Asignación con 1 tupper. Debes registrar exactamente {$asignacion['Brotes_Asignados']} brotes. Tú pusiste: {$brotes_iniciales}.'); window.history.back();</script>";
            exit;
        } elseif ($tupper_vacio != 1) {
            echo "<script>alert('❌ Asignación con 1 tupper. Debes registrar 1 tupper vacío. Tú pusiste: {$tupper_vacio}.'); window.history.back();</script>";
            exit;
        }
    } else {
    if ($brotes_iniciales < 1 || $brotes_iniciales > $asignacion['Brotes_Asignados']) {
        echo "<script>alert('❌ Brotes iniciales deben estar entre 1 y {$asignacion['Brotes_Asignados']}. Tú pusiste: {$brotes_iniciales}.'); window.history.back();</script>";
        exit;
    } elseif ($tasa < 1.00 || $tasa > 50.00) {
        echo "<script>alert('❌ Tasa debe estar entre 1.00 y 50.00. Tú pusiste: {$tasa}.'); window.history.back();</script>";
        exit;
    } elseif ($tupper_lleno < 1) {
        echo "<script>alert('❌ Debes registrar al menos 1 tupper lleno. Tú pusiste: {$tupper_lleno}.'); window.history.back();</script>";
        exit;
    } elseif ($tuppers_restantes == 1 && $brotes_iniciales != $brotes_restantes) {
        echo "<script>alert('❌ Último tupper disponible: debes usar todos los brotes restantes: {$brotes_restantes}. Tú pusiste: {$brotes_iniciales}.'); window.history.back();</script>";
        exit;
    } elseif ($tuppers_restantes == 1 && $tupper_vacio != 1) {
        echo "<script>alert('❌ Último tupper: debes registrar exactamente 1 tupper vacío. Tú pusiste: {$tupper_vacio}.'); window.history.back();</script>";
        exit;
    } elseif ($tupper_vacio < 1 || $tupper_vacio > $tuppers_restantes) {
        echo "<script>alert('❌ Tuppers vacíos deben estar entre 1 y {$tuppers_restantes}. Tú pusiste: {$tupper_vacio}.'); window.history.back();</script>";
        exit;
    } elseif ($num_brotes <= $brotes_iniciales) {
        echo "<script>alert('❌ Brotes generados ({$num_brotes}) deben ser mayores que los brotes iniciales ({$brotes_iniciales}).'); window.history.back();</script>";
        exit;
    }
}
// Obtener ID_Variedad desde Código_Variedad
$id_variedad = null;
$stmt_var = $conn->prepare("SELECT ID_Variedad FROM variedades WHERE Codigo_Variedad = ? LIMIT 1");
$stmt_var->bind_param("s", $asignacion['Codigo_Variedad']);
$stmt_var->execute();
$res_var = $stmt_var->get_result();
if ($row = $res_var->fetch_assoc()) {
    $id_variedad = intval($row['ID_Variedad']);
}

if (!$id_variedad) {
    echo "<script>alert('❌ Variedad no encontrada.'); window.history.back();</script>";
    exit;
}

// 🔥 Buscar o crear el LOTE
$id_lote = null;

// 1️⃣ Verificar si ya existe un lote para esta variedad en Multiplicación (ID_Etapa = 2)
$stmt_lote = $conn->prepare("SELECT ID_Lote FROM lotes WHERE ID_Variedad = ? AND ID_Etapa = 2 LIMIT 1");
$stmt_lote->bind_param("i", $id_variedad);
$stmt_lote->execute();
$res_lote = $stmt_lote->get_result();
if ($row = $res_lote->fetch_assoc()) {
    $id_lote = $row['ID_Lote'];
} else {
    // 2️⃣ Si no existe, crear el lote
    $stmt_create_lote = $conn->prepare("INSERT INTO lotes (Fecha, ID_Variedad, ID_Operador, ID_Etapa) VALUES (?, ?, ?, 2)");
    $stmt_create_lote->bind_param("sii", $fecha_actual, $id_variedad, $ID_Operador);
    if ($stmt_create_lote->execute()) {
        $id_lote = $stmt_create_lote->insert_id;
    } else {
        echo "<script>alert('❌ Error al crear el lote.'); window.history.back();</script>";
        exit;
    }
}

// 🔥 Insertar registro en Multiplicación (ya con el ID_Lote correcto)
$sql_insert = "INSERT INTO multiplicacion 
    (ID_Variedad, ID_MedioNutritivo, Brotes_Iniciales, Cantidad_Dividida, Fecha_Siembra, 
     Tasa_Multiplicacion, Tuppers_Llenos, Tuppers_Desocupados, Operador_Responsable, 
     ID_Asignacion, Estado_Revision, ID_Lote)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente', ?)";

$stmt = $conn->prepare($sql_insert);
$stmt->bind_param("iiiisddiiii", 
    $id_variedad, 
    $id_medio,  
    $brotes_iniciales,
    $num_brotes, 
    $fecha_actual, 
    $tasa, 
    $tupper_lleno, 
    $tupper_vacio, 
    $ID_Operador, 
    $id_asignacion,
    $id_lote
);

if ($stmt->execute()) {
    // Actualizar brotes y tuppers restantes en asignaciones
    $stmt_sum = $conn->prepare("SELECT 
        COALESCE(SUM(Brotes_Iniciales), 0) AS Total_Brotes_Trabajados,
        COALESCE(SUM(Tuppers_Desocupados), 0) AS Total_Tuppers_Desocupados
    FROM multiplicacion
    WHERE ID_Asignacion = ?");
    $stmt_sum->bind_param("i", $id_asignacion);
    $stmt_sum->execute();
    $res_sum = $stmt_sum->get_result();
    $trabajado = $res_sum->fetch_assoc();

    // Calcular nuevos valores
    $nuevo_brotes = max($asignacion['Brotes_Asignados'] - $trabajado['Total_Brotes_Trabajados'], 0);
    $nuevo_tuppers = max($asignacion['Tuppers_Asignados'] - $trabajado['Total_Tuppers_Desocupados'], 0);
   
    // Actualizar el estado de la asignación
$estado = ($nuevo_brotes <= 0 && $nuevo_tuppers <= 0) ? 'Trabajado' : 'Asignado';
$stmt_update = $conn->prepare("UPDATE asignaciones_multiplicacion 
    SET Estado = ? 
    WHERE ID_Asignacion = ?");
$stmt_update->bind_param("si", $estado, $id_asignacion);
$stmt_update->execute();

    header("Location: trabajo_multiplicacion.php?success=1");
    exit;
}else {
    echo "<script>alert('❌ Error al guardar el registro.');</script>";
}

        }
  
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Trabajo en Multiplicación</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  #formulario-trabajo {
    opacity: 0;
    transform: translateY(20px);
    animation: slideFadeIn 0.6s ease forwards;
  }

  @keyframes slideFadeIn {
    to {
      opacity: 1;
      transform: translateY(0);
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
    <div class="encabezado">
      <a class="navbar-brand">
        <img src="../logoplantulas.png" width="130" height="124" alt="Logo" />
      </a>
      <h2>Trabajo en Multiplicación</h2>
    </div>

    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_cultivo.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
  </header>

  <main class="container">
<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success mt-3">✅ Registro guardado correctamente.</div>
<?php endif; ?>

<?php if (!empty($asignaciones)): ?>
<div class="container mt-4">
  <h4 class="mb-3">📦 Asignaciones pendientes</h4>
  <div class="carrousel">
<?php foreach ($asignaciones as $asignacion): ?>
  <?php
    $seleccionada = $_GET['asignacion'] ?? null;
    $id_actual = $asignacion['ID_Asignacion'];
    $class = 'card card-asignacion';
    if ($seleccionada) {
      $class .= ($seleccionada == $id_actual) ? ' selected' : ' blur';
    }
    $fecha = date('Y-m-d', strtotime($asignacion['Fecha_Registro']));
    $hora = date('H:i:s', strtotime($asignacion['Fecha_Registro']));

// Obtener avances acumulados de multiplicacion para esta asignación
$stmt_sum = $conn->prepare("SELECT 
    COALESCE(SUM(Brotes_Iniciales), 0) AS Total_Brotes_Trabajados,
    COALESCE(SUM(Tuppers_Desocupados), 0) AS Total_Tuppers_Trabajados
FROM multiplicacion
WHERE ID_Asignacion = ?");
$stmt_sum->bind_param("i", $id_actual);
$stmt_sum->execute();
$res_sum = $stmt_sum->get_result();
$trabajado = $res_sum->fetch_assoc();

// Calcular restantes
$brotes_restantes = max($asignacion['Brotes_Asignados'] - $trabajado['Total_Brotes_Trabajados'], 0);
$tuppers_restantes = max($asignacion['Tuppers_Asignados'] - $trabajado['Total_Tuppers_Trabajados'], 0);

// Ocultar tarjeta solo si ambos son 0 o menos
if ($brotes_restantes <= 0 && $tuppers_restantes <= 0) {
  continue;
}

  ?>

  <div class="<?= $class ?>" data-asignacion="<?= $id_actual ?>">
    <h3><?= htmlspecialchars($asignacion['Codigo_Variedad']) ?> – <?= htmlspecialchars($asignacion['Nombre_Variedad']) ?></h3>
    <div class="dato-tarjeta"><span class="etiqueta">Brotes asignados:</span> <span class="valor"><?= $asignacion['Brotes_Asignados'] ?></span></div>
    <div class="dato-tarjeta"><span class="etiqueta">Brotes restantes:</span> <span class="valor"><?= $brotes_restantes ?></span></div>
    <div class="dato-tarjeta"><span class="etiqueta">Tuppers asignados:</span> <span class="valor"><?= $asignacion['Tuppers_Asignados'] ?></span></div>
    <div class="dato-tarjeta"><span class="etiqueta">Tuppers restantes:</span> <span class="valor"><?= $tuppers_restantes ?></span></div>
    <div class="dato-tarjeta"><span class="etiqueta">Fecha:</span> <span class="valor"><?= $fecha ?></span></div>
    <div class="dato-tarjeta"><span class="etiqueta">Hora:</span> <span class="valor"><?= $hora ?></span></div>
    <div class="dato-tarjeta"><span class="etiqueta">Estado:</span> <span class="valor"><?= $asignacion['Estado'] ?></span></div>
  </div>
<?php endforeach; ?>


  </div>
</div>

  <?php if (isset($_GET['asignacion']) && $asignacion): ?>
  <div id="formulario-trabajo" class="container mt-5 border-top pt-4">
    <h5 class="mb-3">✍️ Registro de trabajo – <?= htmlspecialchars($asignacion['Codigo_Variedad'] . ' – ' . $asignacion['Nombre_Variedad']) ?></h5>
<form method="POST" class="formulario-bootstrap border p-4 bg-light rounded shadow-sm">
  <div class="row g-4">
    <input type="hidden" name="asignacion_a_trabajar" value="<?= $asignacion['ID_Asignacion'] ?>">

    <div class="col-md-4">
      <label class="form-label">Fecha de Reporte:</label>
      <input type="text" class="form-control" value="<?= $fecha_actual ?>" readonly>
    </div>

    <div class="col-md-4">
      <label class="form-label">Tasa de Multiplicación:</label>
      <input type="number" class="form-control" name="tasa_multiplicacion" required min="1" max="50" step="0.01">
    </div>

    <div class="col-md-4">
      <label class="form-label">Numero de brotes Iniciales:</label>
      <input type="number" class="form-control" name="brotes_iniciales" required min="1" max="<?= $asignacion['Brotes_Asignados'] ?>">
    </div>

    <div class="col-md-4">
      <label class="form-label">Número de Brotes Generados:</label>
      <input type="number" class="form-control" name="numero_brotes" required min="1" max="250">
    </div>

    <div class="col-md-6">
      <label class="form-label">Medio Nutritivo:</label>
      <input type="text" id="medio_nutritivo" class="form-control" placeholder="Selecciona el código sugerido automáticamente" required>
      <input type="hidden" id="id_medio_nutritivo" name="id_medio_nutritivo">
      <small class="text-muted">🔍 Escribe para ver los medios nutritivos recomendados para esta especie.</small>
    </div>

    <div class="col-md-3">
      <label class="form-label">Tuppers Llenos:</label>
      <input type="number" class="form-control" name="tupper_lleno" required>
    </div>

    <div class="col-md-3">
      <label class="form-label">Tuppers Vacíos:</label>
    <input type="number" class="form-control" name="tupper_vacios" required min="1" max="<?= max(1, $tuppers_restantes) ?>">
    </div>

    <div class="col-12">
      <button type="submit" class="btn btn-success w-100">✅ Guardar información</button>
    </div>
  </div>
</form>

  </div>
<?php endif; ?>

<?php else: ?>
  <div class="alert alert-warning">No tienes asignaciones pendientes de multiplicación.</div>
<?php endif; ?>
  </main>

  <footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>

<script>
  document.addEventListener("DOMContentLoaded", function () {
    const formBlock = document.getElementById("formulario-trabajo");
    if (formBlock) {
      setTimeout(() => {
        formBlock.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 200); // pequeño retraso para asegurar que el DOM esté cargado
    }

    // Redirigir al hacer clic en la tarjeta
    document.querySelectorAll(".card-asignacion").forEach(card => {
      card.addEventListener("click", () => {
        const id = card.dataset.asignacion;
        window.location.href = `trabajo_multiplicacion.php?asignacion=${id}`;
      });
    });
  });
</script>

<script>
$(function () {
  $("#medio_nutritivo").autocomplete({
    source: function (request, response) {
      $.getJSON("trabajo_multiplicacion.php?action=buscar_medio", {
        term: request.term,
        etapa: "Multiplicación",
        especie: "<?= $especie ?>"
      }, response);
    },
    minLength: 0,
    select: function (event, ui) {
      $("#id_medio_nutritivo").val(ui.item.id);
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
