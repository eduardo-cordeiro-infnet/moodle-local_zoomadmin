<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Arquivo contendo a principal classe do plugin.
 *
 * Contém a classe que interage com a REST API do Zoom.
 *
 * @package    local_zoomadmin
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

namespace local_zoomadmin;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../credentials.php');

/**
 * Classe de interação com a REST API do Zoom.
 *
 * Determina comandos a serem executados na API e retorna os resultados.
 *
 * @package    local_zoomadmin
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class zoomadmin {
    const BASE_URL = 'https://api.zoom.us/v1';
    /**
     * @var int ERR_MAX_REQUESTS Código de erro de quantidade máxima
     *                           de requisições excedida
     */
    const ERR_MAX_REQUESTS = 403;
    const MAX_PAGE_SIZE = 300;
    const KBYTE_BYTES = 1024;
    const MIN_VIDEO_SIZE = self::KBYTE_BYTES * self::KBYTE_BYTES * 20;

    var $commands = array();

    public function __construct() {
        $this->populate_commands();
    }

    private function request(command $command, $params, $attemptcount = 1) {
        /**
         * @var int $attemptsleeptime Tempo (microssegundos) que deve ser
         *                            aguardado para tentar novamente quando o
         *                            máximo de requisições for atingido
         */
        $attemptsleeptime = 100000;
        /** @var int $maxattemptcount Número máximo de novas tentativas */
        $maxattemptcount = 10;

        $params = (is_array($params)) ? $params : array();

        $ch = curl_init($this->get_api_url($command));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge($this->get_credentials(), $params), null, '&'));

        $response = json_decode(curl_exec($ch));
        curl_close($ch);

        if (isset($response->error)) {
            if ($response->error->code === $this::ERR_MAX_REQUESTS && $attemptcount <= $maxattemptcount) {
                usleep($attemptsleeptime);
                return $this->request($command, $params, $attemptcount + 1);
            } else {
                $errorresponse = clone $response;
                $errorresponse->command = $command;
                $errorresponse->params = $params;
                $errorresponse->attempts = $attemptcount;

                print_object($errorresponse);
            }
        }


        return $response;
    }

    public function get_index_commands() {
        $indexcommands = array_filter($this->commands, function($cmd){return $cmd->showinindex === true;}) ;

        $categories = array();

        foreach ($indexcommands as $cmd) {
            $catindex = 0;
            $cat = null;

            foreach ($categories as $catindex => $category) {
                if ($category->name === $cmd->category) {
                    $cat = $category;
                    break;
                }
            }

            if (!isset($cat)) {
                $cat = $this->create_category($cmd);
                $catindex = count($categories);
            }

            $cat->commands[] = $cmd;
            $categories[$catindex] = $cat;
        }

        return $categories;
    }

    private function create_category($command) {
        $category = new \stdClass();
        $category->name = $command->category;
        $category->stringname = $command->categorystringname;
        $category->commands = array();

        return $category;
    }

    public function handle_form(\stdClass $formdata) {
        confirm_sesskey();

        $response = $this->request($this->commands[$formdata->zoom_command], get_object_vars($formdata));

        if (isset($response->error)) {
            $response->notification->type = \core\output\notification::NOTIFY_ERROR;
            $response->notification->message = get_string('zoom_command_error', 'local_zoomadmin', $response->error);
        } else {
            $response->notification->type = \core\output\notification::NOTIFY_SUCCESS;
            $response->notification->message = get_string(
                'notification_' . $formdata->zoom_command,
                'local_zoomadmin',
                $formdata->first_name . ' ' . $formdata->last_name
            );
        }

        $response->formdata = $formdata;
        return $response;
    }

    public function get_user_list($params) {
        $commands = $this->commands;

        $data = $this->request($commands['user_list'], $params);
        $pending = $this->request($commands['user_pending'], $params);
        $data->pending = $pending->users;

        $data->users = $this->sort_users_by_name($data->users);
        $data->pending = $this->sort_users_by_name($data->pending);

        return $data;
    }

    public function get_user($userid) {
        return $this->request($this->commands['user_get'], array('id' => $userid));
    }

    public function get_meetings_list($params = array()) {
        $meetingsdata = new \stdClass();
        $commands = $this->commands;

        $meetingsdata = $this->request($commands['meeting_live'], $params);
        $meetingsdata->live = $meetingsdata->meetings;

        $userdata = $this->request($commands['user_list'], array('page_size' => $this::MAX_PAGE_SIZE));
        $users = $userdata->users;

        $meetingsdata->meetings = array();

        foreach ($users as $user) {
            $params['host_id'] = $user->id;

            $usermeetings = $this->request($commands['meeting_list'], $params);
            $usermeetings->total_records = (int)$usermeetings->total_records;

            if ($usermeetings->total_records > 0) {
                foreach($usermeetings->meetings as $index => $meeting) {
                    $usermeetings->meetings[$index]->host = $user;
                }

                $meetingsdata->total_records = (int)$meetingsdata->total_records + (int)$usermeetings->total_records;
                $meetingsdata->page_count = max((int)$meetingsdata->page_count, (int)$usermeetings->page_count);

                $meetingsdata->meetings = array_merge($meetingsdata->meetings, $usermeetings->meetings);
            }

        }

        foreach ($meetingsdata->live as $index => $meeting) {
            foreach ($users as $user) {
                if ($user->id === $meeting->host_id) {
                    $meetingsdata->live[$index]->host = $user;
                    break;
                }
            }
        }

        $meetingsdata->live = $this->set_meetings_data($meetingsdata->live);
        $meetingsdata->meetings = $this->set_meetings_data($meetingsdata->meetings, true);

        $meetingsdata->live = $this->sort_meetings_by_start($meetingsdata->live);
        $meetingsdata->meetings->past = $this->sort_meetings_by_start($meetingsdata->meetings->past, false);
        $meetingsdata->meetings->upcoming = $this->sort_meetings_by_start($meetingsdata->meetings->upcoming);

        return $meetingsdata;
    }

    public function sort_meetings_by_start($meetings, $ascending = true) {
        $asc = function($meeting1, $meeting2) {
            if ($meeting1->start_time == $meeting2->start_time) {
                return 0;
            }
            return ($meeting1->start_time < $meeting2->start_time) ? -1 : 1;
        };

        $desc = function($meeting1, $meeting2) {
            if ($meeting1->start_time == $meeting2->start_time) {
                return 0;
            }
            return ($meeting1->start_time > $meeting2->start_time) ? -1 : 1;
        };

        usort($meetings, ($ascending === true) ? $asc : $desc);

        return $meetings;
    }

    public function get_recording_list($params) {
        $commands = $this->commands;

        $userdata = $this->request($commands['user_list'], array('page_size' => $this::MAX_PAGE_SIZE));
        $users = $userdata->users;

        $recordingsdata = new \stdClass();
        $recordingsdata->user_get_url = './user_get.php';
        $recordingsdata->add_recordings_to_page_url = './add_recordings_to_page.php';

        $recordingsdata->meetings = array();

        foreach ($users as $user) {
            $params['host_id'] = $user->id;

            $userrecordings = $this->request($commands['recording_list'], $params);
            $recordingsdata->total_records = (int)$userrecordings->total_records;

            if ($recordingsdata->total_records > 0) {
                foreach($userrecordings->meetings as $index => $meeting) {
                    $userrecordings->meetings[$index]->host = $user;
                }

                $recordingsdata->total_records += (int)$userrecordings->total_records;
                $recordingsdata->page_count = max((int)$recordingsdata->page_count, (int)$userrecordings->page_count);

                $recordingsdata->meetings = $this->set_recordings_data(array_merge($recordingsdata->meetings, $userrecordings->meetings));
            }
        }

        $recordingsdata->meetings = $this->sort_meetings_by_start($recordingsdata->meetings, false);

        return $recordingsdata;
    }

    public function add_recordings_to_page($meetingid) {
        if ($meetingid == null) {
            return $this->add_all_recordings_to_page();
        }

        $meetingrecordings = $this->get_recording($meetingid);
        $meetingnumber = $meetingrecordings->meeting_number;
        $pagedata = array_pop($this->get_recordings_page_data(array('meetingnumber' => $meetingnumber)));

        if (($meetingid !== null && $meetingnumber === null) || $pagedata === null) {
            return get_string('error_no_page_instance_found', 'local_zoomadmin', $this->format_meeting_number($meetingnumber));
        }

        $newcontent = $this->get_new_recordings_page_content($pagedata, $meetingrecordings);

        if ($newcontent === 'error_recording_already_added') {
            $this->update_recordpage_timestamp($pagedata->recordpageid, $meetingrecordings->start_time_unix);
        }

        if (substr($newcontent, 0, 5) === 'error') {
            return get_string($newcontent, 'local_zoomadmin', $this->format_file_size($this::MIN_VIDEO_SIZE));
        }

        $pageupdated = $this->update_page_content($pagedata, $newcontent);

        if ($pageupdated === true) {
            $this->update_recordpage_timestamp($pagedata->recordpageid, $meetingrecordings->start_time_unix);
            $recordingpageurl = new \moodle_url('/mod/page/view.php', array('id' => $pagedata->cmid));
            return get_string('recordings_added_to_page', 'local_zoomadmin', $recordingpageurl->out());
        } else {
            return get_string('error_add_recordings_to_page', 'local_zoomadmin', $recordingpageurl->out());
        }

        return $return;
    }

    public function get_recording_pages_list() {
        $pagesdata = $this->get_recordings_page_data();
        $meetingsdata = $this->get_meetings_list();
        $meetings = array_merge($meetingsdata->meetings->past, $meetingsdata->meetings->upcoming);

        $data = new \stdClass();
        $data->user_get_url = './user_get.php';
        $data->recording_edit_page_url = './recording_edit_page.php';

        $data->pagesdata = array();
        foreach ($pagesdata as $dbpagedata) {
            $pagedata = new \stdClass();
            $pagedata->record_page_id = $dbpagedata->recordpageid;
            $meetingnumber = $dbpagedata->zoommeetingnumber;
            $pagedata->meeting_number = $this->format_meeting_number($meetingnumber);

            foreach ($meetings as $meeting) {
                if ($meeting->id == $meetingnumber) {
                    $pagedata->topic = $meeting->topic;
                    $pagedata->host = $meeting->host;
                    break;
                }
            }

            $pagedata->pagecourselink = $this->format_course_path_links(
                array($dbpagedata->cat2name, $dbpagedata->catname, $dbpagedata->coursename),
                array($dbpagedata->cat2id, $dbpagedata->catid, $dbpagedata->courseid)
            );

            $pagedata->pagelink = $this->surround_with_anchor(
                $dbpagedata->name,
                (new \moodle_url('/mod/page/view.php', array('id' => $dbpagedata->cmid)))->out(),
                true
            );

            $data->pagesdata[] = $pagedata;
        }

        return $data;
    }

    public function get_recordings_page_data_by_id($recordpageid) {
        $pagedata = $this->get_recordings_page_data(array('recordpageid' => $recordpageid));
        return (!empty($pagedata)) ? array_pop($pagedata) : $pagedata;
    }

    public function recording_edit_page($formdata) {
        global $DB;

        $action = (is_array($formdata)) ? $formdata['action'] : $formdata->action;
        $tablename = 'local_zoomadmin_recordpages';

        $success = false;
        $message = '';

        if ($action === 'edit') {
            $formdata->id = $formdata->recordpageid;
            if ($DB->update_record($tablename, $formdata) == 1) {
                $success = true;
            } else {
                $success = false;
            }
        } else if ($action === 'add') {
            if ($DB->insert_record($tablename, $formdata) > 0) {
                $success = true;
            } else {
                $success = false;
            }
        } else if (
            $action === 'delete'
            && isset($formdata['delete_confirm'])
            && $formdata['delete_confirm'] == true
        ) {
            $deleteresponse = $DB->delete_records(
                $tablename,
                array('id' => $formdata['recordpageid'])
            );
            if ($deleteresponse === true) {
                $success = true;
            } else {
                $success = false;
            }
        }

        $response = new \stdClass();
        $response->success = $success;
        $response->notification = $this->get_notification(
            $success,
            get_string(
                'notification_recording_edit_page_' .
                    $action .
                    '_' .
                    (($success === true) ? 'success' : 'error'),
                'local_zoomadmin'
            )
        );

        return $response;
    }

    private function populate_commands() {
        $this->commands['user_list'] = new command('user', 'list');
        $this->commands['user_pending'] = new command('user', 'pending', false);
        $this->commands['user_get'] = new command('user', 'get', false);
        $this->commands['user_create'] = new command('user', 'create', false);
        $this->commands['user_update'] = new command('user', 'update', false);

        $this->commands['meeting_list'] = new command('meeting', 'list');
        $this->commands['meeting_live'] = new command('meeting', 'live', false);
        $this->commands['meeting_get'] = new command('meeting', 'get', false);
        $this->commands['meeting_create'] = new command('meeting', 'create', false);
        $this->commands['meeting_update'] = new command('meeting', 'update', false);

        $this->commands['recording_list'] = new command('recording', 'list');
        $this->commands['recording_get'] = new command('recording', 'get', false);
        $this->commands['recording_delete'] = new command('recording', 'delete', false);
        $this->commands['recording_manage_pages'] = new command('recording', 'manage_pages');
    }

    private function get_credentials() {
        global $CFG;

        return array(
            'api_key' => $CFG->zoom_apikey,
            'api_secret' => $CFG->zoom_apisecret
        );
    }

    private function get_api_url($command) {
        return join('/', array($this::BASE_URL, $command->category, $command->name));
    }

    private function set_recordings_data($meetings) {
        foreach($meetings as $meetingindex => $meeting) {
            $timezone = $this->get_meeting_timezone($meeting);

            $meetings[$meetingindex]->encoded_uuid = urlencode($meeting->uuid);
            $meetings[$meetingindex]->start_time_unix = strtotime($meeting->start_time);

            foreach($meeting->recording_files as $fileindex => $file) {
                $recordingstarttime = (new \DateTime($file->recording_start))->setTimezone($timezone);
                $meetings[$meetingindex]->recording_files[$fileindex]->recording_start_formatted = $recordingstarttime->format('d/m/Y H:i:s');

                $recordingendtime = (new \DateTime($file->recording_end))->setTimezone($timezone);
                $meetings[$meetingindex]->recording_files[$fileindex]->recording_end_formatted = $recordingendtime->format('d/m/Y H:i:s');

                $timediff = $recordingstarttime->diff($recordingendtime);
                $meetings[$meetingindex]->recording_files[$fileindex]->recording_duration = sprintf('%02d', $timediff->h) . ':' . sprintf('%02d', $timediff->i) . ':' . sprintf('%02d', $timediff->s);

                $meetings[$meetingindex]->recording_files[$fileindex]->meeting_number_formatted = $this->format_meeting_number($meeting->meeting_number);

                $meetings[$meetingindex]->recording_files[$fileindex]->file_size_formatted = $this->format_file_size($file->file_size);

                $meetings[$meetingindex]->recording_files[$fileindex]->file_type_string = get_string('file_type_' . $file->file_type, 'local_zoomadmin');
                $meetings[$meetingindex]->recording_files[$fileindex]->recording_status_string = get_string('recording_status_' . $file->status, 'local_zoomadmin');
            }
        }

        return $meetings;
    }

    private function get_meeting_timezone($meeting) {
        return new \DateTimeZone((isset($meeting->timezone)) ? $meeting->timezone : (isset($meeting->host->timezone)) ? $meeting->host->timezone : 'America/Sao_Paulo');
    }

    private function format_meeting_number($meetingnumber) {
        return number_format($meetingnumber, 0, '', '-');
    }

    private function format_file_size($filesize) {
        $kb = $this::KBYTE_BYTES;
        $mb = pow($kb, 2);
        $gb = pow($kb, 3);

        if ($filesize < $kb) {
            return $filesize . ' B';
        } else if ($filesize < $mb) {
            return floor($filesize / $kb) . ' KB';
        } else if ($filesize < $gb) {
            return floor($filesize / $mb) . ' MB';
        } else {
            return floor($filesize / $gb) . ' GB';
        }
    }

    private function set_meetings_data($meetings, $separatepastupcoming = false) {
        $meetingswithoccurrences = array();
        $meetingsbydate = new \stdClass();
        $meetingsbydate->past = array();
        $meetingsbydate->upcoming = array();

        $now = new \DateTime();

        foreach ($meetings as $index => $meeting) {
            $meeting->type_string = get_string('meeting_type_' . $meeting->type, 'local_zoomadmin');
            $meeting->id_formatted = $this->format_meeting_number($meeting->id);

            if (!in_array($meeting->type, array(3, 8))) {
                $meetingswithoccurrences[] = $meeting;
            } else {
                $occurrences = $this->get_meeting_occurrences($meeting);
                $meetingswithoccurrences = array_merge($meetingswithoccurrences, $occurrences);
            }
        }

        foreach ($meetingswithoccurrences as $index => $meeting) {
            if ($meeting->start_time !== '') {
                $meetingstarttime = new \DateTime($meeting->start_time);
                $meeting->start_time_formatted = $meetingstarttime->format('d/m/Y H:i:s');

                if ($separatepastupcoming === true) {
                    if ($meetingstarttime < $now) {
                        $meetingsbydate->past[] = $meeting;
                    } else {
                        $meetingsbydate->upcoming[] = $meeting;
                    }
                }
            } else if ($separatepastupcoming === true) {
                $meetingsbydate->past[] = $meeting;
            }
        }

        if ($separatepastupcoming === true) {
            return $meetingsbydate;
        } else {
            return $meetingswithoccurrences;
        }
    }

    private function get_meeting_occurrences($meeting) {
        $occurrences = array();
        $meetingdata = $this->request($this->commands['meeting_get'], array('id' => $meeting->id, 'host_id' => $meeting->host_id));

        if (!isset($meetingdata->occurrences) || empty($meetingdata->occurrences)) {
            $occurrences[] = $meeting;
        } else {
            foreach ($meetingdata->occurrences as $occurrence) {
                $occurrencewithdata = clone $meeting;

                foreach ($occurrence as $key => $value) {
                    $occurrencewithdata->$key = $value;
                }

                $occurrences[] = $occurrencewithdata;
            }
        }

        return $occurrences;
    }

    private function get_recording($meetingid) {
        $commands = $this->commands;

        $recordingmeeting = $this->request($commands['recording_get'], array('meeting_id' => $meetingid));
        $recordingmeeting->host = $this->request($commands['user_get'], array('id' => $recordingmeeting->host_id));
        $recordingmeeting = array_pop($this->set_recordings_data(array($recordingmeeting)));

        return $recordingmeeting;
    }

    private function get_recordings_page_data($params = array()) {
        global $DB;

        $sqlstring = "
            select rp.id recordpageid,
                cm.id cmid,
                rp.pagecmid,
                p.*,
                rp.zoommeetingnumber,
                rp.lastaddedtimestamp,
                cm.course courseid,
                c.fullname coursename,
                cc.id catid,
                cc.name catname,
                cc2.id cat2id,
                cc2.name cat2name
            from {local_zoomadmin_recordpages} rp
                left join {course_modules} cm on cm.id = rp.pagecmid
                left join {modules} m on m.id = cm.module
                    and m.name = 'page'
                left join {page} p on p.id = cm.instance
                left join {course} c on c.id = cm.course
                left join {course_categories} cc on cc.id = c.category
                left join {course_categories} cc2 on cc2.id = cc.parent
            where 1 = 1
        ";

        $tokens = array();
        if (isset($params['meetingnumber'])) {
            $sqlstring .= "
                and rp.zoommeetingnumber = ?
            ";
            $tokens[] = $params['meetingnumber'];
        }

        if (isset($params['recordpageid'])) {
            $sqlstring .= "
                and rp.id = ?
            ";
            $tokens[] = $params['recordpageid'];
        }

        return $DB->get_records_sql($sqlstring, $tokens);
    }

    private function get_new_recordings_page_content($pagedata, $meetingrecordings) {
        $content = $pagedata->content;
        $recordingurls = $this->get_recording_urls_for_page($meetingrecordings->recording_files);
        $recordingcount = count($recordingurls);

        $doc = new \DOMDocument();

        if ($recordingcount > 0) {
            $urlul = $doc->createElement('ul');
            $multiplevideos = ($recordingurls[$recordingcount - 1]['videoindex'] > 1);

            foreach ($recordingurls as $url) {
                if (strpos($content, $url['url']) !== false) {
                    return 'error_recording_already_added';
                }

                $anchortext = $url['text'] . (($multiplevideos) ? (' - ' . get_string('recording_part', 'local_zoomadmin') . ' ' . $url['videoindex']) : '');

                $li = $urlul->appendChild($doc->createElement('li'));
                $a = $li->appendChild($doc->createElement('a', $anchortext));
                $a->setAttribute('href', $url['url']);
                $a->setAttribute('target', '_blank');
            }

            $classnumber = 1;

            $doc->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));

            $classdate = (new \DateTime($meetingrecordings->start_time))->setTimezone($this->get_meeting_timezone($meetingrecordings))->format('d/m/Y');

            $h2list = $doc->getElementsByTagName('h2');
            $h2length = $h2list->length;

            if ($h2length) {
                $lastclasstitle = $h2list->item($h2length - 1)->textContent;
                $lastclassnumber = array_pop(explode(' ', $lastclasstitle));

                if (filter_var($lastclassnumber, FILTER_VALIDATE_INT) !== false) {
                    $classnumber = $lastclassnumber + 1;
                }
            }

            $doc->appendChild($doc->createElement('h2', $classdate . ' - Aula ' . $classnumber));
            $urlul = $doc->importNode($urlul, true);
            $doc->appendChild($urlul);

            return $doc->saveHTML();
        } else {
            return 'error_no_recordings_found';
        }
    }

    private function get_recording_urls_for_page($recordings) {
        $recordinglist = array();
        $ignoredvideo = true;
        $videoindex = 0;

        foreach ($recordings as $index => $recording) {
            $filetype = $recording->file_type;

            if ($filetype === 'MP4') {
                if ($recording->file_size >= $this::MIN_VIDEO_SIZE) {
                    $videoindex++;

                    $recordinglist[] = array(
                        'text' => get_string('recording_text_' . $filetype, 'local_zoomadmin'),
                        'url' => $recording->play_url,
                        'videoindex' => $videoindex
                    );

                    $ignoredvideo = false;
                } else {
                    $ignoredvideo = true;
                }
            } else if ($filetype === 'CHAT' && $ignoredvideo === false) {
                $recordinglist[] = array(
                    'text' => get_string('recording_text_' . $filetype, 'local_zoomadmin'),
                    'url' => $recording->download_url,
                    'videoindex' => $videoindex
                );
            }
        }

        return $recordinglist;
    }

    private function update_page_content($pagedata, $newcontent) {
        global $USER, $DB;

        $timestamp = (new \DateTime())->getTimestamp();

        $pagedata->content = $newcontent;
        $pagedata->usermodified = $USER->id;
        $pagedata->timemodified = $timestamp;

        $pageupdated = $DB->update_record('page', $pagedata);

        return $pageupdated;
    }

    private function update_recordpage_timestamp($id, $lastaddedtimestamp) {
        global $DB;

        return $DB->update_record(
            'local_zoomadmin_recordpages',
            array(
                'id' => $id,
                'lastaddedtimestamp' => $lastaddedtimestamp
            )
        );
    }

    private function add_all_recordings_to_page() {
        $recordingsdata = $this->get_recordings_list();
        $recordingsdata->meetings = $this->sort_meetings_by_start($recordingsdata->meetings);
        $pagesdata = $this->get_recordings_page_data();

        $meetingids = array();
        foreach ($pagesdata as $pagedata) {
            foreach ($recordingsdata->meetings as $meetingdata) {
                if (
                    $meetingdata->meeting_number == $pagedata->zoommeetingnumber
                    && $meetingdata->start_time_unix > $pagedata->lastaddedtimestamp
                ) {
                    $responses[] = '<a href="https://www.zoom.us/recording/management/detail?meeting_id=' .
                        $meetingdata->encoded_uuid .
                        '" target="_blank">' .
                        $meetingdata->topic .
                        ' - ' .
                        $meetingdata->recording_files[0]->recording_start_formatted .
                        '</a> - ' .
                        $this->add_recordings_to_page($meetingdata->uuid)
                    ;
                }
            }
        }

        return $responses;
    }

    private function sort_users_by_name($users) {
        usort($users, function($user1, $user2) {
            $firstname = strcoll($user1->first_name, $user2->first_name);

            if ($firstname === 0) {
                return strcoll($user1->last_name, $user2->last_name);
            }

            return $firstname;
        });

        return $users;
    }

    private function format_course_path_links($contents, $ids) {
        $links = array();
        $lastindex = sizeof($contents) - 1;

        foreach ($contents as $index => $content) {
            if ($index === $lastindex) {
                $href = new \moodle_url('/course/view.php', array('id' => $ids[$index]));
            } else {
                $href = new \moodle_url('/course/index.php', array('categoryid' => $ids[$index]));
            }

            $links[] = $this->surround_with_anchor($content, $href->out(), true);
        }

        return join(
            ' / ',
            $links
        );
    }

    private function surround_with_anchor($content, $href, $newwindow) {
        return '<a href="' . $href . '"' .
            (($newwindow === true) ? 'target="_blank"' : '') .
            '>' . $content .
            '</a>'
        ;
    }

    private function get_notification($success = true, $message = '') {
        $notification = new \stdClass();
        $notification->type = ($success === true) ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR;
        $notification->message = $message;

        return $notification;
    }
}
