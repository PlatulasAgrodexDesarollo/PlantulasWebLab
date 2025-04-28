<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Panel Historial Reportes</title>
    <link rel="stylesheet" href="../style.css?v=<?= time(); ?>" />
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
    />
  </head>

  <body>
    <div class="contenedor-pagina">
      <header>
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
            <h2>Responsable de Registro y Siembra</h2>
            <p>Panel del Historial de Reportes</p>
          </div>
        </div>

        <div class="barra-navegacion">
          <nav class="navbar bg-body-tertiary">
            <div class="container-fluid">
              <div class="Opciones-barra">
                <button onclick="window.location.href='dashboard_rrs.php'">
                  🔙 Regresar al Dashboard
                </button>
              </div>
            </div>
          </nav>
        </div>
      </header>

      <!-- Contenido principal -->
      <main>
        <form action="">
          <input type="text" placeholder="Buscar..." name="search" />
          <button type="submit">Buscar</button><br />
        </form>

        <form class="form-doble-columna">
          <div class="content">
            <div class="section">
              <div class="table-responsive">
                <table class="table table-bordered table-hover table-striped">
                  <thead>
                    <tr>
                      <th>Numero de Reporte</th>
                      <th>Nombre Operador</th>
                      <th>Fecha de trabajo</th>
                      <th>Aprobado por:</th>
                      <th>Variedad Trabajada</th>
                      <th>Numero de brotes trabajador</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td></td>
                      <td></td>
                      <td></td>
                      <td></td>
                      <td></td>
                      <td></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </form>
      </main>

      <footer>
        <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
      </footer>
    </div>

    <!-- Scripts Bootstrap y Scroll -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
      // Guardar el ID de la tarjeta clickeada
      function guardarScroll(cardId) {
        localStorage.setItem("ultima_tarjeta_click", cardId);
      }

      // Al cargar la página, movernos a la última tarjeta
      document.addEventListener("DOMContentLoaded", function () {
        const cardId = localStorage.getItem("ultima_tarjeta_click");
        if (cardId) {
          const card = document.getElementById(cardId);
          if (card) {
            card.scrollIntoView({ behavior: "smooth", block: "center" });
          }
          localStorage.removeItem("ultima_tarjeta_click");
        }
      });
    </script>
  </body>
</html>
