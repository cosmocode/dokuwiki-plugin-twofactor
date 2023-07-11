<?php

namespace dokuwiki\plugin\twofactor;

use dokuwiki\Extension\Event;
use dokuwiki\Extension\Plugin;
use dokuwiki\Form\Form;

/**
 * Manages the child plugins etc.
 */
class Manager extends Plugin
{
    /**
     * Generally all our actions should run before all other plugins
     */
    const EVENT_PRIORITY = -5000;

    /** @var Manager */
    protected static $instance;

    /** @var bool */
    protected $ready = false;

    /** @var Provider[] */
    protected $providers;

    /** @var bool */
    protected $providersInitialized;

    /** @var string */
    protected $user;

    /**
     * Constructor
     */
    protected function __construct()
    {
        $attribute = plugin_load('helper', 'attribute');
        if ($attribute === null) {
            msg('The attribute plugin is not available, 2fa disabled', -1);
            return;
        }

        $this->loadProviders();
        if (!count($this->providers)) {
            msg('No suitable 2fa providers found, 2fa disabled', -1);
            return;
        }

        $this->ready = true;
    }

    /**
     * This is not a conventional class, plugin name can't be determined automatically
     * @inheritdoc
     */
    public function getPluginName()
    {
        return 'twofactor';
    }

    /**
     * Get the instance of this singleton
     *
     * @return Manager
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Manager();
        }
        return self::$instance;
    }

    /**
     * Destroy the singleton instance
     */
    public static function destroyInstance()
    {
        self::$instance = null;
    }

    /**
     * Is the plugin ready to be used?
     *
     * @return bool
     */
    public function isReady()
    {
        if (!$this->ready) return false;
        try {
            $this->getUser();
        } catch (\Exception $ignored) {
            return false;
        }

        return true;
    }

    /**
     * Is a 2fa login required?
     *
     * @return bool
     */
    public function isRequired()
    {
        $set = $this->getConf('optinout');
        if ($set === 'mandatory') {
            return true;
        }
        if ($set === 'optout') {
            $setting = new Settings('twofactor', $this->getUser());
            if ($setting->get('state') !== 'optout') {
                return true;
            }
        }

        return false;
    }

    /**
     * Convenience method to get current user
     *
     * @return string
     */
    public function getUser()
    {
        if ($this->user === null) {
            global $INPUT;
            $this->user = $INPUT->server->str('REMOTE_USER');
        }

        if (!$this->user) {
            throw new \RuntimeException('2fa user specifics used before user available');
        }
        return $this->user;
    }

    /**
     * Set the current user
     *
     * This is only needed when running 2fa actions for a non-logged-in user (e.g. during password reset)
     */
    public function setUser($user)
    {
        if ($this->user) {
            throw new \RuntimeException('2fa user already set, cannot be changed');
        }
        $this->user = $user;
    }

    /**
     * Get or set the user opt-out state
     *
     * true: user opted out
     * false: user did not opt out
     *
     * @param bool|null $set
     * @return bool
     */
    public function userOptOutState($set = null)
    {
        // is optout allowed?
        if ($this->getConf('optinout') !== 'optout') return false;

        $settings = new Settings('twofactor', $this->getUser());

        if ($set === null) {
            $current = $settings->get('state');
            return $current === 'optout';
        }

        if ($set) {
            $settings->set('state', 'optout');
        } else {
            $settings->delete('state');
        }
        return $set;
    }

    /**
     * Get all available providers
     *
     * @return Provider[]
     */
    public function getAllProviders()
    {
        $user = $this->getUser();

        if (!$this->providersInitialized) {
            // initialize providers with user and ensure the ID is correct
            foreach ($this->providers as $providerID => $provider) {
                if ($providerID !== $provider->getProviderID()) {
                    $this->providers[$provider->getProviderID()] = $provider;
                    unset($this->providers[$providerID]);
                }
                $provider->init($user);
            }
            $this->providersInitialized = true;
        }

        return $this->providers;
    }

    /**
     * Get all providers that have been already set up by the user
     *
     * @param bool $configured when set to false, all providers NOT configured are returned
     * @return Provider[]
     */
    public function getUserProviders($configured = true)
    {
        $list = $this->getAllProviders();
        $list = array_filter($list, function ($provider) use ($configured) {
            return $configured ? $provider->isConfigured() : !$provider->isConfigured();
        });

        return $list;
    }

    /**
     * Get the instance of the given provider
     *
     * @param string $providerID
     * @return Provider
     * @throws \Exception
     */
    public function getUserProvider($providerID)
    {
        $providers = $this->getUserProviders();
        if (isset($providers[$providerID])) return $providers[$providerID];
        throw new \Exception('Uncofigured provider requested');
    }

    /**
     * Get the user's default provider if any
     *
     * Autoupdates the apropriate setting
     *
     * @return Provider|null
     */
    public function getUserDefaultProvider()
    {
        $setting = new Settings('twofactor', $this->getUser());
        $default = $setting->get('defaultmod');
        $providers = $this->getUserProviders();

        if (isset($providers[$default])) return $providers[$default];
        // still here? no valid setting. Use first available one
        $first = array_shift($providers);
        if ($first !== null) {
            $this->setUserDefaultProvider($first);
        }
        return $first;
    }

    /**
     * Set the default provider for the user
     *
     * @param Provider $provider
     * @return void
     */
    public function setUserDefaultProvider($provider)
    {
        $setting = new Settings('twofactor', $this->getUser());
        $setting->set('defaultmod', $provider->getProviderID());
    }

    /**
     * Load all available provider classes
     *
     * @return Provider[];
     */
    protected function loadProviders()
    {
        /** @var Provider[] providers */
        $this->providers = [];
        $event = new Event('PLUGIN_TWOFACTOR_PROVIDER_REGISTER', $this->providers);
        $event->advise_before(false);
        $event->advise_after();
        return $this->providers;
    }


    /**
     * Verify a given code
     *
     * @return bool
     * @throws \Exception
     */
    public function verifyCode($code, $providerID)
    {
        if (!$code) return false;
        if (!$providerID) return false;
        $provider = $this->getUserProvider($providerID);
        $ok = $provider->checkCode($code);
        if (!$ok) return false;

        return true;
    }

    /**
     * Get the form to enter a code for a given provider
     *
     * Calling this will generate a new code and transmit it.
     *
     * @param string $providerID
     * @return Form
     */
    public function getCodeForm($providerID)
    {
        $providers = $this->getUserProviders();
        $provider = $providers[$providerID] ?? $this->getUserDefaultProvider();
        // remove current provider from list
        unset($providers[$provider->getProviderID()]);

        $form = new Form(['method' => 'POST']);
        $form->setHiddenField('do', 'twofactor_login');
        $form->setHiddenField('2fa_provider', $provider->getProviderID());

        $form->addFieldsetOpen($provider->getLabel());
        try {
            $code = $provider->generateCode();
            $info = $provider->transmitMessage($code);
            $form->addHTML('<p>' . hsc($info) . '</p>');
            $form->addElement(new OtpField('2fa_code'));
            $form->addTagOpen('div')->addClass('buttons');
            $form->addButton('2fa', $this->getLang('btn_confirm'))->attr('type', 'submit');
            $form->addTagClose('div');
        } catch (\Exception $e) {
            msg(hsc($e->getMessage()), -1); // FIXME better handling
        }
        $form->addFieldsetClose();

        if (count($providers)) {
            $form->addFieldsetOpen('Alternative methods')->addClass('list');
            foreach ($providers as $prov) {
                $form->addButton('2fa_provider', $prov->getLabel())
                    ->attr('type', 'submit')
                    ->attr('value', $prov->getProviderID());
            }
            $form->addFieldsetClose();
        }

        return $form;
    }
}
