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
 * @package   plagiarism_turnitin
 * @copyright 2012 iParadigms LLC *
 */

defined('MOODLE_INTERNAL') || die();

// TODO: Split out all module specific code from plagiarism/turnitin/lib.php.
class turnitin_coursework {

    private $modname;
    public $gradestable;
    public $filecomponent;

    public function __construct() {
        $this->modname = 'coursework';
        $this->gradestable = $this->modname.'_feedbacks';
        $this->filecomponent = 'mod_'.$this->modname;
    }

    public function is_tutor($context) {
        $capabilities = array($this->get_tutor_capability(), 'mod/coursework:addagreedgrade',
            'mod/coursework:addallocatedagreedgrade', 'mod/coursework:administergrades');
        return has_any_capability($capabilities, $context);
    }

    public function can_add_all_grades($coursework){
        $context = $coursework->get_course_context();
       return has_capability('mod/coursework:administergrades', $context);
    }

    public function get_tutor_capability() {
        return 'mod/'.$this->modname.':addinitialgrade';
    }

    public function user_enrolled_on_course($context, $userid) {
        return has_capability('mod/'.$this->modname.':submit', $context, $userid);
    }

    public function get_author($itemid) {
        global $DB;

        $id = 0;

        if ($submission = $DB->get_record('coursework_submissions', array('id' => $itemid))) {
            $id = $submission->authorid;
        }

        return $id;
    }



    public function create_file_event($params) {
        return \courseworksubmission_file\event\assessable_uploaded::create($params);
    }


    public function get_current_gradequery($userid, $moduleid, $itemid = 0) {
        global $DB;

        $sql = "SELECT         *
                FROM           {coursework_submissions}    cs,
                               {coursework_feedbacks}      cf
                WHERE         cs.id   =   cf.submissionid
                AND           cs.authorid         =   :authorid
                AND           cs.courseworkid     =   :courseworkid
                AND           cf.stage_identifier =   :stage";

        $params = array('stage' => 'assessor_1', 'authorid' => $userid, 'courseworkid' => $moduleid);

        $currentgradesquery = $DB->get_record_sql($sql, $params);

        return $currentgradesquery;
    }

    public function initialise_post_date($moduledata) {
        return 0;
    }


    /**
     * Check if the coursework uses multiple markers.
     *
     * @param $courseworkid
     * @return bool
     */
    public function double_marked_coursework($courseworkid){

        $coursework = new \mod_coursework\models\coursework($courseworkid);
        return $doublemarkingcw = $coursework->has_multiple_markers();
    }


    /**
     * Check if current user can grade provided submission.
     *
     * @param $submission
     * @param $coursework
     * @return bool
     * @throws dml_exception
     */
    public function can_grade($submission, $coursework){
        global $DB, $USER;

        $user = mod_coursework\models\user::find($USER->id);
        $ability = new \mod_coursework\ability($user, $coursework);
        $cw_feedback = '';
        $new_feedback = '';

        if ($feedback = $DB->get_record('coursework_feedbacks', array('submissionid'=>$submission->id))){
            $cw_feedback = mod_coursework\models\feedback::build($feedback);

        } else {
            $feedback_params = array(
                'submissionid' => $submission->id,
                'assessorid' => $USER->id,
                'stage_identifier' => 'assessor_1');

            $new_feedback = mod_coursework\models\feedback::build($feedback_params);
        }
        // If a user doesn't have a capability to add or edit grade, or it's a double coursework, don't allow the user to enter the mark
        return ((($cw_feedback && $ability->can('edit', $cw_feedback)) || ($new_feedback && $ability->can('new', $new_feedback))) && !$coursework->has_multiple_markers());
    }

    public function set_content($linkarray, $cm) {
        $onlinetextdata = $this->get_onlinetext($linkarray["userid"], $cm);

        return (empty($onlinetextdata->onlinetext)) ? '' : $onlinetextdata->onlinetext;
    }


    public function get_onlinetext($userid, $cm) {
        global $DB;

        // Get latest text content submitted as we do not have submission id.
        $submissions = $DB->get_records_select('coursework_submissions', ' authorid = ? AND courseworkid = ? ',
                                                array($userid, $cm->instance), 'id DESC', 'id', 0, 1);
        $submission = end($submissions);
        $moodletextsubmission = $DB->get_record('cwksub_onlinetext',
                        array('submissionid' => $submission->id), 'onlinetext, onlineformat');

        $onlinetextdata = new stdClass();
        $onlinetextdata->itemid = $submission->id;

        if (isset($moodletextsubmission->onlinetext)) {
            $onlinetextdata->onlinetext = $moodletextsubmission->onlinetext;
        }
        if (isset($moodletextsubmission->onlineformat)) {
            $onlinetextdata->onlineformat = $moodletextsubmission->onlineformat;
        }

        return $onlinetextdata;
    }

    public function create_text_event($params) {
        return \courseworksubmission_onlinetext\event\assessable_uploaded::create($params);

    }

    /**
     * Check if resubmissions in a Turnitin sense are allowed to an coursework.
     *
     * @param $courseworkid
     */
    public function is_resubmission_allowed($courseworkid, $reportgenspeed, $submissiontype, $attemptreopenmethod=null,
                                            $attemptreopened = null) {
        global $DB, $CFG;

        // Get the maximum number of file submissions allowed.
        $params = array('courseworkid' => $courseworkid);

        $maxfilesubmissions = 0;

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('coursework_sub_plugin')) {

            $sql = "SELECT value
                FROM {coursework_sub_plugin} csp JOIN {coursework_sub_plugin_cfg} cspc ON csp.id = cspc.subpluginid
                WHERE csp.name = 'file' AND  courseworkid = :courseworkid AND cspc.name = 'maxfiles'";

        } else {
            $sql = "SELECT maxfiles FROM {coursework} WHERE id = :courseworkid";

        }
        if ($result = $DB->get_record_sql($sql, $params)) {
            if ($dbman->table_exists('coursework_sub_plugin')) {
                 $maxfilesubmissions = $result->value;
            } else {
                $maxfilesubmissions = $result->maxfiles;
            }
        }


        // If resubmissions are enabled in a Turnitin sense.
        if ($reportgenspeed > 0) {

            // If this is a text or file submission, or we can only submit one file.
            if ($submissiontype == 'text_content' || ($submissiontype == 'file' && $maxfilesubmissions == 1)) {
                    // Treat this as a resubmission.
                    return true;
            }
        }
        return false;
    }

}