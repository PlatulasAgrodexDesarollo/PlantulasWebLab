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

// 2) Autocompletado para medios ECAS
if (isset($_GET['action']) && $_GET['action'] === 'buscar_medio') {
    $term = "%".($_GET['term'] ?? '')."%";
    $stmt = $conn->prepare("
        SELECT DISTINCT Codigo_Medio
          FROM medios_nutritivos
         WHERE Codigo_Medio LIKE ?
           AND Etapa_Destinada = 'ECAS'
           AND Estado = 'Activo'
         LIMIT 10
    ");
    $stmt->bind_param("s", $term);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $out = array_map(fn($r)=>['label'=>$r['Codigo_Medio'],'value'=>$r['Codigo_Medio']], $rows);
    echo json_encode($out);
    exit;
}

// 3) Cargar siembras disponibles, calculando dinámicamente los brotes restantes
$sql = "
    SELECT
      S.ID_Siembra,
      V.Codigo_Variedad,
      V.Nombre_Variedad,
      S.Tuppers_Llenos AS Tuppers_Sembrados,
S.Tuppers_Llenos
  - IFNULL((
      SELECT SUM(D.Tuppers_Desocupados)
      FROM division_ecas D
      WHERE D.ID_Siembra = S.ID_Siembra
    ), 0) AS Tuppers_Disponibles,
      S.Cantidad_Sembrada
        - IFNULL((
            SELECT SUM(D.Cantidad_Dividida + COALESCE(D.Brotes_Contaminados, 0))
              FROM division_ecas D
             WHERE D.ID_Siembra = S.ID_Siembra
          ), 0) AS Disponibles,
      S.Cantidad_Sembrada,
      S.Fecha_Siembra,
      DE.Origen_Explantes
    FROM siembra_ecas S
    JOIN variedades V ON V.ID_Variedad = S.ID_Variedad
    JOIN desinfeccion_explantes DE ON S.ID_Desinfeccion = DE.ID_Desinfeccion
    WHERE S.ID_Desinfeccion IS NOT NULL
      AND (
        S.Cantidad_Sembrada
        - IFNULL((
            SELECT SUM(D.Cantidad_Dividida + COALESCE(D.Brotes_Contaminados, 0))
              FROM division_ecas D
             WHERE D.ID_Siembra = S.ID_Siembra
          ), 0)
      ) > 0
    ORDER BY S.Fecha_Siembra DESC
";
$siembras = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// 4) Procesar formulario de división
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_division"])) {
    date_default_timezone_set('America/Mexico_City');

    $id_siembra          = (int) $_POST["id_siembra"];
    $fecha               = date('Y-m-d H:i:s'); // ✅ Paso 1: fecha automática
    $cantidad_div        = (int) $_POST["cantidad"];
    $tuppers_llenos      = (int) $_POST["tuppers_llenos"];
    $tuppers_vacios      = (int) $_POST["tuppers_desocupados"];
    $brotes_cont         = (int) ($_POST["brotes_contaminados"] ?? 0);
    $brotes_totales      = (int) $_POST["brotes_totales"];
    $tasa                = (float) $_POST["tasa_multiplicacion"];
    $medio               = $_POST["medio"];
    $obs                 = htmlspecialchars(strip_tags(trim($_POST["observaciones"] ?? '')), ENT_QUOTES, 'UTF-8'); // ✅ Paso 9
    $gen                 = (int) $_POST["generacion"];
    $brotes_disponibles  = (int) $_POST["brotes_disponibles"];   // desde campo oculto
    $tuppers_iniciales   = (int) $_POST["tuppers_iniciales"];    // desde campo oculto

    // ✅ Paso 2: cantidad dividida no debe superar los disponibles
    if ($cantidad_div > $brotes_disponibles) {
        $mensaje = "⚠️ No puedes dividir más brotes de los disponibles.";
    }

    // ✅ Paso 3: tuppers llenos entre 1 y 100
    elseif ($tuppers_llenos < 1 || $tuppers_llenos > 100) {
        $mensaje = "⚠️ La cantidad de tuppers llenos debe estar entre 1 y 100.";
    }

    // ✅ Paso 4: tuppers desocupados entre 1 y los iniciales
    elseif ($tuppers_vacios < 1 || $tuppers_vacios > $tuppers_iniciales) {
        $mensaje = "⚠️ Los tuppers desocupados deben estar entre 1 y $tuppers_iniciales.";
    }

    // ✅ Paso 5: cantidad dividida + contaminados no mayor a disponibles
    elseif (($cantidad_div + $brotes_cont) > $brotes_disponibles) {
        $mensaje = "⚠️ La suma de brotes divididos y contaminados no puede superar los disponibles.";
    }

    // ✅ Paso 6: brotes totales entre 1 y 100
    elseif ($brotes_totales < 1 || $brotes_totales > 100) {
        $mensaje = "⚠️ El total de brotes debe estar entre 1 y 100.";
    }

    // ✅ Paso 8: Validar medio nutritivo
    else {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
              FROM medios_nutritivos
             WHERE Codigo_Medio = ?
               AND Etapa_Destinada = 'ECAS'
        ");
        $stmt->bind_param("s", $medio);
        $stmt->execute();

        if ($stmt->get_result()->fetch_assoc()['total'] === 0) {
            $mensaje = "❌ Medio nutritivo no registrado para ECAS.";
        }
    }

$sql_origen = "
    SELECT DE.Origen_Explantes
    FROM siembra_ecas S
    JOIN desinfeccion_explantes DE ON S.ID_Desinfeccion = DE.ID_Desinfeccion
    WHERE S.ID_Siembra = ?
";
$stmt_origen = $conn->prepare($sql_origen);
$stmt_origen->bind_param("i", $id_siembra);
$stmt_origen->execute();
$origen_resultado = $stmt_origen->get_result()->fetch_assoc();
$origen_explantes = strtoupper(trim($origen_resultado['Origen_Explantes'] ?? ''));


    // ✅ Si no hay errores, continuar con el insert
    if (empty($mensaje)) {
        $ins = $conn->prepare("
INSERT INTO division_ecas
  (ID_Siembra, Fecha_Division, Origen_Explantes, Cantidad_Dividida,
  Tuppers_Llenos, Tuppers_Desocupados, Tuppers_Disponibles,
  Medio_Nuevo, Observaciones, Operador_Responsable,
  Brotes_Totales, Brotes_Contaminados)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param(
 "issiiiissiii",
  $id_siembra,
  $fecha,
  $origen_explantes,
  $cantidad_div,
  $tuppers_llenos,
  $tuppers_vacios,
  $tuppers_llenos,  // Tuppers_Disponibles = tuppers llenos
  $medio,
  $obs,
  $ID_Operador,
  $brotes_totales,
  $brotes_cont
);

        if ($ins->execute()) {
          $id_div = $conn->insert_id;

          // Sumar los brotes divididos y contaminados para restarlos de los disponibles
          $total_utilizados = $cantidad_div + $brotes_cont;
    
          // Actualizar brotes disponibles correctamente
          $upd = $conn->prepare("
          UPDATE siembra_ecas
          SET Brotes_Disponibles = Brotes_Disponibles - ?
          WHERE ID_Siembra = ?
          ");
          $upd->bind_param("ii", $total_utilizados, $id_siembra);
          $upd->execute();


            // Registrar pérdidas por contaminación
            if ($brotes_cont > 0) {
                $perd = $conn->prepare("
                    INSERT INTO perdidas_laboratorio
                      (ID_Entidad, Tipo_Entidad, Fecha_Perdida,
                       Cantidad_Perdida, Motivo, Operador_Entidad, Operador_Chequeo)
                    VALUES (?, 'division_ecas', ?, ?, 'Contaminación en división', ?, ?)
                ");
                $perd->bind_param("isiii", $id_div, $fecha, $brotes_cont, $ID_Operador, $ID_Operador);
                $perd->execute();
            }

            header("Location: divisiones_ecas.php?success=1");
            exit;
        } else {
            $mensaje = "❌ Error al guardar división: " . $ins->error;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registro de Divisiones - ECAS</title>
  <link rel="stylesheet" href="../style.css?v=<?=time()?>">
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
      <a class="navbar-brand" href="#"><img src="../logoplantulas.png" width="130" height="124" alt="Logo"></a>
      <h2>Registro de Divisiones - ECAS</h2>
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
      <div class="alert alert-success">✅ División registrada correctamente.</div>
    <?php endif; ?>

    <h4>🌱 Siembras disponibles para dividir:</h4>
    <div class="carrusel-desinfecciones mb-4">
      <?php foreach ($siembras as $s): ?>
        <div class="tarjeta-desinf"
             data-id="<?= $s['ID_Siembra'] ?>"
             data-variedad="<?= htmlspecialchars("{$s['Codigo_Variedad']} - {$s['Nombre_Variedad']}") ?>"
             data-disponibles="<?= $s['Disponibles'] ?>"
             data-tuppers-iniciales="<?= $s['Tuppers_Sembrados'] ?>">
          <strong><?= htmlspecialchars("{$s['Codigo_Variedad']} - {$s['Nombre_Variedad']}") ?></strong><br>
          <strong>Origen: <?= htmlspecialchars($s['Origen_Explantes'] ?? '-') ?><br></strong>
          Brotes Sembrados: <?= $s['Cantidad_Sembrada'] ?><br>
          No. Tuppers Iniciales: <?= $s['Tuppers_Sembrados'] ?><br>
          🧬Brotes Disponibles: <?= $s['Disponibles'] ?><br>
          📦Tuppers Disponibles: <?= $s['Tuppers_Disponibles'] ?><br>
          Fecha de Siembra: <?= date("d/m/Y",strtotime($s['Fecha_Siembra'])) ?>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" id="formulario-division" class="form-doble-columna" style="display:none;">
      <input type="hidden" name="id_siembra" id="id_siembra">
      <input type="hidden" name="brotes_disponibles" id="brotes_disponibles">
      <input type="hidden" name="tuppers_iniciales" id="tuppers_iniciales">

      <p><strong>Variedad:</strong> <span id="nombre_variedad"></span></p>
      <p>Brotes Disponibles: <span id="span_disponibles"></span></p>

      <label>🔢 Brotes Iniciales:</label>
      <input type="number" name="cantidad" id="cantidad" class="form-control" min="1" required>

      <label>💥 Brotes contaminados:</label>
      <input type="number" name="brotes_contaminados" class="form-control" min="0">

      <label>🌿 Brotes Obtenidos:</label>
      <input type="number" name="brotes_totales" class="form-control" min="1" required>

      <label>📦 Tuppers llenos:</label>
      <input type="number" name="tuppers_llenos" id="tuppers_llenos" class="form-control" min="0" required>

      <label>📦 Tuppers desocupados:</label>
      <input type="number" name="tuppers_desocupados" id="tuppers_desocupados" class="form-control" min="1" required>

      <label>🧪 Medio nutritivo:</label>
      <input type="text" name="medio" id="medio_nutritivo" class="form-control" required>

      <label>📝 Observaciones:</label>
      <textarea name="observaciones" class="form-control" rows="3"></textarea>

      <button type="submit" name="guardar_division" class="btn btn-primary mt-3">Guardar División</button>
    </form>
  </main>
  <footer class="text-center mt-5">&copy; 2025 PLANTAS AGRODEX</footer>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
$(function(){
  let tuppersInit = 0;
  $(".tarjeta-desinf").click(function(){
    const $t = $(this);
    tuppersInit = +$t.data("tuppers-iniciales");
    $("#id_siembra").val($t.data("id"));
    $("#brotes_disponibles").val($t.data("disponibles"));
    $("#tuppers_iniciales").val($t.data("tuppers-iniciales"));
    $("#nombre_variedad").text($t.data("variedad"));
    $("#span_disponibles").text($t.data("disponibles"));
    $("#cantidad").val($t.data("disponibles")).attr("max", $t.data("disponibles"));
    $("#tuppers_desocupados").attr("max", tuppersInit);
    $(".tarjeta-desinf").removeClass("selected blur");
    $t.addClass("selected").siblings().addClass("blur");
    $("#formulario-division").slideDown();
    window.scrollTo({ top: $("#formulario-division").offset().top - 80, behavior:'smooth' });
  });

  $("#formulario-division").submit(function(e){
    if (+$("#tuppers_desocupados").val() > tuppersInit) {
      alert(`❌ Desocupados no pueden superar los ${tuppersInit} iniciales.`);
      e.preventDefault();
    }
  });

  $("#medio_nutritivo").autocomplete({
    source(request,response){ 
      $.getJSON("divisiones_ecas.php?action=buscar_medio",{term:request.term},response);
    },
    minLength:0
  }).focus(function(){ $(this).autocomplete("search"); });
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
