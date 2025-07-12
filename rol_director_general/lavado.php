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

// Procesar búsqueda
$busqueda = $_GET['busqueda'] ?? '';
$datos = [];

// Construir consultas base
$sqlMultiplicacion = "
    SELECT
        m.ID_Multiplicacion AS ID,
        m.ID_Lote,
        l.ID_Variedad,
        v.Nombre_Variedad,
        COALESCE(m.Tuppers_Reservados_Lavado, 0) AS Cantidad,
        'Multiplicación' AS Etapa,
        m.Fecha_Siembra,
        o.Nombre AS Operador,
        CASE 
            WHEN COALESCE(m.Tuppers_Reservados_Lavado, 0) = 0 THEN 'No requiere lavado'
            WHEN COALESCE(m.Tuppers_Reservados_Lavado, 0) > 0 THEN 'Por Lavar'
            ELSE 'Estado desconocido'
        END AS Estado
    FROM
        multiplicacion m
    LEFT JOIN lotes l ON m.ID_Lote = l.ID_Lote
    LEFT JOIN variedades v ON l.ID_Variedad = v.ID_Variedad
    LEFT JOIN operadores o ON l.ID_Operador = o.ID_Operador
";

$sqlEnraizamiento = "
    SELECT
        e.ID_Enraizamiento AS ID,
        e.ID_Lote,
        l.ID_Variedad,
        v.Nombre_Variedad,
        COALESCE(e.Tuppers_Organizados_Lavado, 0) AS Cantidad,
        'Enraizamiento' AS Etapa,
        e.Fecha_Siembra,
        o.Nombre AS Operador,
        CASE 
            WHEN COALESCE(e.Tuppers_Organizados_Lavado, 0) = 0 THEN 'No requiere lavado'
            WHEN COALESCE(e.Tuppers_Organizados_Lavado, 0) > 0 THEN 'Por Lavar'
            ELSE 'Estado desconocido'
        END AS Estado
    FROM
        enraizamiento e
    LEFT JOIN lotes l ON e.ID_Lote = l.ID_Lote
    LEFT JOIN variedades v ON l.ID_Variedad = v.ID_Variedad
    LEFT JOIN operadores o ON l.ID_Operador = o.ID_Operador
";

// Aplicar filtros solo si hay búsqueda Y no es por etapa
if (!empty($busqueda)) {
    $busquedaLower = mb_strtolower($busqueda, 'UTF-8');
    
    // Verificar si es búsqueda por etapa
    $esMultiplicacion = (strpos($busquedaLower, 'multiplicación') !== false) || 
                       (strpos($busquedaLower, 'multiplicacion') !== false);
    $esEnraizamiento = (strpos($busquedaLower, 'enraizamiento') !== false);
    
    if (!$esMultiplicacion && !$esEnraizamiento) {
        // Búsqueda normal (por variedad, operador o fecha)
        $filtro = " WHERE (v.Nombre_Variedad LIKE ? OR o.Nombre LIKE ? OR DATE(Fecha_Siembra) LIKE ?)";
        $params = ["%$busqueda%", "%$busqueda%", "%$busqueda%"];
        $types = "sss";
        
        $sqlMultiplicacion .= $filtro;
        $sqlEnraizamiento .= $filtro;
    } else {
        // Búsqueda por etapa - no aplicar filtro adicional
        $params = [];
        $types = "";
    }
}

// Ordenar ambas consultas
$sqlMultiplicacion .= " ORDER BY Fecha_Siembra DESC";
$sqlEnraizamiento .= " ORDER BY Fecha_Siembra DESC";

// Función para ejecutar consultas
function ejecutarConsulta($conn, $sql, $params, $types, &$datos) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error al preparar la consulta: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $datos[] = $fila;
    }

    $stmt->close();
}

// Ejecutar consultas
ejecutarConsulta($conn, $sqlMultiplicacion, $params ?? [], $types ?? "", $datos);
ejecutarConsulta($conn, $sqlEnraizamiento, $params ?? [], $types ?? "", $datos);

// Filtrar por etapa si es necesario
if (!empty($busqueda)) {
    $busquedaLower = mb_strtolower($busqueda, 'UTF-8');
    $esMultiplicacion = (strpos($busquedaLower, 'multiplicación') !== false) || 
                       (strpos($busquedaLower, 'multiplicacion') !== false);
    $esEnraizamiento = (strpos($busquedaLower, 'enraizamiento') !== false);
    
    if ($esMultiplicacion || $esEnraizamiento) {
        $datos = array_filter($datos, function($item) use ($esMultiplicacion, $esEnraizamiento) {
            if ($esMultiplicacion && $esEnraizamiento) {
                return true; // Mostrar ambos
            } elseif ($esMultiplicacion) {
                return $item['Etapa'] === 'Multiplicación';
            } elseif ($esEnraizamiento) {
                return $item['Etapa'] === 'Enraizamiento';
            }
            return true;
        });
    }
}



?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Director General - Producción</title>
  <link rel="stylesheet" href="../style.css?v=<?= time() ?>" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS           = <?= $nowTs           * 1000 ?>;
  </script>
  <!-- Librerías para generar PDF -->
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
          <h2>Producción</h2>
          <p>Control de lavado - Etapas 2 y 3</p>
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
                placeholder="Buscar por variedad, operador, etapa o estado..."
                value="<?= htmlspecialchars($busqueda) ?>">
          <button class="btn btn-outline-secondary" type="submit">
            Buscar
          </button>
          <button class="btn btn-outline-danger" type="button" id="limpiar-busqueda">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
      </form>

      <div class="card card-lista">
        <h2>Control de Lavado</h2>
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead class="sticky-top">
              <tr>
                <th>ID</th>
                <th>Fecha Siembra</th>
                <th>Etapa</th>
                <th>Variedad</th>
                <th>Operador</th>
                <th>Tuppers</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($datos)): ?>
                <tr>
                  <td colspan="7" class="text-center py-4">
                    <i class="bi bi-inbox text-muted" style="font-size: 2rem;"></i>
                    <p class="mt-2">No hay registros de lavado</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($datos as $fila): ?>
                  <tr>
                    <td><?= htmlspecialchars($fila['ID']) ?></td>
                    <td><?= htmlspecialchars($fila['Fecha_Siembra']) ?></td>
                    <td><?= htmlspecialchars($fila['Etapa'] ?? '') ?></td>
                    <td><?= htmlspecialchars($fila['Nombre_Variedad'] ?? '') ?></td>
                    <td><?= htmlspecialchars($fila['Operador'] ?? '') ?></td>
                    <td><?= htmlspecialchars($fila['Cantidad']) ?></td>
                    <td>
                      <span class="badge 
                        <?= match($fila['Estado']) {
                            'Por Lavar' => 'bg-warning text-dark',
                            'No requiere lavado' => 'bg-secondary',
                            default => 'bg-light text-dark'
                        } ?>">
                        <?= htmlspecialchars($fila['Estado']) ?>
                      </span>
                    </td>
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
    document.addEventListener('DOMContentLoaded', () => {
      const limpiarBtn = document.getElementById('limpiar-busqueda');
      const inputBusqueda = document.getElementById('busqueda');

      if (limpiarBtn && inputBusqueda) {
        limpiarBtn.addEventListener('click', () => {
          inputBusqueda.value = '';
          window.location.href = window.location.pathname;
        });
      }
    });
    // Función para generar el PDF
    function generarPDFLavado() {
        try {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape',
                unit: 'mm'
            });
            
            // Título del documento
            const title = "Reporte de Control de Lavado";
            const date = new Date().toLocaleDateString();
            const subtitle = `Generado el ${date}`;
            
            // Agregar título
            doc.setFontSize(18);
            doc.text(title, 105, 15, { align: 'center' });
            doc.setFontSize(12);
            doc.text(subtitle, 105, 22, { align: 'center' });
            
            // Preparar datos de la tabla
            const tableData = [
                ['ID', 'Fecha Siembra', 'Etapa', 'Variedad', 'Operador', 'Tuppers', 'Estado']
            ];
            
            <?php foreach ($datos as $fila): ?>
                tableData.push([
                    '<?= htmlspecialchars($fila['ID']) ?>',
                    '<?= htmlspecialchars($fila['Fecha_Siembra']) ?>',
                    '<?= htmlspecialchars($fila['Etapa'] ?? '') ?>',
                    '<?= htmlspecialchars($fila['Nombre_Variedad'] ?? '') ?>',
                    '<?= htmlspecialchars($fila['Operador'] ?? '') ?>',
                    '<?= htmlspecialchars($fila['Cantidad']) ?>',
                    '<?= htmlspecialchars($fila['Estado']) ?>'
                ]);
            <?php endforeach; ?>
            
            // Crear tabla
            doc.autoTable({
                startY: 30,
                head: [tableData[0]],
                body: tableData.slice(1),
                theme: 'grid',
                headStyles: { 
                    fillColor: [41, 128, 185],
                    textColor: [255, 255, 255]
                },
                margin: { horizontal: 14 },
                styles: { 
                    fontSize: 8,
                    cellPadding: 2
                },
                columnStyles: {
                    0: { cellWidth: 15 },
                    1: { cellWidth: 20 },
                    2: { cellWidth: 20 },
                    3: { cellWidth: 30 },
                    4: { cellWidth: 30 },
                    5: { cellWidth: 15 },
                    6: { cellWidth: 25 }
                },
                didDrawPage: function(data) {
                    // Agregar número de página
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.setFontSize(10);
                    doc.text(`Página ${data.pageNumber} de ${pageCount}`, 200, 200, {
                        align: 'right'
                    });
                }
            });
            
            // Guardar el PDF
            doc.save(`Control_Lavado_${date.replace(/\//g, '-')}.pdf`);
            
        } catch (error) {
            console.error('Error al generar PDF:', error);
            alert('Error al generar el PDF: ' + error.message);
        }
    }

    // Configurar el botón de PDF en el área de búsqueda
    document.addEventListener('DOMContentLoaded', function() {
        // Crear botón
        const pdfBtn = document.createElement('button');
        pdfBtn.className = 'btn btn-danger ms-2';
        pdfBtn.type = 'button';
        pdfBtn.innerHTML = '<i class="bi bi-file-earmark-pdf"></i> Generar PDF';
        
        // Agregar evento con feedback visual
        pdfBtn.addEventListener('click', function() {
            // Cambiar apariencia durante la generación
            const originalHTML = this.innerHTML;
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generando...';
            this.disabled = true;
            
            // Pequeño retraso para permitir la actualización visual
            setTimeout(() => {
                try {
                    generarPDFLavado();
                } catch (error) {
                    console.error('Error al generar PDF:', error);
                    alert('Error al generar el PDF: ' + error.message);
                } finally {
                    // Restaurar botón
                    this.innerHTML = originalHTML;
                    this.disabled = false;
                }
            }, 100);
        });
        
        // Insertar el botón en el formulario de búsqueda
        const searchForm = document.querySelector('form.mb-4');
        if (searchForm) {
            const inputGroup = searchForm.querySelector('.input-group');
            if (inputGroup) {
                // Insertar después del botón de limpiar
                const clearBtn = inputGroup.querySelector('#limpiar-busqueda');
                if (clearBtn) {
                    clearBtn.insertAdjacentElement('afterend', pdfBtn);
                } else {
                    inputGroup.appendChild(pdfBtn);
                }
            }
        }
    });
  </script>
</body>
</html>