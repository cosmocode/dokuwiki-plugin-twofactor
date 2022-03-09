<?php

use dokuwiki\Form\Form;
use dokuwiki\plugin\twofactor\Manager;

/**
 *  Twofactor Manager
 *
 *  Dokuwiki Admin Plugin
 *  Special thanks to the useradmin extension as a starting point for this class
 *
 * @author  Mike Wilmes <mwilmes@avc.edu>
 */
class admin_plugin_twofactor extends DokuWiki_Admin_Plugin
{
    protected $userList = array();     // list of users with attributes
    protected $filter = array();   // user selection filter(s)
    protected $start = 0;          // index of first user to be displayed
    protected $last = 0;           // index of the last user to be displayed
    protected $pagesize = 20;      // number of users to list on one page
    protected $disabled = '';      // if disabled set to explanatory string
    protected $lastdisabled = false; // set to true if last user is unknown and last button is hence buggy

    /** @var helper_plugin_attribute */
    protected $attribute;

    /**
     * Constructor
     */
    public function __construct()
    {
        if (!(Manager::getInstance())->isReady()) return;
        $this->attribute = plugin_load('helper', 'attribute');
        $this->userList = $this->attribute->enumerateUsers('twofactor');
    }

    /** @inheritdoc */
    public function handle()
    {
        global $INPUT, $INFO;
        if (!$INFO['isadmin']) return false;
        if ($this->disabled) {
            // If disabled, don't process anything.
            return true;
        }

        // extract the command and any specific parameters
        // submit button name is of the form - fn[cmd][param(s)]
        $fn = $INPUT->param('fn');

        if (is_array($fn)) {
            $cmd = key($fn);
            $param = is_array($fn[$cmd]) ? key($fn[$cmd]) : null;
        } else {
            $cmd = $fn;
            $param = null;
        }

        if ($cmd != "search") {
            $this->start = $INPUT->int('start', 0);
            $this->filter = $this->_retrieveFilter();
        }

        switch ($cmd) {
            case "reset"  :
                $this->_resetUser();
                break;
            case "search" :
                $this->_setFilter($param);
                $this->start = 0;
                break;
        }

        $this->_user_total = count($this->userList) > 0 ? $this->_getUserCount($this->filter) : -1;

        // page handling
        switch ($cmd) {
            case 'start' :
                $this->start = 0;
                break;
            case 'prev'  :
                $this->start -= $this->pagesize;
                break;
            case 'next'  :
                $this->start += $this->pagesize;
                break;
            case 'last'  :
                $this->start = $this->_user_total;
                break;
        }
        $this->_validatePagination();
        return true;
    }

    /**
     * Output appropriate html
     *
     * @return bool
     */
    public function html()
    {
        global $ID, $INFO;

        $users = $this->getUsers($this->start, $this->pagesize, $this->filter);
        $pagination = $this->getPagination();

        echo $this->locale_xhtml('admin');

        echo '<div id="user__manager">'; // FIXME do we reuse styles?
        echo '<div class="level2">';

        // FIXME check if isReady, display info if not

        $form = new Form(['method' => 'POST']);
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'twofactor');
        $form->setHiddenField('start', $this->start);

        $form->addTagOpen('div')->addClass('table');
        $form->addTagOpen('table')->addClass('inline');
        $form = $this->addTableHead($form);

        $form->addTagOpen('tbody');
        foreach ($users as $user => $userinfo) {
            $form = $this->addTableUser($form, $user, $userinfo);
        }
        $form->addTagClose('tbody');

        $form->addTagClose('table');
        $form->addTagClose('div');

        echo $form->toHTML();

        return true;
    }

    /**
     * Add the table headers to the table in the given form
     * @param Form $form
     * @return Form
     */
    protected function addTableHead(Form $form)
    {
        $form->addTagOpen('thead');

        // header
        $form->addTagOpen('tr');
        $form->addTagOpen('th');
        $form->addHTML($this->getLang('user_id'));
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addHTML($this->getLang('user_name'));
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addHTML($this->getLang('user_mail'));
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addHTML($this->getLang('action'));
        $form->addTagClose('th');
        $form->addTagClose('tr');

        // filter
        $form->addTagOpen('tr');
        $form->addTagOpen('th');
        $form->addTextInput('userid');
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addTextInput('username');
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addTextInput('usermail');
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addButton('', $this->getLang('search'))->attr('type', 'submit');
        $form->addTagClose('th');
        $form->addTagClose('tr');

        $form->addTagClose('thead');
        return $form;
    }

    /**
     * Add
     *
     * @param Form $form
     * @param $user
     * @param $userinfo
     * @return Form
     */
    protected function addTableUser(Form $form, $user, $userinfo)
    {
        $form->addTagOpen('tr');
        $form->addTagOpen('td');
        $form->addHTML(hsc($user));
        $form->addTagClose('td');
        $form->addTagOpen('td');
        $form->addHTML(hsc($userinfo['name']));
        $form->addTagClose('td');
        $form->addTagOpen('td');
        $form->addHTML(hsc($userinfo['mail']));
        $form->addTagClose('td');
        $form->addTagOpen('td');
        $form->addButton('reset[' . $user . ']', $this->getLang('reset'))->attr('type', 'submit');
        $form->addTagClose('td');
        $form->addTagClose('tr');
        return $form;
    }

    /**
     * @return int current start value for pageination
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * @return int number of users per page
     */
    public function getPagesize()
    {
        return $this->pagesize;
    }

    /**
     * @param boolean $lastdisabled
     */
    public function setLastdisabled($lastdisabled)
    {
        $this->lastdisabled = $lastdisabled;
    }

    /**
     * Prints a inputfield
     *
     * @param string $id
     * @param string $name
     * @param string $label
     * @param string $value
     * @param bool $cando whether auth backend is capable to do this action
     * @param int $indent
     */
    protected function _htmlInputField($id, $name, $label, $value, $cando, $indent = 0)
    {
        $class = $cando ? '' : ' class="disabled"';
        echo str_pad('', $indent);

        if ($name == 'userpass' || $name == 'userpass2') {
            $fieldtype = 'password';
            $autocomp = 'autocomplete="off"';
        } elseif ($name == 'usermail') {
            $fieldtype = 'email';
            $autocomp = '';
        } else {
            $fieldtype = 'text';
            $autocomp = '';
        }
        $value = hsc($value);

        echo "<tr $class>";
        echo "<td><label for=\"$id\" >$label: </label></td>";
        echo "<td>";
        if ($cando) {
            echo "<input type=\"$fieldtype\" id=\"$id\" name=\"$name\" value=\"$value\" class=\"edit\" $autocomp />";
        } else {
            echo "<input type=\"hidden\" name=\"$name\" value=\"$value\" />";
            echo "<input type=\"$fieldtype\" id=\"$id\" name=\"$name\" value=\"$value\" class=\"edit disabled\" disabled=\"disabled\" />";
        }
        echo "</td>";
        echo "</tr>";
    }

    /**
     * Returns htmlescaped filter value
     *
     * @param string $key name of search field
     * @return string html escaped value
     */
    protected function _htmlFilter($key)
    {
        if (empty($this->filter)) return '';
        return (isset($this->filter[$key]) ? hsc($this->filter[$key]) : '');
    }

    /**
     * Print hidden inputs with the current filter values
     *
     * @param int $indent
     */
    protected function _htmlFilterSettings($indent = 0)
    {

        ptln("<input type=\"hidden\" name=\"start\" value=\"" . $this->start . "\" />", $indent);

        foreach ($this->filter as $key => $filter) {
            ptln("<input type=\"hidden\" name=\"filter[" . $key . "]\" value=\"" . hsc($filter) . "\" />", $indent);
        }
    }

    /**
     * Reset user (a user has been selected to remove two factor authentication)
     *
     * @param string $param id of the user
     * @return bool whether succesful
     */
    protected function _resetUser()
    {
        global $INPUT;
        if (!checkSecurityToken()) return false;

        $selected = $INPUT->arr('delete');
        if (empty($selected)) return false;
        $selected = array_keys($selected);

        if (in_array($_SERVER['REMOTE_USER'], $selected)) {
            msg($this->lang['reset_not_self'], -1);
            return false;
        }

        $count = 0;
        foreach ($selected as $user) {
            // All users here have a attribute namespace file. Purge them.
            $purged = $this->attribute->purge('twofactor', $user);
            foreach ($this->modules as $mod) {
                $purged |= $this->attribute->purge($mod->moduleName, $user);
            }
            $count += $purged ? 1 : 0;
        }

        if ($count == count($selected)) {
            $text = str_replace('%d', $count, $this->lang['reset_ok']);
            msg("$text.", 1);
        } else {
            $part1 = str_replace('%d', $count, $this->lang['reset_ok']);
            $part2 = str_replace('%d', (count($selected) - $count), $this->lang['reset_fail']);
            // Output results.
            msg("$part1, $part2", -1);
        }

        // Now refresh the user list.
        $this->_getUsers();

        return true;
    }

    protected function _retrieveFilteredUsers($filter = array())
    {
        global $auth;
        $users = array();
        $noUsers = is_null($auth) || !$auth->canDo('getUsers');
        foreach ($this->userList as $user) {
            if ($noUsers) {
                $userdata = array('user' => $user, 'name' => $user, 'mail' => null);
            } else {
                $userdata = $auth->getUserData($user);
                if (!is_array($userdata)) {
                    $userdata = array('user' => $user, 'name' => null, 'mail' => null);
                }
            }
            $include = true;
            foreach ($filter as $key => $value) {
                $include &= strstr($userdata[$key], $value);
            }
            if ($include) {
                $users[$user] = $userdata;
            }
        }
        return $users;
    }

    protected function _getUserCount($filter)
    {
        return count($this->_retrieveFilteredUsers($filter));
    }

    protected function getUsers($start, $pagesize, $filter)
    {
        $users = $this->_retrieveFilteredUsers($filter);
        return $users;
    }

    /**
     * Retrieve & clean user data from the form
     *
     * @param bool $clean whether the cleanUser method of the authentication backend is applied
     * @return array (user, password, full name, email, array(groups))
     */
    protected function _retrieveUser($clean = true)
    {
        /** @var DokuWiki_Auth_Plugin $auth */
        global $auth;
        global $INPUT;

        $user = array();
        $user[] = $INPUT->str('userid');
        $user[] = $INPUT->str('username');
        $user[] = $INPUT->str('usermail');

        return $user;
    }

    /**
     * Set the filter with the current search terms or clear the filter
     *
     * @param string $op 'new' or 'clear'
     */
    protected function _setFilter($op)
    {

        $this->filter = array();

        if ($op == 'new') {
            list($user, $name, $mail) = $this->_retrieveUser();

            if (!empty($user)) $this->filter['user'] = $user;
            if (!empty($name)) $this->filter['name'] = $name;
            if (!empty($mail)) $this->filter['mail'] = $mail;
        }
    }

    /**
     * Get the current search terms
     *
     * @return array
     */
    protected function _retrieveFilter()
    {
        global $INPUT;

        $t_filter = $INPUT->arr('filter');

        // messy, but this way we ensure we aren't getting any additional crap from malicious users
        $filter = array();

        if (isset($t_filter['user'])) $filter['user'] = $t_filter['user'];
        if (isset($t_filter['name'])) $filter['name'] = $t_filter['name'];
        if (isset($t_filter['mail'])) $filter['mail'] = $t_filter['mail'];

        return $filter;
    }

    /**
     * Validate and improve the pagination values
     */
    protected function _validatePagination()
    {

        if ($this->start >= $this->_user_total) {
            $this->start = $this->_user_total - $this->pagesize;
        }
        if ($this->start < 0) $this->start = 0;

        $this->last = min($this->_user_total, $this->start + $this->pagesize);
    }

    /**
     * Return an array of strings to enable/disable pagination buttons
     *
     * @return array with enable/disable attributes
     */
    protected function getPagination()
    {

        $disabled = 'disabled="disabled"';

        $buttons = array();
        $buttons['start'] = $buttons['prev'] = ($this->start == 0) ? $disabled : '';

        if ($this->_user_total == -1) {
            $buttons['last'] = $disabled;
            $buttons['next'] = '';
        } else {
            $buttons['last'] = $buttons['next'] = (($this->start + $this->pagesize) >= $this->_user_total) ? $disabled : '';
        }

        if ($this->lastdisabled) {
            $buttons['last'] = $disabled;
        }

        return $buttons;
    }

}
