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

if ((int) $_SESSION['Rol'] !== 9) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado de Incubadora.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Lista fija de materiales permitidos
$lista_materiales = [
    'Pinza grande',
    'Pinza mediana',
    'Bisturí',
    'Bolsa de periódico',
    'Trapos'
];

// 2) Procesar formularios
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Registrar nuevos tipos desde checklist
    if (isset($_POST['registrar_materiales']) && !empty($_POST['materiales_nuevos'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO materiales (nombre) VALUES (?)");
        foreach ($_POST['materiales_nuevos'] as $nombre) {
            $stmt->bind_param('s', $nombre);
            $stmt->execute();
        }
        $msg = '✅ Nuevos materiales registrados';
    }
    // Actualizar inventario
    if (isset($_POST['actualizar_inventario'])) {
        $id   = intval($_POST['id_material']);
        $cant = intval($_POST['cantidad']);
        $oper = $_SESSION['ID_Operador'];
        $stmt = $conn->prepare("
            INSERT INTO inventario_materiales
              (id_material, cantidad, fecha_act, id_operador_registro)
            VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
              cantidad             = VALUES(cantidad),
              fecha_act            = NOW(),
              id_operador_registro = VALUES(id_operador_registro)
        ");
        $stmt->bind_param('iii', $id, $cant, $oper);
        $stmt->execute();
        $msg = '✅ Inventario actualizado';
    }
}

// 3) Cargar datos para dropdown y tabla
$materiales = $conn->query("SELECT * FROM materiales ORDER BY nombre");
$inv_res    = $conn->query("
    SELECT m.id_material, m.nombre, COALESCE(i.cantidad, 0) AS cantidad
      FROM materiales m
      LEFT JOIN inventario_materiales i ON m.id_material = i.id_material
    ORDER BY m.nombre
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventario de Materiales</title>
  <link rel="stylesheet" href="../style.css?v=<?=time()?>"/>
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet" crossorigin="anonymous"
  />
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
        <a class="navbar-brand me-3" href="dashboard_eism.php">
          <img src="../logoplantulas.png" width="130" height="124" alt="Logo">
        </a>
        <div>
          <h2>Inventario de Materiales</h2>
          <p class="mb-0">Selecciona materiales y actualiza sus existencias.</p>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_eism.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <main class="container mt-4">
      <?php if ($msg): ?>
        <div class="alert alert-success"><?= $msg ?></div>
      <?php endif; ?>

      <div class="row mb-4">
        <!-- CHECKLIST DE MATERIALES FIJOS -->
        <div class="col-md-6">
          <div class="card p-3 h-100">
            <h4>Registrar tipos de material</h4>
            <form method="POST">
              <?php foreach ($lista_materiales as $mat): ?>
                <div class="form-check">
                  <input class="form-check-input"
                         type="checkbox"
                         name="materiales_nuevos[]"
                         value="<?= htmlspecialchars($mat) ?>"
                         id="<?= str_replace(' ', '_', $mat) ?>">
                  <label class="form-check-label" for="<?= str_replace(' ', '_', $mat) ?>">
                    <?= htmlspecialchars($mat) ?>
                  </label>
                </div>
              <?php endforeach; ?>
              <button name="registrar_materiales" class="btn btn-primary mt-3">
                Registrar seleccionados
              </button>
            </form>
          </div>
        </div>

        <!-- Formulario: actualizar existencias -->
        <div class="col-md-6">
          <div class="card p-3 h-100">
            <h4>Actualizar existencias</h4>
            <form method="POST" class="row g-2 align-items-end">
              <div class="col-7">
                <label for="id_material" class="form-label">Material</label>
                <select id="id_material" name="id_material" class="form-select" required>
                  <option value="">Selecciona material…</option>
                  <?php
                  // recargar lista de materiales para el dropdown
                  $materiales->data_seek(0);
                  while ($m = $materiales->fetch_assoc()): ?>
                    <option value="<?= $m['id_material'] ?>">
                      <?= htmlspecialchars($m['nombre']) ?>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>
              <div class="col-3">
                <label for="cantidad" class="form-label">Cantidad</label>
                <input type="number" id="cantidad" name="cantidad"
                       class="form-control" placeholder="0" min="0" required>
              </div>
              <div class="col-2">
                <button name="actualizar_inventario" class="btn btn-success w-100">
                  Guardar
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Tabla de inventario actual -->
      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Material</th>
              <th>Cantidad Disponible</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($r = $inv_res->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($r['nombre']) ?></td>
                <td><?= intval($r['cantidad']) ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </main>

    <footer class="text-center py-3">
      &copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.
    </footer>
  </div>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    crossorigin="anonymous"
  ></script>
  
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
