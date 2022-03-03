<?php

namespace dokuwiki\plugin\twofactor;

use dokuwiki\Extension\Plugin;

/**
 * Manages the child plugins etc.
 */
class Manager extends Plugin
{
    /** @var Manager */
    protected static $instance;

    /** @var bool */
    protected $ready = false;

    /** @var string[] */
    protected $classes = [];

    /** @var Provider[] */
    protected $providers;

    /**
     * Constructor
     */
    protected function __construct()
    {
        $this->classes = $this->getProviderClasses();

        $attribute = plugin_load('helper', 'attribute');
        if ($attribute === null) {
            msg('The attribute plugin is not available, 2fa disabled', -1);
        }

        if (!count($this->classes)) {
            msg('No suitable 2fa providers found, 2fa disabled', -1);
            return;
        }

        $this->ready = true;
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
     * Is the plugin ready to be used?
     *
     * @return bool
     */
    public function isReady()
    {
        return $this->ready;
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
        // FIXME handle other options:
        // when optout, return true unless user opted out

        return false;
    }

    /**
     * Convenience method to get current user
     *
     * @return string
     */
    public function getUser()
    {
        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');
        if (!$user) {
            throw new \RuntimeException('2fa user specifics used before user available');
        }
        return $user;
    }

    /**
     * Get all available providers
     *
     * @return Provider[]
     */
    public function getAllProviders()
    {
        $user = $this->getUser();

        if ($this->providers === null) {
            $this->providers = [];
            foreach ($this->classes as $plugin => $class) {
                $this->providers[$plugin] = new $class($user);
            }
        }

        return $this->providers;
    }

    /**
     * Get all providers that have been already set up by the user
     *
     * @return Provider[]
     */
    public function getUserProviders()
    {
        $list = $this->getAllProviders();
        $list = array_filter($list, function ($provider) {
            return $provider->isConfigured();
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
     * Find all available provider classes
     *
     * @return string[];
     */
    protected function getProviderClasses()
    {
        // FIXME this relies on naming alone, we might want to use an action for registering
        $plugins = plugin_list('helper');
        $plugins = array_filter($plugins, function ($plugin) {
            return $plugin !== 'twofactor' && substr($plugin, 0, 9) === 'twofactor';
        });

        $classes = [];
        foreach ($plugins as $plugin) {
            $class = 'helper_plugin_' . $plugin;
            if (is_a($class, Provider::class, true)) {
                $classes[$plugin] = $class;
            }
        }

        return $classes;
    }

}
