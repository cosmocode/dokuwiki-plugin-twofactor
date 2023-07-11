<?php

use dokuwiki\plugin\twofactor\Manager;

/**
 * DokuWiki Plugin twofactor (Action Component)
 *
 * This adds 2fa handling to the resendpwd action. It will interrupt the normal, first step of the
 * flow and insert our own 2fa form, initialized with the user provided in the reset form. When the user
 * has successfully authenticated, the normal flow will continue. All within the do?do=resendpwd action.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <dokuwiki@cosmocode.de>
 */
class action_plugin_twofactor_resendpwd extends \dokuwiki\Extension\ActionPlugin
{
    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'handleActionPreProcess',
            null,
            Manager::EVENT_PRIORITY - 1
        );

        $controller->register_hook(
            'TPL_ACT_UNKNOWN',
            'BEFORE',
            $this,
            'handleTplActUnknown',
            null,
            Manager::EVENT_PRIORITY - 1
        );
    }

    /**
     * Event handler for ACTION_ACT_PREPROCESS
     *
     * @see https://www.dokuwiki.org/devel:events:ACTION_ACT_PREPROCESS
     * @param Doku_Event $event Event object
     * @param mixed $param optional parameter passed when event was registered
     * @return void
     */
    public function handleActionPreProcess(Doku_Event $event, $param)
    {
        if ($event->data !== 'resendpwd') return;

        global $INPUT;
        if ($INPUT->has('pwauth')) return; // we're already in token phase, don't interrupt
        if (!$INPUT->str('login')) return; // no user given yet, don't interrupt

        $user = $INPUT->str('login');
        $manager = Manager::getInstance();
        $manager->setUser($user);

        if (!$manager->isReady()) return; // no 2fa setup, don't interrupt
        if (!count($manager->getUserProviders())) return; // no 2fa for this user, don't interrupt

        $code = $INPUT->post->str('2fa_code');
        $providerID = $INPUT->post->str('2fa_provider');
        if ($code && $manager->verifyCode($code, $providerID)) {
            // all is good, don't interrupt
            Manager::destroyInstance(); // remove our instance so login.php can create a new one
            return;
        }

        // we're still here, so we need to interrupt
        $event->preventDefault();
        $event->stopPropagation();

        // next, we will overwrite the resendpwd form with our own in TPL_ACT_UNKNOWN
    }

    /**
     * Event handler for TPL_ACT_UNKNOWN
     *
     * This is executed only when we prevented the default action in handleActionPreProcess()
     *
     * @see https://www.dokuwiki.org/devel:events:TPL_ACT_UNKNOWN
     * @param Doku_Event $event Event object
     * @param mixed $param optional parameter passed when event was registered
     * @return void
     */
    public function handleTplActUnknown(Doku_Event $event, $param)
    {
        if ($event->data !== 'resendpwd') return;
        $event->stopPropagation();
        $event->preventDefault();

        global $INPUT;

        $providerID = $INPUT->post->str('2fa_provider');

        $manager = Manager::getInstance();
        $form = $manager->getCodeForm($providerID);

        // overwrite form defaults, to redo the resendpwd action but with the code supplied
        $form->setHiddenField('do', 'resendpwd');
        $form->setHiddenField('login', $INPUT->str('login'));
        $form->setHiddenField('save', 1);


        echo '<div class="plugin_twofactor_login">';
        echo inlineSVG(__DIR__ . '/../admin.svg');
        echo $this->locale_xhtml('resendpwd');
        echo $form->toHTML();
        echo '</div>';
    }
}

