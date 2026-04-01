<?php
/**
 * QuickCheckout — Override CartController
 * Salta el carrito SOLO si todas las condiciones se cumplen.
 * En cualquier otro caso deja pasar al comportamiento nativo.
 */

class CartController extends CartControllerCore
{
    public function init()
    {
        parent::init();

        // No interceptar AJAX (añadir/quitar productos del carrito)
        if ($this->ajax) {
            return;
        }

        // Solo cuando se muestra el carrito, no en otras acciones
        $action = Tools::getValue('action');
        if ($action !== 'show' && $action !== '') {
            return;
        }

        if (!(int) Configuration::get('QUICKCHECKOUT_ENABLED')) {
            return;
        }

        if (!(int) Configuration::get('QUICKCHECKOUT_SKIP_CART')) {
            return;
        }

        $module = Module::getInstanceByName('quickcheckout');
        if (!Validate::isLoadedObject($module) || !$module->active) {
            return;
        }

        if (!$module->currentCustomerHasAccess()) {
            return;
        }

        $context     = Context::getContext();
        $checkoutUrl = $context->link->getModuleLink('quickcheckout', 'checkout');

        if (!$context->customer->isLogged()) {
            $loginUrl = $context->link->getPageLink(
                'authentication', null, null,
                ['back' => urlencode($checkoutUrl)]
            );
            Tools::redirect($loginUrl);
        }

        Tools::redirect($checkoutUrl);
    }
}
