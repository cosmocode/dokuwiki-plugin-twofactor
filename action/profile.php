<?php

use dokuwiki\Form\Form;
use dokuwiki\plugin\twofactor\Manager;
use dokuwiki\plugin\twofactor\MenuItem;

/**
 * DokuWiki Plugin twofactor (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */
class action_plugin_twofactor_profile extends \dokuwiki\Extension\ActionPlugin
{
    /** @var Manager */
    protected $manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->manager = Manager::getInstance();
    }

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        if (!$this->manager->isReady()) return;

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
        }

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
     * Handle POSTs for provider forms
     */
    protected function handleProfile()
    {
        global $INPUT;
        if (!checkSecurityToken()) return;

        if ($INPUT->has('2fa_optout') && $this->getConf('optinout') === 'optout') {
            $this->manager->userOptOutState($INPUT->bool('optout'));
            return;
        }

        if (!$INPUT->has('provider')) return;
        $providers = $this->manager->getAllProviders();
        if (!isset($providers[$INPUT->str('provider')])) return;
        $provider = $providers[$INPUT->str('provider')];

        if (!$provider->isConfigured()) {
            $provider->handleProfileForm();
        } elseif ($INPUT->has('2fa_delete')) {
            $provider->reset();
            $this->manager->getUserDefaultProvider(); // resets the default to the next available
        } elseif ($INPUT->has('2fa_default')) {
            $this->manager->setUserDefaultProvider($provider);
        }
    }

    /**
     * Handles the profile form rendering.  Displays user manageable settings.
     *
     * @todo split up in smaller methods
     */
    protected function printProfile()
    {
        global $lang;

        echo $this->locale_xhtml('profile');

        switch ($this->getConf('optinout')) {
            case 'optout':
                $form = new Form(['method' => 'post']);
                $form->addFieldsetOpen('Opt Out');
                $form->addHTML('<p>This wiki highly recomends 2fa, if you don\'t want it opt out here...</p>');
                $cb = $form->addCheckbox('optout', 'I understand my account is less secure. I opt out');
                if ($this->manager->userOptOutState()) {
                    $cb->attr('checked', 'checked');
                }
                $form->addButton('2fa_optout', 'Save');
                $form->addFieldsetClose();
                echo $form->toHTML();

                // when user opted out, don't show the rest of the form
                if ($this->manager->userOptOutState()) {
                    return;
                }
                break;
            case 'optin':
                echo '<p>Using 2fa is not mandatory but recommended. Please configure at least one of the providers...</p>';
                break;
            case 'mandatory':
                echo '<p>The use of 2fa is mandatory you must configure at least one provider before using the wiki...</p>';
                break;
        }

        // default provider selection
        $userproviders = $this->manager->getUserProviders();
        $default = $this->manager->getUserDefaultProvider();
        if (count($userproviders)) {
            $form = new Form(['method' => 'POST']);
            $form->addFieldsetOpen('Default Provider');
            foreach ($userproviders as $provider) {
                $form->addRadioButton('provider', $provider->getLabel())
                     ->val($provider->getProviderID())
                     ->attr('checked', $provider->getProviderID() === $default->getProviderID());
            }
            $form->addButton('2fa_default', $lang['btn_save'])->attr('submit');
            $form->addFieldsetClose();
            echo $form->toHTML();
        }

        // iterate over all providers
        $providers = $this->manager->getAllProviders();
        foreach ($providers as $provider) {
            $form = new dokuwiki\Form\Form(['method' => 'POST']);
            $form->setHiddenField('do', 'twofactor_profile');
            $form->setHiddenField('provider', $provider->getProviderID());
            $form->addFieldsetOpen($provider->getLabel());
            $provider->renderProfileForm($form);
            if (!$provider->isConfigured()) {
                $form->addButton('2fa_submit', $lang['btn_save'])->attr('submit');
            } else {
                $form->addButton('2fa_delete', $lang['btn_delete'])->attr('submit');
            }
            $form->addFieldsetClose();
            echo $form->toHTML();
        }
    }
}

