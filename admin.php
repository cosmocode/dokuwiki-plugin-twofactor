<?php

use dokuwiki\Form\Form;
use dokuwiki\plugin\twofactor\Manager;
use dokuwiki\plugin\twofactor\Settings;

/**
 *  Twofactor Manager
 *
 *  Allows to reset a user's twofactor data
 */
class admin_plugin_twofactor extends DokuWiki_Admin_Plugin
{
    /** @var array currently active filters */
    protected $filter = [];
    /** @var int index of first user to be displayed */
    protected $start = 0;
    /** @var int number of users to list on one page */
    protected $pagesize = 20;
    /** @var Manager */
    protected $manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->manager = Manager::getInstance();
        if (!$this->manager->isReady()) return;

        global $INPUT;

        $this->filter = $INPUT->arr('filter');
        $this->start = $INPUT->int('start');
    }

    /** @inheritdoc */
    public function handle()
    {
        global $INPUT;

        if ($INPUT->has('reset') && checkSecurityToken()) {
            $userdel = $INPUT->extract('reset')->str('reset');
            if ($userdel == $INPUT->server->str('REMOTE_USER')) {
                msg($this->getLang('reset_not_self'), -1);
                return;
            }
            foreach ($this->manager->getAllProviders() as $providerID => $provider) {
                (new Settings($providerID, $userdel))->purge();
            }
            (new Settings('twofactor', $userdel))->purge();
        }

        // when a search is initiated, roll back to first page
        if ($INPUT->has('search')) {
            $this->start = 0;
        }
    }

    /** @inheritdoc */
    public function html()
    {
        echo $this->locale_xhtml('admin');
        if (!$this->manager->isReady()) {
            return true;
        }

        $users = $this->getUserData($this->filter);
        $usercount = count($users);
        $users = $this->applyPagination($users, $this->start, $this->pagesize);

        $form = new Form(['method' => 'POST', 'class' => 'plugin_twofactor_admin']);
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

        $form->addTagOpen('tfooter');
        $form = $this->addTablePagination($form, $usercount, $this->start, $this->pagesize);
        $form->addTagClose('tfooter');

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
        $form->addTextInput('filter[user]');
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addTextInput('filter[name]');
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addTextInput('filter[mail]');
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addButton('search', $this->getLang('search'))->attr('type', 'submit');
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
        $form->addButton('reset[' . $user . ']', $this->getLang('reset'))
             ->attr('type', 'submit')
             ->addClass('twofactor_resetconfirm');
        $form->addTagClose('td');
        $form->addTagClose('tr');
        return $form;
    }

    /**
     * Add the pagination buttons to the form
     *
     * @param Form $form
     * @param int $usercount
     * @param int $start
     * @param int $pagesize
     * @return Form
     */
    protected function addTablePagination(Form $form, $usercount, $start, $pagesize)
    {
        $form->addTagOpen('tr');
        $form->addTagOpen('td')->attr('colspan', '4');
        $form->addTagOpen('div')->addClass('pagination');

        // start
        $btn = $form->addButton('start', $this->getLang('start'))->val('0');
        if ($start <= 0) $btn->attr('disabled', 'disabled');

        // prev
        $btn = $form->addButton('start', $this->getLang('prev'))->val($start - $pagesize);
        if ($start - $pagesize < 0) $btn->attr('disabled', 'disabled');

        // next
        $btn = $form->addButton('start', $this->getLang('next'))->val($start + $pagesize);
        if ($start + $pagesize >= $usercount) $btn->attr('disabled', 'disabled');

        // last
        $btn = $form->addButton('start', $this->getLang('last'))->val($usercount - $pagesize);
        if ($usercount - $pagesize <= 0) $btn->attr('disabled', 'disabled');
        if ($usercount - $pagesize == $start) $btn->attr('disabled', 'disabled');

        $form->addTagClose('div');
        $form->addTagClose('td');
        $form->addTagClose('tr');

        return $form;
    }

    /**
     * Get the filtered users that have a twofactor provider set
     *
     * @param array $filter
     * @return array
     */
    protected function getUserData($filter)
    {
        $users = Settings::findUsers('twofactor');
        return $this->applyFilter($users, $filter);
    }

    /**
     * Apply the given filters and return user details
     *
     * @param string[] $users simple list of user names
     * @param array $filter
     * @return array (user => userdata)
     */
    protected function applyFilter($users, $filter)
    {
        global $auth;
        $filtered = [];

        $hasFilter = (bool)array_filter(array_values($filter));
        foreach ($users as $user) {
            $userdata = $auth->getUserData($user);
            if (!$userdata) continue;
            $userdata['user'] = $user;
            if ($hasFilter) {
                foreach ($filter as $key => $value) {
                    $q = preg_quote($value, '/');
                    if ($value && preg_match("/$q/iu", $userdata[$key])) {
                        $filtered[$user] = $userdata;
                        continue 2;
                    }
                }
            } else {
                $filtered[$user] = $userdata;
            }
        }
        return $filtered;
    }

    /**
     * Get the current page of users
     *
     * @param array $users
     * @param int $start
     * @param int $pagesize
     * @return array
     */
    protected function applyPagination($users, $start, $pagesize)
    {
        return array_slice($users, $start, $pagesize, true);
    }

}
