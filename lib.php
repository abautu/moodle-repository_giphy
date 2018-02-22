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
 * Giphy repository plugin.
 *
 * @package    repository_giphy
 * @copyright  2017 Andrei Bautu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');

/**
 * Giphy repository plugin implementation.
 * @author Andrei Bautu
 * @copyright  2017 Andrei Bautu
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_giphy extends repository {
    /**
     * Retrieves a list of from giphy's API
     * @see https://developers.giphy.com/explorer/
     *
     * @param string $id id of the image to retrieve data for
     * @param string $search text to search for (use when id is null); if null, then trending images will be retrieve
     * @param int $page the page of the listing to retrieve
     * @param int $pagesize the number of items per page to retrieve
     * @return array
     */
    protected function get_files($id = null, $search = null, $page = 0, $pagesize = 0) {
        if (empty($page)) {
            $page = 1;
        }
        if (empty($pagesize)) {
            $pagesize = (int)get_config('giphy', 'page_size');
        }
        $url = 'https://api.giphy.com/v1/gifs/';
        if ($id) {
            $url .= $id . '?';
        } else if ($search) {
            $url .= 'search?q=' . urlencode($search);
        } else {
            $url .= 'trending?';
        }
        $url .= '&offset=' . (($page - 1) * $pagesize);
        $url .= '&limit=' . $pagesize;
        $url .= '&api_key=' . get_config('giphy', 'api_key');
        $url .= '&rating=' . get_config('giphy', 'rating');
        $data = @file_get_contents($url);
        $data = @json_decode($data);
        if ($data->meta->status != 200) {
            return null;
        }
        $data->pagination->pagesize = max($pagesize, $data->pagination->count);
        $data->pagination->path = $id;
        // Make the output uniform so format_files and format_folders have simpler structure.
        if ($id) {
            $data->data = array($data->data);
            $data->pagination->page = 1;
            $data->pagination->pages = 1;
        } else {
            $data->pagination->page = 1 + floor($data->pagination->offset / $data->pagination->pagesize);
            $data->pagination->pages = ceil($data->pagination->total_count / $data->pagination->pagesize);
        }
        return $data;
    }

    /**
     * Format files data as folders for filepicker.
     *
     * @param object $data object received from get_files
     * @return array
     * @see https://docs.moodle.org/dev/Repository_plugins
     */
    protected function format_folders($data) {
        $list = array();
        foreach ($data->data as $item) {
            $list[] = array(
                'title' => ($item->title ? $item->title : $item->slug),
                'shorttitle' => $item->slug,
                'date' => strtotime($item->import_datetime),
                'thumbnail' => $item->images->fixed_height_small->url,
                'icon' => $item->images->fixed_height_small_still->url,
                'children' => array(),
                'path' => $item->id,
                'size' => $item->images->original->size,
            );
        }
        $result = array(
            'nologin' => true,
            'dynload' => true,
            'page' => $data->pagination->page,
            'pages' => $data->pagination->pages,
            'list' => $list,
        );
        return $result;
    }

    /**
     * Format files data as files for filepicker.
     *
     * @param object $data object received from get_files
     * @return array
     * @see https://docs.moodle.org/dev/Repository_plugins
     */
    protected function format_files($data) {
        $mimetypes = $this->options['mimetypes'];
        if (is_string($mimetypes) && $mimetypes == '*') {
            $mimetypes = array('.gif', '.mp4', '.webp');
        }

        $list = array();
        $sources = array('url', 'mp4', 'webp');
        foreach ($data->data as $item) {
            // For each image, we have multiple formats.
            foreach ($item->images as $format => $img) {
                $thumbnail = strpos($format, '_still') ? 'fixed_height_small_still' : 'fixed_height_small';
                $display = str_replace('_', ' ', $format);
                $template = array(
                    'date' => strtotime($item->import_datetime),
                    'thumbnail' => $item->images->{$thumbnail}->url,
                    'thumbnail_width' => $item->images->{$thumbnail}->width,
                    'thumbnail_height' => $item->images->{$thumbnail}->height,
                    'icon' => $item->images->{$thumbnail}->url,
                    'author' => $item->user->display_name,
                    'image_height' => $img->height,
                    'image_width' => $img->width,
                );
                // For a particular format, we can have multiple files.
                foreach ($sources as $urlfield) {
                    if (empty($img->$urlfield)) {
                        continue;
                    }
                    $url = $img->$urlfield;
                    $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
                    if (!in_array('.' . $extension, $mimetypes)) {
                        continue;
                    }

                    $sizefield = $urlfield == 'url' ? 'size' : $urlfield .'_size';
                    $template['source'] = $url;
                    $template['url'] = $url;
                    $template['size'] = $img->$sizefield;
                    $template['title'] = ($item->title ? $item->title : $item->slug) . '.' . $extension;
                    $template['shorttitle'] = strtoupper($extension) . '@' . $img->width .'x'. $img->height . 'px '
                                                . display_size($img->$sizefield) . ' - ' . $display;
                    $list[] = $template;
                }
            }
        }
        $result = array(
            'path' => array(array('name' => 'Giphy', 'path' => ''), array('name' => $item->title, 'path' => $item->id)),
            'nologin' => true,
            'dynload' => false,
            'page' => $data->pagination->page,
            'pages' => $data->pagination->pages,
            'list' => $list,
        );
        return $result;
    }

    /**
     * Get file listing
     *
     * @param string $path path of the listing
     * @param int $page page of listing
     * @return mixed
     */
    public function get_listing($path = '', $page = '') {
        $data = $this->get_files($path, null, (int)$page);
        if ($path) {
            return $this->format_files($data);
        }
        return $this->format_folders($data);
    }

    /**
     * Search files in repository
     * When doing global search, $searchtext will be used as
     * keyword.
     *
     * @param string $searchtext search key word
     * @param int $page page
     * @return mixed see {@link repository::get_listing()}
     */
    public function search($searchtext, $page = '') {
        $data = $this->get_files(null, $searchtext, (int)$page);
        return $this->format_folders($data);
    }


    /**
     * Tells how the file can be picked from this repository
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_EXTERNAL;
    }

    /**
     * What kind of files will be in this repository?
     *
     * @return array return '*' means this repository support any files, otherwise
     *               return mimetypes of files, it can be an array
     */
    public function supported_filetypes() {
        return array('web_image', 'web_video');
    }

    /**
     * Show the search screen, if required
     *
     * @return string
     */
    public function print_search() {
        global $CFG;
        $str = parent::print_search();
        $str .= html_writer::img("{$CFG->wwwroot}/repository/giphy/pix/Poweredby_100px-Black_VertLogo.png");
        return $str;
    }


    /**
     * Return names of the general options.
     * By default: no general option name
     *
     * @return array
     */
    public static function get_type_option_names() {
        return array_merge(parent::get_type_option_names(), array(
            'api_key',
            'rating',
            'page_size',
        ));
    }

    /**
     * Edit/Create Admin Settings Moodle form
     *
     * @param MoodleQuickForm $mform Moodle form (passed by reference)
     * @param string $classname repository class name
     */
    public static function type_config_form($mform, $classname = 'repository') {
        parent::type_config_form($mform, $classname);

        $apikey = get_config('giphy', 'api_key');
        $rating = get_config('giphy', 'rating');
        $pagesize = (int)get_config('giphy', 'page_size');

        $mform->addElement('text', 'api_key', get_string('api_key', 'repository_giphy'), array('size' => '32'));
        $mform->addRule('api_key', get_string('required'), 'required', null, 'client');
        $mform->setDefault('api_key', $apikey);
        $mform->setType('api_key', PARAM_ALPHANUMEXT);

        $ratings = array(
            '' => get_string('any'),
            'Y' => get_string('ratingY', 'repository_giphy'),
            'PG-13' => get_string('ratingPG-13', 'repository_giphy'),
            'PG' => get_string('ratingPG', 'repository_giphy'),
            'R' => get_string('ratingR', 'repository_giphy'),
            'G' => get_string('ratingG', 'repository_giphy'),
        );
        $mform->addElement('select', 'rating', get_string('rating', 'repository_giphy'), $ratings);
        $mform->setDefault('rating', $rating);

        $pagesizes = array(25, 50, 100, 250, 500, 1000);
        $pagesizes = array_combine($pagesizes, $pagesizes);
        $mform->addElement('select', 'page_size', get_string('page_size', 'repository_giphy'), $pagesizes);
        $mform->addRule('page_size', get_string('required'), 'required', null, 'client');
        $mform->setDefault('page_size', $pagesizes);
    }
}
