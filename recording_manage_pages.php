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
 * Página de administração de módulos de página onde são incluídos links
 * de gravações do Zoom.
 *
 * @package    local_zoomadmin
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$url = new moodle_url('/local/zoomadmin/recording_manage_pages.php');
$PAGE->set_url($url);
$context = context_system::instance();
$PAGE->set_context($context);

$title = get_string('pluginname', 'local_zoomadmin') . ' - ' . get_string('command_recording_manage_pages', 'local_zoomadmin');
$PAGE->set_title($title);
$PAGE->set_pagelayout('admin');


require_login();
admin_externalpage_setup('local_zoomadmin_recording_manage_pages');
require_capability('local/zoomadmin:managezoom', $context);

$output = $PAGE->get_renderer('local_zoomadmin');
$page = new \local_zoomadmin\output\manage_zoom();

echo $output->header() . $output->heading($title);

echo $output->render_page($page);

echo $output->footer();
