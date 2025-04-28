<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Limpieza de Repisas</title>
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
            <h2>Limpieza de Repisas</h2>
            <p></p>
          </div>
        </div>

        <div class="barra-navegacion">
          <nav class="navbar bg-body-tertiary">
            <div class="container-fluid">
              <div class="Opciones-barra">
                <button onclick="window.location.href='dashboard_eol.php'">
                  🔙 Regresar al Dashboard
                </button>
              </div>
            </div>
          </nav>
        </div>
      </header>

      <!-- Contenido principal -->
      <main>
        <div class="fila-flex">
          <form>
            <div>
              <div>
                <h3>Variedad que se lavara</h3>
                <div class="form_caja">
                  <p>Variedad 1</p>
                </div>
                <div class="form_caja">
                  <p>Variedad 2</p>
                </div>
              </div>
            </div>
          </form>
          <form>
            <div>
              <h3>Estado de la organización</h3>
              
              <!-- Opción 1 -->
              <div class="opcion-contenedor">
                <div class="form_caja">
                  <p>opcion 1</p>
                </div>
                <div class="checkbox-contenedor">
                  <input type="checkbox" id="opcion1">
                  <label for="opcion1">Colocada en caja</label>
                </div>
              </div>
              
              <!-- Opción 2 -->
              <div class="opcion-contenedor">
                <div class="form_caja">
                  <p>opcion 2</p>
                </div>
                <div class="checkbox-contenedor">
                  <input type="checkbox" id="opcion2">
                  <label for="opcion2">Otra opción</label>
                </div>
              </div>
            </div>
            </div>
          </form>
        </div>
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
