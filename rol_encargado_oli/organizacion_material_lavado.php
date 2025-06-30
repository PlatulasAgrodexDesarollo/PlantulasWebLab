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

if ((int) $_SESSION['Rol'] !== 10) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado de Organización y Limpieza de Incubadora.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_enr'])) {
    $id   = intval($_POST['id_enr']);
    $org  = intval($_POST['organizados']);
    $cont = ($_POST['hubo_contaminados'] === 'Si') 
            ? intval($_POST['contaminados']) 
            : 0;
    $user = $_SESSION['ID_Operador'];
    $hoy  = date('Y-m-d');

    // 1) Actualizar organizados
    $upd = $conn->prepare("
      UPDATE enraizamiento
         SET Tuppers_Organizados_Lavado = COALESCE(Tuppers_Organizados_Lavado,0) + ?
       WHERE ID_Enraizamiento = ?
    ");
    $upd->bind_param('ii', $org, $id);
    $upd->execute();
    $upd->close();

    // 2) Registrar pérdidas si hubo contaminados
    if ($cont > 0) {
        $ins = $conn->prepare("
          INSERT INTO perdidas_laboratorio
            (ID_Entidad, Tipo_Entidad, Fecha_Perdida,
             Tuppers_Perdidos, Motivo, Operador_Entidad, Operador_Chequeo)
          VALUES (?, 'Enraizamiento', ?, ?, 'Contaminación detectada', ?, ?)
        ");
        $ins->bind_param('isiii', $id, $hoy, $cont, $user, $user);
        $ins->execute();
        $ins->close();
    }

    header('Location: organizacion_material_lavado.php');
    exit();
}

// Consulta corregida
$sql = "
  SELECT 
    E.ID_Enraizamiento AS id,
    V.Codigo_Variedad, V.Nombre_Variedad,
    E.Fecha_Siembra,
    E.Tuppers_Llenos AS llenos,
    COALESCE(E.Tuppers_Organizados_Lavado,0) AS organizados,
    COALESCE((
      SELECT SUM(p.Tuppers_Perdidos)
        FROM perdidas_laboratorio p
       WHERE p.Tipo_Entidad='Enraizamiento'
         AND p.ID_Entidad=E.ID_Enraizamiento
    ),0) AS perdidos
  FROM enraizamiento E
  JOIN variedades V ON E.ID_Variedad = V.ID_Variedad
 WHERE E.Estado_Revision = 'Consolidado'
   AND E.Tuppers_Llenos > COALESCE(E.Tuppers_Organizados_Lavado,0)
                + COALESCE((
                    SELECT SUM(p2.Tuppers_Perdidos)
                      FROM perdidas_laboratorio p2
                     WHERE p2.Tipo_Entidad='Enraizamiento'
                       AND p2.ID_Entidad=E.ID_Enraizamiento
                  ),0)
 ORDER BY E.Fecha_Siembra DESC
";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Organizar Material para Lavado</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="../style.css?v=<?=time()?>"/>
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-pagina">
    <header>
      <div class="encabezado d-flex align-items-center">
        <a class="navbar-brand me-3" href="#"><img src="../logoplantulas.png" width="130" height="124" alt="Logo"></a>
        <div>
          <h2>Organización de Material para Lavado</h2>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_eol.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <main class="container mt-4">
      <h4>Solicitudes pendientes</h4>
      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th><th>Variedad</th><th>Fecha Siembra</th>
              <th>Tuppers Llenos</th><th>Tuppers Organizados</th><th>Tuppers Perdidos</th>
              <th>Tuppers Disponibles</th><th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php while($r = $result->fetch_assoc()):
              $disp = $r['llenos'] - $r['organizados'] - $r['perdidos'];
            ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td><?= htmlspecialchars("{$r['Codigo_Variedad']} – {$r['Nombre_Variedad']}") ?></td>
              <td><?= $r['Fecha_Siembra'] ?></td>
              <td><?= $r['llenos'] ?></td>
              <td><?= $r['organizados'] ?></td>
              <td><?= $r['perdidos'] ?></td>
              <td><?= $disp ?></td>
              <td class="text-center">
                <button
                  class="btn-consolidar btn-sm"
                  data-id="<?= $r['id'] ?>"
                  data-disponibles="<?= $disp ?>"
                  onclick="abrirModal(this)"
                >✔ Organizar</button>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </main>

    <footer class="text-center py-3">&copy; 2025 PLANTAS AGRODEX</footer>
  </div>

  <div class="modal fade" id="organizarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
      <form id="organizarForm" method="POST" class="modal-content" onsubmit="return validarModal()">
        <div class="modal-header">
          <h5 class="modal-title">Organizar Tuppers</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id_enr" id="modalId">
          <div class="mb-2">
            <label class="form-label">Tuppers Disponibles</label>
            <input type="text" id="modalDisponibles" class="form-control form-control-sm" readonly>
          </div>
          <div class="mb-2">
            <label class="form-label">Tuppers Organizados</label>
            <input type="number" min="1" name="organizados" id="modalOrganizados" class="form-control form-control-sm" required>
          </div>
          <div class="mb-2">
            <label class="form-label">¿Hubo contaminados?</label>
            <select name="hubo_contaminados" id="modalContCheck" class="form-select form-select-sm">
              <option value="No">No</option>
              <option value="Si">Sí</option>
            </select>
          </div>
          <div class="mb-2 d-none" id="contRow">
            <label class="form-label">Cantidad de tuppers contaminados:</label>
            <input type="number" min="1" name="contaminados" id="modalContaminados" class="form-control form-control-sm">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-anular btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn-inicio btn-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const modal = new bootstrap.Modal(document.getElementById('organizarModal'));
    function abrirModal(btn) {
      document.getElementById('modalId').value = btn.dataset.id;
      document.getElementById('modalDisponibles').value = btn.dataset.disponibles;
      document.getElementById('modalOrganizados').value = '';
      document.getElementById('modalContCheck').value = 'No';
      document.getElementById('contRow').classList.add('d-none');
      document.getElementById('modalContaminados').value = '';
      modal.show();
    }
    document.getElementById('modalContCheck').addEventListener('change', e => {
      document.getElementById('contRow').classList.toggle('d-none', e.target.value !== 'Si');
    });
    function validarModal() {
      const dis = +document.getElementById('modalDisponibles').value;
      const org = +document.getElementById('modalOrganizados').value || 0;
      const cont = document.getElementById('modalContCheck').value==='Si'
                  ? (+document.getElementById('modalContaminados').value||0)
                  : 0;
      if (org<1||org>dis) {
        alert(`Organizados debe ser entre 1 y ${dis}`);
        return false;
      }
      if (cont && org+cont>dis) {
        alert(`Organizados + contaminados no puede exceder ${dis}`);
        return false;
      }
      return true;
    }
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
