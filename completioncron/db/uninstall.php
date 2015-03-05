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
 * Split completion task allowing new users being added to be done once daily - Good for high user levels
 *
 * @package    tool_completioncron
 * @copyright  2014 Josh Willcock  http://charitylearning.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
function xmldb_completioncron_uninstall() {
    global $DB;
    $sql ="UPDATE {task_scheduled} SET `disabled`='0' WHERE `classname`  LIKE '%completion_cron_task%'";
        $DB->execute($sql);
  echo '<div class="alert alert-success">Disabled Split Crons & Enabled Default Moodle Core Completions</div>';
}
xmldb_completioncron_uninstall();