<?php

namespace dokuwiki\plugin\twofactor;

use dokuwiki\Menu\Item\Profile;

/**
 * Menu Item to open the user's 2FA profile
 */
class MenuItem extends Profile
{
    /** @inheritdoc */
    public function __construct($label = '')
    {
        parent::__construct();

        // Borrow the Profile  language construct.
        global $lang;
        $this->label = $label ?: $lang['btn_profile'] . ' (2FA)';
    }

    /** @inheritdoc */
    public function getType()
    {
        return 'twofactor_profile';
    }

    /** @inheritdoc */
    public function getSvg()
    {
        return __DIR__ . '/admin.svg';
    }
}
