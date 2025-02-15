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
 * Base class for a single booking option availability condition.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability\conditions;

use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * This class takes the configuration from json in the available column of booking_options table.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userprofilefield_2_custom implements bo_condition {

    /** @var int $id Id is set via json during construction */
    public $id = null;

    /** @var stdClass $customsettings an stdclass coming from the json which passes custom settings */
    public $customsettings = null;

    /**
     * Constructor.
     *
     * @param integer $id
     * @return void
     */
    public function __construct(int $id = null) {

        if ($id) {
            $this->id = $id;
        }
    }

    /**
     * Needed to see if class can take JSON.
     * @return bool
     */
    public function is_json_compatible(): bool {
        return true; // Customizable condition.
    }

    /**
     * Needed to see if it shows up in mform.
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return true;
    }

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, $userid, $not = false):bool {

        // This is the return value. Not available to begin with.
        $isavailable = false;

        if (!isset($this->customsettings->profilefield)) {
            $isavailable = true;
        } else {

            if (isloggedin()) {
                // Profilefield is set.
                $user = singleton_service::get_instance_of_user($userid);
                $profilefield = $this->customsettings->profilefield;

                // If the profilefield is not here right away, we might need to retrieve it.
                if (!isset($user->$profilefield)) {
                    profile_load_custom_fields($user);
                    $value = $user->profile[$profilefield] ?? null;
                } else {
                    $value = $user->$profilefield;
                }

                // If value is not null, we compare it.
                if ($value) {
                    switch ($this->customsettings->operator) {
                        case '=':
                            if ($value == $this->customsettings->value) {
                                $isavailable = true;
                            }
                            break;
                        case '<':
                            if ($value < $this->customsettings->value) {
                                $isavailable = true;
                            }
                            break;
                        case '>':
                            if ($value > $this->customsettings->value) {
                                $isavailable = true;
                            }
                            break;
                        case '~':
                            if (strpos($this->customsettings->value, $value)) {
                                $isavailable = true;
                            }
                            break;
                        case '!=':
                            if ($value != $this->customsettings->value) {
                                $isavailable = true;
                            }
                            break;
                        case '!~':
                            if (!strpos($this->customsettings->value, $value)) {
                                $isavailable = true;
                            }
                            break;
                        case '[]':
                            $array = explode(",", $this->customsettings->value);
                            if (in_array($value, $array)) {
                                $isavailable = true;
                            }
                            break;
                        case '[!]':
                            $array = explode(",", $this->customsettings->value);
                            if (!in_array($value, $array)) {
                                $isavailable = true;
                            }
                            break;
                        case '()':
                            if (empty($value)) {
                                $isavailable = true;
                            }
                            break;
                        case '(!)':
                            if (!empty($value)) {
                                $isavailable = true;
                            }
                            break;
                    }
                }
            }
        }

        // If it's inversed, we inverse.
        if ($not) {
            $isavailable = !$isavailable;
        }

        return $isavailable;
    }

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * The $full parameter can be used to distinguish between 'staff' cases
     * (when displaying all information about the activity) and 'student' cases
     * (when displaying only conditions they don't meet).
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false):array {

        $description = '';

        $isavailable = $this->is_available($settings, $userid, $not);

        if ($isavailable) {
            $description = $full ? get_string('bo_cond_customuserprofilefield_full_available', 'mod_booking') :
                get_string('bo_cond_customuserprofilefield_available', 'mod_booking');
        } else {
            $description = $full ? get_string('bo_cond_customuserprofilefield_full_not_available',
                'mod_booking',
                $this->customsettings) :
                get_string('bo_cond_customuserprofilefield_not_available', 'mod_booking');
        }

        return [$isavailable, $description];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        global $DB;

        // Check if PRO version is activated.
        if (wb_payment::pro_version_is_activated()) {

            $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
            if (!empty($customuserprofilefields)) {
                $customuserprofilefieldsarray = [];
                $customuserprofilefieldsarray[0] = get_string('userinfofieldoff', 'mod_booking');

                // Create an array of key => value pairs for the dropdown.
                foreach ($customuserprofilefields as $customuserprofilefield) {
                    $customuserprofilefieldsarray[$customuserprofilefield->shortname] = $customuserprofilefield->name;
                }

                $mform->addElement('checkbox', 'restrictwithcustomuserprofilefield',
                    get_string('restrictwithcustomuserprofilefield', 'mod_booking'));

                $mform->addElement('select', 'bo_cond_customuserprofilefield_field',
                    get_string('bo_cond_customuserprofilefield_field', 'mod_booking'), $customuserprofilefieldsarray);
                $mform->hideIf('bo_cond_customuserprofilefield_field', 'restrictwithcustomuserprofilefield', 'notchecked');

                $operators = [
                    '=' => get_string('equals', 'mod_booking'),
                    '!=' => get_string('equalsnot', 'mod_booking'),
                    '<' => get_string('lowerthan', 'mod_booking'),
                    '>' => get_string('biggerthan', 'mod_booking'),
                    '~' => get_string('contains', 'mod_booking'),
                    '!~' => get_string('containsnot', 'mod_booking'),
                    '[]' => get_string('inarray', 'mod_booking'),
                    '[!]' => get_string('notinarray', 'mod_booking'),
                    '()' => get_string('isempty', 'mod_booking'),
                    '(!)' => get_string('isnotempty', 'mod_booking')
                ];
                $mform->addElement('select', 'bo_cond_customuserprofilefield_operator',
                    get_string('bo_cond_customuserprofilefield_operator', 'mod_booking'), $operators);
                $mform->hideIf('bo_cond_customuserprofilefield_operator', 'bo_cond_customuserprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_operator', 'restrictwithcustomuserprofilefield', 'notchecked');

                $mform->addElement('text', 'bo_cond_customuserprofilefield_value',
                    get_string('bo_cond_customuserprofilefield_value', 'mod_booking'));
                $mform->setType('bo_cond_customuserprofilefield_value', PARAM_RAW);
                $mform->hideIf('bo_cond_customuserprofilefield_value', 'bo_cond_customuserprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_value', 'restrictwithcustomuserprofilefield', 'notchecked');

                $mform->addElement('checkbox', 'bo_cond_customuserprofilefield_overrideconditioncheckbox',
                    get_string('overrideconditioncheckbox', 'mod_booking'));
                $mform->hideIf('bo_cond_customuserprofilefield_overrideconditioncheckbox', 'bo_cond_customuserprofilefield_field',
                    'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_overrideconditioncheckbox', 'restrictwithcustomuserprofilefield',
                    'notchecked');

                $overrideoperators = [
                    'AND' => get_string('overrideoperator:and', 'mod_booking'),
                    'OR' => get_string('overrideoperator:or', 'mod_booking')
                ];
                $mform->addElement('select', 'bo_cond_customuserprofilefield_overrideoperator',
                    get_string('overrideoperator', 'mod_booking'), $overrideoperators);
                $mform->hideIf('bo_cond_customuserprofilefield_overrideoperator',
                    'bo_cond_customuserprofilefield_overrideconditioncheckbox', 'notchecked');

                $overrideconditions = bo_info::get_conditions(CONDPARAM_HARDCODED_ONLY);
                $overrideconditionsarray = [];
                foreach ($overrideconditions as $overridecondition) {
                    // Remove the namespace from classname.
                    $fullclassname = get_class($overridecondition); // With namespace.
                    $classnameparts = explode('\\', $fullclassname);
                    $shortclassname = end($classnameparts); // Without namespace.
                    $overrideconditionsarray[$overridecondition->id] =
                        get_string('bo_cond_' . $shortclassname, 'mod_booking');
                }

                // Check for json conditions that might have been saved before.
                if (!empty($optionid) && $optionid > 0) {
                    $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                    if (!empty($settings->availability)) {

                        $jsonconditions = json_decode($settings->availability);

                        if (!empty($jsonconditions)) {
                            foreach ($jsonconditions as $jsoncondition) {
                                // Currently conditions of the same type cannot be combined with each other.
                                if ($jsoncondition->id != BO_COND_JSON_CUSTOMUSERPROFILEFIELD) {
                                    $overrideconditionsarray[$jsoncondition->id] = get_string('bo_cond_' .
                                        $jsoncondition->name, 'mod_booking');
                                }
                            }
                        }
                    }
                }

                $mform->addElement('select', 'bo_cond_customuserprofilefield_overridecondition',
                    get_string('overridecondition', 'mod_booking'), $overrideconditionsarray);
                $mform->hideIf('bo_cond_customuserprofilefield_overridecondition',
                    'bo_cond_customuserprofilefield_overrideconditioncheckbox', 'notchecked');
            }
        } else {
            // No PRO license is active.
            $mform->addElement('static', 'restrictwithcustomuserprofilefield',
                get_string('restrictwithcustomuserprofilefield', 'mod_booking'),
                get_string('proversiononly', 'mod_booking'));
        }
    }

    /**
     * Returns a condition object which is needed to create the condition JSON.
     *
     * @param stdClass $fromform
     * @return stdClass|null the object for the JSON
     */
    public function get_condition_object_for_json(stdClass $fromform): stdClass {

        $conditionobject = new stdClass;

        if (!empty($fromform->restrictwithcustomuserprofilefield)) {
            // Remove the namespace from classname.
            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts); // Without namespace.

            $conditionobject->id = BO_COND_JSON_CUSTOMUSERPROFILEFIELD;
            $conditionobject->name = $shortclassname;
            $conditionobject->class = $classname;
            $conditionobject->profilefield = $fromform->bo_cond_customuserprofilefield_field;
            $conditionobject->operator = $fromform->bo_cond_customuserprofilefield_operator;
            $conditionobject->value = $fromform->bo_cond_customuserprofilefield_value;

            if (!empty($fromform->bo_cond_customuserprofilefield_overrideconditioncheckbox)) {
                $conditionobject->overrides = $fromform->bo_cond_customuserprofilefield_overridecondition;
                $conditionobject->overrideoperator = $fromform->bo_cond_customuserprofilefield_overrideoperator;
            }
        }
        // Might be an empty object.
        return $conditionobject;
    }

    /**
     * Set default values to be shown in form when loaded from DB.
     * @param stdClass &$defaultvalues the default values
     * @param stdClass $acdefault the condition object from JSON
     */
    public function set_defaults(stdClass &$defaultvalues, stdClass $acdefault) {
        if (!empty($acdefault->profilefield)) {
            $defaultvalues->restrictwithcustomuserprofilefield = "1";
            $defaultvalues->bo_cond_customuserprofilefield_field = $acdefault->profilefield;
            $defaultvalues->bo_cond_customuserprofilefield_operator = $acdefault->operator;
            $defaultvalues->bo_cond_customuserprofilefield_value = $acdefault->value;
        }
        if (!empty($acdefault->overrides)) {
            $defaultvalues->bo_cond_customuserprofilefield_overrideconditioncheckbox = "1";
            $defaultvalues->bo_cond_customuserprofilefield_overridecondition = $acdefault->overrides;
            $defaultvalues->bo_cond_customuserprofilefield_overrideoperator = $acdefault->overrideoperator;
        }
    }
}
