<?php
/**
 * QuickCheckout — Controlador de la página de checkout propia.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class QuickCheckoutCheckoutModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function init()
    {
        parent::init();

        if (!$this->module->isQuickCheckoutEnabled() || !$this->module->currentCustomerHasAccess()) {
            Tools::redirect($this->context->link->getPageLink('index'));
            return;
        }

        if (!$this->context->customer->isLogged()) {
            $back = urlencode($this->context->link->getModuleLink('quickcheckout', 'checkout'));
            Tools::redirect($this->context->link->getPageLink('authentication', null, null, ['back' => $back]));
            return;
        }

        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() === 0) {
            Tools::redirect($this->context->link->getPageLink('index'));
            return;
        }
    }

    public function initContent()
    {
        parent::initContent();

        $cart     = $this->context->cart;
        $customer = $this->context->customer;
        $currency = $this->context->currency;

        // ---- Productos ----
        $cartProducts = [];
        foreach ($cart->getProducts() as $product) {
            $cartProducts[] = [
                'name'        => $product['name'],
                'reference'   => $product['reference'],
                'quantity'    => (int) $product['cart_quantity'],
                'unit_price'  => Tools::displayPrice($product['price_wt'], $currency),
                'total'       => Tools::displayPrice($product['total_wt'], $currency),
                'cover'       => $this->getProductImageUrl($product),
                'attributes'  => isset($product['attributes']) ? $product['attributes'] : '',
                'product_url' => $this->context->link->getProductLink((int) $product['id_product']),
            ];
        }

        // ---- Direcciones PRIMERO (pueden actualizar el carrito) ----
        $addresses    = $customer->getAddresses($this->context->language->id);
        $addressCount = count($addresses);
        $addressData  = $this->buildAddressData($addresses, $cart);

        // ---- Transportista ----
        $carrierId    = $this->module->getConfiguredCarrierId();
        $carrierOk    = false;
        $carrierError = '';
        $shippingCost = 0;

        if (!$carrierId) {
            $carrierError = $this->module->l('No hay ningún transportista configurado. Contacta con el administrador.');
        } elseif ($addressCount > 0 && (int) $cart->id_address_delivery) {
            // Resolver el ID real del transportista disponible
            $resolvedId = $this->resolveCarrierId($cart, $carrierId, $customer);
            if ($resolvedId) {
                $carrierOk        = true;
                $cart->id_carrier = $resolvedId;
                $cart->update();
                $shippingCost = (float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
            } else {
                $carrier = new Carrier($carrierId);
                if (!Validate::isLoadedObject($carrier) || !$carrier->active) {
                    $carrierError = $this->module->l('El transportista configurado no está activo. Contacta con el administrador.');
                } else {
                    $carrierError = $this->module->l('Lo sentimos, no disponemos de envío para tu dirección. Contacta con nosotros.');
                }
            }
        }

        // ---- Método de pago ----
        $paymentModule = $this->module->getConfiguredPaymentModule();
        $paymentOk     = false;
        $paymentError  = '';
        if (!$paymentModule) {
            $paymentError = $this->module->l('No hay ningún método de pago configurado. Contacta con el administrador.');
        } else {
            $inst = Module::getInstanceByName($paymentModule);
            if (!$inst || !$inst->active) {
                $paymentError = $this->module->l('El método de pago configurado no está disponible. Contacta con el administrador.');
            } else {
                $paymentOk = true;
            }
        }

        // ---- Totales ----
        $subtotal = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
        $total    = $subtotal + $shippingCost;

        // ---- ¿Se puede pedir? ----
        $canOrder   = $carrierOk && $paymentOk && $addressCount > 0;
        $blockError = '';
        if (!$canOrder && $addressCount > 0) {
            $blockError = $carrierError ?: $paymentError;
        }

        $this->context->smarty->assign([
            'qc_products'        => $cartProducts,
            'qc_subtotal'        => Tools::displayPrice($subtotal, $currency),
            'qc_shipping'        => $carrierOk ? Tools::displayPrice($shippingCost, $currency) : '—',
            'qc_shipping_free'   => $carrierOk && $shippingCost == 0,
            'qc_total'           => $carrierOk ? Tools::displayPrice($total, $currency) : '—',
            'qc_address_count'   => $addressCount,
            'qc_addresses'       => $addressData['list'],
            'qc_selected_addr'   => $addressData['selected'],
            'qc_add_address_url' => $this->context->link->getPageLink('address', null, null, [
                'back' => urlencode($this->context->link->getModuleLink('quickcheckout', 'checkout')),
            ]),
            'qc_can_order'       => $canOrder,
            'qc_block_error'     => $blockError,
            'qc_cart_url'        => $this->context->link->getPageLink('cart', null, null, ['action' => 'show']),
            'qc_process_url'     => $this->context->link->getModuleLink('quickcheckout', 'processorder'),
            'qc_token'           => Tools::getToken(false),
        ]);

        $this->setTemplate('module:quickcheckout/views/templates/front/checkout.tpl');
    }

    /* =========================================================
       HELPERS PRIVADOS
    ========================================================= */

    /**
     * Resuelve el id_carrier real y disponible para este carrito.
     *
     * Estrategia:
     * 1. Buscar el transportista en la lista de disponibles por id_carrier exacto
     * 2. Buscar por id_reference (PS crea nuevo id al editar un transportista)
     * 3. Como último recurso: verificar zona del transportista manualmente
     *
     * Devuelve el id_carrier correcto o 0 si no disponible.
     */
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

        // Paso 3: verificar zona directamente en la BD
        // Address::getZoneById necesita id_zone, no id_address
        $address = new Address((int) $cart->id_address_delivery);
        if (Validate::isLoadedObject($address)) {
            // Obtener el id_zone desde el país/estado de la dirección
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

    private function getProductImageUrl(array $product): string
    {
        $images = Image::getImages($this->context->language->id, (int) $product['id_product']);
        if (!empty($images)) {
            $imageId  = (int) $images[0]['id_image'];
            $imageObj = new Image($imageId);
            $path     = _PS_IMG_DIR_ . 'p/' . $imageObj->getExistingImgPath() . '-small_default.jpg';
            if (file_exists($path)) {
                return $this->context->link->getImageLink(
                    $product['link_rewrite'],
                    $imageId,
                    'small_default'
                );
            }
        }

        if (!empty($product['id_image'])) {
            $imgId = is_array($product['id_image'])
                ? (int) $product['id_image']['id_image']
                : (int) $product['id_image'];
            if ($imgId > 0) {
                return $this->context->link->getImageLink(
                    $product['link_rewrite'],
                    $imgId,
                    'small_default'
                );
            }
        }

        return '';
    }

    private function buildAddressData(array $addresses, Cart $cart): array
    {
        $list = [];
        foreach ($addresses as $addr) {
            $list[] = [
                'id'    => (int) $addr['id_address'],
                'alias' => $addr['alias'],
                'name'  => $addr['firstname'] . ' ' . $addr['lastname'],
                'line1' => $addr['address1'] . (!empty($addr['address2']) ? ', ' . $addr['address2'] : ''),
                'line2' => $addr['postcode'] . ', ' . $addr['city'] . ', ' . $addr['country'],
            ];
        }

        $selected = null;
        if (count($list) === 1) {
            $selected = $list[0]['id'];
            if ((int) $cart->id_address_delivery !== $selected) {
                $cart->id_address_delivery = $selected;
                $cart->id_address_invoice  = $selected;
                $cart->update();
            }
        } elseif (count($list) > 1) {
            $selected = (int) $cart->id_address_delivery ?: $list[0]['id'];
        }

        return ['list' => $list, 'selected' => $selected];
    }
}
