<?php
/* dashboard_tuppers.php  ─ Inventario unificado de tuppers ---------------- */
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__.'/../session_manager.php';
require_once __DIR__.'/../db.php';

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

/* ─── Seguridad ─────────────────────────────────────────────────────────── */
if(!isset($_SESSION['ID_Operador'])){ header('Location: ../login.php'); exit; }
if((int)$_SESSION['Rol']!==4){ echo "<p class='error'>⚠️ Acceso denegado, solo Supervisora de Incubadora.</p>"; exit; }

/* ─── Parámetros del modal de expiración ───────── */
$sessionLifetime = 60*3;  // 3 min
$warningOffset   = 60;    // 1 min antes
$nowTs           = time();

/* ─── Consulta UNIÓN (ECAS + Multiplicación + Enraizamiento) ────────────── */
$sql = "
WITH saldo_mov AS (                    -- saldo neto x lote
  SELECT Etapa,
         ID_Etapa,
         /* tupper-saldo (ya lo tenías) */
         SUM(CASE
               WHEN TipoMovimiento='alta_inicial'   THEN  Tuppers
               WHEN TipoMovimiento IN('reserva_lavado','merma')
                                                    THEN -Tuppers
               WHEN TipoMovimiento='ajuste_manual'  THEN  Tuppers
             END)                     AS Tup_Disp,

         /* **nuevo** → saldo de BROTES */
         SUM(CASE
               WHEN TipoMovimiento='alta_inicial'   THEN  Brotes
               WHEN TipoMovimiento IN('reserva_lavado','merma')
                                                    THEN -Brotes
               WHEN TipoMovimiento='ajuste_manual'  THEN  Brotes
             END)                     AS Bro_Disp
  FROM movimientos_proyeccion
  GROUP BY Etapa, ID_Etapa
),

pl_salidas AS (                        -- lo que ya salió a lavado
  SELECT Etapa,
         ID_Etapa,
         SUM(Tuppers_Proyectados) AS Tup_PL,
         SUM(Brotes_Proyectados)  AS Bro_PL          -- ← nuevo
  FROM proyecciones_lavado
  WHERE Estado_Flujo IN ('acomodados','asignado_lavado',
                         'lavado','enviado_tenancingo')
  GROUP BY Etapa, ID_Etapa
)

/* ==================== UNIÓN DE INVENTARIO ==================== */
SELECT * FROM (
  /* ── ECAS · Disección ─────────────────────────────── */
  SELECT 'ECAS'      Etapa,
         'Disección' SubEtapa,
         v.Codigo_Variedad,
         v.Nombre_Variedad,
         d.Fecha_Diseccion       Fecha,
         d.Tuppers_Llenos        Tup_Llenos,
         d.Tuppers_Disponibles   Tup_Disp,
         d.Tuppers_Llenos*12     Bro_Disp          -- aprox. 12 brotes por tupper
  FROM diseccion_hojas_ecas d
  JOIN siembra_ecas s  ON s.ID_Siembra = d.ID_Siembra
  JOIN variedades  v  USING(ID_Variedad)

  UNION ALL
  /* ── ECAS · División ─────────────────────────────── */
  SELECT 'ECAS','División',
         v.Codigo_Variedad,
         v.Nombre_Variedad,
         dv.Fecha_Division,
         dv.Tuppers_Llenos,
         dv.Tuppers_Disponibles,
         dv.Brotes_Totales                         -- ← esta columna sí existe
  FROM division_ecas dv
  JOIN siembra_ecas s ON s.ID_Siembra = dv.ID_Siembra
  JOIN variedades  v USING(ID_Variedad)

  UNION ALL
  /* ── Multiplicación ─────────────────────────────── */
  SELECT 'Multiplicación',NULL,
         v.Codigo_Variedad,
         v.Nombre_Variedad,
         m.Fecha_Siembra,
         m.Tuppers_Llenos,
         GREATEST(COALESCE(sm.Tup_Disp,0) - COALESCE(pl.Tup_PL,0),0),
         GREATEST(COALESCE(sm.Bro_Disp,0) - COALESCE(pl.Bro_PL,0),0)
  FROM multiplicacion m
  JOIN variedades   v USING(ID_Variedad)
  LEFT JOIN saldo_mov  sm ON sm.Etapa='multiplicacion'
                         AND sm.ID_Etapa = m.ID_Multiplicacion
  LEFT JOIN pl_salidas pl ON pl.Etapa='multiplicacion'
                         AND pl.ID_Etapa = m.ID_Multiplicacion

  UNION ALL
  /* ── Enraizamiento ─────────────────────────────── */
  SELECT 'Enraizamiento',NULL,
         v.Codigo_Variedad,
         v.Nombre_Variedad,
         e.Fecha_Siembra,
         e.Tuppers_Llenos,
         GREATEST(COALESCE(se.Tup_Disp,0) - COALESCE(pe.Tup_PL,0),0),
         GREATEST(COALESCE(se.Bro_Disp,0) - COALESCE(pe.Bro_PL,0),0)
  FROM enraizamiento e
  JOIN variedades   v USING(ID_Variedad)
  LEFT JOIN saldo_mov  se ON se.Etapa='enraizamiento'
                         AND se.ID_Etapa = e.ID_Enraizamiento
  LEFT JOIN pl_salidas pe ON pe.Etapa='enraizamiento'
                         AND pe.ID_Etapa = e.ID_Enraizamiento
) AS inv
ORDER BY Etapa, Fecha DESC";

$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Dashboard de Tuppers</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../style.css?v=<?= time() ?>">
  <!-- Bootstrap + DataTables -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <link href="https://cdn.datatables.net/v/bs5/dt-2.0.6/r-3.0.1/b-3.0.1/datatables.min.css" rel="stylesheet">
  <style>
    .badge-ECAS{background:#0d6efd}
    .badge-Multiplicacion{background:#ffc107;color:#000}
    .badge-Enraizamiento{background:#20c997}
  </style>
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime*1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset*1000 ?>;
    let   START_TS         = <?= $nowTs*1000 ?>;
  </script>
</head>
<body>
<div class="contenedor-pagina d-flex flex-column min-vh-100">
  <header>
    <div class="encabezado">
      <a class="navbar-brand"><img src="../logoplantulas.png" alt="Logo" width="130" height="124"></a>
      <h2>Inventario de Tuppers</h2>
    </div>
    <div class="barra-navegacion">
      <nav class="navbar bg-body-tertiary">
        <div class="container-fluid">
          <div class="Opciones-barra">
            <button onclick="window.location.href='dashboard_supervisora.php'">🏠 Volver al Inicio</button>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <main class="container-fluid mt-3 flex-grow-1">
    <table id="tabla" class="table table-sm table-bordered table-hover w-100">
      <thead class="table-light">
        <tr>
          <th>Etapa</th><th>Sub-etapa</th><th>Variedad</th><th>Fecha</th>
<th class="text-end">Tuppers Llenos</th>
<th class="text-end">Tuppers Disponibles</th>
<th class="text-end">Brotes Disponibles</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </main>

  <footer class="text-center py-3 mt-5">&copy; <?= date('Y') ?> PLANTAS AGRODEX</footer>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-2.0.6/r-3.0.1/b-3.0.1/datatables.min.js"></script>
<script>
const data = <?= json_encode($rows, JSON_NUMERIC_CHECK) ?>;

new DataTable('#tabla',{
  data,
  columns:[
    {data:'Etapa',
     render:d=>`<span class="badge badge-${d.replace(/ó/g,'o')}">${d}</span>`},

    {data:'SubEtapa', defaultContent:'—'},

    {data:null,       // Variedad
     render:r=>`${r.Codigo_Variedad} – ${r.Nombre_Variedad}`},

    {data:'Fecha',
     render:d=>new Date(d).toLocaleDateString('es-MX')},

    /* Usa LOS ALIAS CORRECTOS ▼ */
    {data:'Tup_Llenos', className:'text-end'},   // antes ‘Llenos’
    {data:'Tup_Disp',   className:'text-end'},   // antes ‘Disponibles’
    {data:'Bro_Disp',   className:'text-end'}    // nuevo
  ],

  responsive:true,
  rowGroup:{dataSrc:'Etapa'},
  order:[[0,'asc'],[3,'desc']],
  paging:false,
  searching:true,
  buttons:['copy','excel'],
  language:{
    search:'Buscar:',
    searchPlaceholder:'Buscar…',
    info:'Mostrando _START_ a _END_ de _TOTAL_ registros',
    infoEmpty:'Sin registros para mostrar'
  },
  dom:'Bfrtip'
});
</script>

<!-- Modal de expiración de sesión (el mismo que en tus otras páginas) -->
<script>
(function(){
  let modalShown=false,warningTimer,expireTimer;
  function showModal(){
    modalShown=true;
    document.body.insertAdjacentHTML('beforeend',`
      <div id="session-warning" class="modal-overlay">
        <div class="modal-box">
          <p>Tu sesión va a expirar pronto. ¿Deseas mantenerla activa?</p>
          <button id="keepalive-btn" class="btn-keepalive">Seguir activo</button>
        </div>
      </div>`);
    document.getElementById('keepalive-btn').addEventListener('click',cerrarModalYReiniciar);
  }
  function cerrarModalYReiniciar(){
    document.getElementById('session-warning')?.remove();
    reiniciarTimers();
    fetch('../keepalive.php',{credentials:'same-origin'}).catch(()=>{});
  }
  function reiniciarTimers(){
    START_TS=Date.now(); modalShown=false;
    clearTimeout(warningTimer); clearTimeout(expireTimer); scheduleTimers();
  }
  function scheduleTimers(){
    const warnAfter=SESSION_LIFETIME-WARNING_OFFSET;
    warningTimer=setTimeout(showModal,warnAfter);
    expireTimer=setTimeout(()=>window.location.href=
      '../login.php?mensaje='+encodeURIComponent('Sesión caducada por inactividad'),
      SESSION_LIFETIME);
  }
  ['click','keydown'].forEach(evt=>document.addEventListener(evt,()=>{
    reiniciarTimers(); fetch('../keepalive.php',{credentials:'same-origin'}).catch(()=>{});
  }));
  scheduleTimers();
})();
</script>
</body>
</html>
