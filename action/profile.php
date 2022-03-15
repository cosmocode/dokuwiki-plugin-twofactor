<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Form\Form;
use dokuwiki\plugin\twofactor\Manager;
use dokuwiki\plugin\twofactor\MenuItem;

/**
 * DokuWiki Plugin twofactor (Action Component)
 *
 * This handles the 2fa profile screen where users can set their 2fa preferences and configure the
 * providers they want to use.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */
class action_plugin_twofactor_profile extends ActionPlugin
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
        if (!(Manager::getInstance())->isReady()) return;

        global $INPUT;
        // If this is not the user menu, then get out.
        if ($event->data['view'] != 'user') return;
        if (!$INPUT->server->has('REMOTE_USER')) return;

        // Create the new menu item
        $menuitem = new MenuItem($this->getLang('btn_twofactor_profile'));

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
        if (!(Manager::getInstance())->isReady()) return;

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

        if (strtolower($INPUT->server->str('REQUEST_METHOD')) == 'post') {
            if ($this->handleProfile()) {
                // we might have changed something important, make sure the whole workflow restarts
                send_redirect(wl($ID, ['do' => 'twofactor_profile'], true, '&'));
            }

        }
    }

    /**
     * Output the forms
     *
     * @param Doku_Event $event
     */
    public function handleUnknownAction(Doku_Event $event)
    {
        if ($event->data != 'twofactor_profile') return;
        if (!(Manager::getInstance())->isReady()) return;
        $event->preventDefault();
        $event->stopPropagation();
        global $INPUT;

        echo '<div class="plugin_twofactor_profile">';
        echo $this->locale_xhtml('profile');

        if ($INPUT->has('twofactor_setup')) {
            $this->printProviderSetup();
        } else {
            if (!$this->printOptOutForm()) {
                $this->printConfiguredProviders();

                $this->printProviderSetupSelect();
            }
        }
        echo '</div>';
    }

    /**
     * Handle POSTs for provider forms
     *
     * @return bool should a redirect be made?
     */
    protected function handleProfile()
    {
        global $INPUT;
        if (!checkSecurityToken()) return true;
        $manager = Manager::getInstance();

        if ($INPUT->has('twofactor_optout') && $this->getConf('optinout') === 'optout') {
            $manager->userOptOutState($INPUT->bool('optout'));
            return true;
        }

        if (!$INPUT->has('provider')) return true;
        $providers = $manager->getAllProviders();
        if (!isset($providers[$INPUT->str('provider')])) return true;
        $provider = $providers[$INPUT->str('provider')];

        if ($INPUT->has('twofactor_delete')) {
            $provider->reset();
            $manager->getUserDefaultProvider(); // resets the default to the next available
            return true;
        }

        if ($INPUT->has('twofactor_default')) {
            $manager->setUserDefaultProvider($provider);
            return true;
        }

        if (!$provider->isConfigured()) {
            $provider->handleProfileForm();
            return $provider->isConfigured(); // redirect only if configuration finished
        }

        return true;
    }

    /**
     * Print the opt-out form (if available)
     *
     * @return bool true if the user currently opted out
     */
    protected function printOptOutForm()
    {
        $manager = Manager::getInstance();
        $optedout = false;
        $setting = $this->getConf('optinout');

        echo '<section class="state">';
        echo $this->locale_xhtml($setting);

        // optout form
        if ($setting == 'optout') {
            $form = new Form(['method' => 'post']);
            $cb = $form->addCheckbox('optout', $this->getLang('optout'));
            if ($manager->userOptOutState()) {
                $cb->attr('checked', 'checked');
            }
            $form->addButton('twofactor_optout', $this->getLang('btn_confirm'));
            echo $form->toHTML();

            // when user opted out, don't show the rest of the form
            if ($manager->userOptOutState()) {
                $optedout = true;
            }
        }

        echo '</section>';
        return $optedout;
    }

    /**
     * Print the form where a user can select their default provider
     *
     * @return void
     */
    protected function printConfiguredProviders()
    {
        $manager = Manager::getInstance();

        $userproviders = $manager->getUserProviders();
        $default = $manager->getUserDefaultProvider();
        if (!$userproviders) return;

        $form = new Form(['method' => 'POST']);
        $form->addFieldsetOpen($this->getLang('providers'));
        foreach ($userproviders as $provider) {
            $el = $form->addRadioButton('provider', $provider->getLabel())->val($provider->getProviderID());
            if ($provider->getProviderID() === $default->getProviderID()) {
                $el->attr('checked', 'checked');
                $el->getLabel()->val($provider->getLabel() . ' ' . $this->getLang('default'));
            }
        }

        $form->addTagOpen('div')->addClass('buttons');
        $form->addButton('twofactor_default', $this->getLang('btn_default'))->attr('submit');
        $form->addButton('twofactor_delete', $this->getLang('btn_remove'))
             ->addClass('twofactor_delconfirm')->attr('submit');
        $form->addTagClose('div');

        $form->addFieldsetClose();
        echo $form->toHTML();
    }

    /**
     * List providers available for adding
     *
     * @return void
     */
    protected function printProviderSetupSelect()
    {
        $manager = Manager::getInstance();
        $available = $manager->getUserProviders(false);
        if (!$available) return;

        $options = [];
        foreach ($available as $provider) {
            $options[$provider->getProviderID()] = $provider->getLabel();
        }

        $form = new Form(['method' => 'post']);
        $form->setHiddenField('do', 'twofactor_profile');
        $form->setHiddenField('init', '1');
        $form->addFieldsetOpen($this->getLang('newprovider'));
        $form->addDropdown('provider', $options, $this->getLang('provider'));

        $form->addTagOpen('div')->addClass('buttons');
        $form->addButton('twofactor_setup', $this->getLang('btn_setup'))->attr('type', 'submit');
        $form->addTagClose('div');

        $form->addFieldsetClose();
        echo $form->toHTML();
    }

    /**
     * Display the setup form for a provider
     *
     * @return void
     */
    protected function printProviderSetup()
    {
        global $lang;
        global $INPUT;

        $providerID = $INPUT->str('provider');
        $providers = (Manager::getInstance())->getUserProviders(false);
        if (!isset($providers[$providerID])) return;
        $provider = $providers[$providerID];

        $form = new Form(['method' => 'POST', 'class' => 'provider-' . $providerID]);
        $form->setHiddenField('do', 'twofactor_profile');
        $form->setHiddenField('twofactor_setup', '1');
        $form->setHiddenField('provider', $provider->getProviderID());

        $form->addFieldsetOpen($provider->getLabel());
        $provider->renderProfileForm($form);

        $form->addTagOpen('div')->addClass('buttons');
        $form->addButton('twofactor_submit', $this->getLang('btn_confirm'))->attr('type', 'submit');
        $form->addButton('twofactor_delete', $lang['btn_cancel'])->attr('type', 'submit');
        $form->addTagClose('div');

        $form->addFieldsetClose();
        echo $form->toHTML();
    }

}


