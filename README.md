# Tasafy

Cotizaciones del dólar (USD), euro (EUR) y Tether (USDT) en bolívares, con conversor bidireccional y una interfaz oscura responsive.

**Demo:** https://tasafy.45.79.220.248.sslip.io/

## Características

- Cotización oficial **USD** y **EUR** desde el BCV.
- Cotización **USDT** desde Binance P2P (mediana de las 10 mejores ofertas de compra).
- Conversor bidireccional entre USD/EUR/USDT y VES.
- Variación diaria y **brecha vs USD** en las tarjetas de euro y tether.
- Actualización manual, indicador de última actualización y aviso de datos en caché.
- Caché del lado del servidor con fallback a la última cotización disponible.
- Interfaz responsive, modo oscuro y assets PWA.

## Fuentes de datos

| Moneda | Fuente | Caché |
|--------|--------|-------|
| USD / EUR | [BCV](https://www.bcv.org.ve/) | 6 horas |
| USDT | [Binance P2P](https://p2p.binance.com/) | 10 minutos |

Si una fuente falla, se sirve la última cotización guardada y se marca la respuesta como `stale`.

## Stack

- **Frontend:** HTML + JavaScript vanilla + Tailwind CSS por CDN.
- **Backend:** PHP 8 (`api/rates.php`), sin framework ni dependencias de Composer.
- **Caché:** archivos JSON en `cache/`.
- **Servidor:** Apache + HTTPS (producción).

No hay build, lint ni tests automatizados.

## Estructura

```text
tasafy/
├── index.html              # Interfaz y lógica del conversor
├── api/
│   └── rates.php           # Endpoint de cotizaciones
├── cache/                  # Caché JSON generada en runtime
├── assets/icons/           # Iconos PWA
├── deploy/
│   └── tasafy.conf         # Vhost de Apache
├── deploy.sh               # Script de despliegue por rsync
├── manifest.json           # Manifest PWA
└── README.md
```

## Ejecución local

Requiere PHP 8 con cURL o `allow_url_fopen` habilitado.

```bash
php -S 127.0.0.1:8000
```

Luego abrir http://127.0.0.1:8000.

> `python3 -m http.server` solo sirve el HTML: el endpoint PHP no se ejecuta y las tasas no cargan.

Verificar el endpoint:

```bash
curl -s http://127.0.0.1:8000/api/rates.php | python3 -m json.tool
```

La carpeta `cache/` debe ser escribible por el usuario del servidor web. Si no existe, PHP intenta crearla con permisos `0775`.

## API

`GET api/rates.php`

Respuesta de ejemplo:

```json
{
  "ok": true,
  "fetchedAt": "2026-11-08T10:30:00-04:00",
  "valueDate": "2026-11-08",
  "valueDateText": "08/11/2026",
  "rates": {
    "USD": 897.54,
    "EUR": 1001.19,
    "USDT": 1027.05
  },
  "variations": {
    "USD": 0.0,
    "EUR": 0.0,
    "USDT": 0.42
  },
  "sources": {
    "USD": "bcv",
    "EUR": "bcv",
    "USDT": "binance-p2p"
  },
  "stale": false,
  "errors": null
}
```

- `stale: true` indica que al menos una cotización proviene de la caché por fallo de la fuente.
- Para forzar una actualización, eliminar los archivos JSON de `cache/`.

## Despliegue

El sitio se publica en la raíz del dominio:

```bash
SSHPASS='...' ./deploy.sh
# o
SSHPASS_FILE=/ruta/al/archivo ./deploy.sh
```

El script sincroniza el proyecto por `rsync`, ajusta permisos y recarga Apache. No se incluyen credenciales en el repositorio.

El vhost de referencia está en `deploy/tasafy.conf`. Si se usa Certbot para HTTPS, no volver a subir ese archivo después de configurar el redirect HTTP → HTTPS.

## Créditos

Idea y Programa de [@EdFasano70](https://github.com/edfasano70).
