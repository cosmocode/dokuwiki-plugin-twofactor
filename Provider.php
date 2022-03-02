<?php

namespace dokuwiki\plugin\twofactor;

use dokuwiki\Extension\Plugin;
use dokuwiki\Form\Form;
use dokuwiki\Utf8\PhpString;

/**
 * Baseclass for all second factor providers
 */
abstract class Provider extends Plugin
{
    /** @var Settings */
    protected $settings;

    /** @var string */
    protected $providerID;

    /**
     * Initializes the provider for the given user
     * @param string $user Current user
     * @throws \Exception
     */
    public function __construct($user)
    {
        $this->providerID = substr(get_called_class(), strlen('helper_plugin_'));
        $this->settings = new Settings($this->providerID, $user);
    }

    /**
     * The ID of this provider
     *
     * @return string
     */
    public function getProviderID()
    {
        return $this->providerID;
    }

    /**
     * Pretty Label for this provider
     *
     * @return string
     */
    public function getLabel()
    {
        return PhpString::ucfirst($this->providerID);
    }

    /**
     * Clear all settings
     */
    public function reset()
    {
        $this->settings->purge();
    }

    /**
     * Has this provider been fully configured by the user and thus can be used
     * for authentication?
     *
     * @return bool
     */
    abstract public function isConfigured();

    /**
     * Render the configuration form
     *
     * This method should add the needed form elements to (re)configure the provider.
     * The contents of the form may change depending on the current settings.
     *
     * No submit button should be added - this is handled by the main plugin.
     *
     * @param Form $form The initial form to add elements to
     * @return Form
     */
    abstract public function renderProfileForm(Form $form);

    /**
     * Handle any input data
     *
     * @return void
     */
    abstract public function handleProfileForm();

    /**
     * Transmits the code to the user
     *
     * @param string $code The code to transmit
     * @return string Informational message for the user
     * @throw \Exception when the message can't be sent
     */
    abstract public function transmitMessage($code);

    // region OTP methods

    /**
     * Create and store a new secret for this provider
     *
     * @return string the new secret
     * @throws \Exception when no suitable random source is available
     */
    public function initSecret()
    {
        $ga = new GoogleAuthenticator();
        $secret = $ga->createSecret();

        $this->settings->set('secret', $secret);
        return $secret;
    }

    /**
     * Generate an auth code
     *
     * @return string
     * @throws \Exception when no code can be created
     */
    public function generateCode()
    {
        $secret = $this->settings->get('secret');
        if (!$secret) throw new \Exception('No secret for provider ' . $this->getProviderID());

        $ga = new GoogleAuthenticator();
        return $ga->getCode($secret);
    }

    /**
     * Check the given code
     *
     * @param string $code
     * @param int $tolerance
     * @return string
     * @throws \Exception when no code can be created
     */
    public function checkCode($code, $tolerance = 2)
    {
        $secret = $this->settings->get('secret');
        if (!$secret) throw new \Exception('No secret for provider ' . $this->getProviderID());

        $ga = new GoogleAuthenticator();
        return $ga->verifyCode($secret, $code, $tolerance);
    }

    // endregion

    // region old shit

    /**
     * This is called to see if the user can use it to login.
     * @return bool - True if this module has access to all needed information
     * to perform a login.
     */
    abstract public function canUse($user = null);

    /**
     * This is called to see if the module provides login functionality on the
     * main login page.
     * @return bool - True if this module provides main login functionality.
     */
    abstract public function canAuthLogin();

    /**
     * This is called to process the user configurable portion of the module
     * inside the user's profile.
     * @return mixed - True if the user's settings were changed, false if
     *     settings could not be changed, null if no settings were changed,
     *     the string 'verified' if the module was successfully verified,
     *     the string 'failed' if the module failed verification,
     *       the string 'otp' if the module is requesting a one-time password
     *     for verification,
     *     the string 'deleted' if the module was unenrolled.
     */
    public function processProfileForm()
    {
        return null;
    }

    /**
     * This is called to see if the module can send a message to the user.
     * @return bool - True if a message can be sent to the user.
     */
    abstract public function canTransmitMessage();

    /**
     * This is called to validate the code provided.  The default is to see if
     * the code matches the one-time password.
     * @return bool - True if the user has successfully authenticated using
     * this mechanism.
     */
    public function processLogin($code, $user = null)
    {
        $twofactor = plugin_load('action', 'twofactor');
        $otpQuery = $twofactor->get_otp_code();
        if (!$otpQuery) {
            return false;
        }
        list($otp, $modname) = $otpQuery;
        return ($code == $otp && $code != '' && (count($modname) == 0 || in_array(get_called_class(), $modname)));
    }

    /**
     * This is a helper function to get text strings from the twofactor class
     * calling this module.
     * @return string - Language string from the calling class.
     */
    protected function _getSharedLang($key)
    {
        $twofactor = plugin_load('action', 'twofactor');
        return $twofactor->getLang($key);
    }

    /**
     * This is a helper function to get shared configuration options from the
     * twofactor class.
     * @return string - Language string from the calling class.
     */
    protected function _getSharedConfig($key)
    {
        $twofactor = plugin_load('action', 'twofactor');
        return $twofactor->getConf($key);
    }

    /**
     * This is a helper function to check for the existence of shared
     * twofactor settings.
     * @return string - Language string from the calling class.
     */
    protected function _sharedSettingExists($key)
    {
        return $this->attribute->exists("twofactor", $key);
    }

    /**
     * This is a helper function to get shared twofactor settings.
     * @return string - Language string from the calling class.
     */
    protected function _sharedSettingGet($key, $default = null, $user = null)
    {
        return $this->_sharedSettingExists($key) ? $this->attribute->get("twofactor", $key, $success, $user) : $default;
    }

    /**
     * This is a helper function to set shared twofactor settings.
     * @return string - Language string from the calling class.
     */
    protected function _sharedSettingSet($key, $value)
    {
        return $this->attribute->set("twofactor", $key, $value);
    }

    /**
     * This is a helper function that lists the names of all available
     * modules.
     * @return array - Names of availble modules.
     */
    static public function _listModules()
    {
        $modules = plugin_list();
        return array_filter($modules, function ($x) {
            return substr($x, 0, 9) === 'twofactor' && $x !== 'twofactor';
        });
    }

    /**
     * This is a helper function that attempts to load the named modules.
     * @return array - An array of instanced objects from the loaded modules.
     */
    static public function _loadModules($mods)
    {
        $objects = array();
        foreach ($mods as $mod) {
            $obj = plugin_load('helper', $mod);
            if ($obj && is_a($obj, 'Twofactor_Auth_Module')) {
                $objects[$mod] = $obj;
            }
        }
        return $objects;
    }

    // endregion
}
