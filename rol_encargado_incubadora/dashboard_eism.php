<?php
include '../db.php';
session_start();

// 1) Verificar sesión iniciada
if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php');
    exit();
}

// 2) Verificar rol = 9 (Encargado de Incubadora y Suministro de Material)
if ($_SESSION['Rol'] != 9) {
    header('Location: ../login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Encargado de Incubadora y Suministro de Material</title>
  <link rel="stylesheet" href="../style.css?v=<?= time() ?>">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
    integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
    crossorigin="anonymous"
  />
  <style>
    /* Resalta brevemente la tarjeta seleccionada */
    .highlight {
      animation: highlight 2s ease-out;
    }
    @keyframes highlight {
      from { background-color: #fffae6; }
      to   { background-color: transparent; }
    }
  </style>
</head>
<body>
  <div class="contenedor-pagina">
    <header>
      <div class="encabezado d-flex align-items-center">
        <a class="navbar-brand me-3" href="#">
          <img src="../logoplantulas.png" alt="Logo" width="130" height="124" class="d-inline-block align-text-center"/>
        </a>
        <div>
          <h2>Bienvenido, Encargado de Incubadora y Suministro de Material</h2>
          <p>Resumen de tus actividades y accesos rápidos.</p>
        </div>
      </div>
      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='../logout.php'">🔄 Cerrar sesión</button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <main class="container mt-4">
      <section class="dashboard-grid">
        <div class="card" data-card-id="inventario_materiales">
          <h2>📦 Inventario de Materiales</h2>
          <p>Agrega y actualiza existencias de pinzas, bisturíes, periódicos y trapos.</p>
          <a href="inventario_materiales.php">Ver detalles</a>
        </div>

        <div class="card" data-card-id="suministro_material">
          <h2>🚚 Suministro de Insumos</h2>
          <p>Asigna y despacha medio nutritivo y explantes según etapa.</p>
          <a href="suministro_material.php">Ver detalles</a>
        </div>
        <div class="card" id="card-vista-tuppers">
          <h2>📋 Existencias de Tuppers</h2>
          <p>Consulta todos los tuppers, sus estados y su trazabilidad completa.</p>
          <a href="existencias_tuppers.php" onclick="guardarScroll('card-vista-tuppers')">Ir a Vista</a>
        </div>
        <div class="card" id="card-seleccion-tuppers">
          <h2>🔬 Selección de Tuppers</h2>
          <p>Coordina la selección de tuppers para lavado.</p>
          <a href="seleccion_tuppers.php" onclick="guardarScroll('card-seleccion-tuppers')">Gestionar selección</a>
        </div>
        
        <div class="card" data-card-id="registro_datos_incubadora">
          <h2>📝 Registro Temperatura & Humedad 🌡</h2>
          <p>Captura diaria de condiciones del incubador.</p>
          <a href="registro_datos_incubadora.php">Ver detalles</a>
        </div>
        <div class="card" data-card-id="historial_completo_incubadora">
          <h2>📜 Historial de Parámetros</h2>
          <p>Consulta todos los registros de temperatura y humedad.</p>
          <a href="historial_completo_incubadora.php">Ver detalles</a>
        </div>
        <div class="card" data-card-id="inventario_etapa3">
          <h2>📦 Inventario Etapa 3</h2>
          <p>Control puntual del stock de material vegetativo</p>
          <a href="inventario_etapa3.php">Ver detalles</a>
        </div>
        <!--
        <div class="card" data-card-id="material_operadores">
          <h2>🛠️ Revisión de Material Operadoras</h2>
          <p>Verifica que cada operadora reciba el medio y explantes correctos.</p>
          <a href="material_operadores.php">Ver detalles</a>
        </div>
        -->
        <div class="card" data-card-id="seguimiento_operadoras">
          <h2>👀 Supervisión de Trabajo</h2>
          <p>Monitorea en tiempo real el uso de materiales por operadora.</p>
          <a href="seguimiento_operadoras.php">Ver detalles</a>
        </div>
        <!--
        <div class="card" data-card-id="reporte_semanal_incubadora">
          <h2>📊 Reporte Semanal Incubadora</h2>
          <p>Genera inventario y reporte de temperatura/humedad del lunes.</p>
          <a href="reporte_semanal_incubadora.php">Ver detalles</a>
        </div>
        -->
      </section>
    </main>

    <footer class="text-center py-3">&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</footer>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const cards = document.querySelectorAll('.dashboard-grid .card');
      // Al hacer clic en cualquier enlace de tarjeta
      cards.forEach(card => {
        const link = card.querySelector('a');
        link.addEventListener('click', () => {
          const id = card.dataset.cardId;
          sessionStorage.setItem('lastCard', id);
        });
      });

      // Al cargar la página, leer y, si existe, hacer scroll y resaltar
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

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
  ></script>
</body>
</html>
