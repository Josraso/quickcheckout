<?php
/**
 * QuickCheckout — Override OrderController
 * Redirige a nuestra página SOLO si:
 *  1. El módulo está activo
 *  2. El cliente pertenece a un grupo con acceso
 *  3. No estamos ya en nuestra página (anti-bucle)
 *  4. No es una petición AJAX
 */

class OrderController extends OrderControllerCore
{
    public function init()
    {
        // Guardia anti-bucle: si ya venimos de quickcheckout, seguimos normal
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, 'quickcheckout') !== false) {
            parent::init();
            return;
        }

        // Solo si el módulo está activo
        if (!(int) Configuration::get('QUICKCHECKOUT_ENABLED')) {
            parent::init();
            return;
        }

        $module = Module::getInstanceByName('quickcheckout');
        if (!Validate::isLoadedObject($module) || !$module->active) {
            parent::init();
            return;
        }

        // Solo si el cliente tiene acceso según su grupo
        if (!$module->currentCustomerHasAccess()) {
            parent::init();
            return;
        }

        // Todo ok → redirigir a nuestra página
        Tools::redirect(Context::getContext()->link->getModuleLink('quickcheckout', 'checkout'));
    }
}
