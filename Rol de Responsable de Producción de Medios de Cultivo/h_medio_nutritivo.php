<?php
include '../db.php';
session_start();

if (!isset($_SESSION['ID_Operador']) || $_SESSION['Rol'] != 7) {
  header("Location: ../login.php");
  exit();
}

function generarNuevoIDDilucion($conn) {
  $query = "SELECT COUNT(*) AS total FROM dilucion_llenado_tuppers";
  $result = $conn->query($query);
  $row = $result->fetch_assoc();
  $nuevoNumero = (int)$row['total'] + 1;
  return 'DL-' . str_pad($nuevoNumero, 3, '0', STR_PAD_LEFT);
}

$mediosQuery = $conn->query("SELECT ID_MedioNM, Codigo_Medio, FORMAT(Cantidad_Disponible, 2) AS Cantidad_Disponible, Estado FROM medios_nutritivos_madre ORDER BY Codigo_Medio ASC");
$medios = $mediosQuery->fetch_all(MYSQLI_ASSOC);

if (isset($_POST['guardar_registro'])) {
    $id_medio = $_POST['id_medio'];
    $cantidad_ocupada = round((float)$_POST['cantidad_ocupada'], 2);
    $cantidad_creada = round((float)$_POST['cantidad_creada'], 2);
    $tuppers_llenos = (int)$_POST['tuppers_llenos'];
    $observaciones = $_POST['observaciones'];
    $fecha = date('Y-m-d');
    $operador = $_SESSION['ID_Operador'];

    $stmt = $conn->prepare("SELECT Cantidad_Disponible FROM medios_nutritivos_madre WHERE ID_MedioNM = ? LIMIT 1");
    $stmt->bind_param("i", $id_medio);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $cantidad_disponible = round((float)$row['Cantidad_Disponible'], 2);

        if ($cantidad_ocupada > $cantidad_disponible) {
            echo "<script>alert('Error: La cantidad ocupada excede la disponible.'); window.location.href='h_medio_nutritivo.php';</script>";
            exit();
        }

        $nuevoID = generarNuevoIDDilucion($conn);

        $insert = $conn->prepare("INSERT INTO dilucion_llenado_tuppers (ID_Dilucion, ID_MedioNM, Fecha_Preparacion, Cantidad_MedioMadre, Volumen_Final, Tuppers_Llenos, Operador_Responsable) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param("sisdiis", $nuevoID, $id_medio, $fecha, $cantidad_ocupada, $cantidad_creada, $tuppers_llenos, $operador);
        $insert->execute();

        $nuevaCantidad = round($cantidad_disponible - $cantidad_ocupada, 2);
        $estado = ($nuevaCantidad <= 0) ? 'Consumido' : 'Disponible';
        $update = $conn->prepare("UPDATE medios_nutritivos_madre SET Cantidad_Disponible = ?, Estado = ?, Ultima_Modificacion = NOW() WHERE ID_MedioNM = ?");
        $update->bind_param("dsi", $nuevaCantidad, $estado, $id_medio);
        $update->execute();

        echo "<script>alert('Registro exitoso.'); window.location.href='h_medio_nutritivo.php';</script>";
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Homogeneización del Medio</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="contenedor-pagina">
  <header>
    <div class="encabezado">
      <a class="navbar-brand" href="#">
        <img src="../logoplantulas.png" alt="Logo" width="130" height="124">
      </a>
      <div>
        <h2>Homogeneización del Medio</h2>
        <p>Registra el proceso de homogeneización y dilución de medios nutritivos madre.</p>
      </div>
    </div>
    <div class="barra-navegacion">
      <nav class="navbar bg-body-tertiary">
        <div class="container-fluid">
          <div class="Opciones-barra">
            <button onclick="window.location.href='dashboard_rpmc.php'">🔄 Regresar</button>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <main class="container py-4">
    <h2 class="mb-4 text-center">📦 Medios Nutritivos Madre Disponibles</h2>
    <div class="table-responsive mb-5">
      <table class="table table-bordered table-striped" id="tabla-medios">
        <thead class="table-light">
          <tr>
            <th>ID Medio</th>
            <th>Código del Medio</th>
            <th>Cantidad Disponible (L)</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($medios as $medio): ?>
          <tr data-id="<?= $medio['ID_MedioNM'] ?>">
            <td><?= $medio['ID_MedioNM'] ?></td>
            <td><?= htmlspecialchars($medio['Codigo_Medio']) ?></td>
            <td><?= number_format($medio['Cantidad_Disponible'], 2) ?></td>
            <td><?= htmlspecialchars($medio['Estado']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <section class="form-container">
      <div class="card p-4 mb-5">
        <h4 class="mb-4 text-center">📋 Seleccionar Medio para Dilución</h4>
        <form id="formSeleccion" onsubmit="validarYMostrarModal(); return false;">
          <div class="row g-3">
            <div class="col-md-6">
              <label>Seleccionar Medio:</label>
              <select id="id_medio" class="form-select" required>
                <option value="">-- Selecciona un medio --</option>
                <?php foreach ($medios as $medio): ?>
                <option value="<?= $medio['ID_MedioNM'] ?>" data-disponible="<?= $medio['Cantidad_Disponible'] ?>">
                  <?= htmlspecialchars($medio['Codigo_Medio']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label>Cantidad a Ocupar (L):</label>
              <input type="number" step="0.1" id="cantidad_ocupada" class="form-control" required>
            </div>
            <div class="col-md-12 d-grid">
              <button type="submit" class="btn btn-primary">Validar y Continuar</button>
            </div>
          </div>
        </form>
      </div>

      <!-- Modal Bootstrap -->
      <div class="modal fade" id="modalRegistro" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <form method="POST" class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Completar Información de Dilución</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="alert alert-info mb-3" id="modal_resumen">
                <strong>Resumen:</strong><br>
                Medio seleccionado: <span id="resumen_medio"></span><br>
                Cantidad a ocupar: <span id="resumen_cantidad"></span> L
              </div>
              <input type="hidden" name="id_medio" id="modal_id_medio">
              <input type="hidden" name="cantidad_ocupada" id="modal_cantidad_ocupada">

              <div class="mb-3">
                <label>Cantidad Creada (L):</label>
                <input type="number" step="0.1" name="cantidad_creada" id="modal_cantidad_creada" class="form-control" required>
              </div>

              <div class="mb-3">
                <label>Número de Tuppers Llenados:</label>
                <input type="number" name="tuppers_llenos" class="form-control" required>
              </div>

              <div class="mb-3">
                <label>Observaciones:</label>
                <textarea name="observaciones" class="form-control" rows="3"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="submit" name="guardar_registro" class="btn btn-success">Guardar Registro</button>
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            </div>
          </form>
        </div>
      </div>
    </section>
  </main>

  <footer class="text-center p-3">
    &copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function validarYMostrarModal() {
  const idMedio = document.getElementById('id_medio').value;
  const cantidadOcupada = parseFloat(document.getElementById('cantidad_ocupada').value);

  if (!idMedio || !cantidadOcupada) {
    alert('Por favor completa todos los campos.');
    return;
  }

  const option = document.querySelector(`#id_medio option[value='${idMedio}']`);
  const disponible = parseFloat(option.dataset.disponible);
  const codigoMedio = option.textContent.trim();

  if (cantidadOcupada > disponible) {
    alert('Error: La cantidad ocupada excede la disponible.');
    return;
  }

  document.getElementById('modal_id_medio').value = idMedio;
  document.getElementById('modal_cantidad_ocupada').value = cantidadOcupada;
  document.getElementById('resumen_medio').textContent = codigoMedio;
  document.getElementById('resumen_cantidad').textContent = cantidadOcupada;

  const modal = new bootstrap.Modal(document.getElementById('modalRegistro'));
  modal.show();
}

document.getElementById('id_medio').addEventListener('change', function () {
  const selectedId = this.value;
  document.querySelectorAll("#tabla-medios tbody tr").forEach(row => {
    row.classList.remove('table-primary');
    if (row.getAttribute('data-id') === selectedId) {
      row.classList.add('table-primary');
    }
  });
});
</script>
</body>
</html>
