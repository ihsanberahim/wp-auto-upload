<?php

require 'ImageUploader.php';

/**
 * Wordpress Auto Upload Images
 * @link http://wordpress.org/plugins/auto-upload-images/
 * @link https://github.com/airani/wp-auto-upload
 * @author Ali Irani <ali@irani.im>
 */
class WpAutoUpload
{
    const WP_OPTIONS_KEY = 'aui-setting';

    private static $_options;

    /**
     * WP_Auto_Upload constructor.
     * Set default variables and options
     * Add wordpress actions
     */
    public function __construct()
    {
        add_action('plugins_loaded', array($this, 'initTextdomain'));
        add_action('save_post', array($this, 'afterSavePost'));
        add_action('admin_menu', array($this, 'addAdminMenu'));
    }

    /**
     * Initial plugin textdomain for translation files
     */
    public function initTextdomain()
    {
        load_plugin_textdomain('auto-upload-images', false, basename(WPAUI_DIR) . '/src/lang');
    }

    /**
     * Returns options in an array
     * @return array
     */
    public static function getOptions()
    {
        if (static::$_options) {
            return static::$_options;
        }
        $defaults = array(
            'base_url' => get_bloginfo('url'),
            'image_name' => '%filename%',
            'alt_name' => '%image_alt%',
        );
        return static::$_options = wp_parse_args(get_option(self::WP_OPTIONS_KEY), $defaults);
    }

    /**
     * Return an option with specific key
     * @param $key
     * @return null
     */
    public static function getOption($key)
    {
        $options = static::getOptions();
        if (isset($options[$key]) === false) {
            return null;
        }
        return $options[$key];
    }

    /**
     * Automatically upload external images of a post to Wordpress upload directory
     * @param int $post_id
     * @return bool
     */
    public function afterSavePost($post_id)
    {
        if (wp_is_post_revision($post_id) ||
            (defined('DOING_AJAX') && DOING_AJAX) ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return false;
        }

        $post = get_post($post_id);

        return $this->save($post) || $this->savePostOptions($post);
    }

    /**
     * Upload images and save new urls
     * @return bool
     */
    public function save($post)
    {
        $excludePostTypes = self::getOption('exclude_post_types');
        if (is_array($excludePostTypes) && in_array($post->post_type, $excludePostTypes, true)) {
            return false;
        }

        global $wpdb;
        $content = $post->post_content;
        $images = $this->findAllImageUrls($content);

        if ($images === null || empty($images)) {
            return false;
        }

        foreach ($images as $image) {
            $uploader = new ImageUploader($image['url'], $image['alt'], $post);
            if ($uploader->validate() && $uploader->save()) {
                $urlParts = parse_url($uploader->url);
                $base_url = $uploader::getHostUrl(null, true);
                $image_url = $base_url . $urlParts['path'];
                $content = preg_replace('/'. preg_quote($image['url'], '/') .'/', $image_url, $content);
                $content = preg_replace('/alt=["\']'. preg_quote($image['alt'], '/') .'["\']/', "alt='{$uploader->getAlt()}'", $content);
            }
        }

        return (bool) $wpdb->update($wpdb->posts, array('post_content' => $content), array('ID' => $post->ID));
    }
    /**
     * Upload images and save new urls
     * @return bool
     */
    public function savePostOptions($post)
    {
        $excludePostTypes = self::getOption('exclude_post_types');
        if (is_array($excludePostTypes) && in_array($post->post_type, $excludePostTypes, true)) {
            return false;
        }

        global $wpdb;

        $sql_select_post_options = "SELECT * FROM {$wpdb->postmeta} WHERE post_id = {$post->ID}";
        $results = $wpdb->get_results( $sql_select_post_options );

        $is_done_something = false;

        foreach($results as $key => $result)
        {
            $content = $result->meta_value;
            $images = $this->findAllImageUrls($content);

            if ($images === null || empty($images)) 
            {
                
            }else
            {
                foreach ($images as $image) {
                    $uploader = new ImageUploader($image['url'], $image['alt'], $post);
                    if ($uploader->validate() && $uploader->save()) {
                        $urlParts = parse_url($uploader->url);
                        $base_url = $uploader::getHostUrl(null, true);
                        $image_url = $base_url . $urlParts['path'];
                        $image_oldUrl = $image['url'];

                        $prepare_sql = $wpdb->prepare(
                            "
                            UPDATE {$wpdb->postmeta} 
                            SET meta_value = replace(meta_value, %s, %s)
                            WHERE meta_key = %s AND post_id = %d
                            ", 
                            $image_oldUrl, $image_url, $result->meta_key, $post->ID
                        );

                        if($wpdb->query($prepare_sql)) 
                        {
                            $is_done_something = true;
                        }
                    }
                }
            }
        }

        return $is_done_something;
    }

    /**
     * Find image urls in content and retrieve urls by array
     * @param $content
     * @return array|null
     */
    public function findAllImageUrls($content)
    {   
        $unique_array = [];
        $images = [];

        //Scan image
        
        $pattern = '/<img[^>]*src=["\']([^"\']*)[^"\']*["\'][^>]*>/i'; // find img tags and retrieve src
        preg_match_all($pattern, $content, $urls, PREG_SET_ORDER);

        if (!empty($urls)) {
            foreach ($urls as $index => &$url) {
                $images[$index]['alt'] = preg_match('/<img[^>]*alt=["\']([^"\']*)[^"\']*["\'][^>]*>/i', $url[0], $alt) ? $alt[1] : null;
                $images[$index]['url'] = $url = $url[1];
            }
            foreach (array_unique($urls) as $index => $url) {
                $unique_array[] = $images[$index];
            }
        }

        //Grab Background
        preg_match_all('/\\bbackground-image?\\s*:(.*?)\\(\\s*(\\\'|")?(?<image>.*?)\\3?\\s*\\)/uism', $content, $urls, PREG_SET_ORDER);

        foreach($urls as $url) 
        {
            if(isset($url['image']))
            {
                $unique_array[] = [
                    'alt' => '',
                    'url' => $url['image']
                ];
            }
        }

        return $unique_array;
    }

    /**
     * Add settings page under options menu
     */
    public function addAdminMenu()
    {
        add_options_page(
            __('Auto Upload Images Settings', 'auto-upload-images'),
            __('Auto Upload Images', 'auto-upload-images'),
            'manage_options',
            'auto-upload',
            array($this, 'settingPage')
        );
    }

    /**
     * Settings page contents
     */
    public function settingPage()
    {
        if (isset($_POST['submit'])) {
            $fields = array('base_url', 'image_name', 'alt_name', 'exclude_urls', 'max_width', 'max_height', 'exclude_post_types');
            foreach ($fields as $field) {
                if (array_key_exists($field, $_POST) && $_POST[$field]) {
                    static::$_options[$field] = $_POST[$field];
                }
            }
            update_option(self::WP_OPTIONS_KEY, static::$_options);
            $message = true;
        }

        if (function_exists('curl_init') === false) {
            $curl_error = true;
        }

        include_once('setting-page.php');
    }
}
