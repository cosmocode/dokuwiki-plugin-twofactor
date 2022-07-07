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

    /**
     * Registers the event handlers.
     */
    public function register(Doku_Event_Handler $controller)
    {
        // check 2fa requirements and either move to profile or login handling
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'handleActionPreProcess',
            null,
            Manager::EVENT_PRIORITY
        );

        // display login form
        $controller->register_hook(
            'TPL_ACT_UNKNOWN',
            'BEFORE',
            $this,
            'handleLoginDisplay'
        );

        // disable user in all non-main screens (media, detail, ajax, ...)
        $controller->register_hook(
            'DOKUWIKI_INIT_DONE',
            'BEFORE',
            $this,
            'handleInitDone'
        );
    }

    /**
     * Decide if any 2fa handling needs to be done for the current user
     *
     * @param Doku_Event $event
     */
    public function handleActionPreProcess(Doku_Event $event)
    {
        $manager = Manager::getInstance();
        if (!$manager->isReady()) return;

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

        // clear cookie on logout
        if ($event->data === 'logout') {
            $this->deAuth();
            return;
        }

        // authed already, continue
        if ($this->isAuthed()) {
            return;
        }

        if (count($manager->getUserProviders())) {
            // user has already 2fa set up - they need to authenticate before anything else
            $event->data = 'twofactor_login';
            $event->preventDefault();
            $event->stopPropagation();
            return;
        }

        if ($manager->isRequired()) {
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
        $manager = Manager::getInstance();
        if (!$manager->isReady()) return;

        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        global $ID;

        $providerID = $INPUT->str('2fa_provider');
        $providers = $manager->getUserProviders();
        $provider = $providers[$providerID] ?? $manager->getUserDefaultProvider();
        // remove current provider from list
        unset($providers[$provider->getProviderID()]);

        echo '<div class="plugin_twofactor_login">';
        echo inlineSVG(__DIR__ . '/../admin.svg');
        echo $this->locale_xhtml('login');
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
            $form->addTagOpen('div')->addClass('buttons');
            $form->addButton('2fa', 'Submit')->attr('type', 'submit');
            $form->addTagClose('div');
        } catch (Exception $e) {
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
                $form->addHTML('<a href="' . $url . '">' . hsc($prov->getLabel()) . '</a>');
            }
            $form->addFieldsetClose();
        }

        echo $form->toHTML();
        echo '</div>';
    }

    /**
     * Remove user info from non-main entry points while we wait for 2fa
     *
     * @param Doku_Event $event
     */
    public function handleInitDone(Doku_Event $event)
    {
        global $INPUT;

        if (!(Manager::getInstance())->isReady()) return;
        if (basename($INPUT->server->str('SCRIPT_NAME')) == DOKU_SCRIPT) return;
        if ($this->isAuthed()) return;

        if ($this->getConf('optinout') !== 'mandatory' && empty(Manager::getInstance()->getUserProviders())) return;

        // temporarily remove user info from environment
        $INPUT->server->remove('REMOTE_USER');
        unset($_SESSION[DOKU_COOKIE]['auth']);
        unset($GLOBALS['USERINFO']);
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
            $provider = (Manager::getInstance())->getUserProvider($providerID);
            if ($this->cookieHash($provider) !== $hash) return false;
            return true;
        } catch (Exception $ignored) {
            return false;
        }
    }

    /**
     * Deletes the cookie
     *
     * @return void
     */
    protected function deAuth()
    {
        global $conf;

        $cookieDir = empty($conf['cookiedir']) ? DOKU_REL : $conf['cookiedir'];
        $time = time() - 60 * 60 * 24 * 365; // one year in the past
        setcookie(self::TWOFACTOR_COOKIE, null, $time, $cookieDir, '', ($conf['securecookie'] && is_ssl()), true);
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
        $provider = (Manager::getInstance())->getUserProvider($providerID);
        $ok = $provider->checkCode($code);
        if (!$ok) return false;

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
            (Manager::getInstance())->getUser(),
            $provider->getSecret(),
            auth_browseruid(),
            auth_cookiesalt(false, true),
        ]));
    }
}
