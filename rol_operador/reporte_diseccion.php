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

date_default_timezone_set('America/Mexico_City');
$fecha_actual = date('Y-m-d H:i:s');

// --- NUEVO BLOQUE: cargar etapas Multiplicación y Enraizamiento ---
$etapas = [];
$query = "SELECT ID_Etapa, Descripcion FROM catalogo_etapas WHERE ID_Etapa IN (2, 3)";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $etapas[] = $row;
}

// Autocompletado AJAX para variedad
if (isset($_GET['action']) && $_GET['action'] === 'buscar_variedad') {
    $term = $_GET['term'] ?? '';
    $sql = "SELECT ID_Variedad, Codigo_Variedad, Nombre_Variedad, Especie 
            FROM variedades 
            WHERE Codigo_Variedad LIKE ? OR Nombre_Variedad LIKE ? LIMIT 10";
    $stmt = $conn->prepare($sql);
    $like = "%$term%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $sugerencias = [];
    while ($row = $result->fetch_assoc()) {
        $sugerencias[] = [
            'id' => $row['ID_Variedad'],
            'especie' => $row['Especie'],
            'label' => $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad'],
            'value' => $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad']
        ];
    }
    echo json_encode($sugerencias);
    exit;
}

// Autocompletado AJAX para medio nutritivo
if (isset($_GET['action']) && $_GET['action'] === 'buscar_medio') {
    $term = $_GET['term'] ?? '';
    $especie = $_GET['especie'] ?? '';
    $etapa = $_GET['etapa'] ?? '';

$sql = "SELECT ID_MedioNutritivo, Codigo_Medio 
        FROM medios_nutritivos 
        WHERE Codigo_Medio LIKE ? 
          AND Etapa_Destinada = ? 
          AND Especie = ? 
        LIMIT 20";
$stmt = $conn->prepare($sql);
$like = "%$term%";
$stmt->bind_param("sss", $like, $etapa, $especie);
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

// Validar asignación
$sql = "SELECT * FROM asignaciones WHERE ID_Operador = ? AND Fecha = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $ID_Operador, $fecha_actual);
$stmt->execute();
$result = $stmt->get_result();
$asignacion = $result->fetch_assoc();

$ID_Asignacion = $asignacion['ID_Asignacion'] ?? null;
$ID_Variedad = $asignacion['ID_Variedad'] ?? '';
$ID_Etapa = $asignacion['ID_Etapa'] ?? '';
$reporteExistente = null;
$editable = true;

if ($asignacion) {
    // Según la etapa se guarda en Multiplicacion o Enraizamiento
    $tabla = ($ID_Etapa == 1) ? "multiplicacion" : "enraizamiento";
    $sql_check = "SELECT * FROM $tabla WHERE ID_Asignacion = ?";
    $stmt = $conn->prepare($sql_check);
    $stmt->bind_param("i", $ID_Asignacion);
    $stmt->execute();
    $result = $stmt->get_result();
    $reporteExistente = $result->fetch_assoc();

    if ($reporteExistente && $reporteExistente['Estado_Revision'] !== 'Rechazado') {
        $editable = false;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $editable) {
    $errores = [];

    $tasa = floatval($_POST["tasa_multiplicacion"]);
    $id_medio = $_POST["id_medio_nutritivo"];
    $num_brotes = $_POST["numero_brotes"];
    $tupper_lleno = $_POST["tupper_lleno"];
    $tupper_vacio = $_POST["tupper_vacios"];
    $etapa = $_POST["etapa"] ?? $ID_Etapa;
    $variedad = $_POST["id_variedad"] ?? $ID_Variedad;
    $brotes_iniciales = intval($_POST["brotes_iniciales"]);

    // Validar medio nutritivo
    $stmt = $conn->prepare("SELECT COUNT(*) FROM medios_nutritivos WHERE ID_MedioNutritivo = ?");
    $stmt->bind_param("i", $id_medio);
    $stmt->execute();
    $stmt->bind_result($medio_existe);
    $stmt->fetch();
    $stmt->close();
    if ($medio_existe == 0) {
        $errores['medio_nutritivo'] = "El medio nutritivo seleccionado no existe.";
    }

    // Validaciones de campos
    if ($brotes_iniciales < 1 || $brotes_iniciales > 1000) {
        $errores['brotes_iniciales'] = "Los brotes iniciales deben estar entre 1 y 1000.";
    }
    if ($num_brotes < 1 || $num_brotes > 1000) {
        $errores['numero_brotes'] = "Los brotes divididos deben estar entre 1 y 1000.";
    }
    if ($tupper_lleno < 1 || $tupper_lleno > 400) {
        $errores['tupper_lleno'] = "Los tuppers llenos deben estar entre 1 y 400.";
    }
    if ($tupper_vacio < 1 || $tupper_vacio > 400) {
        $errores['tupper_vacios'] = "Los tuppers vacíos deben estar entre 1 y 400.";
    }

    // Si hay errores, redirigir
    if (!empty($errores)) {
        $_SESSION['errores_diseccion'] = $errores;
        $_SESSION['form_data'] = $_POST;
        header("Location: reporte_diseccion.php");
        exit;
    }

// Insertar o actualizar según corresponda
$tabla = ($etapa == 2) ? "multiplicacion" : (($etapa == 3) ? "enraizamiento" : null);

if ($reporteExistente) {

    /* --- UPDATE (no toca movimientos) --- */
    $sql = "UPDATE $tabla 
            SET Tasa_Multiplicacion = ?, ID_MedioNutritivo = ?, Cantidad_Dividida = ?, 
                Tuppers_Llenos = ?, Tuppers_Desocupados = ?, Estado_Revision = 'Pendiente' 
            WHERE ID_Asignacion = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("diiiii",
        $tasa, $id_medio, $num_brotes,
        $tupper_lleno, $tupper_vacio, $ID_Asignacion
    );

} else {

    /* --- INSERT NUEVO LOTE ------------------------------------------------ */
    $sql = "INSERT INTO $tabla 
            (ID_Asignacion, Fecha_Siembra, ID_Variedad, Tasa_Multiplicacion, ID_MedioNutritivo, 
             Brotes_Iniciales, Cantidad_Dividida, Tuppers_Llenos, Tuppers_Desocupados, Estado_Revision, Operador_Responsable) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issdiiiiis",
        $ID_Asignacion, $fecha_actual, $variedad, $tasa, $id_medio,
        $brotes_iniciales, $num_brotes, $tupper_lleno, $tupper_vacio, $ID_Operador
    );
}

if ($stmt->execute()) {

    /* ────────────────────────────────★ alta_inicial ★──────────────────────── */
    if (!$reporteExistente) {                     // solo en INSERT, no en UPDATE
        $last_id    = $stmt->insert_id;           // ID_Multiplicacion o ID_Enraizamiento
        $etapaTexto = ($tabla === 'multiplicacion') ? 'multiplicacion' : 'enraizamiento';

        $stmtMov = $conn->prepare("
            INSERT INTO movimientos_proyeccion
                  (Fecha, Etapa, ID_Etapa, TipoMovimiento,
                   Tuppers, Brotes, ID_Operador, Comentarios)
            VALUES (NOW(), ?, ?, 'alta_inicial',
                    ?, ?, ?, 'Alta automática')
        ");
        $stmtMov->bind_param('siiii',
            $etapaTexto,
            $last_id,
            $tupper_lleno,
            $num_brotes,
            $ID_Operador
        );
        $stmtMov->execute();
        $stmtMov->close();
    }
    /* ──────────────────────────────────────────────────────────────────────── */

    /* --- Creación de lote en tabla lotes (tu código original) -------------- */
    $sql_lote = "INSERT INTO lotes (Fecha, ID_Variedad, ID_Operador, ID_Etapa)
                 VALUES (?, ?, ?, ?)";
    $stmt_lote = $conn->prepare($sql_lote);
    $stmt_lote->bind_param("siii",
        $fecha_actual, $variedad, $ID_Operador, $etapa
    );

    if ($stmt_lote->execute()) {
        $ID_Lote   = $stmt_lote->insert_id;
        $tabla_id  = ($tabla == "multiplicacion") ? "ID_Multiplicacion" : "ID_Enraizamiento";
        $last_id   = $reporteExistente ? $reporteExistente[$tabla_id] : $stmt->insert_id;

        $stmt_update = $conn->prepare("
            UPDATE $tabla SET ID_Lote = ? WHERE $tabla_id = ?
        ");
        $stmt_update->bind_param("ii", $ID_Lote, $last_id);
        $stmt_update->execute();
    }

    /* --- Marcar asignación completada -------------------------------------- */
    if ($ID_Asignacion) {
        $stmt_asig = $conn->prepare("
            UPDATE asignaciones SET Estado = 'Completado' WHERE ID_Asignacion = ?
        ");
        $stmt_asig->bind_param("i", $ID_Asignacion);
        $stmt_asig->execute();
    }

    echo "<script>alert('Registro guardado correctamente.'); window.location.href='dashboard_cultivo.php';</script>";

} else {
    echo "<script>alert('Error al guardar el registro.');</script>";
}
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reporte de Siembra</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
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
    <div class="encabezado">
      <a class="navbar-brand">
        <img src="../logoplantulas.png" width="130" height="124" alt="Logo" />
      </a>
      <h2>Reporte de Siembra</h2>
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

  <main>
  <?php
    $form_data = $_SESSION['form_data'] ?? [];
    $errores   = $_SESSION['errores_diseccion'] ?? [];
  ?>
  <form method="POST" class="form-doble-columna">
    <div class="content">
      <div class="section">
        <label>Fecha de Reporte:</label>
        <input type="text" value="<?= $fecha_actual ?>" readonly>

        <?php if (!$asignacion): ?>
          <label for="etapa">Etapa:</label>
          <select id="etapa" name="etapa" required class="<?= isset($errores['etapa']) ? 'is-invalid' : '' ?>">
            <option value="">-- Selecciona una etapa --</option>
<?php foreach ($etapas as $etapa): ?>
  <?php
    // Ajustar etiqueta mostrada al usuario
    if ($etapa['ID_Etapa'] == 2) {
        $label = 'Multiplicación – Etapa 2';
    } elseif ($etapa['ID_Etapa'] == 3) {
        $label = 'Enraizamiento – Etapa 3';
    } else {
        $label = $etapa['Descripcion']; // por si mañana añades más etapas
    }
  ?>
  <option value="<?= $etapa['ID_Etapa'] ?>" 
          <?= ($form_data['etapa'] ?? '') == $etapa['ID_Etapa'] ? 'selected' : '' ?>>
    <?= $label ?>
  </option>
<?php endforeach; ?>
          </select>

          <label for="nombre_variedad">Buscar Variedad:</label>
          <input type="text" id="nombre_variedad" required value="<?= htmlspecialchars($form_data['nombre_variedad'] ?? '') ?>">
          <input type="hidden" id="id_variedad" name="id_variedad" value="<?= htmlspecialchars($form_data['id_variedad'] ?? '') ?>">
          <input type="hidden" id="especie_variedad" value="<?= htmlspecialchars($form_data['especie_variedad'] ?? '') ?>">
        <?php endif; ?>

        <label for="tasa_multiplicacion">Tasa de Multiplicación:</label>
        <input type="number" name="tasa_multiplicacion" step="0.01"
               class="<?= isset($errores['tasa_multiplicacion']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($form_data['tasa_multiplicacion'] ?? '') ?>"
               required <?= $editable ? '' : 'readonly' ?>>

        <label for="medio_nutritivo">Medio Nutritivo:</label>
        <input type="text" id="medio_nutritivo" name="medio_nutritivo"
               class="<?= isset($errores['medio_nutritivo']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($form_data['medio_nutritivo'] ?? '') ?>"
               <?= $editable ? '' : 'readonly' ?> required>
        <input type="hidden" id="id_medio_nutritivo" name="id_medio_nutritivo"
               value="<?= htmlspecialchars($form_data['id_medio_nutritivo'] ?? '') ?>">

        <?php if (isset($errores['medio_nutritivo'])): ?>
          <div class="invalid-feedback"><?= $errores['medio_nutritivo'] ?></div>
        <?php endif; ?>

        <label for="tupper_lleno">Tuppers Llenos:</label>
        <input type="number" name="tupper_lleno" min="1" max="500"
               class="<?= isset($errores['tupper_lleno']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($form_data['tupper_lleno'] ?? '') ?>"
               required <?= $editable ? '' : 'readonly' ?>>
        <?php if (isset($errores['tupper_lleno'])): ?>
          <div class="invalid-feedback"><?= $errores['tupper_lleno'] ?></div>
        <?php endif; ?>

      <label for="numero_brotes">Número de Brotes Finales:</label>
        <input type="number" name="numero_brotes" min="1" max="1000"
               class="<?= isset($errores['numero_brotes']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($form_data['numero_brotes'] ?? '') ?>"
               required <?= $editable ? '' : 'readonly' ?>>
        <?php if (isset($errores['numero_brotes'])): ?>
          <div class="invalid-feedback"><?= $errores['numero_brotes'] ?></div>
        <?php endif; ?>

        <label for="tupper_vacios">Tuppers Vacíos:</label>
        <input type="number" name="tupper_vacios" min="1" max="500"
               class="<?= isset($errores['tupper_vacios']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($form_data['tupper_vacios'] ?? '') ?>"
               required <?= $editable ? '' : 'readonly' ?>>
        <?php if (isset($errores['tupper_vacios'])): ?>
          <div class="invalid-feedback"><?= $errores['tupper_vacios'] ?></div>
        <?php endif; ?>

        <label for="brotes_iniciales">Número de Brotes Iniciales:</label>
        <input type="number" name="brotes_iniciales" min="1" max="600"
               class="<?= isset($errores['brotes_iniciales']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($form_data['brotes_iniciales'] ?? '') ?>"
               required <?= $editable ? '' : 'readonly' ?>>
        <?php if (isset($errores['brotes_iniciales'])): ?>
          <div class="invalid-feedback"><?= $errores['brotes_iniciales'] ?></div>
        <?php endif; ?>

        <?php if ($editable): ?>
          <button type="submit" class="save-button">Guardar información</button>
        <?php else: ?>
          <p><strong>Este reporte ya fue enviado y está en revisión o aprobado.</strong></p>
        <?php endif; ?>
      </div>
    </div>
  </form>
</main>

<?php
// Limpieza final de errores después de usarlos
unset($_SESSION['errores_diseccion'], $_SESSION['form_data']);
?>

  <footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
$(function () {
  // Autocompletar variedad
  $("#nombre_variedad").autocomplete({
    source: "reporte_diseccion.php?action=buscar_variedad",
    minLength: 2,
    select: function (event, ui) {
      $("#id_variedad").val(ui.item.id);
      $("#especie_variedad").val(ui.item.especie);
      $("#medio_nutritivo").val("");
      $("#id_medio_nutritivo").val("");
    }
  });

  // Autocompletar medio nutritivo
  $("#medio_nutritivo").autocomplete({
    source: function (request, response) {
      const etapaSeleccionada = $("#etapa").val();
const etapa = etapaSeleccionada === "2" ? "Multiplicación" :
              etapaSeleccionada === "3" ? "Enraizamiento" : "";
      const especie = $("#especie_variedad").val();
      $.getJSON("reporte_diseccion.php?action=buscar_medio", {
        term: request.term,
        etapa: etapa,
        especie: especie
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

$('form').on('submit', function () {
  if (!$('#id_variedad').val()) {
    alert('❌ Por favor selecciona una variedad válida desde la lista sugerida.');
    $('#nombre_variedad').addClass('is-invalid').focus(); // ⬅️ aquí aplicamos el borde rojo
    return false;
  } else {
    $('#nombre_variedad').removeClass('is-invalid'); // Quita el borde si sí es válida
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
