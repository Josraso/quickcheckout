<?php
/**
 * QuickCheckout v1.3.1
 * Checkout propio con soporte de grupos de clientes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class QuickCheckout extends Module
{
    public function __construct()
    {
        $this->name            = 'quickcheckout';
        $this->tab             = 'checkout';
        $this->version         = '1.3.1';
        $this->author          = 'QuickCheckout';
        $this->need_instance   = 0;
        $this->bootstrap       = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];

        parent::__construct();

        $this->displayName = $this->l('QuickCheckout');
        $this->description = $this->l('Checkout simplificado por grupos de cliente.');
    }

    /* =========================================================
       INSTALL / UNINSTALL
    ========================================================= */

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->installConfig();
    }

    public function uninstall()
    {
        return parent::uninstall() && $this->uninstallConfig();
    }

    private function installConfig()
    {
        Configuration::updateValue('QUICKCHECKOUT_ENABLED', 0);
        Configuration::updateValue('QUICKCHECKOUT_SKIP_CART', 0);
        Configuration::updateValue('QUICKCHECKOUT_CARRIER_ID', 0);
        Configuration::updateValue('QUICKCHECKOUT_PAYMENT_MODULE', '');
        Configuration::updateValue('QUICKCHECKOUT_GROUPS', '');
        return true;
    }

    private function uninstallConfig()
    {
        Configuration::deleteByName('QUICKCHECKOUT_ENABLED');
        Configuration::deleteByName('QUICKCHECKOUT_SKIP_CART');
        Configuration::deleteByName('QUICKCHECKOUT_CARRIER_ID');
        Configuration::deleteByName('QUICKCHECKOUT_PAYMENT_MODULE');
        Configuration::deleteByName('QUICKCHECKOUT_GROUPS');
        return true;
    }

    /* =========================================================
       BACKOFFICE
    ========================================================= */

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitQuickCheckout')) {
            $output .= $this->postProcess();
        }
        return $output . $this->renderConfigForm();
    }

    private function postProcess()
    {
        Configuration::updateValue('QUICKCHECKOUT_ENABLED',        (int) Tools::getValue('QUICKCHECKOUT_ENABLED'));
        Configuration::updateValue('QUICKCHECKOUT_SKIP_CART',      (int) Tools::getValue('QUICKCHECKOUT_SKIP_CART'));
        Configuration::updateValue('QUICKCHECKOUT_CARRIER_ID',     (int) Tools::getValue('QUICKCHECKOUT_CARRIER_ID'));
        Configuration::updateValue('QUICKCHECKOUT_PAYMENT_MODULE', pSQL(Tools::getValue('QUICKCHECKOUT_PAYMENT_MODULE')));

        // Los checkboxes del HelperForm llegan como QUICKCHECKOUT_GROUPS_{id_group}
        // Recorremos todos los grupos y vemos cuáles están marcados
        $allGroups    = Group::getGroups($this->context->language->id);
        $selectedIds  = [];
        foreach ($allGroups as $g) {
            $key = 'QUICKCHECKOUT_GROUPS_' . (int) $g['id_group'];
            if (Tools::getValue($key)) {
                $selectedIds[] = (int) $g['id_group'];
            }
        }
        Configuration::updateValue('QUICKCHECKOUT_GROUPS', implode(',', $selectedIds));

        return $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
    }

    private function renderConfigForm()
    {
        // Transportistas activos
        $carriers    = Carrier::getCarriers($this->context->language->id, true);
        $carrierList = [['id_carrier' => 0, 'name' => $this->l('-- Selecciona un transportista --')]];
        foreach ($carriers as $c) {
            $carrierList[] = ['id_carrier' => (int) $c['id_carrier'], 'name' => $c['name']];
        }

        // Módulos de pago instalados
        $paymentModules = PaymentModule::getInstalledPaymentModules();
        $paymentList    = [['module' => '', 'name' => $this->l('-- Selecciona un método de pago --')]];
        foreach ($paymentModules as $p) {
            $inst          = Module::getInstanceByName($p['name']);
            $paymentList[] = [
                'module' => $p['name'],
                'name'   => $inst ? $inst->displayName : $p['name'],
            ];
        }

        // Grupos de clientes
        $allGroups     = Group::getGroups($this->context->language->id);
        $savedGroupIds = $this->getConfiguredGroupIds();

        $groupList = [];
        foreach ($allGroups as $g) {
            $groupList[] = [
                'id_group' => (int) $g['id_group'],
                'name'     => $g['name'],
            ];
        }

        $fields_form = [[
            'form' => [
                'legend' => ['title' => $this->l('Configuración de QuickCheckout'), 'icon' => 'icon-cog'],
                'input'  => [
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Activar módulo'),
                        'name'    => 'QUICKCHECKOUT_ENABLED',
                        'is_bool' => true,
                        'values'  => [
                            ['id' => 'qc_on',  'value' => 1, 'label' => $this->l('Sí')],
                            ['id' => 'qc_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Activa o desactiva completamente QuickCheckout.'),
                    ],
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Saltar página del carrito'),
                        'name'    => 'QUICKCHECKOUT_SKIP_CART',
                        'is_bool' => true,
                        'values'  => [
                            ['id' => 'sc_on',  'value' => 1, 'label' => $this->l('Sí')],
                            ['id' => 'sc_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Si está activo, los grupos permitidos van directamente al checkout sin pasar por el carrito.'),
                    ],
                    [
                        'type'   => 'checkbox',
                        'label'  => $this->l('Grupos de clientes con acceso a QuickCheckout'),
                        'name'   => 'QUICKCHECKOUT_GROUPS',
                        'values' => [
                            'query' => $groupList,
                            'id'    => 'id_group',
                            'name'  => 'name',
                        ],
                        'desc' => $this->l('Solo los grupos marcados usarán QuickCheckout. El resto irá al checkout nativo. Si no marcas ninguno nadie usará QuickCheckout.'),
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Transportista asignado'),
                        'name'    => 'QUICKCHECKOUT_CARRIER_ID',
                        'options' => ['query' => $carrierList, 'id' => 'id_carrier', 'name' => 'name'],
                        'desc'    => $this->l('Transportista asignado automáticamente. Si no cubre la dirección del cliente se bloqueará el botón.'),
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Método de pago asignado'),
                        'name'    => 'QUICKCHECKOUT_PAYMENT_MODULE',
                        'options' => ['query' => $paymentList, 'id' => 'module', 'name' => 'name'],
                        'desc'    => $this->l('Método de pago asignado automáticamente. Debe ser interno, sin redirección externa.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Guardar')],
            ],
        ]];

        $helper                        = new HelperForm();
        $helper->show_toolbar          = false;
        $helper->table                 = $this->table;
        $helper->module                = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->submit_action         = 'submitQuickCheckout';
        $helper->currentIndex          = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->fields_value['QUICKCHECKOUT_ENABLED']        = Configuration::get('QUICKCHECKOUT_ENABLED');
        $helper->fields_value['QUICKCHECKOUT_SKIP_CART']      = Configuration::get('QUICKCHECKOUT_SKIP_CART');
        $helper->fields_value['QUICKCHECKOUT_CARRIER_ID']     = Configuration::get('QUICKCHECKOUT_CARRIER_ID');
        $helper->fields_value['QUICKCHECKOUT_PAYMENT_MODULE'] = Configuration::get('QUICKCHECKOUT_PAYMENT_MODULE');

        // CLAVE: el HelperForm con type=checkbox busca fields_value con clave NOMBRE_ID
        foreach ($groupList as $g) {
            $helper->fields_value['QUICKCHECKOUT_GROUPS_' . $g['id_group']] =
                in_array($g['id_group'], $savedGroupIds) ? true : false;
        }

        return $helper->generateForm($fields_form);
    }

    /* =========================================================
       HELPERS PÚBLICOS
    ========================================================= */

    public function isQuickCheckoutEnabled()
    {
        return (bool) Configuration::get('QUICKCHECKOUT_ENABLED');
    }

    public function isSkipCartEnabled()
    {
        return $this->isQuickCheckoutEnabled() && (bool) Configuration::get('QUICKCHECKOUT_SKIP_CART');
    }

    public function getConfiguredCarrierId()
    {
        return (int) Configuration::get('QUICKCHECKOUT_CARRIER_ID');
    }

    public function getConfiguredPaymentModule()
    {
        return (string) Configuration::get('QUICKCHECKOUT_PAYMENT_MODULE');
    }

    public function getQuickCheckoutUrl()
    {
        return $this->context->link->getModuleLink('quickcheckout', 'checkout');
    }

    /**
     * Devuelve los IDs de grupo configurados como array de enteros.
     */
    public function getConfiguredGroupIds(): array
    {
        $raw = Configuration::get('QUICKCHECKOUT_GROUPS');
        if (empty($raw)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /**
     * Comprueba si el cliente/visitante actual tiene acceso a QuickCheckout.
     */
    public function currentCustomerHasAccess(): bool
    {
        $allowedGroups = $this->getConfiguredGroupIds();
        if (empty($allowedGroups)) {
            return false;
        }

        $context  = Context::getContext();
        $customer = $context->customer;

        if ($customer->isLogged()) {
            $customerGroups = $customer->getGroups();
        } else {
            $customerGroups = [(int) Configuration::get('PS_UNIDENTIFIED_GROUP')];
        }

        return !empty(array_intersect($allowedGroups, $customerGroups));
    }

    /* =========================================================
       HOOKS
    ========================================================= */

    public function hookDisplayHeader($params)
    {
        if (!$this->isQuickCheckoutEnabled()) {
            return;
        }

        $controller = $this->context->controller;

        if (!($controller instanceof ModuleFrontController)) {
            return;
        }
        if ($controller->module->name !== 'quickcheckout') {
            return;
        }

        $controller->addCSS($this->_path . 'views/css/quickcheckout.css');
        $controller->addJS($this->_path . 'views/js/quickcheckout.js');

        Media::addJsDef([
            'quickcheckout' => [
                'processUrl' => $this->context->link->getModuleLink('quickcheckout', 'processorder'),
                'cartUrl'    => $this->context->link->getPageLink('cart', null, null, ['action' => 'show']),
                'labels'     => [
                    'confirm'    => $this->l('Completar pedido'),
                    'processing' => $this->l('Procesando...'),
                    'error'      => $this->l('Ha ocurrido un error. Por favor, inténtalo de nuevo.'),
                ],
            ],
        ]);
    }
}
