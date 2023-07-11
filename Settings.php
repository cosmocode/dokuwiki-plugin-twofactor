<?php

namespace dokuwiki\plugin\twofactor;

/**
 * Encapsulate the attribute plugin for per user/provider storage
 *
 * @todo maybe do our own settings saving with backwards compatibility to attribute?
 */
class Settings
{

    /** @var \helper_plugin_attribute */
    protected $attribute;

    /** @var string Identifier of the provider these settings are for */
    protected $providerID;

    /** @var string Login of the user these settings are for */
    protected $user;

    /**
     * @param string $module Name of the provider
     * @param string $user User login
     */
    public function __construct($module, $user)
    {
        $this->attribute = plugin_load('helper', 'attribute');
        if ($this->attribute === null) throw new \RuntimeException('attribute plugin not found');
        $this->attribute->setSecure(false);

        $this->providerID = $module;
        $this->user = $user;
    }

    /**
     * Return a list of users that have settings for the given module
     *
     * @param $module
     * @return array|bool
     */
    static public function findUsers($module)
    {
        /** @var \helper_plugin_attribute $attribute */
        $attribute = plugin_load('helper', 'attribute');
        if ($attribute === null) throw new \RuntimeException('attribute plugin not found');

        return $attribute->enumerateUsers($module);
    }

    /**
     * Get the user these settings are for
     *
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Check if a setting exists
     *
     * @param string $key Settings key
     * @return bool
     */
    public function has($key)
    {
        return $this->attribute->exists($this->providerID, $key, $this->user);
    }

    /**
     * Get a stored setting
     *
     * @param string $key Settings key
     * @param mixed $default Default to return when no setting available
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $success = false;
        $data = $this->attribute->get($this->providerID, $key, $success, $this->user);
        if (!$success) return $default;
        return $data;
    }

    /**
     * Store a settings value
     *
     * @param string $key Settings key
     * @param mixed $value Value to store
     * @return bool
     */
    public function set($key, $value)
    {
        return $this->attribute->set($this->providerID, $key, $value, $this->user);
    }

    /**
     * Delete a settings value
     *
     * @param string $key Settings key
     * @return bool
     */
    public function delete($key)
    {
        return $this->attribute->del($this->providerID, $key, $this->user);
    }

    /**
     * Remove all settings
     *
     * @return bool
     */
    public function purge()
    {
        return $this->attribute->purge($this->providerID, $this->user);
    }

}
