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
            $this->handleProfile();
            // we might have changed something important, make sure the whole workflow restarts
            send_redirect(wl($ID, ['do' => 'twofactor_profile'], true, '&'));
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

        echo '<div class="plugin_twofactor_profile">';
        echo $this->locale_xhtml('profile');
        if ($this->printOptOutForm()) return;
        $this->printDefaultProviderForm();
        $this->printProviderForms();
        echo '</div>';

    }

    /**
     * Handle POSTs for provider forms
     */
    protected function handleProfile()
    {
        global $INPUT;
        if (!checkSecurityToken()) return;
        $manager = Manager::getInstance();

        if ($INPUT->has('2fa_optout') && $this->getConf('optinout') === 'optout') {
            $manager->userOptOutState($INPUT->bool('optout'));
            return;
        }

        if (!$INPUT->has('provider')) return;
        $providers = $manager->getAllProviders();
        if (!isset($providers[$INPUT->str('provider')])) return;
        $provider = $providers[$INPUT->str('provider')];

        if (!$provider->isConfigured()) {
            $provider->handleProfileForm();
        } elseif ($INPUT->has('2fa_delete')) {
            $provider->reset();
            $manager->getUserDefaultProvider(); // resets the default to the next available
        } elseif ($INPUT->has('2fa_default')) {
            $manager->setUserDefaultProvider($provider);
        }
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
            $form->addButton('2fa_optout', $this->getLang('btn_confirm'));
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
    protected function printDefaultProviderForm()
    {
        global $lang;
        $manager = Manager::getInstance();

        $userproviders = $manager->getUserProviders();
        $default = $manager->getUserDefaultProvider();
        if (count($userproviders)) {
            $form = new Form(['method' => 'POST']);
            $form->addFieldsetOpen($this->getLang('defaultprovider'));
            foreach ($userproviders as $provider) {
                $form->addRadioButton('provider', $provider->getLabel())
                     ->val($provider->getProviderID())
                     ->attr('checked', $provider->getProviderID() === $default->getProviderID());
            }
            $form->addButton('2fa_default', $lang['btn_save'])->attr('submit');
            $form->addFieldsetClose();
            echo $form->toHTML();
        }
    }

    /**
     * Prints a form for each available provider to configure
     *
     * @return void
     */
    protected function printProviderForms()
    {
        global $lang;
        $manager = Manager::getInstance();

        echo '<section class="providers">';
        echo '<h2>' . $this->getLang('providers') . '</h2>';

        echo '<div>';
        $providers = $manager->getAllProviders();
        foreach ($providers as $provider) {
            $form = new dokuwiki\Form\Form(['method' => 'POST']);
            $form->setHiddenField('do', 'twofactor_profile');
            $form->setHiddenField('provider', $provider->getProviderID());
            $form->addFieldsetOpen($provider->getLabel());
            $provider->renderProfileForm($form);
            if (!$provider->isConfigured()) {
                $form->addButton('2fa_submit', $lang['btn_save'])->attr('type', 'submit');
            } else {
                $form->addButton('2fa_delete', $lang['btn_delete'])
                     ->addClass('twofactor_delconfirm')
                     ->attr('type', 'submit');
            }
            $form->addFieldsetClose();
            echo $form->toHTML();
        }
        echo '</div>';

        echo '</section>';
    }
}


