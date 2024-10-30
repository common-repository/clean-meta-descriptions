<?php 
/**
 * Plugin Name: Clean Meta Descriptions
 * Description: This plugin allows you to remove shortcodes that may appear in the meta descriptions of your pages/posts.
 * Version: 1.1.1
 * Requires PHP: 5.6.39
 * Author: Matthew Sudekum
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: clean-meta-descriptions
 */

add_action('admin_enqueue_scripts', 'cmds_enqueue_scripts');
function cmds_enqueue_scripts() {
    wp_enqueue_script('jquery');
}

add_action( 'admin_menu', 'cmds_menu_setup' );
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'cmds_add_tool_link' );

function cmds_menu_setup() {
    add_submenu_page( 'tools.php', 'Clean Meta Desc.', 'Clean Meta Desc.','manage_options', 'clean-meta-descriptions', 'cmds_display' );
}

function cmds_add_tool_link( $settings ) {
   $settings[] = '<a href="'. get_admin_url(null, 'tools.php?page=clean-meta-descriptions') .'"><b>Use Tool</b></a>';
   return array_reverse($settings);
}


function cmds_display() {
    ?>
    <h1>Clean Meta Descriptions Options</h1>
    <br>
    <h2>Stored Shortcodes</h2>
    <?php cmds_present_data(); ?>
    <form method="post">
        <input type="submit" class="button button-primary" name="reset" value="Reset" />
    </form>
    <?php cmds_reset_data(); ?>
    <br>
    <h2>New Shortcodes To Replace</h2>
    <p>List shortcodes you want to remove from your meta descriptions, separated by commas. (Include the square brackets)</p>
    <form method="post">
        <textarea type='text-area' style='margin-bottom:20px; word-wrap:normal; min-height: 200px; max-height: 200px; min-width: 200px; max-width: 200px; resize: none; overflow-wrap: break-word;'
         id='shortcodes' name='shortcodes' placeholder='[example-1],[example-2]'></textarea>
        <br>
        <input type="submit" class="button button-primary" name="add" value="Add"/>
    </form>
    <?php cmds_store_data(); ?>
    <h2>Clean The Meta Descriptions</h2>
    <form method="post">
        <input type="submit" class="button button-primary" name="cleanse" value="Cleanse">
    </form>
    <?php
    if(isset($_POST['cleanse'])) {
        cmds_update_yoast_meta_desc();
    }

}

function cmds_present_data() {
    $json = cmds_get_data();
    $codes = '';
    echo '<div id="reloadable"><ul style="margin-left: 20px; list-style: disc;">';
    foreach($json as $code) {
        $code = cmds_sanitize_code($code);
        echo '<li>'.esc_html($code['code']).'</li>';
    }
    echo '</ul></div>';
}

function cmds_get_data() {
    $jsonString = file_get_contents(plugin_dir_path( __FILE__ ).'codes.json');
    $data = json_decode($jsonString, true);
    return $data;
}

function cmds_store_data() {
    if(isset($_POST['add'])){
        if(!empty($_POST['shortcodes'])){
            $codes = cmds_sanitize_code($_POST['shortcodes']);
            //Find and replace any spaces
            $codes = str_replace(' ', '', $codes);
            //Divide up shortcodes
            $codes = explode(',',$codes);
            //Format for json insertion
            $codes_seperated = [];
            foreach($codes as $code){
                $arr = array('code' => $code);
                array_push($codes_seperated, $arr);
            }
            //Get current json data and append new data
            $json = cmds_get_data();
            foreach($codes_seperated as $code) {
                array_push($json, $code);
            }
            //Encode appended data
            $jsonString = json_encode($json);
            file_put_contents(plugin_dir_path( __FILE__ ).'codes.json', $jsonString);
            echo '<p style="color:green; margin-top: 0px;">Shortcodes were added successfully!</p>';
            echo '<script>jQuery("#reloadable").load(location.href + " #reloadable");</script>';
        }
        else {
            echo '<p style="color:red; margin-top: 0px;">Input shortcode(s) to add to the list.</p>';
        }
    }
}

function cmds_sanitize_code($code){
    return preg_replace('/[^\[\]a-zA-Z0-9_-]/', '', $code);
}

function cmds_reset_data() {
    if(isset($_POST['reset'])) {
        $data = [];
        $jsonString = json_encode($data);
        file_put_contents(plugin_dir_path( __FILE__ ).'codes.json', $jsonString);
        echo '<p style="color:green; margin: 0px auto -13px auto;">Shortcodes were reset successfully!</p>';
        echo '<script>jQuery("#reloadable").load(location.href + " #reloadable");</script>';
    }
}

function cmds_update_yoast_meta_desc() {
    $data = cmds_get_data();
    $shortcodes = []; //Array holding shortcodes to look to replace
    foreach($data as $code) {
        array_push($shortcodes, $code['code']);
    }
    $more = '...'; //String to add to the end of the meta description
    cmds_iterate_update('page', $shortcodes, $more);
    cmds_iterate_update('post', $shortcodes, $more);
    echo '<p style="color: green; margin-top: 0px;">Success! Any changes may only be visible after clearing your cache and refreshing any pages that were open.</p>';
}

function cmds_iterate_update($type, $shortcodes, $more){
    $pages = get_posts(array("post_type"=>$type));
    foreach($pages as $page) {
        if(get_post_meta($page->ID, '_yoast_wpseo_metadesc', true) == ''){ //If meta description is blank, update to contain clean excerpt.
            $content = $page->post_content;
            $content = apply_filters('the_content', $content);
            $meta_desc = substr(wp_trim_words(strip_tags($content), 100, ''), 0, 100) . $more;
            $cleaned_meta_desc = str_replace($shortcodes, '', $meta_desc);
            update_post_meta( $page->ID, '_yoast_wpseo_metadesc', $cleaned_meta_desc );
            wp_update_post($page);
        }else{ //If meta description is not blank
            $meta_desc = get_post_meta($page->ID, '_yoast_wpseo_metadesc', true);
            $cleaned_meta_desc = $meta_desc;
            $needs_updating = false;
            foreach($shortcodes as $code){ //Look for shortcodes and remove them.
                if(str_contains($meta_desc, $code)){
                    $cleaned_meta_desc = str_replace($code, '', $meta_desc);
                    $meta_desc = $cleaned_meta_desc;
                    if($needs_updating == false){
                        $needs_updating = true;
                    }
                }
            }
            if($needs_updating){ //If shortcodes found, update meta description to the cleaned version.
                if($meta_desc == ''){ //If cleaned meta description is blank, update to contain clean excerpt.
                    $content = $page->post_content;
                    $content = apply_filters('the_content', $content);
                    $meta_desc = substr(wp_trim_words(strip_tags($content), 100, ''), 0, 100) . $more;
                    $cleaned_meta_desc = str_replace($shortcodes, '', $meta_desc);
                }
                update_post_meta( $page->ID, '_yoast_wpseo_metadesc', $cleaned_meta_desc );
                wp_update_post($page);
            }
            
        }
    }
}

?>