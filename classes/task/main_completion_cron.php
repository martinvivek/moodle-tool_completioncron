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
namespace tool_completioncron\task;


class main_completion_cron extends \core\task\scheduled_task {      
    public function get_name() {
        return 'Main Completion Cron';
    }
                                                                     
    public function execute() {
    global $CFG;
    require_once($CFG->libdir.'/completionlib.php');

/**
 * Update user's course completion statuses
 *
 * First update all criteria completions, then aggregate all criteria completions
 * and update overall course completions
 */
    $this->completion_cron_criteria();
    $this->completion_cron_completions();
 
}
/**
 * Run installed criteria's data aggregation methods
 *
 * Loop through each installed criteria and run the
 * cron() method if it exists
 *
 * @return void
 */
function completion_cron_criteria() {

    // Process each criteria type
    global $CFG, $COMPLETION_CRITERIA_TYPES;

    foreach ($COMPLETION_CRITERIA_TYPES as $type) {

        $object = 'completion_criteria_'.$type;
        require_once $CFG->dirroot.'/completion/criteria/'.$object.'.php';

        $class = new $object();

        // Run the criteria type's cron method, if it has one
        if (method_exists($class, 'cron')) {

            if (debugging()) {
                mtrace('Running '.$object.'->cron()');
            }
            $class->cron();
        }
    }
}

/**
 * Aggregate each user's criteria completions
 */
function completion_cron_completions() {
    global $DB;

    if (debugging()) {
        mtrace('Aggregating completions');
    }

    // Save time started
    $timestarted = time();

    // Grab all criteria and their associated criteria completions
    $sql = '
        SELECT DISTINCT
            c.id AS course,
            cr.id AS criteriaid,
            crc.userid AS userid,
            cr.criteriatype AS criteriatype,
            cc.timecompleted AS timecompleted
        FROM
            {course_completion_criteria} cr
        INNER JOIN
            {course} c
         ON cr.course = c.id
        INNER JOIN
            {course_completions} crc
         ON crc.course = c.id
        LEFT JOIN
            {course_completion_crit_compl} cc
         ON cc.criteriaid = cr.id
        AND crc.userid = cc.userid
        WHERE
            c.enablecompletion = 1
        AND crc.timecompleted IS NULL
        AND crc.reaggregate > 0
        AND crc.reaggregate < :timestarted
        ORDER BY
            course,
            userid
    ';

    $rs = $DB->get_recordset_sql($sql, array('timestarted' => $timestarted));

    // Check if result is empty
    if (!$rs->valid()) {
        $rs->close(); // Not going to iterate (but exit), close rs
        return;
    }

    $current_user = null;
    $current_course = null;
    $completions = array();

    while (1) {

        // Grab records for current user/course
        foreach ($rs as $record) {
            // If we are still grabbing the same users completions
            if ($record->userid === $current_user && $record->course === $current_course) {
                $completions[$record->criteriaid] = $record;
            } else {
                break;
            }
        }

        // Aggregate
        if (!empty($completions)) {

            if (debugging()) {
                mtrace('Aggregating completions for user '.$current_user.' in course '.$current_course);
            }

            // Get course info object
            $info = new completion_info((object)array('id' => $current_course));

            // Setup aggregation
            $overall = $info->get_aggregation_method();
            $activity = $info->get_aggregation_method(COMPLETION_CRITERIA_TYPE_ACTIVITY);
            $prerequisite = $info->get_aggregation_method(COMPLETION_CRITERIA_TYPE_COURSE);
            $role = $info->get_aggregation_method(COMPLETION_CRITERIA_TYPE_ROLE);

            $overall_status = null;
            $activity_status = null;
            $prerequisite_status = null;
            $role_status = null;

            // Get latest timecompleted
            $timecompleted = null;

            // Check each of the criteria
            foreach ($completions as $params) {
                $timecompleted = max($timecompleted, $params->timecompleted);

                $completion = new completion_criteria_completion((array)$params, false);

                // Handle aggregation special cases
                if ($params->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                    $this->completion_cron_aggregate($activity, $completion->is_complete(), $activity_status);
                } else if ($params->criteriatype == COMPLETION_CRITERIA_TYPE_COURSE) {
                    $this->completion_cron_aggregate($prerequisite, $completion->is_complete(), $prerequisite_status);
                } else if ($params->criteriatype == COMPLETION_CRITERIA_TYPE_ROLE) {
                    $this->completion_cron_aggregate($role, $completion->is_complete(), $role_status);
                } else {
                    $this->completion_cron_aggregate($overall, $completion->is_complete(), $overall_status);
                }
            }

            // Include role criteria aggregation in overall aggregation
            if ($role_status !== null) {
                $this->completion_cron_aggregate($overall, $role_status, $overall_status);
            }

            // Include activity criteria aggregation in overall aggregation
            if ($activity_status !== null) {
                $this->completion_cron_aggregate($overall, $activity_status, $overall_status);
            }

            // Include prerequisite criteria aggregation in overall aggregation
            if ($prerequisite_status !== null) {
                $this->completion_cron_aggregate($overall, $prerequisite_status, $overall_status);
            }

            // If aggregation status is true, mark course complete for user
            if ($overall_status) {
                if (debugging()) {
                    mtrace('Marking complete');
                }

                $ccompletion = new completion_completion(array('course' => $params->course, 'userid' => $params->userid));
                $ccompletion->mark_complete($timecompleted);
            }
        }

        // If this is the end of the recordset, break the loop
        if (!$rs->valid()) {
            $rs->close();
            break;
        }

        // New/next user, update user details, reset completions
        $current_user = $record->userid;
        $current_course = $record->course;
        $completions = array();
        $completions[$record->criteriaid] = $record;
    }

    // Mark all users as aggregated
    $sql = "
        UPDATE
            {course_completions}
        SET
            reaggregate = 0
        WHERE
            reaggregate < :timestarted
        AND reaggregate > 0
    ";

    $DB->execute($sql, array('timestarted' => $timestarted));
}

/**
 * Aggregate criteria status's as per configured aggregation method
 *
 * @param int $method COMPLETION_AGGREGATION_* constant
 * @param bool $data Criteria completion status
 * @param bool|null $state Aggregation state
 */
function completion_cron_aggregate($method, $data, &$state) {
    if ($method == COMPLETION_AGGREGATION_ALL) {
        if ($data && $state !== false) {
            $state = true;
        } else {
            $state = false;
        }
    } elseif ($method == COMPLETION_AGGREGATION_ANY) {
        if ($data) {
            $state = true;
        } else if (!$data && $state === null) {
            $state = false;
        }
    }
}




    
}
