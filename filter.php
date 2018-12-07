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
 * Automatically links section names in a Moodle course and its activities
 *
 * This filter provides automatic linking to sections when its name (title)
 * is found inside every Moodle text
 *
 * @package    filter_sectionnames
 * @copyright  2017 Matt Davidson
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Section name filtering.
 *
 * @package    filter_sectionnames
 * @copyright  2017 Matt Davidson
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_sectionnames extends moodle_text_filter {
    // Trivial-cache - keyed on $cachedcourseid and $cacheduserid.
    /** @var array section list. */
    public static $sectionslist = null;

    /** @var int course id. */
    public static $cachedcourseid;

    /** @var int userid. */
    public static $cacheduserid;

    /**
     * Given an object containing all the necessary data,
     * (defined by the form in mod_form.php) this function
     * will update an existing instance with new data.
     *
     * @param string $text The text that will be filtered.
     * @param array $options The standard filter options passed.
     * @return string Filtered text.
     */
    public function filter($text, array $options = array()) {
        global $CFG, $USER, $PAGE; // Since 2.7 we can finally start using globals in filters.

        $coursectx = $this->context->get_course_context(false);
        if (!$coursectx) {
            return $text;
        }
        $courseid = $coursectx->instanceid;

        // Initialise/invalidate our trivial cache if dealing with a different course.
        if (!isset(self::$cachedcourseid) || self::$cachedcourseid !== (int)$courseid) {
            self::$sectionslist = null;
        }
        self::$cachedcourseid = (int)$courseid;
        // And the same for user id.
        if (!isset(self::$cacheduserid) || self::$cacheduserid !== (int)$USER->id) {
            self::$sectionslist = null;
        }
        self::$cacheduserid = (int)$USER->id;

        // It may be cached.

        if (is_null(self::$sectionslist)) {
            self::$sectionslist = array();

            $modinfo = get_fast_modinfo($courseid);
            self::$sectionslist = array(); // We will store all the created filters here.

            // Create array of visible sections sorted by the name length (we are only interested in properties name and url).
            $sortedsections = array();

            if (function_exists('course_get_format')) {
                $formatinfo = course_get_format($courseid);
            } else {
                $formatinfo = format_base::instance($courseid);
            }

            $format = $formatinfo->get_format();
            if ($CFG->branch < 33) {
                $numsections = $formatinfo->get_course()->numsections;
            } else {
                $numsections = $formatinfo->get_last_section_number();
            }

            $section = 1; // Skip the general section 0.
            while ($section <= $numsections) {
                if (!empty($modinfo->get_section_info($section)) && $modinfo->get_section_info($section)->visible) {
                    $sortedsections[] = (object)array(
                        'name' => get_section_name($courseid, $section),
                        'url' => course_get_url($courseid, $section),
                        'id' => $section,
                        'namelen' => -strlen(get_section_name($courseid, $section)), // Negative value for reverse sorting.
                    );
                }
                $section++;
            }

            // Sort activities by the length of the section name in reverse order.
            core_collator::asort_objects_by_property($sortedsections, 'namelen', core_collator::SORT_NUMERIC);

            // TOFIX: This is an abort issued if buttons or grid format are used.
            if ($format == "buttons" ||
                ($format == "grid" && strstr($PAGE->bodyid, "page-course-view"))) {
                    return $text;
            }

            foreach ($sortedsections as $section) {
                $title = s(trim(strip_tags($section->name)));
                $currentname = trim($section->name);
                $entname  = s($currentname);
                // Avoid empty or unlinkable activity names.
                if (!empty($title)) {
                    $hrefopen = html_writer::start_tag('a',
                            array('class' => 'autolink', 'title' => $title,
                                'href' => $section->url));
                    self::$sectionslist[$section->id] = new filterobject($currentname, $hrefopen, '</a>', false, true);
                    if ($currentname != $entname) {
                        // If name has some entity (&amp; &quot; &lt; &gt;) add that filter too. MDL-17545.
                        self::$sectionslist[$section->id.'-e'] = new filterobject($entname, $hrefopen, '</a>', false, true);
                    }
                }
            }
        }

        $filterslist = array();
        if (self::$sectionslist) {
            $sectionid = $this->context->instanceid;
            if ($this->context->contextlevel == CONTEXT_MODULE && isset(self::$sectionslist[$sectionid])) {
                // Remove filterobjects for the current module.
                $filterslist = array_values(array_diff_key(self::$sectionslist, array($sectionid => 1, $sectionid.'-e' => 1)));
            } else {
                $filterslist = array_values(self::$sectionslist);
            }
        }

        if ($filterslist) {
            return $text = filter_phrases($text, $filterslist);
        } else {
            return $text;
        }
    }
}
