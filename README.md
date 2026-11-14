# Ricetta

Ricetta es una aplicación web autoalojada para guardar recetas, organizar el
menú de la semana y compartir recetas o planificaciones entre usuarios. La
interfaz está en español, es responsiva (adaptada a móviles y ordenadores) y no
depende de servicios externos.

Está desarrollada con frontend en HTML, CSS y JavaScript moderno (sin frameworks)
y backend en **PHP 8.1+ y MariaDB / MySQL**, totalmente compatible con **XAMPP**
y cualquier servidor estándar **Apache o Nginx**.

---

## Características

- **Recetas y Planificador**: Registro de recetas organizadas por categorías, tiempos de preparación, porciones e ingredientes, con asignación directa a los días de la semana (desayuno, comida, merienda y cena).
- **Control de Versiones y Sincronización**: Detección de cambios y sincronización en tiempo real sin sobrescribir información entre múltiples pestañas o dispositivos.
- **Compartición**: Envío y recepción de recetas individuales o planificadores completos entre cuentas del sistema.
- **Seguridad**: Hashing de contraseñas con Argon2id / bcrypt, cookies HttpOnly SameSite, rate-limiting contra ataques de fuerza bruta y cabeceras de protección (CSP, HSTS, X-Frame-Options).
- **Sin dependencias externas**: No requiere npm, composer ni librerías de terceros.

---

## Requisitos

- **PHP 8.1 o superior** con extensiones `pdo_mysql`, `json`, `mbstring`.
- **MariaDB 10.4+** o **MySQL 8.0+**.
- Servidor web: **Apache** (con `mod_rewrite` habilitado) o el servidor embebido de PHP.

---

## Instalación y Uso

### Opción 1: Con XAMPP (Windows o Linux) — Recomendada

1. Copia o extrae la carpeta `ricetta` dentro del directorio `htdocs` de XAMPP:
   - En Windows: `C:\xampp\htdocs\ricetta`
   - En Linux: `/opt/lampp/htdocs/ricetta`
2. Inicia los módulos **Apache** y **MySQL** desde el panel de control de XAMPP.
3. Abre en tu navegador web:
   ```
   http://localhost/ricetta/
   ```
No necesitas configurar nada más: la aplicación se conecta automáticamente con el usuario por defecto de XAMPP (`root` sin contraseña), crea la base de datos `ricetta` en la primera visita e instala las tablas vacías.

---

### Opción 2: Con el servidor integrado de PHP

Si tienes PHP y MariaDB o MySQL instalados directamente en el sistema:

1. Si tu base de datos tiene usuario o contraseña específicos, copia `.env.example` a `.env` y ajusta las credenciales:
   ```bash
   cp .env.example .env
   ```
2. Inicia el servidor integrado:
   ```bash
   php -S 127.0.0.1:8000 router.php
   ```
3. Abre en tu navegador: <http://127.0.0.1:8000>

---

## Base de datos

El esquema completo de tablas se encuentra en `schema.sql`. Si `RICETTA_DB_AUTO_CREATE=true` está activo (por defecto), Ricetta creará las tablas automáticamente al recibir la primera petición.

Si prefieres inicializarla manualmente:

```bash
mariadb -u root -p < schema.sql
```

---

## Estructura del Proyecto

- `index.html` / `confirm-email.html` - Vistas y marcado de la interfaz.
- `styles.css` - Estilos de la aplicación.
- `app.js` / `confirm-email.js` - Lógica de cliente en el navegador.
- `api.php` - API REST en PHP (autenticación, gestión de recetas, planificación y compartición).
- `config.php` - Conexión con MariaDB/MySQL y utilidades del sistema.
- `router.php` - Enrutador para el servidor de desarrollo `php -S`.
- `schema.sql` - Estructura de la base de datos.
- `.htaccess` - Reglas de seguridad y enrutamiento para Apache.
- `.env.example` - Plantilla de variables de entorno opcionales.

---

## Licencia

Este proyecto es de código abierto y se distribuye bajo la **Licencia MIT**. En resumen:
- **Libre para todos**: Puedes usarlo, modificarlo, compartirlo o adaptarlo libremente para uso personal o comercial.
- **Sin garantías**: El software se comparte tal cual, sin responsabilidades sobre fallos o pérdida de datos.

Puedes consultar el texto legal completo en el archivo [LICENSE](LICENSE).
