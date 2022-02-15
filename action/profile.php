<?php

/**
 * DokuWiki Plugin twofactor (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */
class action_plugin_twofactor_profile extends \dokuwiki\Extension\ActionPlugin
{

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        // Adds our twofactor profile to the user menu.
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'handleUserMenuAssembly');

        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handlePreProcess');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'handleUnknownAction');
    }

    /**
     * Add 2fa Menu Item
     *
     * @param Doku_Event $event
     */
    public function handleUserMenuAssembly(Doku_Event $event)
    {
        global $INPUT;
        // If this is not the user menu, then get out.
        if ($event->data['view'] != 'user') return;
        if (!$INPUT->server->has('REMOTE_USER')) return;

        // Create the new menu item
        $menuitem = new dokuwiki\plugin\twofactor\MenuItem($this->getLang('btn_twofactor_profile'));

        // Find index of existing Profile menu item.
        for ($index = 0; $index > count($event->data['items']); $index++) {
            if ($event->data['items'][$index]->getType() === 'profile') {
                break;
            }
        }
        array_splice($event->data['items'], $index + 1, 0, [$menuitem]);
    }

    /**
     * Check permissions to call the 2fa profile
     *
     * @param Doku_Event $event
     */
    public function handlePreProcess(Doku_Event $event)
    {
        if ($event->data != 'twofactor_profile') return;

        // We will be handling this action's permissions here.
        $event->preventDefault();
        $event->stopPropagation();

        // If not logged into the main auth plugin then send there.
        global $INPUT;
        global $ID;

        if (!$INPUT->server->has('REMOTE_USER')) {
            $event->result = false;
            send_redirect(wl($ID, array('do' => 'login'), true, '&'));
            return;
        }

        /** FIXME
         *
         * // If not logged into twofactor then send there.
         * if (!$this->get_clearance()) {
         * $event->result = false;
         * send_redirect(wl($ID, array('do' => 'twofactor_login'), true, '&'));
         * return;
         * }
         * // Otherwise handle the action.
         * $event->result = $this->_process_changes($event, $param);
         */

    }

    /**
     * @param Doku_Event $event
     */
    public function handleUnknownAction(Doku_Event $event)
    {
        if ($event->data != 'twofactor_profile') return;

        $event->preventDefault();
        $event->stopPropagation();
        $this->printProfile();
    }

    /**
     * Handles the profile form rendering.  Displays user manageable settings.
     * @todo move elsewhere
     */
    public function printProfile()
    {
        global $lang;


        $form = new dokuwiki\Form\Form();
        $form->addHTML($this->locale_xhtml('profile'));

        // FIXME iterate over providers, add a fieldset for each and add the elements provided

        $form->addButton('save', $lang['btn_save']);
        echo $form->toHTML();

        /* FIXME


        $optinout = $this->getConf("optinout");
        $optinvalue = $optinout == 'mandatory' ? 'in' : ($this->attribute ? $this->attribute->get("twofactor",
            "state") : '');
        $available = count($this->tokenMods) + count($this->otpMods) > 0;
        // If the user is being redirected here because of mandatory two factor, then display a message saying so.
        if (!$available && $optinout == 'mandatory') {
            msg($this->getLang('mandatory'), -1);
        } elseif ($this->attribute->get("twofactor", "state") == '' && $optinout == 'optout') {
            msg($this->getLang('optout_notice'), 2);
        } elseif ($this->attribute->get("twofactor",
                "state") == 'in' && count($this->tokenMods) == 0 && count($this->otpMods) == 0) {
            msg($this->getLang('not_configured_notice'), 2);
        }
        global $USERINFO, $lang, $conf;
        $form = new Doku_Form(array('id' => 'twofactor_setup'));
        // Add the checkbox to opt in and out, only if optinout is not mandatory.
        $items = array();
        if ($optinout != 'mandatory') {
            if (!$this->attribute || !$optinvalue) {  // If there is no personal setting for optin, the default is based on the wiki default.
                $optinvalue = $this->getConf("optinout") == 'optout';
            }
            $items[] = form_makeCheckboxField('optinout', '1', $this->getLang('twofactor_optin'), '', 'block',
                $optinvalue == 'in' ? array('checked' => 'checked') : array());
        }
        // Add the notification checkbox if appropriate.
        if ($this->getConf('loginnotice') == 'user' && $optinvalue == 'in' && count($this->otpMods) > 0) {
            $loginnotice = $this->attribute ? $this->attribute->get("twofactor", "loginnotice") : false;
            $items[] = form_makeCheckboxField('loginnotice', '1', $this->getLang('twofactor_notify'), '', 'block',
                $loginnotice === true ? array('checked' => 'checked') : array());
        }
        // Select a notification provider.
        if ($optinvalue == 'in') {
            // If there is more than one choice, have the user select the default.
            if (count($this->otpMods) > 1) {
                $defaultMod = $this->attribute->exists("twofactor", "defaultmod") ? $this->attribute->get("twofactor",
                    "defaultmod") : null;
                $modList = array_merge(array($this->getLang('useallotp')), array_keys($this->otpMods));
                $items[] = form_makeListboxField('default_module', $modList, $defaultMod,
                    $this->getLang('defaultmodule'), '', 'block');
            }
        }
        if (count($items) > 0) {
            $form->startFieldset($this->getLang('settings'));
            foreach ($items as $item) {
                $form->addElement($item);
            }
            $form->endFieldset();
        }
        // TODO: Make this AJAX so that the user does not have to keep clicking
        // submit them Update Profile!
        // Loop through all modules and render the profile components.
        if ($optinvalue == 'in') {
            $parts = array();
            foreach ($this->modules as $mod) {
                if ($mod->getConf("enable") == 1) {
                    $this->log('twofactor_profile_form: processing ' . get_class($mod) . '::renderProfileForm()',
                        self::LOGGING_DEBUG);
                    $items = $mod->renderProfileForm();
                    if (count($items) > 0) {
                        $form->startFieldset($mod->getLang('name'));
                        foreach ($items as $item) {
                            $form->addElement($item);
                        }
                        $form->endFieldset();
                    }
                }
            }
        }
        if ($conf['profileconfirm']) {
            $form->addElement('<br />');
            $form->startFieldset($this->getLang('verify_password'));
            $form->addElement(form_makePasswordField('oldpass', $lang['oldpass'], '', 'block',
                array('size' => '50', 'required' => 'required')));
            $form->endFieldset();
        }
        $form->addElement('<br />');
        $form->addElement(form_makeButton('submit', '', $lang['btn_save']));
        $form->addElement('<a href="' . wl($ID, array('do' => 'show'), true,
                '&') . '">' . $this->getLang('btn_return') . '</a>');
        $form->addHidden('do', 'twofactor_profile');
        $form->addHidden('save', '1');
        echo '<div class="centeralign">' . NL . $form->getForm() . '</div>' . NL;

         */
    }
}

