<?php
/**
 * QuickCheckout — Controlador de proceso del pedido (AJAX).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class QuickCheckoutProcessorderModuleFrontController extends ModuleFrontController
{
    public $ssl  = true;
    public $ajax = true;

    public function postProcess()
    {
        if (!$this->isAjaxRequest()) {
            Tools::redirect($this->context->link->getModuleLink('quickcheckout', 'checkout'));
        }

        if (!$this->module->isQuickCheckoutEnabled()) {
            $this->jsonError($this->module->l('El módulo no está activo.'));
        }

        if (!$this->context->customer->isLogged()) {
            $this->jsonError($this->module->l('Debes iniciar sesión para completar el pedido.'));
        }

        $cart     = $this->context->cart;
        $customer = $this->context->customer;

        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() === 0) {
            $this->jsonError($this->module->l('Tu carrito está vacío.'));
        }

        // Dirección de envío
        $addressId = (int) Tools::getValue('id_address_delivery');
        if ($addressId) {
            $address = new Address($addressId);
            if (!Validate::isLoadedObject($address) || (int) $address->id_customer !== (int) $customer->id) {
                $this->jsonError($this->module->l('La dirección seleccionada no es válida.'));
            }
            $cart->id_address_delivery = $addressId;
            $cart->id_address_invoice  = $addressId;
            $cart->update();
        }

        if (!(int) $cart->id_address_delivery) {
            $this->jsonError($this->module->l('No tienes ninguna dirección de envío.'));
        }

        // Transportista
        $carrierId = $this->module->getConfiguredCarrierId();
        if (!$carrierId) {
            $this->jsonError($this->module->l('No hay ningún transportista configurado. Contacta con el administrador.'));
        }

        $resolvedCarrierId = $this->resolveCarrierId($cart, $carrierId, $customer);
        if (!$resolvedCarrierId) {
            $this->jsonError($this->module->l('Lo sentimos, no disponemos de envío para tu dirección de envío.'));
        }

        $cart->id_carrier = $resolvedCarrierId;
        $cart->update();

        // Método de pago
        $paymentModuleName = $this->module->resolvePaymentModule((int) $customer->id);
        if (!$paymentModuleName) {
            $error = $this->module->usesCustomerPaymentMethod()
                ? $this->module->l('No tienes ningún método de pago asignado. Contacta con el administrador.')
                : $this->module->l('No hay ningún método de pago configurado. Contacta con el administrador.');
            $this->jsonError($error);
        }

        $paymentModule = Module::getInstanceByName($paymentModuleName);
        if (!$paymentModule || !$paymentModule->active) {
            $this->jsonError($this->module->l('El método de pago no está disponible.'));
        }

        // Crear el pedido
        try {
            $total   = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $message = strip_tags(trim(Tools::getValue('message', ''))) ?: null;

            $paymentModule->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                $total,
                $paymentModule->displayName,
                null,
                [],
                (int) $this->context->currency->id,
                false,
                $customer->secure_key
            );

            $orderId = (int) Order::getOrderByCartId((int) $cart->id);
            if (!$orderId) {
                $this->jsonError($this->module->l('No se pudo registrar el pedido. Por favor, inténtalo de nuevo.'));
            }

            // Guardar nota del pedido (visible en pedido y servicio al cliente)
            if ($message) {
                Db::getInstance()->insert('message', [
                    'id_cart'     => (int) $cart->id,
                    'id_customer' => (int) $customer->id,
                    'id_employee' => 0,
                    'id_order'    => (int) $orderId,
                    'message'     => pSQL($message),
                    'private'     => 0,
                    'new_message' => 1,
                    'date_add'    => date('Y-m-d H:i:s'),
                ]);
            }

            $confirmUrl = $this->context->link->getPageLink(
                'order-confirmation',
                null,
                null,
                [
                    'id_cart'   => (int) $cart->id,
                    'id_module' => (int) $paymentModule->id,
                    'id_order'  => $orderId,
                    'key'       => $customer->secure_key,
                ]
            );

            $this->jsonSuccess($confirmUrl);

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'QuickCheckout error: ' . $e->getMessage(),
                3, null, 'Cart', (int) $cart->id, true
            );
            $this->jsonError($this->module->l('Ha ocurrido un error al procesar el pedido. Por favor, inténtalo de nuevo.'));
        }
    }

    /* =========================================================
       HELPERS — idénticos a checkout.php para consistencia
    ========================================================= */

    private function resolveCarrierId(Cart $cart, int $carrierId, Customer $customer): int
    {
        $carrier = new Carrier($carrierId);
        if (!Validate::isLoadedObject($carrier) || !$carrier->active) {
            return 0;
        }

        $idReference    = (int) $carrier->id_reference;
        $customerGroups = $customer->isLogged()
            ? $customer->getGroups()
            : [(int) Configuration::get('PS_UNIDENTIFIED_GROUP')];

        $availableCarriers = Carrier::getCarriersForOrder(
            (int) $cart->id_address_delivery,
            $customerGroups,
            $cart
        );

        // Paso 1: id_carrier exacto
        foreach ($availableCarriers as $c) {
            if ((int) $c['id_carrier'] === $carrierId) {
                return $carrierId;
            }
        }

        // Paso 2: mismo id_reference
        if ($idReference > 0) {
            foreach ($availableCarriers as $c) {
                $ac = new Carrier((int) $c['id_carrier']);
                if (Validate::isLoadedObject($ac) && (int) $ac->id_reference === $idReference) {
                    return (int) $c['id_carrier'];
                }
            }
        }

        // Paso 3: verificar zona directamente
        $address = new Address((int) $cart->id_address_delivery);
        if (Validate::isLoadedObject($address)) {
            $idZone = 0;
            if ($address->id_state) {
                $state  = new State((int) $address->id_state);
                $idZone = Validate::isLoadedObject($state) ? (int) $state->id_zone : 0;
            }
            if (!$idZone && $address->id_country) {
                $country = new Country((int) $address->id_country);
                $idZone  = Validate::isLoadedObject($country) ? (int) $country->id_zone : 0;
            }

            if ($idZone && $carrier->getZone($idZone)) {
                return $carrierId;
            }
        }

        return 0;
    }

    private function isAjaxRequest(): bool
    {
        return (bool) Tools::getValue('ajax')
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    }

    private function jsonSuccess(string $redirectUrl): void
    {
        header('Content-Type: application/json');
        die(json_encode(['success' => true, 'redirectUrl' => $redirectUrl]));
    }

    private function jsonError(string $message): void
    {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'error' => $message]));
    }
}
