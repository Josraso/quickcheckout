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
        $allAddresses   = $customer->getAddresses($this->context->language->id);
        $totalCount     = count($allAddresses);
        $billingWarning     = false;
        $billingAddrId      = 0;
        $billingConfigError = '';

        if ($totalCount > 1) {
            $billingAddrs  = array_values(array_filter($allAddresses, function ($a) {
                return strtolower(trim($a['firstname'])) === 'facturacio';
            }));
            $shippingAddrs = array_values(array_filter($allAddresses, function ($a) {
                return strtolower(trim($a['firstname'])) === 'entrega';
            }));

            if (count($billingAddrs) > 1) {
                $billingConfigError = $this->module->l('Configuración incorrecta: hay más de una dirección de facturación (Facturacio). Solo puede existir una. Contacta con el administrador para corregirlo.');
                $addresses          = $shippingAddrs; // mostrar solo Entrega, nunca Facturacio
            } elseif (count($billingAddrs) === 0) {
                $billingConfigError = $this->module->l('Configuración incorrecta: no hay ninguna dirección de facturación (Facturacio). Debes tener exactamente una. Contacta con el administrador para corregirlo.');
                $addresses          = $shippingAddrs;
            } elseif (count($shippingAddrs) === 0) {
                $billingConfigError = $this->module->l('Configuración incorrecta: no hay ninguna dirección de envío (Entrega). Debes tener al menos una. Contacta con el administrador para corregirlo.');
                $addresses          = [];
            } else {
                // VÁLIDO: exactamente 1 Facturacio + 1 o más Entrega
                $addresses     = $shippingAddrs;
                $billingAddrId = (int) $billingAddrs[0]['id_address'];
            }
        } else {
            // 0 o 1 dirección única: sin filtrado
            $addresses = $allAddresses;
        }

        $addressCount = count($addresses);
        $addressData  = $this->buildAddressData($addresses, $cart, $billingAddrId);

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
        $paymentOk    = false;
        $paymentError = '';

        $paymentModuleName = $this->module->resolvePaymentModule((int) $customer->id);
        if (!$paymentModuleName) {
            $paymentError = $this->module->usesCustomerPaymentMethod()
                ? $this->module->l('No tienes ningún método de pago asignado. Contacta con el administrador.')
                : $this->module->l('No hay ningún método de pago configurado. Contacta con el administrador.');
        } else {
            $inst = Module::getInstanceByName($paymentModuleName);
            if (!$inst || !$inst->active) {
                $paymentError = $this->module->l('El método de pago no está disponible. Contacta con el administrador.');
            } else {
                $paymentOk = true;
            }
        }

        // ---- Totales ----
        $subtotal = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
        $total    = $subtotal + $shippingCost;

        // ---- ¿Se puede pedir? ----
        $canOrder   = $carrierOk && $paymentOk && $addressCount > 0 && !$billingConfigError;
        $blockError = '';
        if ($billingConfigError) {
            $blockError = $billingConfigError;
        } elseif (!$canOrder && $addressCount > 0) {
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
            'qc_billing_warning' => $billingWarning,
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

    private function buildAddressData(array $addresses, Cart $cart, int $billingAddrId = 0): array
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
            $selected                  = $list[0]['id'];
            $cart->id_address_delivery = $selected;
            $cart->id_address_invoice  = $billingAddrId ?: $selected;
            $cart->update();
        } elseif (count($list) > 1) {
            // Verificar que la dirección de envío del carrito está en la lista visible.
            // Si no (puede ser un ID obsoleto de un test anterior), usar la primera de la lista.
            $cartDelivery = (int) $cart->id_address_delivery;
            $inList       = false;
            foreach ($list as $item) {
                if ($item['id'] === $cartDelivery) {
                    $inList = true;
                    break;
                }
            }
            $selected                  = ($cartDelivery && $inList) ? $cartDelivery : $list[0]['id'];
            $cart->id_address_delivery = $selected;
            $cart->id_address_invoice  = $billingAddrId ?: $selected;
            $cart->update();
        }

        return ['list' => $list, 'selected' => $selected];
    }
}
