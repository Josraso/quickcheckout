# QuickCheckout — Módulo para PrestaShop

**Versión:** 1.1.0  
**Compatible con:** PrestaShop 1.7 · 8 · 9  
**Idiomas incluidos:** Español · Inglés  
**Independiente del tema:** Sí (Warehouse, Classic, cualquier tema)

---

## ¿Qué hace este módulo?

QuickCheckout crea su **propia página de checkout** completamente independiente del tema y del checkout nativo de PrestaShop. El cliente ve solo lo que necesita:

- **Resumen del carrito**: productos, cantidades, coste de envío y total.
- **Dirección de envío**: gestionada automáticamente según cuántas tiene el cliente.
- **Botón "Completar pedido"**: un solo clic y el pedido queda registrado.

El cliente **no ve** ningún selector de transportista ni de método de pago. Todo se asigna automáticamente según la configuración del módulo.

---

## Flujo del cliente

1. Añade productos al carrito.
2. Pulsa "Tramitar pedido" → va directamente a nuestra página (si saltar carrito está activo) o pasa por el carrito primero.
3. Si no está logueado → redirige al login/registro nativo de PrestaShop.
4. Llega a nuestra página de checkout:
   - **0 direcciones** → enlace para añadir una (formulario nativo de PS).
   - **1 dirección** → se muestra directamente, sin elección.
   - **2+ direcciones** → selector visual para elegir cuál usar.
5. Si el transportista no cubre la dirección → botón bloqueado + mensaje claro.
6. Pulsa "Completar pedido" → el pedido se registra, se envían los emails y los estados funcionan de forma 100% nativa.

---

## Instalación

1. Sube el ZIP desde **Backoffice → Módulos → Subir módulo**.
2. Una vez instalado, haz clic en **Configurar**.
3. Activa el módulo, selecciona transportista y método de pago.

---

## Configuración

| Opción | Descripción |
|--------|-------------|
| **Activar módulo** | Activa/desactiva todo. Si está off, el checkout nativo vuelve a funcionar. |
| **Saltar página del carrito** | Si está on, "Tramitar pedido" va directo a nuestra página. |
| **Transportista asignado** | El único transportista que se usará. Debe estar activo y configurado en PS. |
| **Método de pago asignado** | El único método de pago. Debe ser **interno** (sin redirección externa). |

> ⚠️ El método de pago no puede ser PayPal, Stripe ni ningún otro que redirija fuera de la tienda.

---

## Estructura de archivos

```
quickcheckout/
├── quickcheckout.php
├── logo.svg
├── README.md
├── controllers/front/
│   ├── checkout.php        ← Página de checkout propia
│   └── processorder.php    ← Procesa el pedido vía AJAX
├── views/
│   ├── css/quickcheckout.css
│   ├── js/quickcheckout.js
│   └── templates/front/checkout.tpl
└── translations/
    ├── es.php
    └── en.php
```
