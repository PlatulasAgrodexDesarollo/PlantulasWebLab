<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Panel Encargado General de Producción</title>
    <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
      integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
      crossorigin="anonymous"
    />
  </head>
  <body>
    <div class="contenedor-pagina">
      <header>
        <!-- Encabezado con logo y título -->
        <div class="encabezado">
          <a class="navbar-brand" href="#">
            <img
              src="../logoplantulas.png"
              alt="Logo"
              width="130"
              height="124"
              class="d-inline-block align-text-center"
            />
          </a>
          <div>
            <h2>Encargado General de Producción</h2>
            <p>Panel de gestión y supervisión</p>
          </div>
        </div>

        <!-- Barra de navegación -->
        <div class="barra-navegacion">
          <nav class="navbar bg-body-tertiary">
            <div class="container-fluid">
              <div class="Opciones-barra">
                <button onclick="window.location.href='../Login/logout.php'">
                  Cerrar Sesión
                </button>
              </div>
            </div>
          </nav>
        </div>
      </header>

      <!-- Contenido principal -->
      <main>
        <section class="dashboard-grid">
          <div class="card">
            <h2>🧼 Desinfección de Explantes</h2>
            <p>
              Realiza la desinfección de explantes para su preparación en el
              laboratorio.
            </p>
            <a href="desinfeccion_explantes.php">Ir a Desinfección</a>
          </div>

          <div class="card">
            <h2>📄 Historial de Desinfección</h2>
            <p>Consulta todas las desinfecciones registradas por los operadores.</p>
            <a href="historial_desinfeccion_explantes.php">Ver Historial</a>
          </div>
          
          <div class="card">
            <h2>🔬 Reportes de Producción</h2>
            <p>Consulta y revisa los reportes diarios de producción.

            </p>
            <a href="reportes_produccion.php">Ver Reportes</a>
          </div>

          <div class="card">
            <h2>🧪 Preparación de Soluciones Madre</h2>
            <p>
              Supervisa y controla la preparación de soluciones madre en el
              laboratorio.
            </p>
            <a href="preparacion_soluciones.php">Ir a Preparación</a>
          </div>

          <div class="card">
            <h2>📊 Inventario de Soluciones Madre</h2>
            <p>Consulta la cantidad restante de cada solución madre.</p>
            <a href="inventario_soluciones_madre.php">Ver Inventario</a>
          </div>

          <div class="card">
            <h2>🧹 Crear el Rol de Limpieza</h2>
            <p>Define las tareas de limpieza y asigna responsabilidades.</p>
            <a href="rol_limpieza.php">Crear Rol de Limpieza</a>
          </div>

          <div class="card">
            <h2>🌿 Relación del Material Vegetativo</h2>
            <p>Gestiona la relación del material vegetativo que se lavará.</p>
            <a href="relacion_material_lavado.php">Ver Relación</a>
          </div>

          <div class="card">
            <h2>📈 Historial de Lavado Parcial</h2>
            <p>Visualiza los reportes de avance de todos los operadores.</p>
            <a href="historial_lavado_parcial.php">Ver Historial</a>
          </div>

        </section>
      </main>

      <!-- Footer -->
      <footer>
        <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
      </footer>
    </div>

    <script
      src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
      integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
      crossorigin="anonymous"
    ></script>
  </body>
</html>
