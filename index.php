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
            <h2>Index</h2>
            <p>Pagina para entrar a otras paginas</p>
          </div>
        </div>

        <div class="barra-navegacion">
          <nav class="navbar bg-body-tertiary">
            <div class="container-fluid">
              <div class="Opciones-barra">
                <button onclick="window.location.href='dashboard_rrs.html'">
                  🔙 no funciona
                </button>
              </div>
            </div>
          </nav>
        </div>
      </header>

      <!-- Contenido principal -->
      <main>
        <button
          onclick="window.location.href='Rol de Responsable de Registro y Reporte de Siembra/dashboard_rrs.php'"
        >
          Panel Responsable de Registro y Siembra
        </button>
        <button
          onclick="window.location.href='Rol de operador/dashboard_cultivo.php'"
        >
          Panel Operador
        </button>
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
