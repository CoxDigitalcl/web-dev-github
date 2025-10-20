# PMPro Payku Gateway

Este plugin agrega un gateway de pago para [Paid Memberships Pro](https://www.paidmembershipspro.com/) que permite cobrar membresías recurrentes con [Payku](https://www.payku.cl/).

## Características

- Crea suscripciones en Payku cuando un usuario finaliza el checkout en PMPro.
- Redirige al usuario al formulario seguro de Payku.
- Procesa webhooks de Payku para activar, renovar o cancelar membresías automáticamente.
- Permite cancelar suscripciones directamente desde PMPro.
- Entrega campos de configuración para credenciales en la pantalla de ajustes de pagos de PMPro.

## Requisitos

- WordPress 5.8 o superior.
- Paid Memberships Pro 2.9 o superior.
- Cuenta Payku con suscripciones habilitadas.

## Instalación

1. Copia la carpeta `payku-pmpro-integration` dentro del directorio `wp-content/plugins/` de tu sitio WordPress.
2. Activa el plugin **PMPro Payku Gateway** desde el panel de administración de WordPress.
3. Ve a **Memberships → Settings → Payment Settings** y selecciona **Payku** como gateway.
4. Completa las credenciales proporcionadas por Payku:
   - API Key
   - Secret Key
   - Public Token
   - Secret Token
   - Webhook Secret
5. Configura la URL del webhook que aparece en los ajustes dentro del panel de Payku.

## Webhooks

El plugin expone el endpoint `https://tu-sitio.com/wp-json/pmpro-payku/v1/webhook`. Configura esta URL en Payku y utiliza el mismo **Webhook Secret** que definas en los ajustes del plugin. Los eventos manejados actualmente son:

- `subscription.activated`
- `subscription.cancelled`
- `subscription.payment_succeeded`
- `subscription.payment_failed`

## Personalización

Puedes engancharte a los filtros y acciones de WordPress/PMPro que utiliza el plugin para ajustar la experiencia de pago, por ejemplo:

- `pmpro_payku_settings`
- `pmpro_payku_is_sandbox`
- `pmpro_payku_payment_option_fields`

## Desarrollo

- Ejecuta `php -l` sobre los archivos para validar sintaxis.
- Utiliza el filtro `pmpro_payku_is_sandbox` para forzar el entorno en entornos de pruebas si es necesario.

## Licencia

GPL-2.0-or-later
