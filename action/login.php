<?php

use dokuwiki\plugin\twofactor\Manager;
use dokuwiki\plugin\twofactor\Provider;

/**
 * DokuWiki Plugin twofactor (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */
class action_plugin_twofactor_login extends DokuWiki_Action_Plugin
{
    const TWOFACTOR_COOKIE = '2FA' . DOKU_COOKIE;

    /** @var Manager */
    protected $manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->manager = Manager::getInstance();
    }

    /**
     * Registers the event handlers.
     */
    public function register(Doku_Event_Handler $controller)
    {
        if (!(Manager::getInstance())->isReady()) return;

        // check 2fa requirements and either move to profile or login handling
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'handleActionPreProcess',
            null,
            -999999
        );

        // display login form
        $controller->register_hook(
            'TPL_ACT_UNKNOWN',
            'BEFORE',
            $this,
            'handleLoginDisplay'
        );

        // FIXME disable user in all non-main screens (media, detail, ajax, ...)
    }

    /**
     * Decide if any 2fa handling needs to be done for the current user
     *
     * @param Doku_Event $event
     */
    public function handleActionPreProcess(Doku_Event $event)
    {
        try {
            $this->manager->getUser();
        } catch (\Exception $ignored) {
            return;
        }

        global $INPUT;

        // already in a 2fa login?
        if ($event->data === 'twofactor_login') {
            if ($this->verify(
                $INPUT->str('2fa_code'),
                $INPUT->str('2fa_provider'),
                $INPUT->bool('sticky')
            )) {
                $event->data = 'show';
                return;
            } else {
                // show form
                $event->preventDefault();
                return;
            }
        }

        // authed already, continue
        if ($this->isAuthed()) {
            return;
        }

        if (count($this->manager->getUserProviders())) {
            // user has already 2fa set up - they need to authenticate before anything else
            $event->data = 'twofactor_login';
            $event->preventDefault();
            $event->stopPropagation();
            return;
        }

        if ($this->manager->isRequired()) {
            // 2fa is required - they need to set it up now
            // this will be handled by action/profile.php
            $event->data = 'twofactor_profile';
        }

        // all good. proceed
    }

    /**
     * Show a 2fa login screen
     *
     * @param Doku_Event $event
     */
    public function handleLoginDisplay(Doku_Event $event)
    {
        if ($event->data !== 'twofactor_login') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        global $ID;

        $providerID = $INPUT->str('2fa_provider');
        $providers = $this->manager->getUserProviders();
        if (isset($providers[$providerID])) {
            $provider = $providers[$providerID];
        } else {
            $provider = $this->manager->getUserDefaultProvider();
        }
        // remove current provider from list
        unset($providers[$provider->getProviderID()]);

        $form = new dokuwiki\Form\Form(['method' => 'POST']);
        $form->setHiddenField('do', 'twofactor_login');
        $form->setHiddenField('2fa_provider', $provider->getProviderID());
        $form->addFieldsetOpen($provider->getLabel());
        try {
            $code = $provider->generateCode();
            $info = $provider->transmitMessage($code);
            $form->addHTML('<p>' . hsc($info) . '</p>');
            $form->addTextInput('2fa_code', 'Your Code')->val('');
            $form->addCheckbox('sticky', 'Remember this browser'); // reuse same name as login
            $form->addButton('2fa', 'Submit')->attr('type', 'submit');
        } catch (\Exception $e) {
            msg(hsc($e->getMessage()), -1); // FIXME better handling
        }
        $form->addFieldsetClose();

        if (count($providers)) {
            $form->addFieldsetOpen('Alternative methods');
            foreach ($providers as $prov) {
                $url = wl($ID, [
                    'do' => 'twofactor_login',
                    '2fa_provider' => $prov->getProviderID(),
                ]);
                $form->addHTML('< href="' . $url . '">' . hsc($prov->getLabel()) . '</a>');
            }
            $form->addFieldsetClose();
        }

        echo $form->toHTML();
    }

    /**
     * Has the user already authenticated with the second factor?
     * @return bool
     */
    protected function isAuthed()
    {
        if (!isset($_COOKIE[self::TWOFACTOR_COOKIE])) return false;
        $data = unserialize(base64_decode($_COOKIE[self::TWOFACTOR_COOKIE]));
        if (!is_array($data)) return false;
        list($providerID, $hash,) = $data;

        try {
            $provider = $this->manager->getUserProvider($providerID);
            if ($this->cookieHash($provider) !== $hash) return false;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verify a given code
     *
     * @return bool
     * @throws Exception
     */
    protected function verify($code, $providerID, $sticky)
    {
        global $conf;

        if (!$code) return false;
        if (!$providerID) return false;
        $provider = $this->manager->getUserProvider($providerID);
        $ok = $provider->checkCode($code);
        if (!$ok) {
            msg('code was wrong', -1);
            return false;
        }

        // store cookie
        $hash = $this->cookieHash($provider);
        $data = base64_encode(serialize([$providerID, $hash, time()]));
        $cookieDir = empty($conf['cookiedir']) ? DOKU_REL : $conf['cookiedir'];
        $time = $sticky ? (time() + 60 * 60 * 24 * 365) : 0; //one year
        setcookie(self::TWOFACTOR_COOKIE, $data, $time, $cookieDir, '', ($conf['securecookie'] && is_ssl()), true);

        return true;
    }

    /**
     * Create a hash that validates the cookie
     *
     * @param Provider $provider
     * @return string
     */
    protected function cookieHash($provider)
    {
        return sha1(join("\n", [
            $provider->getProviderID(),
            $this->manager->getUser(),
            $provider->getSecret(),
            auth_browseruid(),
            auth_cookiesalt(false, true),
        ]));
    }
}
