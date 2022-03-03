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

    // region Introspection methods

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

    // endregion
    // region Configuration methods

    /**
     * Clear all settings
     */
    public function reset()
    {
        $this->settings->purge();
    }

    /**
     * Has this provider been fully configured and verified by the user and thus can be used
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

    // endregion
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
     * @return bool
     * @throws \Exception when no code can be created
     */
    public function checkCode($code, $tolerance = 2)
    {
        $secret = $this->settings->get('secret');
        if (!$secret) throw new \Exception('No secret for provider ' . $this->getProviderID());

        $ga = new GoogleAuthenticator();
        return $ga->verifyCode($secret, $code, $tolerance);
    }

    /**
     * Transmits the code to the user
     *
     * If a provider does not transmit anything (eg. TOTP) simply
     * return the message.
     *
     * @param string $code The code to transmit
     * @return string Informational message for the user
     * @throw \Exception when the message can't be sent
     */
    abstract public function transmitMessage($code);

    // endregion
}
