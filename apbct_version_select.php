<?php

/*
 * Plugin Name:       CleanTalk plugins custom version installer
 * Plugin URI:        https://cleantalk.org
 * Description:       Handle the basics with this plugin.
 * Version:           1.0
 * Requires at least: 6.3
 * Requires PHP:      5.6
 * Author:            Alexander Gull
 * Author URI:        https://author.example.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://example.com/my-plugin/
 * Text Domain:       gull-avs
 * Domain Path:       /languages
 */


define('AVS_VERSIONS_REGEXP', '/https:\/\/downloads\.wordpress\.org\/plugin\/cleantalk-spam-protect\.6\..*?zip/');
define('AVS_VERSION_SLUG', 'cleantalk-spam-protect');
define('AVS_ARCHIVE_NAME', 'packed.zip');
define('AVS_TEMP_ARCHIVE_PATH', wp_get_upload_dir()['path'] . '/avs');
define('AVS_TEMP_DIRECTORY_PATH', wp_get_upload_dir()['path'] . '/avs/versions');
define('AVS_DEBUG_DISPLAY', false);

require_once ('inc/gvs_helper.php');

if (empty($_POST)) {
    add_action( 'admin_menu', 'gvs_menu_page', 25  );
}

gvs_main();

function gvs_get_form()
{
    $versions = gvs_get_versions();
    $html = file_get_contents(__DIR__ . '/templates/gvs_form.html');
    $options = '';
    foreach ($versions as $version) {
        $options .= "<option value='$version'>$version</option>";
    }
    $html = str_replace('%GVS_OPTIONS_APBCT%', $options, $html);

    echo $html;
}


function gvs_menu_page(){

    add_menu_page(
        'AVS Page', // тайтл страницы
        'APBCT Version', // текст ссылки в меню
        'manage_options', // права пользователя, необходимые для доступа к странице
        'avs_page', // ярлык страницы
        'gvs_get_form', // функция, которая выводит содержимое страницы
        'dashicons-images-alt2', // иконка, в данном случае из Dashicons
        20 // позиция в меню
    );
}

function gvs_main()
{
    try {

        global $gvs_state;

        if (isset($_POST['gvs_select'])){

            // download selected version gvs_select
            $gvs_state['downloaded_zip_file_path'] = gvs_download_plugin($_POST['gvs_select'], AVS_TEMP_ARCHIVE_PATH);

            // unpack zip file
            $gvs_state['new_version_dir'] = gvs_unpack_zip($gvs_state['downloaded_zip_file_path'], AVS_TEMP_DIRECTORY_PATH);

            // prepare dirs

            $gvs_state['temp_active_plugin_dir'] = AVS_TEMP_DIRECTORY_PATH . '/active';
            if (is_dir($gvs_state['temp_active_plugin_dir'])) {
                gvs_delete_folder_recursive($gvs_state['temp_active_plugin_dir']);
            }
            $gvs_state['active_plugin_dir'] = WP_PLUGIN_DIR . '/' . AVS_VERSION_SLUG;
            if (!is_dir($gvs_state['active_plugin_dir'])) {
                throw new \Exception('Invalid active plugin path');
            }

            // prepare filesystem
            if (!gvs_prepare_filesystem()){
                throw new \Exception('Can not init WordPress filesystem.');
            }

            // do backup
            if (!gvs_backup()){
                throw new \Exception('Can not backup active plugin.');
            }

            if (!gvs_replace_active_plugin()){
                throw new \Exception('Can not replace active plugin.');
            }

            //delete temp files
            gvs_clear_temp_data();

        }

    } catch (\Exception $e) {
        gvs_log($e->getMessage());
    }
}

function gvs_get_versions()
{
    gvs_log("Api response proceed..");

    $wp_api_response = @file_get_contents("https://api.wordpress.org/plugins/info/1.0/" . AVS_VERSION_SLUG);

    if ( empty($wp_api_response) ) {
        throw new \Exception('Empty API response');
    }

    gvs_log("Api response successfully got.");

    gvs_log("Seek for versions..\n");

    preg_match_all(AVS_VERSIONS_REGEXP, $wp_api_response, $versions_found);

    if ( empty($versions_found) ) {
        throw new \Exception('No versions found');
    }

    $versions_found = $versions_found[0];

    gvs_log("Versions found:");

    gvs_log($versions_found);

    return $versions_found;
}

function gvs_download_plugin($url, $output_path)
{
    gvs_log( "Downloading content of [$url] to [$output_path] ...");

    if (!is_dir($output_path)) {
        $result = mkdir($output_path);
        if (!$result) {
            throw new \Exception('Can not create temp folder.');
        }
    }

    $version_content = file_get_contents($url);

    if (empty($version_content)) {
        throw new \Exception('Cannot get url content.');
    }

    $downloaded_zip_path = $output_path . '/' . AVS_ARCHIVE_NAME;

    gvs_log("Writing $downloaded_zip_path ..");

    $result = file_put_contents($downloaded_zip_path, $version_content);

    if (empty($result)) {
        throw new \Exception('Cannot write file.');
    }

    return $downloaded_zip_path;
}

function gvs_unpack_zip($downloaded_zip_path, $temp_dir_path)
{
    if (!is_dir($temp_dir_path)) {
        $result = mkdir($temp_dir_path);
        if (!$result) {
            throw new \Exception('Invalid temp dir path');
        }
    }

    gvs_log( "Unpacking " . $downloaded_zip_path . " ...");

    $zip = new ZipArchive();
    $zip->open($downloaded_zip_path);
    $plugin_folder_name = $zip->getNameIndex(0);
    $plugin_folder_name = substr($plugin_folder_name, 0, strlen($plugin_folder_name)-1);
    $zip->extractTo($temp_dir_path);
    $zip->close();

    $extracted_plugin_path = $temp_dir_path . '/' . $plugin_folder_name;

    if (!is_dir($extracted_plugin_path)) {
        throw new \Exception('Invalid completed temp path. Roll back..');
    }

    gvs_log("Unpacking success: $extracted_plugin_path");

    return $extracted_plugin_path;
}

function gvs_clear_temp_data()
{
    global $gvs_state;
    gvs_log("Delete zip " . AVS_ARCHIVE_NAME . " ...");

    $result = unlink($gvs_state['downloaded_zip_file_path']);

    if (empty($result)) {
        throw new \Exception('Cannot delete file.');
    }

    gvs_log("Deleting zip success.");

    gvs_log("Delete temp version folder " . $gvs_state['new_version_dir'] . " ...");

    gvs_delete_folder_recursive($gvs_state['new_version_dir']);

    gvs_log("Deleting temp version folder success.");

}

function gvs_backup(){
    global $gvs_state;
    $result = copy_dir($gvs_state['active_plugin_dir'], $gvs_state['temp_active_plugin_dir']);
    return $result ?: false;
}

function gvs_replace_active_plugin() {
    global $gvs_state;

    // enable maintenance mode
    gvs_maintenance_mode__enable(120);

    // remove active plugin
    gvs_delete_folder_recursive($gvs_state['active_plugin_dir']);

    // replace active plugin
    $result = copy_dir($gvs_state['new_version_dir'], $gvs_state['active_plugin_dir']);

    gvs_maintenance_mode__disable();

    return $result ?: false;
}
