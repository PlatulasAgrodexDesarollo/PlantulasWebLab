<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Mexico_City');

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

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
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

$mensaje = '';

// Autocompletado para medios ECAS únicos
if (isset($_GET['action']) && $_GET['action'] === 'buscar_medio') {
    $term = $_GET['term'] ?? '';
    $sql = "SELECT DISTINCT Codigo_Medio 
            FROM medios_nutritivos 
            WHERE Codigo_Medio LIKE ? 
              AND Etapa_Destinada = 'ECAS' 
              AND Estado = 'Activo' 
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    $like = "%{$term}%";
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();
    $res = [];
    while ($row = $result->fetch_assoc()) {
        $res[] = ['label' => $row['Codigo_Medio'], 'value' => $row['Codigo_Medio']];
    }
    echo json_encode($res);
    exit;
}

// Obtener desinfecciones con explantes aún disponibles
$sql_des = "
    SELECT 
        D.ID_Desinfeccion,
        D.ID_Variedad,
        D.FechaHr_Desinfeccion,
            D.Origen_Explantes,
        D.Explantes_Desinfectados,
        V.Codigo_Variedad,
        V.Nombre_Variedad,
        COALESCE(SUM(S.Cantidad_Sembrada), 0) AS Cantidad_Sembrada_Total
    FROM desinfeccion_explantes D
    JOIN variedades V ON D.ID_Variedad = V.ID_Variedad
    LEFT JOIN siembra_ecas S ON D.ID_Desinfeccion = S.ID_Desinfeccion
    WHERE D.Operador_Responsable = ?
      AND D.Estado_Desinfeccion = 'Completado'
    GROUP BY D.ID_Desinfeccion
    HAVING D.Explantes_Desinfectados > COALESCE(SUM(S.Cantidad_Sembrada), 0)
    ORDER BY D.FechaHr_Desinfeccion DESC
";
$stmt_des = $conn->prepare($sql_des);
$stmt_des->bind_param("i", $ID_Operador);
$stmt_des->execute();
$desinfecciones = $stmt_des->get_result()->fetch_all(MYSQLI_ASSOC);

// Manejo del formulario de siembra
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_siembra"])) {
    $id_desinfeccion = (int)$_POST["id_desinfeccion"];
    $id_variedad     = (int)$_POST["id_variedad"];
    $fecha_siembra   = date('Y-m-d H:i:s'); // Fecha automática con zona horaria establecida arriba
    $medio           = $_POST["medio"];
    $cantidad        = (int)$_POST["cantidad"];
    $tuppers_llenos  = (int)$_POST["tuppers_llenos"];
    $tuppers_disponibles = $tuppers_llenos;
$observaciones = isset($_POST["observaciones"]) ? trim($_POST["observaciones"]) : '';
if ($observaciones === '0' || $observaciones === 0) {
    $observaciones = '';
}
$observaciones = htmlspecialchars(strip_tags($observaciones), ENT_QUOTES, 'UTF-8');


//Validación para que no se inserten brotes menores a 1
if ($cantidad < 1) {
    $mensaje = "⚠️ La cantidad de brotes a sembrar debe ser mayor a 1.";
}

    // 1. Validación: tuppers no deben exceder a cantidad disponible
    if ($tuppers_llenos > $cantidad) {
        $mensaje = "❌ Los tuppers llenos no pueden exceder la cantidad de explantes.";
    }

    // 2. Validación: tuppers deben estar entre 1 y 60
    elseif ($tuppers_llenos < 1 || $tuppers_llenos > 100) {
        $mensaje = "⚠️ El número de tuppers debe estar entre 1 y 100.";
    }

    // 3. Validación: existencia del medio nutritivo
    else {
        $ver = $conn->prepare("
            SELECT COUNT(*) AS total 
            FROM medios_nutritivos 
            WHERE Codigo_Medio = ? 
              AND Etapa_Destinada = 'ECAS'
        ");
        $ver->bind_param("s", $medio);
        $ver->execute();
        $res = $ver->get_result()->fetch_assoc();

        if ($res['total'] == 0) {
            $mensaje = "❌ El código del medio nutritivo no está registrado para ECAS.";
        }
    }

    // 4. Validación: no sembrar más de lo disponible
    if (empty($mensaje)) {
        $sql_disp = "SELECT D.Explantes_Desinfectados - COALESCE(SUM(S.Cantidad_Sembrada),0) AS disponibles
                     FROM desinfeccion_explantes D
                     LEFT JOIN siembra_ecas S ON D.ID_Desinfeccion = S.ID_Desinfeccion
                     WHERE D.ID_Desinfeccion = ?";
        $stmt_disp = $conn->prepare($sql_disp);
        $stmt_disp->bind_param("i", $id_desinfeccion);
        $stmt_disp->execute();
        $row_disp = $stmt_disp->get_result()->fetch_assoc();
        $disponibles = (int)$row_disp['disponibles'];

        if ($cantidad > $disponibles) {
            $mensaje = "⚠️ No puedes sembrar más de los $disponibles explantes disponibles.";
        }
    }

    // 5. Si todo va bien: buscar o crear lote
    if (empty($mensaje)) {
        $sql_buscar_lote = "
            SELECT ID_Lote 
            FROM lotes 
            WHERE Fecha = ? 
              AND ID_Variedad = ? 
              AND ID_Operador = ? 
              AND ID_Etapa = 1
        ";
        $stmt_buscar_lote = $conn->prepare($sql_buscar_lote);
        $stmt_buscar_lote->bind_param("sii", $fecha_siembra, $id_variedad, $ID_Operador);
        $stmt_buscar_lote->execute();
        $row = $stmt_buscar_lote->get_result()->fetch_assoc();

        if ($row) {
          var_dump($row);
            $id_lote = $row['ID_Lote'];
        } else {
            $sql_lote = "
                INSERT INTO lotes (Fecha, ID_Variedad, ID_Operador, ID_Etapa)
                VALUES (?, ?, ?, 1)
            ";
            $stmt_lote = $conn->prepare($sql_lote);
            $stmt_lote->bind_param("sii", $fecha_siembra, $id_variedad, $ID_Operador);
            if ($stmt_lote->execute()) {
                $id_lote = $conn->insert_id;
            } else {
                $mensaje = "❌ Error al crear el lote.";
            }
        }
    }

// 6. Registrar la siembra
if (empty($mensaje)) {
    $brotes_disponibles = $cantidad;

$sql_origen = "SELECT Origen_Explantes FROM desinfeccion_explantes WHERE ID_Desinfeccion = ?";
$stmt_origen = $conn->prepare($sql_origen);
$stmt_origen->bind_param("i", $id_desinfeccion);
$stmt_origen->execute();
$result_origen = $stmt_origen->get_result()->fetch_assoc();
$origen_explantes = strtoupper(trim($result_origen['Origen_Explantes'] ?? ''));

$sql_siembra = "
    INSERT INTO siembra_ecas
    (ID_Desinfeccion, ID_Variedad, Fecha_Siembra, Origen_Explantes, Medio_Nutritivo,
    Cantidad_Sembrada, Tuppers_Llenos, Tuppers_Disponibles, Brotes_Disponibles, Observaciones,
    Operador_Responsable, ID_Lote)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

    $stmt_siembra = $conn->prepare($sql_siembra);
    $stmt_siembra->bind_param(
   "iissiiiissii",
    $id_desinfeccion,
    $id_variedad,
    $fecha_siembra,
    $origen_explantes,
    $medio,
    $cantidad,
    $tuppers_llenos,
    $tuppers_disponibles, 
    $brotes_disponibles,
    $observaciones,
    $ID_Operador,
    $id_lote
);

    if ($stmt_siembra->execute()) {
        header("Location: registro_siembra_ecas.php?success=1");
        exit();
    } else {
        $mensaje = "❌ Error al registrar la siembra: " . $stmt_siembra->error;
    }
}
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Registro de Siembra Inicial - ECAS</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css" />
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
      <a class="navbar-brand" href="#"><img src="../logoplantulas.png" alt="Logo" width="130" height="124" /></a>
      <h2>Registro de Siembra Inicial - ECAS</h2>
      <div></div>
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
    <?php if ($mensaje): ?>
      <div class="alert alert-warning"><?= $mensaje ?></div>
    <?php elseif (isset($_GET['success'])): ?>
      <div class="alert alert-success">✅ Siembra registrada correctamente.</div>
    <?php endif; ?>

    <h4>🧼 Desinfecciones con explantes disponibles:</h4>
    <div class="carrusel-desinfecciones mb-4">
      <?php foreach ($desinfecciones as $d):
          $disponibles = $d['Explantes_Desinfectados'] - $d['Cantidad_Sembrada_Total'];
      ?>
        <div class="tarjeta-desinf" 
             data-id="<?= $d['ID_Desinfeccion'] ?>" 
             data-variedad-id="<?= $d['ID_Variedad'] ?>"
             data-variedad-nombre="<?= htmlspecialchars($d['Codigo_Variedad'] . ' - ' . $d['Nombre_Variedad']) ?>"
             data-explantes="<?= $disponibles ?>">
          <strong><?= htmlspecialchars($d['Codigo_Variedad'] . ' - ' . $d['Nombre_Variedad']) ?></strong><br>
          <strong>Origen: <?= htmlspecialchars($d['Origen_Explantes'] ?? '-') ?><br></strong>
          Explantes Disponibles: <?= $disponibles ?><br>
          Fecha de desinfección:
          <?php 
            if (!empty($d['FechaHr_Desinfeccion'])) {
                echo date("d/m/Y", strtotime($d['FechaHr_Desinfeccion']));
            } else {
                echo '—';
            }
          ?>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" id="formulario-siembra" class="form-doble-columna" style="display:none;">
      <input type="hidden" name="id_variedad" id="id_variedad">
      <input type="hidden" name="id_desinfeccion" id="id_desinfeccion">

      <label>Variedad: <strong><span id="nombre_variedad"></span></strong></label>
      <label for="medio">🧪 Código del medio nutritivo:</label>
      <input type="text" id="medio_nutritivo" name="medio" class="form-control" required>

      <label for="cantidad">🔢 Explantes a sembrar:</label>
      <input type="number" name="cantidad" class="form-control" min="1" required>

      <label for="tuppers_llenos">📦 Tuppers llenos:</label>
      <input type="number" name="tuppers_llenos" class="form-control" min="1" max="100" required>

      <label for="observaciones">📝 Observaciones:</label>
      <textarea name="observaciones" class="form-control" rows="3"></textarea>

      <button type="submit" name="guardar_siembra" class="btn btn-primary mt-3">Guardar Registro</button>
    </form>
  </main>

  <footer class="text-center mt-5">
    © 2025 PLANTAS AGRODEX. Todos los derechos reservados.
  </footer>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
$(function(){
  let explantesDisponibles = 0;

  $("#medio_nutritivo").autocomplete({
    source(request, response) {
      $.getJSON("registro_siembra_ecas.php?action=buscar_medio", { term: request.term }, response);
    },
    minLength: 0
  }).focus(function(){
    $(this).autocomplete("search", "");
  });

  $(".tarjeta-desinf").click(function(){
    explantesDisponibles = +$(this).data("explantes");
    $("#formulario-siembra")[0].reset();
    $(".tarjeta-desinf").removeClass("selected blur");
    $(this).addClass("selected").siblings().addClass("blur");

    $("#id_desinfeccion").val($(this).data("id"));
    $("#id_variedad").val($(this).data("variedad-id"));
    $("#nombre_variedad").text($(this).data("variedad-nombre"));

    $("input[name='cantidad']").attr("max", explantesDisponibles);

    $("#formulario-siembra").slideDown("fast");
    window.scrollTo({ top: $("#formulario-siembra").offset().top - 100, behavior: 'smooth' });
  });

  $("#formulario-siembra").submit(function(e){
    const medio    = $("#medio_nutritivo").val().trim();
    const sembrados = +$("input[name='cantidad']").val();
    const llenos    = +$("input[name='tuppers_llenos']").val();

    if (!medio) {
      alert("⚠️ Debes ingresar un medio nutritivo.");
      e.preventDefault(); return;
    }
    if (sembrados > explantesDisponibles) {
      alert(`❌ Solo hay ${explantesDisponibles} explantes disponibles.`);
      e.preventDefault(); return;
    }
    if (llenos > sembrados) {
      alert("❌ Los tuppers llenos no pueden exceder la cantidad de explantes.");
      e.preventDefault(); return;
    }
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