<?php
    //This script is used to configure and execute the restore proccess.
require_once('../config.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

$contextid   = required_param('contextid', PARAM_INT);
$stage       = optional_param('stage', restore_ui::STAGE_CONFIRM, PARAM_INT);
list($context, $course, $cm) = get_context_info_array($contextid);

navigation_node::override_active_url(new moodle_url('/backup/restorefile.php', array('contextid'=>$contextid)));
$PAGE->set_url(new moodle_url('/backup/restore.php', array('contextid'=>$contextid)));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
require_login($course, null, $cm);
require_capability('moodle/restore:restorecourse', $context);
 

if ($stage & restore_ui::STAGE_CONFIRM + restore_ui::STAGE_DESTINATION) {
    $restore = restore_ui::engage_independent_stage($stage, $contextid);
} else {
    if ($_POST['searchcourses'] || !$_POST['targetid']){
            // $restore = restore_ui::engage_independent_stage($stage, $contextid);
        $restore = restore_ui::engage_independent_stage(restore_ui::STAGE_DESTINATION, $contextid);
        $restore->process();
    } else {
        $target = required_param('target', PARAM_INT);
        $filepath = $_SESSION['filepath'];
        if ($target == backup::TARGET_NEW_COURSE){
            $transaction = $DB->start_delegated_transaction();
            $targetid = required_param('targetid', PARAM_INT);
            list($fullname, $shortname) = restore_dbops::calculate_course_names($course->id, get_string('restoringcourse', 'backup'), get_string('restoringcourseshortname', 'backup'));
            $courseid = restore_dbops::create_new_course($fullname, $shortname, $targetid);
            $controller = new restore_controller($filepath, $courseid, 
             backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id,
             $target);
            $controller->execute_precheck();
            $controller->execute_plan();
            $transaction->allow_commit();
        } elseif (($target==backup::TARGET_CURRENT_DELETING) || ($target==backup::TARGET_CURRENT_ADDING)) {
            $transaction = $DB->start_delegated_transaction();
            $courseid = $course->id;
            if ($target == backup::TARGET_CURRENT_DELETING) {
                $options = array();
                $options['keep_roles_and_enrolments'] = new restore_course_generic_setting('keep_roles_and_enrolments', base_setting::IS_BOOLEAN, true);
                $options['keep_groups_and_groupings'] = new restore_course_generic_setting('keep_groups_and_groupings', base_setting::IS_BOOLEAN, true);
                restore_dbops::delete_course_content($courseid, $options);
            }
            $controller = new restore_controller($filepath, $courseid, 
             backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id,
             $target);
            $controller->execute_precheck();
            $controller->execute_plan();
            $transaction->allow_commit();
        } else {
            $courseids = required_param('targetid', PARAM_INT);
            $transaction = $DB->start_delegated_transaction();
            foreach ($courseids as $key => $courseid) {
                if ($target == backup::TARGET_EXISTING_DELETING) {
                    $options = array();
                    $options['keep_roles_and_enrolments'] = new restore_course_generic_setting('keep_roles_and_enrolments', base_setting::IS_BOOLEAN, true);
                    $options['keep_groups_and_groupings'] = new restore_course_generic_setting('keep_groups_and_groupings', base_setting::IS_BOOLEAN, true);
                    restore_dbops::delete_course_content($courseid, $options);
                }
                $controller = new restore_controller($filepath, $courseid, 
                 backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id,
                 $target);
                $controller->execute_precheck();
                $controller->execute_plan();
            }
            $transaction->allow_commit();
        }
        backup_helper::delete_backup_dir($filepath);
    }
}

echo $OUTPUT->header();
if ($restore){
    $heading = $course->fullname;
    $PAGE->set_title($heading.': '.$restore->get_stage_name());
    $PAGE->set_heading($heading);
    $outcome = $restore->process();
    $PAGE->navbar->add($restore->get_stage_name());

    $renderer = $PAGE->get_renderer('core','backup');
    if (!$restore->is_independent() && $restore->enforce_changed_dependencies()) {
        echo $renderer->dependency_notification(get_string('dependenciesenforced','backup'));
    }
    echo $renderer->progress_bar($restore->get_progress_bar());
    echo $restore->display($renderer);
    $restore->destroy();
    unset($restore);
} else {
    $renderer = $PAGE->get_renderer('core','backup');
    $html  = '';
    $html .= $renderer->box_start();
    $html .= $renderer->notification('Os cursos foram resturados com êxito, clique em alguns dos links abaixo para ver o respectivo curso que você alterou', 'notifysuccess');
    $courseids = $_POST['targetid'];
    $html .= "<center>";
    if (is_array($courseids)){
        foreach ($courseids as $key => $courseid) {
            $course = $DB->get_record('course', array('id' => $courseid));
            $link = '/moodle/course/view.php?id=' . $course->id;
            $text = $course->fullname;
            $html .= html_writer::link($link, $text);
            $html .= "<br/>";
        }
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
        $link = '/moodle/course/view.php?id=' . $course->id;
        $text = $course->fullname;
        $html .= html_writer::link($link, $text);
        $html .= "<br/>";
    }
    $html .= "</center>";
    $html .= $renderer->box_end();
    echo $html;
}
echo $OUTPUT->footer();
?>


