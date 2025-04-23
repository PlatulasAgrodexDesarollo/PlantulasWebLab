<?php
session_start();
require '../db.php';

if (!isset($_SESSION['ID_Operador']) || $_SESSION['Rol'] != 6) {
    header("Location: ../login.php");
    exit();
}

// Obtener todos los operadores disponibles (para preparar cajas)
$operadores = [];
$result_operadores = $conn->query("SELECT ID_Operador, CONCAT(Nombre, ' ', Apellido_P, ' ', Apellido_M) AS NombreCompleto FROM operadores WHERE Activo = 1 AND ID_Rol = 2 ORDER BY Nombre ASC");
while ($op = $result_operadores->fetch_assoc()) {
    $operadores[] = $op;
}

// Guardar orden de lavado cuando envían el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_lote'], $_POST['cantidad_lavada'], $_POST['fecha_lavado'])) {
    $id_lote = intval($_POST['id_lote']);
    $cantidad_lavada = intval($_POST['cantidad_lavada']);
    $fecha_lavado = $_POST['fecha_lavado'];
    $id_operador = $_SESSION['ID_Operador'];
    $responsables = $_POST['responsables'] ?? [];

    $query = "INSERT INTO orden_tuppers_lavado (ID_Lote, Cantidad_Lavada, Fecha_Lavado, ID_Operador, Estado)
              VALUES (?, ?, ?, ?, 'Pendiente')";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iisi", $id_lote, $cantidad_lavada, $fecha_lavado, $id_operador);

    if ($stmt->execute()) {
        $id_orden = $conn->insert_id;

        $query_movimiento = "INSERT INTO movimientos_lote (ID_Lote, Fecha_Movimiento, Tipo_Movimiento, Cantidad_Tuppers, ID_Operador, Observaciones)
                             VALUES (?, NOW(), 'Lavado', ?, ?, 'Tuppers seleccionados para lavado')";
        $stmt_movimiento = $conn->prepare($query_movimiento);
        $stmt_movimiento->bind_param("iii", $id_lote, $cantidad_lavada, $id_operador);
        $stmt_movimiento->execute();

        if (!empty($responsables)) {
            $query_resp = "INSERT INTO responsables_cajas (ID_Orden, ID_Operador) VALUES (?, ?)";
            $stmt_resp = $conn->prepare($query_resp);
            foreach ($responsables as $id_resp) {
                $id_resp = intval($id_resp);
                $stmt_resp->bind_param("ii", $id_orden, $id_resp);
                $stmt_resp->execute();
            }
        }

        echo "<script>alert('✅ Orden de lavado y responsables registrados correctamente.'); window.location.href='seleccion_tuppers.php';</script>";
        exit();
    } else {
        echo "<script>alert('❌ Error al registrar la orden de lavado.');</script>";
    }
}

// Obtener información detallada del lote
if (isset($_GET['id_lote'])) {
    $id_lote = intval($_GET['id_lote']);

    $query = "SELECT l.Fecha, CONCAT(o.Nombre, ' ', o.Apellido_P, ' ', o.Apellido_M) AS Operador, l.ID_Etapa
              FROM lotes l
              INNER JOIN operadores o ON l.ID_Operador = o.ID_Operador
              WHERE l.ID_Lote = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id_lote);
    $stmt->execute();
    $result = $stmt->get_result();
    $info = $result->fetch_assoc();

    if ($info) {
        $etapa = $info['ID_Etapa'];

        $sqlCantidad = ($etapa == 2) ? 
            "SELECT SUM(Tuppers_Llenos) AS CantidadTuppers FROM multiplicacion WHERE ID_Lote = ?" :
            "SELECT SUM(Tuppers_Llenos) AS CantidadTuppers FROM enraizamiento WHERE ID_Lote = ?";
        $stmt2 = $conn->prepare($sqlCantidad);
        $stmt2->bind_param("i", $id_lote);
        $stmt2->execute();
        $resCantidad = $stmt2->get_result();
        $rowCantidad = $resCantidad->fetch_assoc();
        $cantidadTuppers = $rowCantidad['CantidadTuppers'] ?? 0;

        $queryLavados = "SELECT SUM(Cantidad_Lavada) AS TuppersLavados
                         FROM orden_tuppers_lavado
                         WHERE ID_Lote = ? AND Estado = 'Pendiente'";
        $stmt3 = $conn->prepare($queryLavados);
        $stmt3->bind_param("i", $id_lote);
        $stmt3->execute();
        $resLavados = $stmt3->get_result();
        $rowLavados = $resLavados->fetch_assoc();
        $tuppersLavados = $rowLavados['TuppersLavados'] ?? 0;

        $tuppersDisponibles = $cantidadTuppers - $tuppersLavados;
        if ($tuppersDisponibles < 0) {
            $tuppersDisponibles = 0;
        }

        echo json_encode([
            'fecha' => $info['Fecha'],
            'operador' => $info['Operador'],
            'cantidad' => $tuppersDisponibles
        ]);
    } else {
        echo json_encode(['error' => 'No encontrado']);
    }
    exit();
}

// Buscar lotes por variedad + etapa
if (isset($_GET['buscar_variedad']) && isset($_GET['etapa'])) {
    $buscar = "%" . $_GET['buscar_variedad'] . "%";
    $etapa = intval($_GET['etapa']);

    $query = "SELECT l.ID_Lote, v.Nombre_Variedad, v.Especie, l.Fecha
              FROM lotes l
              INNER JOIN variedades v ON l.ID_Variedad = v.ID_Variedad
              WHERE (v.Nombre_Variedad LIKE ? OR v.Especie LIKE ?) AND l.ID_Etapa = ?
              ORDER BY l.Fecha DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $buscar, $buscar, $etapa);
    $stmt->execute();
    $result = $stmt->get_result();

    $lotes = [];
    while ($row = $result->fetch_assoc()) {
        $lotes[] = $row;
    }
    echo json_encode($lotes);
    exit();
}
?>


<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Selección de Tuppers</title>
  <link rel="stylesheet" href="../style.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
</head>
<body>
<div class="contenedor-pagina">
  <header>
    <div class="encabezado">
      <a class="navbar-brand" href="#">
        <img src="../logoplantulas.png" alt="Logo" width="130" height="124" />
      </a>
      <h2>📦 Selección de Tuppers</h2>
    </div>
    <div class="barra-navegacion">
      <nav class="navbar bg-body-tertiary">
        <div class="container-fluid">
          <div class="Opciones-barra">
            <button onclick="window.location.href='dashboard_gpl.php'">🔙 REGRESAR</button>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <main class="container mt-4">
    <div class="row">
      <div class="col-md-4">
        <div class="card">
          <div class="card-header">Información del Lote</div>
          <div class="card-body" id="infoLote" style="display:none;">
            <p><strong>Fecha del Lote:</strong> <span id="fecha_lote"></span></p>
            <p><strong>Operador Responsable:</strong> <span id="operador_lote"></span></p>
            <p><strong>Cantidad de Tuppers Disponibles:</strong> <span id="cantidad_tuppers"></span></p>
          </div>
        </div>
      </div>

      <div class="col-md-8">
        <h4>🧼 Enviar Tuppers a Lavado</h4>
        <div class="row">
          <div class="col-md-6">
            <label class="form-label">Etapa:</label>
            <select id="etapa" class="form-select">
              <option value="">-- Selecciona Etapa --</option>
              <option value="2">Multiplicación</option>
              <option value="3">Enraizamiento</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Buscar Variedad:</label>
            <input type="text" id="buscar_variedad" class="form-control" placeholder="Buscar variedad...">
          </div>
        </div>

        <form method="POST" class="row g-3 mt-3" id="formLavado">
          <div class="col-md-12">
            <label class="form-label">Seleccionar Lote:</label>
            <select name="id_lote" id="id_lote" class="form-select" required>
              <option value="">-- Selecciona un lote --</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Cantidad de Tuppers a Lavar:</label>
            <input type="number" name="cantidad_lavada" id="cantidad_lavada" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Fecha de Lavado:</label>
            <input type="date" name="fecha_lavado" class="form-control" required>
          </div>

          <div class="col-md-12">
            <label class="form-label">Seleccionar Operadores Responsables de llenado y acomodo de tuppers en Cajas:</label><br>

          <?php foreach ($operadores as $op) : ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="responsables[]" value="<?= $op['ID_Operador'] ?>" id="op<?= $op['ID_Operador'] ?>">
              <label class="form-check-label" for="op<?= $op['ID_Operador'] ?>">
                <?= htmlspecialchars($op['NombreCompleto']) ?>
              </label>
            </div>
          <?php endforeach; ?>

          <small class="text-muted">Puedes seleccionar uno o varios responsables.</small>
          </div>


          <div class="col-12">
            <button type="submit" class="btn btn-success w-100">Registrar Lavado</button>
          </div>
        </form>
      </div>
    </div>
  </main>

  <footer class="text-center mt-4 mb-3">
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script>
$(document).ready(function() {
  function cargarLotes(busqueda = '') {
    var etapa = $('#etapa').val();
    if (etapa !== '') {
      $.ajax({
        url: 'seleccion_tuppers.php',
        method: 'GET',
        data: { buscar_variedad: busqueda, etapa: etapa },
        success: function(data) {
          const lotes = JSON.parse(data);
          $('#id_lote').empty().append('<option value="">-- Selecciona un lote --</option>');
          if (Array.isArray(lotes)) {
            lotes.forEach(lote => {
              $('#id_lote').append(`<option value="${lote.ID_Lote}">${lote.Nombre_Variedad} (${lote.Especie}) - ${lote.Fecha}</option>`);
            });
          }
        }
      });
    }
  }

  $('#etapa').change(function() {
    $('#buscar_variedad').val('');
    $('#id_lote').empty().append('<option value="">-- Selecciona un lote --</option>');
  });

  $('#buscar_variedad').autocomplete({
    source: function(request, response) {
      var etapa = $('#etapa').val();
      $.ajax({
        url: 'seleccion_tuppers.php',
        method: 'GET',
        data: { buscar_variedad: request.term, etapa: etapa },
        success: function(data) {
          const lotes = JSON.parse(data);
          response($.map(lotes, function(lote) {
            return {
              label: lote.Nombre_Variedad + ' (' + lote.Especie + ') - ' + lote.Fecha,
              value: lote.Nombre_Variedad,
              id: lote.ID_Lote
            };
          }));
        }
      });
    },
    select: function(event, ui) {
      $('#id_lote').empty().append(`<option value="${ui.item.id}" selected>${ui.item.label}</option>`);
      $('#buscar_variedad').val(ui.item.value);
      $('#id_lote').trigger('change');
      return false;
    },
    minLength: 1
  });

  $('#id_lote').change(function() {
    var idLote = $(this).val();
    if (idLote) {
      $.ajax({
        url: 'seleccion_tuppers.php',
        method: 'GET',
        data: { id_lote: idLote },
        success: function(data) {
          const info = JSON.parse(data);
          if (!info.error) {
            $('#fecha_lote').text(info.fecha);
            $('#operador_lote').text(info.operador);
            $('#cantidad_tuppers').text(info.cantidad);
            $('#infoLote').show();
          } else {
            $('#infoLote').hide();
          }
        }
      });
    } else {
      $('#infoLote').hide();
    }
  });

  $('#formLavado').submit(function() {
    var cantidadIngresada = parseInt($('#cantidad_lavada').val());
    var cantidadDisponible = parseInt($('#cantidad_tuppers').text());
    if (cantidadIngresada > cantidadDisponible) {
      alert('❌ Error: La cantidad supera los tuppers disponibles.');
      return false;
    }
  });
});
</script>
</body>
</html>
