<?php

require_once __DIR__  . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../../vendor/autoload.php';

class gAgenda extends eqLogic
{
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public static function pull($_option)
    {
        $gCalendar = self::byId($_option['gAgenda_id']);
        if (!is_object($gCalendar)) {
            return;
        }
        $event = $gCalendar->getCache('event', null);
        if ($event == null) {
            return;
        }

        $gCalendar->checkAndUpdateCmd('event', '');
        $gCalendar->syncWithGoogle();
        $gCalendar->reschedule();
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public static function cron30()
    {
        foreach (self::byType('gAgenda') as $eqLogic) {
            try {
                $eqLogic->syncWithGoogle();
                $eqLogic->reschedule();
            } catch (Exception $e) {
                log::add('gAgenda', 'warning', __('Erreur sur : ', __FILE__) . $eqLogic->getHumanName() . ' => ' . $e->getMessage());
            }
        }
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public static function event()
    {
        log::add('gAgenda', 'debug', 'event : ' . json_encode($_GET));

        if (!jeedom::apiAccess(init('apikey'), 'gAgenda')) {
            echo 'Clef API non valide, vous n\'êtes pas autorisé à effectuer cette action';
            die();
        }

        $eqLogic = eqLogic::byId(init('eqLogic_id'));
        if (!is_object($eqLogic)) {
            echo 'Impossible de trouver l\'équipement correspondant à : ' . init('eqLogic_id');
            exit();
        }

        $client = $eqLogic->getProvider();

        if (!isset($_GET['code'])) {
            $auth_url = $client->createAuthUrl();
            redirect($auth_url);
        } else {
            $client->authenticate($_GET['code']);
            $accessToken = $client->getAccessToken();
            log::add('gAgenda', 'debug', 'accessToken : ' . json_encode($accessToken));
            $eqLogic->setConfiguration('accessToken', $accessToken);
            $eqLogic->save();
            redirect(network::getNetworkAccess('external') . '/index.php?v=d&p=gAgenda&m=gAgenda&id=' . $eqLogic->getId());
        }
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public function syncWithGoogle()
    {
        $events = array();
        if (!is_array($this->getConfiguration('calendars')) || count($this->getConfiguration('calendars')) == 0) {
            return;
        }
        foreach ($this->getConfiguration('calendars') as $calendarId => $value) {
            if ($value == 0) {
                continue;
            }
            try {
                foreach ($this->getEvents($calendarId) as $event) {
                    $events[] = array(
                        'summary' => (isset($event['summary']) ? $event['summary'] : __('(Sans titre)', __FILE__)),
                        'start' => (isset($event['start']['date'])) ? $event['start']['date'] . ' 00:00:00' : date('Y-m-d H:i:s', strtotime($event['start']['dateTime'])),
                        'end' => (isset($event['end']['date'])) ? $event['end']['date'] . ' 00:00:00' : date('Y-m-d H:i:s', strtotime($event['end']['dateTime'])),
                        'colorId' => (isset($event['colorId'])) ? intval($event['colorId']) : 0,
                    );
                }
            } catch (Exception $e) {
                log::add('gAgenda', 'error', __('Erreur sur : ', __FILE__) . $calendarId . ' => ' . $e->getMessage());
            }
        }
        log::add('gAgenda', 'debug', 'Events : ' . json_encode($events));
        if (count($events) > 0) {
            $this->setCache('events', $events);
        }
        $cmd = $this->getCmd(null, 'lastsync');
        if (is_object($cmd)) {
            $cmd->event(date('Y-m-d H:i:s'));
        }
        $this->checkAndUpdateCmd('eventCurrent', $this->getCurrentEvent());
        $this->checkAndUpdateCmd('eventToday', $this->getTodayEvent());
        $this->checkAndUpdateCmd('eventNextDay', $this->getNextDayEvent());
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public function getProvider()
    {
        $client = new Google\Client();
        $client->setClientId($this->getConfiguration('client_id'));
        $client->setClientSecret($this->getConfiguration('client_secret'));
        $client->setRedirectUri(network::getNetworkAccess('external') . '/core/api/jeeApi.php?plugin=gAgenda&type=event&apikey=' . jeedom::getApiKey('gAgenda') . '&eqLogic_id=' . $this->getId());
        $client->addScope(Google_Service_Calendar::CALENDAR_READONLY);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');
        return $client;
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public function getProviderWithToken()
    {
        $access_token = $this->getConfiguration('accessToken');
        $client = $this->getProvider();
        $client->setAccessToken($access_token);

        if ($client->isAccessTokenExpired()) {
            log::add('gAgenda', 'debug', 'accessToken : isAccessTokenExpired');
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            $client->setAccessToken($client->getAccessToken());
            $this->setConfiguration('accessToken', $client->getAccessToken());
            $this->save();
        }

        return  new Google_Service_Calendar($client);
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public function linkToUser()
    {
        $client = $this->getProvider();
        return $client->createAuthUrl();
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public function listCalendar()
    {
        $client = $this->getProviderWithToken();
        $calendarList = $client->calendarList->listCalendarList();
        log::add('gAgenda', 'debug', 'calendarList ==  ' . json_encode($calendarList));
        return (isset($calendarList['items'])) ? $calendarList['items'] : array();
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public function getEvents($_calendarId)
    {
        $optParams = array(
            'orderBy' => 'startTime',
            'singleEvents' => TRUE,
            'timeMin' => urlencode(date(DATE_RFC3339, strtotime('-1 day'))),
            'timeMax' => urlencode(date(DATE_RFC3339, strtotime('+1 week'))),
        );

        $client = $this->getProviderWithToken();
        $results = $client->events->listEvents($_calendarId, $optParams);

        return (isset($results['items'])) ? $results['items'] : array();
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////  
    public function getCurrentEvent()
    {
        $return = '';
        if (!is_array($this->getCache('events')) || count($this->getCache('events')) == 0) {
            return $return;
        }

        $now = strtotime('now');
        foreach ($this->getCache('events') as $event) {
            if (strtotime($event['start']) <= $now && strtotime($event['end']) >= $now) {
                $return .= $event['summary'] . ',';
                continue;
            }
        }
        return trim($return, ',');
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////  
    public function getTodayEvent()
    {
        $return = '';
        if (!is_array($this->getCache('events')) || count($this->getCache('events')) == 0) {
            return $return;
        }
        $starttime = strtotime('00:00:00');
        $endtime = strtotime('23:59:59');
        foreach ($this->getCache('events') as $event) {
            $endtime_event = strtotime($event['end']);
            if ($endtime_event == strtotime('00:00:00') && strtotime($event['start']) <> strtotime('00:00:00')) {
                $endtime_event = $endtime_event - 1;
            }
            if (strtotime($event['start']) <= $endtime && $endtime_event >= $starttime) {
                $return .= $event['summary'] . ',';
                continue;
            }
        }
        return trim($return, ',');
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////  
    public function getNextDayEvent()
    {
        $return = '';
        if (!is_array($this->getCache('events')) || count($this->getCache('events')) == 0) {
            return $return;
        }
        $starttime = strtotime('+1 day 00:00:00');
        $endtime = strtotime('+1 day 23:59:59');
        foreach ($this->getCache('events') as $event) {
            $endtime_event = strtotime($event['end']);
            if ($endtime_event == strtotime('+1 day 00:00:00') && strtotime($event['start']) <> strtotime('+1 day 00:00:00')) {
                $endtime_event = $endtime_event - 1;
            }
            if (strtotime($event['start']) <= $endtime && $endtime_event >= $starttime) {
                $return .= $event['summary'] . ',';
                continue;
            }
        }
        return trim($return, ',');
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////  
    public function getNextOccurence()
    {
        $return = array('datetime' => null, 'event' => null, 'mode' => null);
        if (!is_array($this->getCache('events')) || count($this->getCache('events')) == 0) {
            return $return;
        }
        foreach ($this->getCache('events') as $event) {
            if ($return['event'] == null) {
                $return['event'] = $event;
                if (strtotime($event['start']) > strtotime('now')) {
                    $return['mode'] = 'start';
                    $return['datetime'] = $event['start'];
                } else if (strtotime($event['end']) > strtotime('now')) {
                    $return['mode'] = 'end';
                    $return['datetime'] = $event['end'];
                }
                continue;
            }
            if (strtotime($event['start']) > strtotime('now') && ($return['datetime'] == null || strtotime($event['start']) < strtotime($return['datetime']))) {
                $return['mode'] = 'start';
                $return['datetime'] = $event['start'];
                $return['event'] = $event;
            }
            if (strtotime($event['end']) > strtotime('now') && ($return['datetime'] == null || strtotime($event['end']) < strtotime($return['datetime']))) {
                $return['mode'] = 'end';
                $return['datetime'] = $event['end'];
                $return['event'] = $event;
            }
        }
        return $return;
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////  
    public function reschedule()
    {
        $next = $this->getNextOccurence();
        if ($next['datetime'] === null || $next['datetime'] === false) {
            return;
        }
        log::add('gAgenda', 'debug', 'Reprogrammation à : ' . print_r($next['datetime'], true));
        $cron = cron::byClassAndFunction('gAgenda', 'pull', array('gAgenda_id' => intval($this->getId())));
        if ($next['datetime'] != null) {
            if (!is_object($cron)) {
                $cron = new cron();
                $cron->setClass('gAgenda');
                $cron->setFunction('pull');
                $cron->setOption(array('gAgenda_id' => intval($this->getId())));
                $cron->setLastRun(date('Y-m-d H:i:s'));
            }
            $next['datetime'] = strtotime($next['datetime']);
            $cron->setSchedule(date('i', $next['datetime']) . ' ' . date('H', $next['datetime']) . ' ' . date('d', $next['datetime']) . ' ' . date('m', $next['datetime']) . ' * ' . date('Y', $next['datetime']));
            $cron->save();
            $this->setCache('event', $next);
        } else {
            if (is_object($cron)) {
                $cron->remove();
            }
        }
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////  
    public function postSave()
    {
        $cmd = $this->getCmd(null, 'eventCurrent');
        if (!is_object($cmd)) {
            $cmd = new gAgendaCmd();
            $cmd->setLogicalId('eventCurrent');
            $cmd->setIsVisible(1);
            $cmd->setName(__('Evènement en cours', __FILE__));
            $cmd->setTemplate('dashboard', 'line');
            $cmd->setTemplate('mobile', 'line');
        }
        $cmd->setType('info');
        $cmd->setSubType('string');
        $cmd->setEqLogic_id($this->getId());
        $cmd->save();
        //////////////////////////////
        $cmd = $this->getCmd(null, 'eventToday');
        if (!is_object($cmd)) {
            $cmd = new gAgendaCmd();
            $cmd->setLogicalId('eventToday');
            $cmd->setIsVisible(1);
            $cmd->setName(__('Evènement du jour', __FILE__));
            $cmd->setTemplate('dashboard', 'line');
            $cmd->setTemplate('mobile', 'line');
        }
        $cmd->setType('info');
        $cmd->setSubType('string');
        $cmd->setEqLogic_id($this->getId());
        $cmd->save();
        //////////////////////////////
        $cmd = $this->getCmd(null, 'eventNextDay');
        if (!is_object($cmd)) {
            $cmd = new gAgendaCmd();
            $cmd->setLogicalId('eventNextDay');
            $cmd->setIsVisible(1);
            $cmd->setName(__('Evènement de demain', __FILE__));
            $cmd->setTemplate('dashboard', 'line');
            $cmd->setTemplate('mobile', 'line');
        }
        $cmd->setType('info');
        $cmd->setSubType('string');
        $cmd->setEqLogic_id($this->getId());
        $cmd->save();
        //////////////////////////////
        $cmd = $this->getCmd(null, 'lastsync');
        if (!is_object($cmd)) {
            $cmd = new gAgendaCmd();
            $cmd->setLogicalId('lastsync');
            $cmd->setIsVisible(1);
            $cmd->setName(__('Date synchronisation', __FILE__));
            $cmd->setTemplate('dashboard', 'line');
            $cmd->setTemplate('mobile', 'line');
        }
        $cmd->setType('info');
        $cmd->setSubType('string');
        $cmd->setEqLogic_id($this->getId());
        $cmd->save();
        //////////////////////////////
        $cmd = $this->getCmd(null, 'refresh');
        if (!is_object($cmd)) {
            $cmd = new gAgendaCmd();
            $cmd->setLogicalId('refresh');
            $cmd->setIsVisible(1);
            $cmd->setName(__('Rafraîchir', __FILE__));
        }
        $cmd->setType('action');
        $cmd->setSubType('other');
        $cmd->setEqLogic_id($this->getId());
        $cmd->save();
    }
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////  
    public function toHtml($_version = 'dashboard')
    {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }

        $version = jeedom::versionAlias($_version);

        $tEvent = getTemplate('core', $version, 'agenda', __CLASS__);
        $listEvent = $this->getCache('events');

        if (!is_array($listEvent) || count($listEvent) == 0) {
            return $return;
        }

        $color = ["#a4bdfc", "#a4bdfc", "#7ae7bf", "#dbadff", "#ff887c", "#fbd75b", "#ffb878", "#46d6db", "#e1e1e1", "#5484ed", "#51b749", "#dc2127"];

        $dEvent = '';
        $nbEvent = 1;
        $now = new DateTime('today midnight');

        foreach ($listEvent as $event) {

            if ($this->getConfiguration('nbWidgetMaxEvent', 5) < $nbEvent) {
                break;
            }

            if (strtotime($event['end']) < strtotime('now')) {
                continue;
            }

            $then = new DateTime(date('Y-m-d 0:00:00', strtotime($event['start'])));
            $diff = $now->diff($then)->days;

            if ($diff == 0) {
                $diff = 'Auj.';
            } else if ($diff == 1) {
                $diff = 'Demain';
            } else {
                $diff = date_fr(date('D', strtotime($event['start']))) . ' ' . date('d', strtotime($event['start']));
            }

            $replaceCmd = array(
                '#name#' => $event['summary'],
                '#start#' => $diff,
                '#background_color#' => $color[$event['colorId']] . '6e',
                '#text_color#' => ' #e1e1e1',
            );
            $dEvent .= template_replace($replaceCmd, $tEvent);
            $nbEvent++;
        }
        $replace['#events#'] = $dEvent;
        return template_replace($replace, getTemplate('core', $version, 'eqLogic', __CLASS__));
    }
}
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////  
class gAgendaCmd extends cmd
{
    public function execute($_options = array())
    {
        if ($this->getLogicalId() == 'refresh') {
            $eqLogic = $this->getEqLogic();
            $eqLogic->syncWithGoogle();
            $eqLogic->reschedule();
        }
    }
}