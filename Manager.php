<?php

namespace dokuwiki\plugin\twofactor;

/**
 * Manages the child plugins etc.
 */
class Manager
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
     * Get all available providers
     *
     * @return Provider[]
     */
    public function getAllProviders()
    {
        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');
        if (!$user) {
            throw new \RuntimeException('2fa Providers instantiated before user available');
        }

        if ($this->providers === null) {
            $this->providers = [];
            foreach ($this->classes as $class) {
                $this->providers[] = new $class($user);
            }
        }

        return $this->providers;
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
                $classes[] = $class;
            }
        }

        return $classes;
    }

}
