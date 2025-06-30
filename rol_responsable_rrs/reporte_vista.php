<?php
require __DIR__ . '/../session_manager.php';
require __DIR__ . '/../db.php';

if (!isset($_SESSION['ID_Operador']) || (int)$_SESSION['Rol'] !== 8) {
    http_response_code(403);
    exit('Acceso denegado');
}

$operador   = trim($_GET['operador']   ?? '');
$fechaDesde = $_GET['desde']          ?? '';
$fechaHasta = $_GET['hasta']          ?? '';

$conn->set_charset("utf8");
$opEsc = $conn->real_escape_string($operador);
$where = "WHERE 1=1";
if ($operador   !== '') $where .= " AND r.Operador_Original = '{$opEsc}'";
if ($fechaDesde !== '') $where .= " AND r.Fecha >= '{$fechaDesde}'";
if ($fechaHasta !== '') $where .= " AND r.Fecha <= '{$fechaHasta}'";

$sql = "
SELECT * FROM (
  SELECT 'Multiplicación' AS Etapa,
         M.ID_Multiplicacion AS ID,
         CONCAT(O.Nombre,' ',O.Apellido_P,' ',O.Apellido_M) AS Operador_Original,
         CONCAT(Oc.Nombre,' ',Oc.Apellido_P,' ',Oc.Apellido_M) AS Operador_Consolida,
         CONCAT(V.Codigo_Variedad,' – ',V.Nombre_Variedad) AS Variedad,
         COALESCE(NULLIF(V.Color, ''), 'S/D') AS Color,
         M.Fecha_Siembra AS Fecha,
         M.Cantidad_Dividida AS Cantidad
  FROM multiplicacion M
  JOIN operadores O ON M.Operador_Responsable = O.ID_Operador
  JOIN consolidacion_log CL ON CL.ID_Multiplicacion = M.ID_Multiplicacion
  JOIN operadores Oc ON CL.ID_Operador = Oc.ID_Operador
  JOIN variedades V ON M.ID_Variedad = V.ID_Variedad
  WHERE M.Estado_Revision = 'Consolidado'

  UNION ALL

  SELECT 'Enraizamiento' AS Etapa,
         E.ID_Enraizamiento AS ID,
         CONCAT(O.Nombre,' ',O.Apellido_P,' ',O.Apellido_M) AS Operador_Original,
         CONCAT(Oc2.Nombre,' ',Oc2.Apellido_P,' ',Oc2.Apellido_M) AS Operador_Consolida,
         CONCAT(V2.Codigo_Variedad,' – ',V2.Nombre_Variedad) AS Variedad,
         COALESCE(NULLIF(V2.Color, ''), 'S/D') AS Color,
         E.Fecha_Siembra AS Fecha,
         E.Cantidad_Dividida AS Cantidad
  FROM enraizamiento E
  JOIN operadores O ON E.Operador_Responsable = O.ID_Operador
  JOIN consolidacion_log CL2 ON CL2.ID_Enraizamiento = E.ID_Enraizamiento
  JOIN operadores Oc2 ON CL2.ID_Operador = Oc2.ID_Operador
  JOIN variedades V2 ON E.ID_Variedad = V2.ID_Variedad
  WHERE E.Estado_Revision = 'Consolidado'
) AS r
{$where}
ORDER BY r.Fecha DESC
";

$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Vista de Reporte Consolidado</title>
  <style>
    body {
      font-family: sans-serif;
      margin: 2rem;
      color: #333;
    }
    h2 {
      text-align: center;
      margin-bottom: 2rem;
    }
    table {
      border-collapse: collapse;
      width: 100%;
      font-size: 13px;
    }
    th, td {
      border: 1px solid #ccc;
      padding: 6px;
      text-align: left;
    }
    th {
      background-color: #45814d;
      color: white;
    }
    tr:nth-child(even) {
      background: #f2f2f2;
    }

    .acciones {
      margin: 1.5rem 0;
      text-align: center;
    }
    .acciones button,
    .acciones a {
      background-color: #45814d;
      color: white;
      padding: 8px 16px;
      margin: 0 5px;
      text-decoration: none;
      border: none;
      border-radius: 5px;
      cursor: pointer;
    }

    @media print {
      .acciones {
        display: none;
      }
      body {
        margin: 0;
        font-size: 11px;
      }
      table {
        page-break-inside: auto;
      }
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
      }
    }
  </style>
</head>
<body>

  <h2>Reportes Consolidados</h2>

  <div class="acciones">
    <button onclick="window.close()">🔙 Volver</button>
  </div>

  <table>
    <thead>
      <tr>
        <th>Etapa</th>
        <th>ID</th>
        <th>Operador Original</th>
        <th>Consolidado Por</th>
        <th>Variedad</th>
        <th>Color</th>
        <th>Fecha de Siembra</th>
        <th>Cantidad</th>
      </tr>
    </thead>
    <tbody>
      <?php while ($r = $result->fetch_assoc()): ?>
      <tr>
        <td><?= $r['Etapa'] ?></td>
        <td><?= $r['ID'] ?></td>
        <td><?= htmlspecialchars($r['Operador_Original']) ?></td>
        <td><?= htmlspecialchars($r['Operador_Consolida']) ?></td>
        <td><?= htmlspecialchars($r['Variedad']) ?></td>
        <td><?= htmlspecialchars($r['Color']) ?></td>
        <td><?= $r['Fecha'] ?></td>
        <td><?= $r['Cantidad'] ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>

</body>
</html>
