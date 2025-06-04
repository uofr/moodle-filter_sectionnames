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

/**
 * Section name filtering.
 *
 * @package    filter_sectionnames
 * @copyright  2017 Matt Davidson
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_sectionnames;
class text_filter extends \core_filters\text_filter {
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
    public function filter($text, array $options = []) {
        global $CFG, $USER, $PAGE; // Since 2.7 we can finally start using globals in filters.
        require_once($CFG->dirroot . '/course/format/lib.php'); // Needed to ensure course_get_format().

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
            self::$sectionslist = [];
            $modinfo = get_fast_modinfo($courseid);
            self::$sectionslist = []; // We will store all the created filters here.

            // Create array of visible sections sorted by the name length (we are only interested in properties name and url).
            $sortedsections = [];

            if (function_exists('course_get_format')) {
                $formatinfo = course_get_format($courseid);
            } else {
                $formatinfo = \core_courseformat\base::instance($courseid);
            }

            $format = $formatinfo->get_format();
            if ($CFG->branch < 33) {
                $numsections = $formatinfo->get_course()->numsections;
            } else {
                $numsections = $formatinfo->get_last_section_number();
            }

            $section = 1; // Skip the general section 0.
            while ($section <= $numsections) {
                $info = $modinfo->get_section_info($section);
                if (!empty($info) && $info->visible) {
                    $sortedsections[] = (object)[
                        'name' => get_section_name($courseid, $section),
                        'url' => new \moodle_url('/course/section.php', ['id' => $info->id]),
                        'id' => $info->id,
                        'namelen' => -strlen(get_section_name($courseid, $section)),
                    ];
                }
                $section++;
            }
            // Sort activities by the length of the section name in reverse order.
            \core_collator::asort_objects_by_property($sortedsections, 'namelen', \core_collator::SORT_NUMERIC);

            foreach ($sortedsections as $section) {
                $title = s(trim(strip_tags($section->name)));
                $currentname = trim($section->name);
                $entname  = s($currentname);

                // Avoid empty or unlinkable section title.
                if (!empty($title)) {
                    // Add Grid format compatibility.
                    if ($format == "grid" && $formatinfo->get_format_options()['popup'] && !$PAGE->user_is_editing()) {
                        $hreftagbegin = \html_writer::start_tag('a',
                            ['class' => 'autolink',
                             'title' => $title,
                             'data-toggle' => 'modal',
                             'data-target' => '#gridPopup',
                             'data-section' => $section->id,
                             'onclick' => 'if(jQuery(\'#gridPopup.show\').length) {
                                                let a = this;
                                                setTimeout(function() { jQuery(a).trigger(\'click\'); }, 500);
                                            }',
                             'href' => $section->url,
                        ]);
                    } else if ($format == "buttons" && strstr($PAGE->bodyid, "page-course-view")) {
                        $hreftagbegin = \html_writer::start_tag('a',
                            ['class' => 'autolink',
                            'title' => $title,
                            'onclick' => 'M.format_buttons.show(' . $section->id . ',' . $courseid . ')',
                            'href' => $section->url,
                        ]);
                    } else {
                        $hreftagbegin = \html_writer::start_tag('a',
                            ['class' => 'autolink',
                            'title' => $title,
                            'href' => $section->url,
                        ]);
                    }

                    self::$sectionslist[$section->id] = new \filterobject($currentname, $hreftagbegin, '</a>', false, true);
                    if ($currentname != $entname) {
                        // If name has some entity (&amp; &quot; &lt; &gt;) add that filter too. MDL-17545.
                        self::$sectionslist[$section->id.'-e'] = new \filterobject($entname, $hreftagbegin, '</a>', false, true);
                    }
                }
            }
        }

        $filterslist = [];
        if (self::$sectionslist) {
            $sectionid = $this->context->instanceid;
            if ($this->context->contextlevel == CONTEXT_MODULE && isset(self::$sectionslist[$sectionid])) {
                // Remove filterobjects for the current module.
                $filterslist = array_values(array_diff_key(self::$sectionslist, [$sectionid => 1, $sectionid.'-e' => 1]));
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
