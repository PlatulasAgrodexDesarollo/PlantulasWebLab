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
$volver1 = !empty($_SESSION['origin']) && $_SESSION['origin'] === 1;

if ((int) $_SESSION['Rol'] !== 11) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Director General.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 600 * 40;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

//Procesar  búsqueda
$busqueda = $_GET['busqueda'] ?? '';
$plantacion = [];

$sqlBase = "
    SELECT 
        DATE(p.Fecha_Siembra) AS Fecha,
        CASE 
            WHEN p.Origen = 'Multiplicacion' THEN 'Etapa 2 - Multiplicación'
            ELSE 'Etapa 3 - Enraizamiento'
        END AS Etapa,
        v.Nombre_Variedad AS Variedad,
        CONCAT(o.Nombre, ' ', o.Apellido_P) AS Operador,
        COUNT(DISTINCT p.ID_Lote) AS Cantidad_Lotes,
        SUM(p.Cantidad_Dividida) AS Total_Brotes,
        SUM(p.Tuppers_Llenos) AS Total_Tuppers,
        ROUND(SUM(p.Cantidad_Dividida)/SUM(p.Tuppers_Llenos), 1) AS Brotes_por_Tupper
    FROM (
        SELECT 
            ID_Lote, 
            Fecha_Siembra, 
            Cantidad_Dividida, 
            Tuppers_Llenos, 
            Operador_Responsable, 
            ID_Variedad,
            'Multiplicacion' AS Origen
        FROM multiplicacion
        WHERE Estado_Revision = 'Consolidado'

        UNION ALL

        SELECT 
            ID_Lote, 
            Fecha_Siembra, 
            Cantidad_Dividida, 
            Tuppers_Llenos, 
            Operador_Responsable, 
            ID_Variedad,
            'Enraizamiento' AS Origen
        FROM enraizamiento
        WHERE Estado_Revision = 'Consolidado'
    ) AS p
    JOIN variedades v ON p.ID_Variedad = v.ID_Variedad
    JOIN operadores o ON p.Operador_Responsable = o.ID_Operador
";

$where = '';
$params = [];
$types = '';

if (!empty($busqueda)) {
    $where = "WHERE 
        v.Nombre_Variedad LIKE ? OR 
        CONCAT(o.Nombre, ' ', o.Apellido_P) LIKE ? OR 
        DATE(p.Fecha_Siembra) LIKE ? OR 
        (p.Origen = 'Multiplicacion' AND 'Etapa 2 - Multiplicación' LIKE ?) OR 
        (p.Origen = 'Enraizamiento' AND 'Etapa 3 - Enraizamiento' LIKE ?)";

    $params = ["%$busqueda%", "%$busqueda%", "%$busqueda%", "%$busqueda%", "%$busqueda%"];
    $types = "sssss";
}

$sqlGroup = "
    GROUP BY Fecha, Etapa, v.ID_Variedad, o.ID_Operador
    ORDER BY Fecha DESC, Etapa, Variedad
";

$stmt = $conn->prepare($sqlBase . " " . $where . " " . $sqlGroup);
if (!$stmt) {
    die("Error al preparar la consulta: " . $conexion->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$resultado = $stmt->get_result();

while ($fila = $resultado->fetch_assoc()) {
    $plantacion[] = $fila;
}

$stmt->close();

// Calcular totales
$totalGeneralBrotes = 0;
$totalGeneralTuppers = 0;
$totalesPorOperador = [];
$totalesPorVariedad = [];

foreach ($plantacion as $fila) {
    $totalGeneralBrotes += $fila['Total_Brotes'];
    $totalGeneralTuppers += $fila['Total_Tuppers'];
    
    // Totales por operador
    $operador = $fila['Operador'];
    if (!isset($totalesPorOperador[$operador])) {
        $totalesPorOperador[$operador] = [
            'brotes' => 0,
            'tuppers' => 0
        ];
    }
    $totalesPorOperador[$operador]['brotes'] += $fila['Total_Brotes'];
    $totalesPorOperador[$operador]['tuppers'] += $fila['Total_Tuppers'];
    
    // Totales por variedad
    $variedad = $fila['Variedad'];
    if (!isset($totalesPorVariedad[$variedad])) {
        $totalesPorVariedad[$variedad] = [
            'brotes' => 0,
            'tuppers' => 0
        ];
    }
    $totalesPorVariedad[$variedad]['brotes'] += $fila['Total_Brotes'];
    $totalesPorVariedad[$variedad]['tuppers'] += $fila['Total_Tuppers'];
}

// Ordenar totales
arsort($totalesPorOperador);
arsort($totalesPorVariedad);

?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Director General</title>
  <link rel="stylesheet" href="../style.css?v=<?= time() ?>" />
  
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS           = <?= $nowTs           * 1000 ?>;
  </script>
  <!-- librería jsPDF -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
  <div class="contenedor-pagina panel-admin">
    <header>
      <div class="encabezado">
        <img src="../logoplantulas.png"
             alt="Logo"
             width="130" height="124"
             style="cursor:<?= $volver1 ? 'pointer' : 'default' ?>"
             <?= $volver1 ? "onclick=\"location.href='../rol_administrador/volver_rol.php'\"" : '' ?>>
        <div>
          <h2>Produccion</h2>
          <p>Siembra diaria etapa 2 y 3.</p>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="location.href='dashboard_director.php'">🏠 Volver al Inicio</button>
            </div>
          </div>
        </nav>
      </div>
    </header>

<main class="container mt-4">

  <form method="GET" class="mb-4" style="max-width: 600px;">
    <div class="input-group">
      <span class="input-group-text bg-primary text-white">
        <i class="bi bi-search"></i>
      </span>
      <input type="text" class="form-control" name="busqueda" id="busqueda"
            placeholder="Buscar por variedad, operador, etapa o fecha..."
            value="<?= htmlspecialchars($_GET['busqueda'] ?? '') ?>">
      <button class="btn btn-outline-secondary" type="submit">
        Buscar
      </button>
      <button class="btn btn-outline-danger" type="button" id="limpiar-busqueda">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
  </form>

    <!-- Sección de Resúmenes -->
  <div class="row mb-4">
    <!-- Resumen por Operador -->
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-header bg-primary text-white">
          <i class="bi bi-person"></i> Producción por Operador
        </div>
        <div class="card-body">
          <ul class="list-group list-group-flush">
            <?php foreach ($totalesPorOperador as $operador => $totales): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <?= htmlspecialchars($operador) ?>
              <span class="badge bg-primary rounded-pill">
                <?= number_format($totales['brotes']) ?> brotes
              </span>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    
    <!-- Resumen por Variedad -->
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-header bg-success text-white">
          <i class="bi bi-tree"></i> Producción por Variedad
        </div>
        <div class="card-body">
          <ul class="list-group list-group-flush">
            <?php foreach ($totalesPorVariedad as $variedad => $totales): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <?= htmlspecialchars($variedad) ?>
              <span class="badge bg-success rounded-pill">
                <?= number_format($totales['brotes']) ?> brotes
              </span>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    
    <!-- Resumen General -->
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-header bg-info text-white">
          <i class="bi bi-bar-chart"></i> Resumen General
        </div>
        <div class="card-body">
          <div class="d-flex flex-column">
            <div class="mb-3">
              <h5 class="card-title">Total Producción</h5>
              <p class="display-6"><?= number_format($totalGeneralBrotes) ?> brotes</p>
            </div>
            <div>
              <h5 class="card-title">Total Tuppers</h5>
              <p class="display-6"><?= number_format($totalGeneralTuppers) ?></p>
            </div>
            <div class="mt-2">
              <span class="text-muted">Promedio: <?= $totalGeneralTuppers > 0 ? round($totalGeneralBrotes / $totalGeneralTuppers, 1) : 0 ?> brotes/tupper</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
    <div class="card card-lista">
        <h2></h2>
        <div class="table-responsive" >
            <table class="table table-striped table-hover" >
                <thead class="sticky-top">
                    <tr>
                        <th>Fecha</th>
                        <th>Etapa</th>
                        <th>Variedad</th>
                        <th>Operador</th>
                        <th>Cantidad de Lotes</th>
                        <th>Total de Brotes</th>
                        <th>Total de Tuppers</th>
                        <th>Brotes por Tuppers</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plantacion)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <i class="bi bi-people text-muted" style="font-size: 2rem;"></i>
                            <p class="mt-2">No se encontraron datos de plantación <?= isset($_GET['busqueda']) && !empty($_GET['busqueda']) ? 'con el criterio de búsqueda' : '' ?></p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($plantacion as $fila): ?>
                        <tr>
                            <td><?= htmlspecialchars($fila['Fecha']) ?></td>
                            <td><?= htmlspecialchars($fila['Etapa']) ?></td>
                            <td><?= htmlspecialchars($fila['Variedad']) ?></td>
                            <td><?= htmlspecialchars($fila['Operador']) ?></td>
                            <td><?= htmlspecialchars($fila['Cantidad_Lotes']) ?></td>
                            <td><?= htmlspecialchars($fila['Total_Brotes']) ?></td>
                            <td><?= htmlspecialchars($fila['Total_Tuppers']) ?></td>
                            <td><?= htmlspecialchars($fila['Brotes_por_Tupper']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

    <footer>
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

<script>
  function rememberCard(id) {
    sessionStorage.setItem('lastCard', id);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const limpiarBtn = document.getElementById('limpiar-busqueda');
    const inputBusqueda = document.getElementById('busqueda');

    if (limpiarBtn && inputBusqueda) {
      limpiarBtn.addEventListener('click', () => {
        inputBusqueda.value = '';
        window.location.href = window.location.pathname;
      });
    }

    const last = sessionStorage.getItem('lastCard');
    if (last) {
      const target = document.querySelector(`.dashboard-grid .card[data-card-id="${last}"]`);
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.classList.add('highlight');
      }
    }
  });
</script>

  <!-- Modal de advertencia de sesión -->
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
      document.getElementById('keepalive-btn').addEventListener('click', cerrarModalYReiniciar);
    }

    function cerrarModalYReiniciar() {
      const modal = document.getElementById('session-warning');
      if (modal) modal.remove();
      reiniciarTimers();
      fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {});
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

  // Inicializar jsPDF
  const { jsPDF } = window.jspdf;

  // Función para generar el PDF
  function generarPDF() {
      // Crear instancia de jsPDF
      const doc = new jsPDF({
          orientation: 'landscape'
      });
      
      // Título del documento
      const title = "Reporte de Producción - Plantación";
      const date = new Date().toLocaleDateString();
      const subtitle = `Generado el ${date}`;
      
      // Agregar título
      doc.setFontSize(18);
      doc.text(title, 14, 20);
      doc.setFontSize(12);
      doc.text(subtitle, 14, 28);
      
      // Agregar resumenes
      doc.setFontSize(14);
      doc.text("Resumen General", 14, 40);
      
      // Datos del resumen general
      const generalData = [
          ["Total Producción", "<?= number_format($totalGeneralBrotes) ?> brotes"],
          ["Total Tuppers", "<?= number_format($totalGeneralTuppers) ?>"],
          ["Promedio", "<?= $totalGeneralTuppers > 0 ? round($totalGeneralBrotes / $totalGeneralTuppers, 1) : 0 ?> brotes/tupper"]
      ];
      
      doc.autoTable({
          startY: 45,
          head: [['Métrica', 'Valor']],
          body: generalData,
          theme: 'grid',
          headStyles: { fillColor: [41, 128, 185] }
      });
      
      // Agregar tabla principal
      doc.setFontSize(14);
      doc.text("Detalle de Producción", 14, doc.autoTable.previous.finalY + 15);
      
      // Preparar datos de la tabla
      const tableData = [
          ['Fecha', 'Etapa', 'Variedad', 'Operador', 'Lotes', 'Brotes', 'Tuppers', 'Brotes/Tupper']
      ];
      
      <?php foreach ($plantacion as $fila): ?>
          tableData.push([
              '<?= htmlspecialchars($fila['Fecha']) ?>',
              '<?= htmlspecialchars($fila['Etapa']) ?>',
              '<?= htmlspecialchars($fila['Variedad']) ?>',
              '<?= htmlspecialchars($fila['Operador']) ?>',
              '<?= htmlspecialchars($fila['Cantidad_Lotes']) ?>',
              '<?= htmlspecialchars($fila['Total_Brotes']) ?>',
              '<?= htmlspecialchars($fila['Total_Tuppers']) ?>',
              '<?= htmlspecialchars($fila['Brotes_por_Tupper']) ?>'
          ]);
      <?php endforeach; ?>
      
      // Crear tabla
      doc.autoTable({
          startY: doc.autoTable.previous.finalY + 20,
          head: [tableData[0]],
          body: tableData.slice(1),
          theme: 'grid',
          headStyles: { fillColor: [41, 128, 185] },
          margin: { horizontal: 5 },
          styles: { fontSize: 8 },
          columnStyles: {
              0: { cellWidth: 20 },
              1: { cellWidth: 30 },
              2: { cellWidth: 30 },
              3: { cellWidth: 30 },
              4: { cellWidth: 15 },
              5: { cellWidth: 15 },
              6: { cellWidth: 15 },
              7: { cellWidth: 20 }
          }
      });
      
      // Guardar el PDF
      doc.save(`Reporte_Produccion_${date.replace(/\//g, '-')}.pdf`);
  }

  // Agregar botón de generación de PDF
  document.addEventListener('DOMContentLoaded', function() {
      // Crear botón
        const pdfBtn = document.createElement('button');
        pdfBtn.className = 'btn btn-danger ms-2';
        pdfBtn.type = 'button'; // Importante para que no envíe el formulario
        pdfBtn.innerHTML = '<i class="bi bi-file-earmark-pdf"></i> Generar PDF';
        pdfBtn.onclick = generarPDF;
        
        // Insertar el botón en el grupo de inputs de búsqueda
        const searchForm = document.querySelector('form.mb-4');
        if (searchForm) {
            const inputGroup = searchForm.querySelector('.input-group');
            if (inputGroup) {
                // Insertar después del botón de limpiar
                const clearBtn = inputGroup.querySelector('#limpiar-busqueda');
                if (clearBtn) {
                    clearBtn.insertAdjacentElement('afterend', pdfBtn);
                } else {
                    // Si no encuentra el botón de limpiar, lo añade al final
                    inputGroup.appendChild(pdfBtn);
                }
            }
        }
    });


  </script>
</body>
</html>
