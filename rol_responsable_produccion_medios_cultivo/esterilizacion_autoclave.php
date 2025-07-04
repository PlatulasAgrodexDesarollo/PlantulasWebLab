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
$sessionLifetime = 60 * 3;
$warningOffset   = 60 * 1;
$nowTs           = time();

$mensaje = $_GET['mensaje'] ?? '';
// Cargar el proceso seleccionado si se solicitó finalizar
$procesoSeleccionado = null;
if (isset($_GET['abrir_modal_finalizar'])) {
    $idSeleccionado = (int) $_GET['abrir_modal_finalizar'];

    $stmt = $conn->prepare("
        SELECT * FROM esterilizacion_autoclave 
        WHERE ID_Esterilizacion = ? AND Estado = 'En proceso' 
        LIMIT 1
    ");
    $stmt->bind_param("i", $idSeleccionado);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows === 1) {
        $procesoSeleccionado = $resultado->fetch_assoc();
    }
}

// AJAX: lista de tuppers contaminados
if (isset($_POST['ajax_contaminados'])) {
    if (ob_get_level()) ob_end_clean();

    $stmt = $conn->prepare("
      SELECT 
        ID_Perdida,
        Tipo_Entidad,
        Fecha_Perdida,
        COALESCE(Tuppers_Perdidos,0)  AS Tuppers_Perdidos,
        COALESCE(Motivo,'—')          AS Motivo
      FROM perdidas_laboratorio
      WHERE COALESCE(Tuppers_Perdidos,0) > 0
      ORDER BY Fecha_Perdida DESC
    ");
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
      $out[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

// AJAX: Info de dilución seleccionada
if (isset($_POST['ajax_dilucion_info'])) {
    if (ob_get_level()) ob_end_clean();
    $id = $_POST['ajax_dilucion_info'];

    $stmt = $conn->prepare("
        SELECT 
            d.ID_Dilucion,
            mn.Codigo_Medio                     AS Medio,
            d.Tuppers_Llenos                    AS Tuppers_Llenos,
            COALESCE(SUM(ea.Tuppers_Esterilizados),0) AS Usados
        FROM dilucion_llenado_tuppers d
        LEFT JOIN medios_nutritivos_madre mn ON d.ID_MedioNM = mn.ID_MedioNM
        LEFT JOIN esterilizacion_autoclave ea ON d.ID_Dilucion = ea.ID_Dilucion
        WHERE d.ID_Dilucion = ?
        GROUP BY d.ID_Dilucion
    ");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    if ($res) {
        $res['Disponibles'] = max(0, $res['Tuppers_Llenos'] - $res['Usados']);
    } else {
        $res = [
            'Medio' => '—',
            'Tuppers_Llenos' => 0,
            'Usados' => 0,
            'Disponibles' => 0
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// Consulta de total de juegos con estado 'Pendiente'
$totalJuegosPendientes = 0;
$consultaJuegos = $conn->query("
  SELECT COUNT(*) AS total
  FROM juegos_materiales
  WHERE estado_juego = 'Pendiente'
");
if ($consultaJuegos && $fila = $consultaJuegos->fetch_assoc()) {
    $totalJuegosPendientes = $fila['total'];
}

// Juegos esterilizados esta semana agrupados por día
$juegosPorDia = $conn->query("
    SELECT DATE(fecha_esterilizacion) AS fecha, COUNT(*) AS total
    FROM registro_esterilizacion_juego
    WHERE YEARWEEK(fecha_esterilizacion, 1) = YEARWEEK(CURDATE(), 1)
    GROUP BY DATE(fecha_esterilizacion)
    ORDER BY fecha ASC
");


// Modal de finalización
if (isset($_GET['abrir_modal_finalizar'])) {
    $paquete = (int)$_GET['abrir_modal_finalizar'];
    $stmt = $conn->prepare("
        SELECT 
          ID_Esterilizacion,
          id_paquete, 
          FechaInicio, 
          Tipo_Articulo, 
          ID_Operador
        FROM esterilizacion_autoclave
        WHERE id_paquete = ?
        ORDER BY ID_Esterilizacion DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $paquete);
    $stmt->execute();
    $procesoSeleccionado = $stmt->get_result()->fetch_assoc();
}


// Procesar inicio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['iniciar_esterilizacion'])) {
    $self  = basename(__FILE__);
    $id_op = $_SESSION['ID_Operador'];
$presion_inicio = isset($_POST['presion_inicio']) ? floatval($_POST['presion_inicio']) : null;

// 0.5) Si seleccionó un registro contaminado, leer ID y cantidad
$id_perdida_cont = intval($_POST['id_perdida'] ?? 0);
$tupCont        = intval($_POST['tuppers_contaminados'][$id_perdida_cont] ?? 0);

// calcular vacíos y tapas SOLO si marcó el checkbox esterilizar_vacios
if (!empty($_POST['esterilizar_vacios'])) {
    $tupVac = intval($_POST['tuppers_vacios'] ?? 0);
    // calculamos tapas
    switch ($_POST['con_tapas_vacios'] ?? '') {
        case 'mismo':
            $tapVac = $tupVac;
            break;
        case 'otro':
            $tapVac = intval($_POST['tapas_vacios_otras'] ?? 0);
            break;
        default:
            $tapVac = 0;
    }
    // Con tapa solo si no es "ninguno"
    $con_tapa = ($_POST['con_tapas_vacios'] ?? '') !== 'ninguno' ? 1 : 0;
} else {
    $tupVac = 0;
    $tapVac = 0;
    $con_tapa = 0;
}

// 0) Si hay algún elemento a esterilizar, crear el paquete
$tiene_periodico = !empty($_POST['periodico_toggle']) && intval($_POST['paquetes_periodico'] ?? 0) > 0;

if (
    $tupVac > 0 || 
    (!empty($_POST['id_dilucion'])) || 
    (!empty($_POST['contaminados_toggle'])) || 
    (!empty($_POST['juegos_toggle'])) || 
    $tiene_periodico
) {
    // Crear un nuevo paquete
    $stmtP = $conn->prepare("
        INSERT INTO paquete_esterilizacion (operador_id)
        VALUES (?)
    ");
    $stmtP->bind_param("i", $id_op);
    $stmtP->execute();
    $id_paquete = $conn->insert_id;

    // 2) Movimientos para tuppers vacíos y tapas
    if ($tupVac > 0) {
        $mv = $conn->prepare("
            INSERT INTO movimientos_materiales
              (tipo_movimiento, cantidad, id_operador_asignado, id_encargado)
            VALUES
              ('esterilizacion', ?, ?, ?)
        ");
        $mv->bind_param("iii", $tupVac, $id_op, $id_op);
        $mv->execute();
    }
    if ($tapVac > 0) {
        $mv = $conn->prepare("
            INSERT INTO movimientos_materiales
              (tipo_movimiento, cantidad, id_operador_asignado, id_encargado)
            VALUES
              ('esterilizacion', ?, ?, ?)
        ");
        $mv->bind_param("iii", $tapVac, $id_op, $id_op);
        $mv->execute();
    }

if ($tupVac > 0 && isset($_POST['vacíos_toggle']) && empty($_POST['id_dilucion']) && empty($_POST['contaminados_toggle']) && empty($_POST['juegos_toggle'])) {
$insertVacios = $conn->prepare("
    INSERT INTO esterilizacion_autoclave
      (id_paquete, Tipo_Articulo, FechaInicio, Estado,
       ID_Operador, Tuppers_Esterilizados, Con_Tapa, Tuppers_Vacios, Tapas_Vacias, cantidad_juegos, Presion_Inicial)
    VALUES (?, 'Tuppers vacíos', NOW(), 'En proceso',
            ?, 0, ?, ?, ?, 0, ?)
");
$insertVacios->bind_param("iiiiid", $id_paquete, $id_op, $con_tapa, $tupVac, $tapVac, $presion_inicio);
    $insertVacios->execute();
}

// 3.5) Si marcó “esterilizar contaminados”, insertar ese proceso y descontar en la tabla de pérdidas
if (!empty($_POST['contaminados_toggle'])) {
    // Solo si hay ID de pérdida y cantidad válida
    if ($id_perdida_cont > 0 && $tupCont > 0) {
        // 3.5.a) Inserta el proceso de tuppers contaminados
$insCont = $conn->prepare("
    INSERT INTO esterilizacion_autoclave
      (id_paquete, ID_Dilucion, Tipo_Articulo, FechaInicio, Estado,
       ID_Operador, Tuppers_Esterilizados, Con_Tapa, Tuppers_Vacios, Tapas_Vacias,
       ID_Perdida, cantidad_juegos, Presion_Inicial)
    VALUES
      (?, NULL, 'Tuppers contaminados', NOW(), 'En proceso',
       ?, ?, 0, 0, 0, ?, 0, ?)
");
$insCont->bind_param("iiiid", $id_paquete, $id_op, $tupCont, $id_perdida_cont, $presion_inicio);
        $insCont->execute();

        // 3.5.b) Descontar esos tuppers de la tabla de pérdidas
        $upd = $conn->prepare("
            UPDATE perdidas_laboratorio
               SET Tuppers_Perdidos = GREATEST(Tuppers_Perdidos - ?, 0)
             WHERE ID_Perdida = ?
        ");
        $upd->bind_param("ii", $tupCont, $id_perdida_cont);
        $upd->execute();
    }
}

  // 4) Insertar un proceso por cada lote, incluyendo id_paquete, vacíos y tapas
if (!empty($_POST['id_dilucion'])) {
    foreach ($_POST['id_dilucion'] as $idx => $id_dil) {
        $tuppers = intval($_POST['tuppers_esterilizados'][$idx] ?? 0);

        // validación de existencias
        $chk = $conn->prepare("
            SELECT d.Tuppers_Llenos,
                   COALESCE(SUM(ea.Tuppers_Esterilizados),0) AS usados
            FROM dilucion_llenado_tuppers d
            LEFT JOIN esterilizacion_autoclave ea 
              ON d.ID_Dilucion = ea.ID_Dilucion
            WHERE d.ID_Dilucion = ?
            GROUP BY d.ID_Dilucion
        ");
        $chk->bind_param("s", $id_dil);
        $chk->execute();
        $d = $chk->get_result()->fetch_assoc();
        $restL = $d ? ($d['Tuppers_Llenos'] - $d['usados']) : 0;
$tipoArticulo = 'Tuppers con medio'; // o lo que quieras mostrar en esa fila

        if ($tuppers > 0 && $tuppers <= $restL) {
$ins = $conn->prepare("
  INSERT INTO esterilizacion_autoclave
    (id_paquete, ID_Dilucion, Tipo_Articulo, FechaInicio, Estado,
     ID_Operador, Tuppers_Esterilizados, Con_Tapa, Tuppers_Vacios, Tapas_Vacias, cantidad_juegos, Presion_Inicial)
  VALUES
    (?, ?, ?, NOW(), 'En proceso', ?, ?, ?, ?, ?, 0, ?)
");
$ins->bind_param("sssiiiiid", $id_paquete, $id_dil, $tipoArticulo, $id_op, $tuppers, $con_tapa, $tupVac, $tapVac, $presion_inicio);
            $ins->execute();
        }
    }
}

// 5) Si seleccionó juegos, insertar y actualizar estado
if (!empty($_POST['juegos_toggle']) && isset($_POST['juegos_esterilizados'])) {
    $juegosAUsar = max(0, (int) $_POST['juegos_esterilizados']);
    
if ($juegosAUsar > 0 && $juegosAUsar <= $totalJuegosPendientes) {
    // a) Obtener los ID de los juegos más antiguos pendientes
    $obtenerJuegos = $conn->prepare("
        SELECT id_juego
        FROM juegos_materiales
        WHERE estado_juego = 'Pendiente'
        ORDER BY fecha_registro ASC
        LIMIT ?
    ");
    $obtenerJuegos->bind_param("i", $juegosAUsar);
    $obtenerJuegos->execute();
    $res = $obtenerJuegos->get_result();

    // b) Insertar en registro_esterilizacion_juego y guardar los IDs
    $ids = [];
    $insertJuego = $conn->prepare("
        INSERT INTO registro_esterilizacion_juego
          (id_juego, fecha_esterilizacion, id_operador_esteriliza, notas)
        VALUES (?, NOW(), ?, NULL)
    ");

    while ($row = $res->fetch_assoc()) {
        $id_juego = (int)$row['id_juego'];
        $ids[] = $id_juego;
        $insertJuego->bind_param("ii", $id_juego, $ID_Operador);
        $insertJuego->execute();
    }

    $insertJuego->close();

    // c) Marcar como esterilizados los juegos seleccionados
    if (!empty($ids)) {
        $in = implode(',', $ids); // lista segura
        $conn->query("
            UPDATE juegos_materiales
               SET estado_juego = 'Esterilizado',
                   fecha_esterilizacion = NOW()
             WHERE id_juego IN ($in)
        ");
    }

    // d) Registrar proceso en tabla esterilizacion_autoclave
    $stmtEA = $conn->prepare("
        INSERT INTO esterilizacion_autoclave
          (id_paquete, Tipo_Articulo, FechaInicio, Estado,
           ID_Operador, cantidad_juegos, Presion_Inicial)
        VALUES (?, 'Juego de herramientas', NOW(), 'En proceso', ?, ?, ?)
    ");
    $stmtEA->bind_param("isid", $id_paquete, $id_op, $juegosAUsar, $presion_inicio);
    $stmtEA->execute();
}
}

// 6) Si seleccionó paquetes de periódico, registrar
if (!empty($_POST['periodico_toggle']) && isset($_POST['paquetes_periodico'])) {
    $paquetes = max(0, (int) $_POST['paquetes_periodico']);
    if ($paquetes > 0) {
$stmtEA = $conn->prepare("
  INSERT INTO esterilizacion_autoclave
    (id_paquete, Tipo_Articulo, FechaInicio, Estado,
     ID_Operador, cantidad_periodico, Presion_Inicial)
  VALUES (?, 'Periódico', NOW(), 'En proceso', ?, ?, ?)
");
$stmtEA->bind_param("iiid", $id_paquete, $id_op, $paquetes, $presion_inicio);
        $stmtEA->execute();
    }
}

    header("Location: $self?mensaje=" . urlencode("✅ Procesos iniciados."));
    exit;
}
}
// Procesar finalizar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_esterilizacion'])) {
    $self = basename(__FILE__);
    $id_paquete = intval($_POST['id_paquete'] ?? 0);
    $resultado = trim($_POST['resultado'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $presion_final = isset($_POST['presion_final']) && $_POST['presion_final'] !== ''
        ? floatval($_POST['presion_final'])
        : null;

if ($id_paquete && $resultado !== '') {

    // Validación del resultado
    if ($resultado === '') {
        die("❌ Debes seleccionar un resultado.");
    }

    // Validación de presión final
    if ($presion_final === null || $presion_final < 1 || $presion_final > 30) {
        die("❌ La presión final debe estar entre 1 y 30 psi.");
    }

    // 1. Actualizar todos los registros del paquete
    $stmtUpdate = $conn->prepare("
        UPDATE esterilizacion_autoclave
           SET FechaFin      = NOW(),
               Resultado     = ?,
               Observaciones = ?,
               Estado        = 'Finalizado',
               Presion_Final = ?
         WHERE id_paquete   = ?
    ");
    $stmtUpdate->bind_param("ssdi", $resultado, $observaciones, $presion_final, $id_paquete);

if (!$stmtUpdate->execute()) {
    die("❌ Error al guardar el proceso: " . $stmtUpdate->error);
}

// 2) Si hubo pérdidas parciales, insertarlas (una sola vez)
if ($resultado === 'Con pérdida parcial') {

    $articulos  = $_POST['articulo_perdido'] ?? [];
    $cantidades = $_POST['cantidad_perdida'] ?? [];
    $motivos    = $_POST['motivo_perdida']   ?? [];

    /* 1. Obtenemos UN id_esterilizacion del paquete */
    $res = $conn->prepare("
        SELECT ID_Esterilizacion
        FROM esterilizacion_autoclave
        WHERE id_paquete = ?
        ORDER BY ID_Esterilizacion DESC   -- usa ASC si prefieres el primero
        LIMIT 1
    ");
    $res->bind_param("i", $id_paquete);
    $res->execute();
    $row = $res->get_result()->fetch_assoc();

    if ($row) {
        $idEsterilizacion = (int)$row['ID_Esterilizacion'];

        /* 2. Insertamos cada pérdida solo una vez */
        $stmtPerdida = $conn->prepare("
            INSERT INTO perdidas_autoclave
                  (ID_Esterilizacion, Articulo, Cantidad, Motivo)
            VALUES (?, ?, ?, ?)
        ");

        for ($i = 0; $i < count($articulos); $i++) {

            $articulo = trim($articulos[$i]);
            $cantidad = (int)$cantidades[$i];
            $motivo   = trim($motivos[$i]);

            if ($articulo !== '' && $cantidad > 0) {
                $stmtPerdida->bind_param(
                    "isis",
                    $idEsterilizacion,
                    $articulo,
                    $cantidad,
                    $motivo
                );
                $stmtPerdida->execute();
            }
        }
    }
}
}
}

$self = basename(__FILE__);
$id_paquete = intval($_POST['id_paquete'] ?? 0);
$resultado = trim($_POST['resultado'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');
$presion_final = isset($_POST['presion_final']) && $_POST['presion_final'] !== ''
    ? floatval($_POST['presion_final'])
    : null;

// “En proceso”
$procesos = $conn->query("
  SELECT
    e.id_paquete                            AS ID,
    MIN(e.presion_inicial)                 AS PresionInicial,
    GROUP_CONCAT(COALESCE(e.ID_Dilucion,'—') SEPARATOR ', ') AS Lotes,
    MIN(e.FechaInicio)                      AS FechaInicio,
    (
      SELECT GROUP_CONCAT(
        CASE 
          WHEN Tipo_Articulo = 'Juego de herramientas' THEN CONCAT('Juegos: ', cantidad_juegos)
          WHEN Tipo_Articulo = 'Periódico' THEN CONCAT('Periódico: ', cantidad_periodico)
          ELSE Tipo_Articulo
        END
        SEPARATOR ' | '
      )
      FROM esterilizacion_autoclave
      WHERE id_paquete = e.id_paquete
    ) AS Articulos,
    SUM(CASE WHEN e.ID_Perdida IS NOT NULL THEN e.Tuppers_Esterilizados ELSE 0 END) AS TotalContaminados,
    SUM(e.Tuppers_Esterilizados)            AS TotalTuppers,
    SUM(e.Tuppers_Vacios)                   AS TotalVacios,
    SUM(e.Tapas_Vacias)                     AS TotalTapas
  FROM esterilizacion_autoclave e
  WHERE e.Estado = 'En proceso'
  GROUP BY e.id_paquete
  ORDER BY FechaInicio
")->fetch_all(MYSQLI_ASSOC);

// “Finalizadas”
$finalizados = $conn->query("
  SELECT
    e.id_paquete                            AS ID,
    MIN(e.presion_inicial)                 AS PresionInicial,
    GROUP_CONCAT(e.ID_Dilucion SEPARATOR ', ') AS Lotes,
    MIN(e.FechaInicio)                      AS FechaInicio,
    MAX(e.FechaFin)                         AS FechaFin,
    (
      SELECT GROUP_CONCAT(DISTINCT 
        CASE 
          WHEN Tipo_Articulo = 'Juego de herramientas' THEN CONCAT('Juegos: ', cantidad_juegos)
          ELSE Tipo_Articulo
        END SEPARATOR ' | ')
      FROM esterilizacion_autoclave
      WHERE id_paquete = e.id_paquete
    ) AS Articulos,
    SUM(e.Tuppers_Esterilizados)            AS TotalTuppers,
    SUM(e.Tuppers_Vacios)                   AS TotalVacios,
    SUM(e.Tapas_Vacias)                     AS TotalTapas,
    MAX(e.Resultado)                        AS Resultado,
    MAX(e.Observaciones)                    AS Observaciones
  FROM esterilizacion_autoclave e
  WHERE e.Estado = 'Finalizado'
  AND DATE(e.FechaFin) = CURDATE()
  GROUP BY e.id_paquete
  ORDER BY FechaFin DESC
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Esterilización en Autoclave</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .seccion-interna { display:none; }
    .seccion-activa  { display:block; }
    .form-check-input { width:1.5em; height:1.5em; }
    .cantidad-art { display:none; width:80px; }
    .custom-checkbox:checked { background-color:#0d6efd33; border-color:#0d6efd; }
  </style>
  <script>
    const SESSION_LIFETIME=<?= $sessionLifetime*1000 ?>;
    const WARNING_OFFSET  =<?= $warningOffset*1000   ?>;
    let START_TS         =<?= $nowTs*1000           ?>;
  </script>
</head>
<body>
<div class="contenedor-pagina">
  <header>
    <div class="encabezado d-flex align-items-center">
      <a class="navbar-brand me-3" href="#"><img src="../logoplantulas.png" width="130" height="124"></a>
      <div>
        <h2>Esterilización en Autoclave</h2>
        <p>Inicio, seguimiento y cierre de procesos.</p>
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
    <nav class="navbar navbar-expand bg-white border-bottom mb-3">
      <div class="container-fluid justify-content-center">
        <button class="btn btn-outline-primary me-2" onclick="mostrarSeccion('inicio')">➕ Iniciar</button>
        <button class="btn btn-outline-warning me-2" onclick="mostrarSeccion('proceso')">🧪 En proceso</button>
        <button class="btn btn-outline-secondary" onclick="mostrarSeccion('historial')">📜 Finalizadas</button>
      </div>
    </nav>
    <?php if($mensaje): ?><div class="alert alert-info text-center"><?=htmlspecialchars($mensaje)?></div><?php endif; ?>
  </header>
  
<main class="container py-4">

  <!-- INICIO -->
  <div id="inicio" class="seccion-interna <?= $procesoSeleccionado ? '' : 'seccion-activa' ?>">
    <div class="card mb-4">
      <div class="card-header bg-primary text-white">Iniciar nueva esterilización</div>
      <div class="card-body">
        <form method="POST" class="row g-3">
        
 <!-- Esterilizar tuppers vacíos -->
<div class="card mb-4">
  <div class="card-header">
    <h5 class="mb-0">¿Desea esterilizar tuppers vacíos?</h5>
  </div>
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="form-check">
      <!-- 1) name y value para el checkbox -->
      <input class="form-check-input" 
             type="checkbox" 
             id="vacios_toggle"
             name="esterilizar_vacios"
             value="1">
    </div>
    <label class="form-check-label" for="vacios_toggle">Sí</label>

    <div class="vacios-options d-none d-flex align-items-center gap-2">
      <label class="mb-0">¿Cuántos tuppers?</label>
      <!-- 2) deshabilitado por defecto -->
      <input type="number" 
             id="num_vacios" 
             name="tuppers_vacios"
             class="form-control" 
             min="1" value="1" 
             style="width:80px;"
             disabled>

      <label class="mb-0">¿Con tapas?</label>
      <select id="vacios_tapas" 
              name="con_tapas_vacios" 
              class="form-select" 
              style="width:150px;">
        <option value="">Seleccione una opción</option>
        <option value="mismo">Mismo número</option>
        <option value="ninguno">Sin tapas</option>
        <option value="otro">Otro</option>
      </select>

      <div id="otro_tapas_group" class="d-none align-items-center gap-2">
        <label class="mb-0"># tapas</label>
        <input type="number" 
               id="otro_tapas" 
               name="tapas_vacios_otras"
               class="form-control" 
               min="1" 
               style="width:80px;">
      </div>
    </div>
  </div>
</div>

<!-- Esterilizar tuppers contaminados -->
<div class="card mb-4">
  <div class="card-header">
    <h5 class="mb-0">¿Desea esterilizar tuppers contaminados?</h5>
  </div>
  <div class="card-body d-flex align-items-center gap-3">
    <div class="form-check">
      <input class="form-check-input" 
             type="checkbox" 
             id="contaminados_toggle"
             name="contaminados_toggle" 
             value="1">
    </div>
    <label class="form-check-label" for="contaminados_toggle">Sí</label>
  </div>

  <div id="contaminados_list" class="card-body table-responsive d-none">
    <table class="table table-bordered">
      <thead>
        <tr>
          <th>ID</th>
          <th>Tipo</th>
          <th>Fecha</th>
          <th>Tuppers Disponibles</th>
          <th>Motivo</th>
          <th>Cantidad de tuppers a esterilizar</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>
    <!-- ¿Cuántos lotes? -->
<div id="lotes_section" class="card mb-4">
  <div class="card-header">
    <h5 class="mb-0">¿Cuántas tandas?</h5>
  </div>
  <div class="card-body d-flex align-items-center gap-2">
    <input type="number" id="num_lotes" class="form-control" min="1" max="7" value="" placeholder="0" style="width:80px;">
    <button type="button" id="confirm_lotes" class="btn btn-outline-secondary">Confirmar</button>
  </div>
  <div id="lotes_container" class="card-body pt-0"></div>
</div>

          <!-- Sección Juegos-->
<div class="text-center my-4">
  <div class="border rounded p-3 bg-light shadow-sm d-inline-block">
    <h5 class="mb-2">Juegos disponibles para esterilización</h5>
    <p class="fs-5 mb-0">📦 Total: <strong><?= $totalJuegosPendientes ?></strong></p>
  </div>
</div>

<!-- Esterilizar juegos -->
<div class="card mb-4">
  <div class="card-header">
    <h5 class="mb-0">¿Desea esterilizar juegos?</h5>
  </div>
  <div class="card-body d-flex align-items-center gap-3">
    <div class="form-check">
      <input class="form-check-input" 
             type="checkbox" 
             id="juegos_toggle"
             name="juegos_toggle" 
             value="1">
    </div>
    <label class="form-check-label" for="juegos_toggle">Sí</label>
  </div>

  <div id="juegos_input_group" class="card-body d-none">
    <label class="form-label">¿Cuántos juegos desea esterilizar?</label>
    <input type="number" 
           name="juegos_esterilizados" 
           id="juegos_esterilizados"
           class="form-control"
           min="1" 
           max="<?= $totalJuegosPendientes ?>" 
           placeholder="Máximo: <?= $totalJuegosPendientes ?>">
  </div>
</div>

<!-- Esterilizar paquetes de periódico -->
<div class="card mb-4">
  <div class="card-header">
    <h5 class="mb-0">¿Desea esterilizar paquetes de periódico?</h5>
  </div>
  <div class="card-body d-flex align-items-center gap-3">
    <div class="form-check">
      <input class="form-check-input"
             type="checkbox"
             id="periodico_toggle"
             name="periodico_toggle"
             value="1">
    </div>
    <label class="form-check-label" for="periodico_toggle">Sí</label>
  </div>

  <div id="periodico_input_group" class="card-body d-none">
    <label class="form-label">¿Cuántos paquetes desea esterilizar?</label>
    <input type="number"
           name="paquetes_periodico"
           id="paquetes_periodico"
           class="form-control"
           min="1"
           placeholder="Ej. 3">
  </div>
</div>

<div class="mb-3">
  <label class="form-label">Presión registrada al inicio (psi)</label>
  <input type="number" class="form-control" name="presion_inicio" min="1" max="30"step="0.1" placeholder="Ej. 15.2" required>
</div>

          <!-- Botón enviar -->
          <div class="col-12 text-end">
<button name="iniciar_esterilizacion" class="btn btn-primary">
  Iniciar proceso
</button>
          </div>

        </form>
      </div>
    </div>
  </div>

<!-- EN PROCESO -->
<div id="proceso" class="seccion-interna  <?= $procesoSeleccionado ? 'seccion-activa' : '' ?>"> 
  <div class="card mb-4">
    <div class="card-header bg-warning">Esterilizaciones en proceso</div>
    <div class="card-body table-responsive">
      <table class="table table-bordered">
        <thead class="table-dark">
          <tr>
            <th>ID</th>
            <th>Detalles por Lote</th>
            <th>Vacíos / Tapas</th>
            <th>Contaminados</th>
            <th>Inicio</th>
            <th>Presión (psi)</th>
            <th>Artículos</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($procesos as $p): ?>
            <tr>
  <td data-label="ID"><?= $p['ID'] ?></td>
  <td data-label="Lotes">
    <ul class="list-unstyled mb-0">
      <?php
foreach (explode(', ', $p['Lotes']) as $lote):
  $stmtCnt = $conn->prepare("
    SELECT 
      COALESCE(SUM(ea.Tuppers_Esterilizados),0) AS cnt,
      mn.Codigo_Medio
    FROM dilucion_llenado_tuppers d
    LEFT JOIN esterilizacion_autoclave ea ON d.ID_Dilucion = ea.ID_Dilucion
    LEFT JOIN medios_nutritivos_madre mn ON d.ID_MedioNM = mn.ID_MedioNM
    WHERE d.ID_Dilucion = ?
      AND ea.id_paquete = ?
    GROUP BY d.ID_Dilucion
  ");
  $stmtCnt->bind_param("si", $lote, $p['ID']);
  $stmtCnt->execute();
  $res = $stmtCnt->get_result()->fetch_assoc();
  $cnt = $res['cnt'] ?? 0;
  $medio = $res['Codigo_Medio'] ?? '–';
?>
  <li>
    <strong><?= htmlspecialchars($lote) ?></strong>: 
    <?= $cnt ?> tuppers 
    <span class="text-muted">(Medio: <?= htmlspecialchars($medio) ?>)</span>
  </li>
<?php endforeach; ?>
    </ul>
  </td>
  <td data-label="Resumen">
    <small>
      Vacíos: <?= $p['TotalVacios'] ?><br>
      Tapas: <?= $p['TotalTapas'] ?>
    </small>
  </td>
  <td data-label="Contaminados"><?= $p['TotalContaminados'] ?></td>
  <td data-label="Fecha de Inicio"><?= $p['FechaInicio'] ?></td>
  <td data-label="Presión"><?= $p['PresionInicial'] !== null ? $p['PresionInicial'] . ' psi' : '—' ?></td>
  <td data-label="Artículos" class="text-wrap"><?= htmlspecialchars($p['Articulos']) ?></td>
  <td data-label="Acción">
    <a href="?abrir_modal_finalizar=<?= $p['ID'] ?>" class="btn btn-sm btn-success">
      Finalizar
    </a>
  </td>
</tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- FINALIZADAS -->
<div id="historial" class="seccion-interna">
  <div class="card mb-4">
    <div class="card-header bg-secondary text-white">
  Procesos Finalizados hoy, día: <?= date('d/m/Y') ?>
    </div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-striped">
        <thead class="table-dark">
          <tr>
            <th>ID</th>
            <th>Detalles por Lote</th>
            <th>Vacíos / Tapas</th>
            <th>Inicio</th>
            <th>Presión (psi) Inicio</th>
            <th>Fin</th>
            <th>Presión (psi) Final</th>
            <th>Artículos</th>
            <th>Resultado</th>
            <th>Observaciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($finalizados)): ?>
            <tr><td colspan="8" class="text-center">No hay procesos finalizados.</td></tr>
          <?php else: ?>
            <?php foreach ($finalizados as $p): ?>
              <tr>
                <td data-label="ID"><?= $p['ID'] ?></td>
                <td data-label="Lotes">
                  <ul class="list-unstyled mb-0">
                    <?php 
                    if (trim((string)$p['Lotes']) === ''): ?>
                      <li><strong>Total</strong>: <?= $p['TotalTuppers'] ?> tuppers</li>
                    <?php else:
                      foreach (explode(', ', $p['Lotes']) as $lote):
                        $stmtCntF = $conn->prepare("
                          SELECT 
                            COALESCE(SUM(ea.Tuppers_Esterilizados),0) AS cnt,
                            mn.Codigo_Medio
                          FROM dilucion_llenado_tuppers d
                          LEFT JOIN esterilizacion_autoclave ea ON d.ID_Dilucion = ea.ID_Dilucion
                          LEFT JOIN medios_nutritivos_madre mn ON d.ID_MedioNM = mn.ID_MedioNM
                          WHERE d.ID_Dilucion = ?
                            AND ea.id_paquete = ?
                            AND ea.Estado = 'Finalizado'
                          GROUP BY d.ID_Dilucion
                        ");
                        $stmtCntF->bind_param("si", $lote, $p['ID']);
                        $stmtCntF->execute();
                        $resF = $stmtCntF->get_result()->fetch_assoc();
                        $cntF = $resF['cnt'] ?? 0;
                        $medioF = $resF['Codigo_Medio'] ?? '–';
                    ?>
                      <li>
                        <strong><?= htmlspecialchars($lote) ?></strong>: 
                        <?= $cntF ?> tuppers 
                        <span class="text-muted">(Medio: <?= htmlspecialchars($medioF) ?>)</span>
                      </li>
                    <?php 
                      endforeach;
                    endif;
                    ?>
                  </ul>
                </td>
                <td data-label="Resumen">
                  <small>
                    Vacíos: <?= $p['TotalVacios'] ?><br>
                    Tapas: <?= $p['TotalTapas'] ?>
                  </small>
                </td>
                <td data-label="Inicio"><?= $p['FechaInicio'] ?></td>
                <td data-label="Presión">
  <?= is_null($p['PresionInicial']) ? '—' : $p['PresionInicial'] . ' psi' ?>
</td>
                <td data-label="Final"><?= $p['FechaFin'] ?></td>
                <td data-label="Presión"><?= $p['PresionInicial'] !== null ? $p['PresionInicial'] . ' psi' : '—' ?></td>
                <td data-label="Artículos" class="text-wrap"><?= htmlspecialchars($p['Articulos']) ?></td>
                <td data-label="Resultado"><?= htmlspecialchars($p['Resultado']) ?></td>
                <td data-label="Observaciones">
                  <?= nl2br(htmlspecialchars($p['Observaciones'])) ?>
                  <?php
$textoObs = strtolower($p['Observaciones']);
$mostrarPerdidas = !str_contains($textoObs, 'pérdidas:') && !str_contains($textoObs, '×');
?>
  <?php
  // Mostrar si hubo paquetes de periódico
  $stmtPeriodico = $conn->prepare("
    SELECT SUM(cantidad_periodico) AS total
    FROM esterilizacion_autoclave
    WHERE id_paquete = ? AND Tipo_Articulo = 'Periódico'
  ");
  $stmtPeriodico->bind_param("i", $p['ID']);
  $stmtPeriodico->execute();
  $periodico = $stmtPeriodico->get_result()->fetch_assoc();

  if (!empty($periodico['total'])):
  ?>
    <div class="mt-2"><strong>Periódico:</strong> <?= (int)$periodico['total'] ?> paquete(s)</div>
  <?php endif; ?>

                  <?php
                  // Mostrar pérdidas si las hay
                  $stmtPerdidas = $conn->prepare("
                    SELECT DISTINCT Articulo, Cantidad, Motivo
                    FROM perdidas_autoclave
                    WHERE ID_Esterilizacion IN (
                      SELECT ID_Esterilizacion FROM esterilizacion_autoclave WHERE id_paquete = ?
                    )
                  ");
                  $stmtPerdidas->bind_param("i", $p['ID']);
                  $stmtPerdidas->execute();
                  $resPerdidas = $stmtPerdidas->get_result();
                  if ($resPerdidas->num_rows > 0 && $mostrarPerdidas):
                  ?>
                    <div class="mt-2 border-top pt-2">
                      <strong>Pérdidas:</strong>
                      <ul class="mb-0 ps-3">
                        <?php while ($perdida = $resPerdidas->fetch_assoc()): ?>
                          <li>
                            <?= htmlspecialchars($perdida['Cantidad']) ?> × <?= htmlspecialchars($perdida['Articulo']) ?> —
                            <em><?= htmlspecialchars($perdida['Motivo']) ?></em>
                          </li>
                        <?php endwhile; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL FINALIZAR -->
<?php if ($procesoSeleccionado): ?>
  <?php
    // Detectar cantidades según tipo de artículo y su campo
    $cantidades_por_articulo = [];

    $stmt = $conn->prepare("
SELECT Tipo_Articulo, 
       ID_Dilucion,
       COALESCE(Tuppers_Vacios, 0) AS Tuppers_Vacios,
       COALESCE(Tapas_Vacias, 0) AS Tapas_Vacias,
       COALESCE(Tuppers_Esterilizados, 0) AS Tuppers_Esterilizados,
       COALESCE(cantidad_juegos, 0) AS cantidad_juegos
      FROM esterilizacion_autoclave
      WHERE id_paquete = ?
        AND Estado = 'En proceso'
    ");
    $stmt->bind_param("i", $procesoSeleccionado['id_paquete']);
    $stmt->execute();
    $res = $stmt->get_result();
$tiene_tuppers_con_medio = false;

    while ($row = $res->fetch_assoc()) {
      $tipo = trim($row['Tipo_Articulo']);
      $cantidad = 0;

if (str_contains(strtolower($tipo), 'nutritivo') || strtolower($tipo) === 'tuppers con medio') {
  $tiene_tuppers_con_medio = true;
}

if (!empty($row['ID_Dilucion'])) {
  // Es un tupper con medio (lote)
  $tipo = 'Tuppers con medio';
  $cantidad = $row['Tuppers_Esterilizados'];
} elseif (str_contains(strtolower($tipo), 'vacío') && !str_contains(strtolower($tipo), 'tapa')) {
  $cantidad = $row['Tuppers_Vacios'];
} elseif (str_contains(strtolower($tipo), 'tapa')) {
  $cantidad = $row['Tapas_Vacias'];
} elseif (str_contains(strtolower($tipo), 'juego')) {
  $cantidad = $row['cantidad_juegos'];
} else {
  $cantidad = 0;
}

      if (!isset($cantidades_por_articulo[$tipo])) {
        $cantidades_por_articulo[$tipo] = 0;
      }

      $cantidades_por_articulo[$tipo] += $cantidad;
    }
  ?>

  <div class="modal fade" id="modalFinalizar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <form method="POST" action="<?= basename(__FILE__) ?>" class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title">Finalizar Proceso #<?= htmlspecialchars($procesoSeleccionado['ID_Esterilizacion']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="id_paquete" value="<?= htmlspecialchars($paquete ?? '') ?>">
          <input type="hidden" name="id_esterilizacion" value="<?= htmlspecialchars($procesoSeleccionado['ID_Esterilizacion']) ?>">

          <div class="mb-3">
            <label class="form-label">Resultado</label>
            <select name="resultado" class="form-select" id="resultado-select" required>
              <option value="">-- Selecciona --</option>
              <option value="Exitoso">Exitoso</option>
              <option value="Con pérdida parcial" <?= !$tiene_tuppers_con_medio ? 'disabled' : '' ?>>Con pérdida parcial</option>
              <option value="Fallido">Fallido</option>
            </select>
          </div>

          <div id="bloque-perdida-parcial" class="d-none border p-3 rounded bg-light">
            <p class="fw-bold mb-2">Registro de pérdidas</p>
            <div id="contenedor-perdidas">
              <div class="row gy-2 align-items-end fila-perdida">
                <div class="col-md-4">
                  <label class="form-label">Artículo</label>
                  <select name="articulo_perdido[]" class="form-select articulo-select">
                    <option value="">-- Selecciona --</option>
<?php foreach ($cantidades_por_articulo as $tipo => $cant): ?>
  <?php if (str_contains(strtolower($tipo), 'nutritivo') || strtolower($tipo) === 'tuppers con medio'): ?>
    <option value="<?= htmlspecialchars($tipo) ?>"><?= htmlspecialchars($tipo) ?></option>
  <?php endif; ?>
<?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Cantidad</label>
                  <input type="number" name="cantidad_perdida[]" class="form-control cantidad-input" min="1">
                </div>
                <div class="col-md-5">
                  <label class="form-label">Motivo</label>
                  <input type="text" name="motivo_perdida[]" class="form-control texto-mayusculas" placeholder="Ej. Se cayeron">
                </div>
              </div>
            </div>
            <div class="text-end mt-2">
              <button type="button" id="agregar-perdida" class="btn btn-secondary btn-sm">+ Agregar otra pérdida</button>
            </div>
          </div>

<div class="mb-3">
  <label class="form-label">Presión registrada al finalizar (psi)</label>
  <input type="number" name="presion_final" class="form-control" step="0.1" min="1" max="30" placeholder="Ej. 15.0" required>
</div>
          <div class="mb-3 mt-3">
            <label class="form-label">Observaciones</label>
            <textarea name="observaciones" class="form-control" rows="3"></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button name="finalizar_esterilizacion" class="btn btn-success">Finalizar</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>

  <style>
    .texto-mayusculas {
      text-transform: uppercase;
    }
  </style>



  <script>
    const maximosPorArticulo = <?= json_encode($cantidades_por_articulo) ?>;

    document.addEventListener('DOMContentLoaded', function () {
      const resultadoSelect = document.getElementById('resultado-select');
      const bloquePerdida = document.getElementById('bloque-perdida-parcial');
      const contenedor = document.getElementById('contenedor-perdidas');
      const btnAgregar = document.getElementById('agregar-perdida');

      resultadoSelect.addEventListener('change', function () {
  // Mostrar u ocultar el bloque de pérdidas
  bloquePerdida.classList.toggle('d-none', this.value !== 'Con pérdida parcial');

  // Activar o desactivar 'required' solo si es 'Con pérdida parcial'
  const inputs = bloquePerdida.querySelectorAll('select, input');
  inputs.forEach(input => {
    if (this.value === 'Con pérdida parcial') {
      input.setAttribute('required', 'required');
    } else {
      input.removeAttribute('required');
    }
  });
});

      function validarCantidad(input) {
        const fila = input.closest('.fila-perdida');
        const select = fila.querySelector('.articulo-select');
        const articulo = select.value;
        const maxPermitido = maximosPorArticulo[articulo] || 0;
        const valor = parseInt(input.value);

        if (valor < 1 || valor > maxPermitido) {
          alert(`❌ La cantidad para "${articulo}" debe estar entre 1 y ${maxPermitido}.`);
          input.value = '';
        }
      }

      btnAgregar.addEventListener('click', function () {
        const original = contenedor.querySelector('.fila-perdida');
        const nueva = original.cloneNode(true);
        nueva.querySelectorAll('input, select').forEach(el => el.value = '');
        contenedor.appendChild(nueva);
      });

      contenedor.addEventListener('input', function (e) {
        if (e.target.name === 'cantidad_perdida[]') {
          validarCantidad(e.target);
        } else if (e.target.name === 'motivo_perdida[]') {
          e.target.value = e.target.value.toUpperCase();
        }
      });

      contenedor.addEventListener('change', function (e) {
        if (e.target.name === 'articulo_perdido[]') {
          const fila = e.target.closest('.fila-perdida');
          const cantidadInput = fila.querySelector('.cantidad-input');
          cantidadInput.value = '';
        }
      });
    });
  </script>
<?php endif; ?>

</main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const resultadoSelect = document.getElementById('resultado-select');
  const bloquePerdida = document.getElementById('bloque-perdida-parcial');
  const btnAgregar = document.getElementById('agregar-perdida');
  const contenedor = document.getElementById('contenedor-perdidas');

  const MAX_CANTIDAD = <?= $tuppers_total ?>;

  // Mostrar/ocultar bloque según resultado
  resultadoSelect.addEventListener('change', function () {
    bloquePerdida.classList.toggle('d-none', this.value !== 'Con pérdida parcial');
  });

  // Mayúsculas automáticas en motivo
  contenedor.addEventListener('input', function (e) {
    if (e.target.name === 'motivo_perdida[]') {
      e.target.value = e.target.value.toUpperCase();
    }
  });

  // Validación de cantidad
  contenedor.addEventListener('input', function (e) {
    if (e.target.name === 'cantidad_perdida[]') {
      const val = parseInt(e.target.value);
      if (val < 1 || val > MAX_CANTIDAD) {
        alert(`La cantidad debe ser entre 1 y ${MAX_CANTIDAD}.`);
        e.target.value = '';
      }
    }
  });

  // Agregar nueva fila
  btnAgregar.addEventListener('click', function () {
    const fila = contenedor.querySelector('.row').cloneNode(true);
    fila.querySelectorAll('input, select').forEach(el => {
      if (el.tagName === 'SELECT') el.selectedIndex = 0;
      else el.value = '';
    });
    contenedor.appendChild(fila);
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.body.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'resultado-select') {
      const bloque = document.getElementById('bloque-perdida-parcial');
      if (e.target.value === 'Con pérdida parcial') {
        bloque?.classList.remove('d-none');
      } else {
        bloque?.classList.add('d-none');
      }
    }
  });
});
</script>

<script>
  // Navegación de secciones
  function mostrarSeccion(id) {
    document.querySelectorAll('.seccion-interna')
      .forEach(el => el.classList.remove('seccion-activa'));
    document.getElementById(id).classList.add('seccion-activa');
  }

  // Render dinámico de lotes
  function renderLotes(n) {
    const cont = document.getElementById('lotes_container');
    cont.innerHTML = '';
    for (let i = 0; i < n; i++) {
      cont.insertAdjacentHTML('beforeend', `
        <div class="row mb-3 lote-block">
          <div class="col-md-6">
            <label class="form-label">Lote ID_Dilución</label>
            <select name="id_dilucion[]" class="form-select lote-select" required>
              <option value="" disabled selected>-- Selecciona --</option>
              <?php
              $r = $conn->query("
  SELECT d.ID_Dilucion
  FROM dilucion_llenado_tuppers d
  LEFT JOIN esterilizacion_autoclave ea ON d.ID_Dilucion = ea.ID_Dilucion
  GROUP BY d.ID_Dilucion
  HAVING (MAX(d.Tuppers_Llenos) - COALESCE(SUM(ea.Tuppers_Esterilizados), 0)) > 0
  ORDER BY d.ID_Dilucion DESC
");

                while($x = $r->fetch_assoc()):
              ?>
                <option><?= $x['ID_Dilucion'] ?></option>
              <?php endwhile;?>
            </select>
            <small class="info-lote text-muted small">
              Medio: – | Llenos: – | Usados: – | Disponibles: –  
            </small>
          </div>
          <div class="col-md-3">
            <label class="form-label"># Tuppers</label>
            <input type="number" name="tuppers_esterilizados[]"
                   class="form-control lote-qty" min="1" required>
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <div class="form-check">
              <input type="checkbox" name="todos_tuppers[]"
                     class="form-check-input" id="todos_${i}">
              <label class="form-check-label" for="todos_${i}">Todos</label>
            </div>
          </div>
        </div>
      `);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    // Confirmar cantidad de lotes
    document.getElementById('confirm_lotes')
      .addEventListener('click', () => {
        const n = parseInt(document.getElementById('num_lotes').value, 10) || 0;
        renderLotes(n);
      });

    // AJAX de lotes y validaciones
    document.getElementById('lotes_container')
      .addEventListener('change', e => {
        if (e.target.classList.contains('lote-select')) {
          const vals = Array.from(document.querySelectorAll('.lote-select'))
            .map(s => s.value).filter(v => v);
          if (vals.some((v,i,a) => a.indexOf(v)!==i)) {
            alert('No puedes seleccionar el mismo lote dos veces.');
            e.target.value = '';
            return;
          }
          const sel  = e.target;
          const info = sel.closest('.lote-block').querySelector('.info-lote');
          fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `ajax_dilucion_info=${encodeURIComponent(sel.value)}`
          })
          .then(r => r.json())
          .then(d => {
            info.textContent = 
              `Medio: ${d.Medio} | Llenos: ${d.Tuppers_Llenos}` +
              ` | Usados: ${d.Usados} | Disponibles: ${d.Disponibles}`;
            sel.closest('.lote-block')
               .querySelector('.lote-qty').max = d.Disponibles;
          })
          .catch(err => console.error('Fetch error:', err));
        }
        if (e.target.name === 'todos_tuppers[]') {
          const blo = e.target.closest('.lote-block');
          const inp = blo.querySelector('input[name="tuppers_esterilizados[]"]');
          const disp = parseInt(
            blo.querySelector('.info-lote').textContent.match(/Disponibles:\s*(\d+)/)[1],
            10
          );
          if (e.target.checked) {
            inp.value = disp; inp.readOnly = true;
          } else {
            inp.value = ''; inp.readOnly = false;
          }
        }
        if (e.target.classList.contains('lote-qty')) {
          const val = parseInt(e.target.value,10);
          const max = parseInt(e.target.max,10);
          if (val < 1 || val > max) {
            alert(`La cantidad debe estar entre 1 y ${max}.`);
            e.target.value = '';
          }
        }
      });

    // ───────── BLOQUE CONTAMINADOS ─────────
    const contToggle = document.getElementById('contaminados_toggle');
    const contList   = document.getElementById('contaminados_list');
    const contBody   = contList.querySelector('tbody');

    contList.classList.add('d-none');

    contToggle.addEventListener('change', () => {
      const show = contToggle.checked;
      contList.classList.toggle('d-none', !show);

      if (show) {
        contBody.innerHTML = '<tr><td colspan="6">Cargando…</td></tr>';
        fetch(window.location.pathname, {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: 'ajax_contaminados=1'
        })
        .then(r => r.json())
        .then(datos => {
          if (!datos.length) {
            contBody.innerHTML = '<tr><td colspan="6">No hay tuppers contaminados.</td></tr>';
          } else {
            contBody.innerHTML = datos.map(p => `
              <tr>
                <td><input type="radio" name="id_perdida" value="${p.ID_Perdida}"></td>
                <td>${p.Tipo_Entidad}</td>
                <td>${p.Fecha_Perdida}</td>
                <td>${p.Tuppers_Perdidos}</td>
                <td>${p.Motivo}</td>
                <td>
                  <input type="number"
                         name="tuppers_contaminados[${p.ID_Perdida}]"
                         class="form-control"
                         min="1" max="${p.Tuppers_Perdidos}"
                         disabled>
                </td>
              </tr>
            `).join('');
            document.querySelectorAll('input[name="id_perdida"]').forEach(radio => {
              radio.addEventListener('change', () => {
                document.querySelectorAll('input[name^="tuppers_contaminados"]').forEach(i => {
                  i.disabled = true; i.value = '';
                });
                const sel = document.querySelector(
                  `input[name="tuppers_contaminados[${radio.value}]"]`
                );
                sel.disabled = false;
                sel.required = true;
              });
            });
          }
        })
        .catch(() => {
          contBody.innerHTML = '<tr><td colspan="6" class="text-danger">Error cargando datos.</td></tr>';
        });
      }
    });
    // ────────────────────────────────────────

    // Vacíos
    const vaciosToggle = document.getElementById('vacios_toggle');
    const vaciosOpts   = document.querySelectorAll('.vacios-options');
    const numVacios    = document.getElementById('num_vacios');
    const vaciosTapas  = document.getElementById('vacios_tapas');
    const otroTapasGrp = document.getElementById('otro_tapas_group');
    const otroTapas    = document.getElementById('otro_tapas');

    numVacios.disabled = !vaciosToggle.checked;

    vaciosToggle.addEventListener('change', e => {
      const chk = e.target.checked;
      vaciosOpts.forEach(el => el.classList.toggle('d-none', !chk));
      numVacios.disabled = !chk;
      if (!chk) {
        numVacios.value = '';
        vaciosTapas.value = '';
        otroTapasGrp.classList.add('d-none');
        otroTapas.value = '';
      }
    });

    vaciosTapas.addEventListener('change', e => {
      otroTapasGrp.classList.toggle('d-none', e.target.value !== 'otro');
      if (e.target.value !== 'otro') otroTapas.value = '';
    });

    numVacios.addEventListener('input', () => {
      otroTapas.max = numVacios.value;
      if (vaciosTapas.value === 'otro') {
        vaciosTapas.value = '';
        vaciosTapas.dispatchEvent(new Event('change'));
      }
    });
    otroTapas.addEventListener('input', () => {
      if (parseInt(otroTapas.value,10) > parseInt(numVacios.value,10)) {
        alert('Las tapas no pueden exceder el número de tuppers.');
        otroTapas.value = numVacios.value;
      }
    });

    // Mostrar input de juegos si se activa el checkbox
const juegosToggle = document.getElementById('juegos_toggle');
const juegosInputGroup = document.getElementById('juegos_input_group');
const juegosInput = document.getElementById('juegos_esterilizados');

juegosToggle.addEventListener('change', () => {
  const activo = juegosToggle.checked;
  juegosInputGroup.classList.toggle('d-none', !activo);
  juegosInput.required = activo;
  if (!activo) juegosInput.value = '';
});

// Mostrar input de paquetes si se activa el checkbox
const perToggle = document.getElementById('periodico_toggle');
const perInputGroup = document.getElementById('periodico_input_group');
const perInput = document.getElementById('paquetes_periodico');

perToggle.addEventListener('change', () => {
  const activo = perToggle.checked;
  perInputGroup.classList.toggle('d-none', !activo);
  perInput.required = activo;
  if (!activo) perInput.value = '';
});

  });

  
</script>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php if($procesoSeleccionado): ?>
<script>
  // 1) Cambia a la sección “En proceso”
  mostrarSeccion('proceso');

  // 2) Lanza el modal de Bootstrap
  const modalEl = document.getElementById('modalFinalizar');
  const bsModal = new bootstrap.Modal(modalEl);
  bsModal.show();
</script>
<?php endif; ?>

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