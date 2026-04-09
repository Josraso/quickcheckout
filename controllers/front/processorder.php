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

            // Dirección de facturación: firstname="Facturacio", distinta a la de envío
            $allAddresses  = $customer->getAddresses($this->context->language->id);
            $billingAddrId = 0;
            foreach ($allAddresses as $addr) {
                if (strtolower(trim($addr['firstname'])) === 'facturacio'
                    && (int) $addr['id_address'] !== $addressId) {
                    $billingAddrId = (int) $addr['id_address'];
                    break;
                }
            }

            $cart->id_address_delivery = $addressId;
            $cart->id_address_invoice  = $billingAddrId ?: $addressId;
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

            // Guardar nota del pedido
            if ($message) {

                // 1. ps_message — detalle del pedido (vista clásica backoffice)
                try {
                    Db::getInstance()->execute('
                        INSERT INTO `' . _DB_PREFIX_ . 'message`
                        (`id_cart`, `id_customer`, `id_employee`, `id_order`, `message`, `private`, `date_add`)
                        VALUES (' . (int) $cart->id . ', ' . (int) $customer->id . ', 0, ' . (int) $orderId . ',
                                \'' . pSQL($message) . '\', 0, NOW())
                    ');
                } catch (Exception $e) {
                    PrestaShopLogger::addLog('QuickCheckout ps_message error: ' . $e->getMessage(), 2);
                }

                // 2. ps_customer_thread + ps_customer_message
                //    — nueva vista de pedidos (PS 1.7.7+) y servicio al cliente
                try {
                    $contacts  = Contact::getContacts((int) $this->context->language->id);
                    $contactId = !empty($contacts) ? (int) $contacts[0]['id_contact'] : 1;

                    Db::getInstance()->execute('
                        INSERT INTO `' . _DB_PREFIX_ . 'customer_thread`
                        (`id_shop`, `id_lang`, `id_contact`, `id_customer`, `id_order`, `id_product`,
                         `status`, `email`, `token`, `date_add`, `date_upd`)
                        VALUES (
                            ' . (int) $this->context->shop->id . ',
                            ' . (int) $this->context->language->id . ',
                            ' . $contactId . ',
                            ' . (int) $customer->id . ',
                            ' . (int) $orderId . ', 0,
                            \'open\',
                            \'' . pSQL($customer->email) . '\',
                            \'' . pSQL(Tools::passwdGen(12)) . '\',
                            NOW(), NOW()
                        )
                    ');

                    $threadId = (int) Db::getInstance()->Insert_ID();
                    if ($threadId) {
                        Db::getInstance()->execute('
                            INSERT INTO `' . _DB_PREFIX_ . 'customer_message`
                            (`id_customer_thread`, `id_employee`, `message`,
                             `ip_address`, `date_add`, `date_upd`, `read`, `private`)
                            VALUES (
                                ' . $threadId . ', 0,
                                \'' . pSQL($message) . '\',
                                \'' . pSQL(substr(Tools::getRemoteAddr(), 0, 16)) . '\',
                                NOW(), NOW(), 0, 0
                            )
                        ');
                    }
                } catch (Exception $e) {
                    PrestaShopLogger::addLog('QuickCheckout customer_thread error: ' . $e->getMessage(), 2);
                }

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
