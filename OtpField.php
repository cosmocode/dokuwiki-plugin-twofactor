<?php

namespace dokuwiki\plugin\twofactor;

use dokuwiki\Form\InputElement;

/**
 * A Form field for OTP codes
 *
 * Providers should use this field when asking for the code
 */
class OtpField extends InputElement
{

    /** @inheritdoc */
    public function __construct($name, $label = '')
    {
        if ($label === '') {
            $label = (Manager::getInstance())->getLang('otp');
        }
        parent::__construct('password', $name, $label);

        $this->attr('autofocus', 'on');
        $this->attr('autocomplete', 'one-time-code');
        $this->attr('inputmode', 'numeric');
        $this->useInput(false);
    }

}
