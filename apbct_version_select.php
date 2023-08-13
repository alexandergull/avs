<?php

/*
 * Plugin Name:       APBCT plugin versions selector
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
define('AVS_TEMP_ARCHIVE_PATH', __DIR__ . '\temp\\');
define('AVS_TEMP_DIRECTORY_PATH', __DIR__ . '\temp\versions\\');
define('AVS_DEBUG_DISPLAY', false);

//avs_main();
add_filter('apbct_stat_report_hook', 'avs_add_selector',  999,  1);

function avs_add_selector($html)
{
    $versions = avs_get_versions();
    $html = 'OK';
    $html .= '<form name="avs_select" id="avs_select">';
    $html .= '<div>';
    $html .= '<p>Select version you want to install:</p>';
    $html .= '<select>';
    $i = 0;
    foreach ($versions as $version) {
        $html .= "<option value='$i'>$version</option>";
    }
    $html .= '</select>';
    $html .= '</div>';
    $html .= '<input type="submit">';
    $html .= '</form>';
    return $html;
}

function avs_main()
{
    try {

        global $avs_state;

        $versions_urls_found = avs_get_versions();

        $downloaded_zip_path = avs_download_plugin($versions_urls_found[0], AVS_TEMP_ARCHIVE_PATH);

        $temp_extracted_path = avs_unpack_zip($downloaded_zip_path, AVS_TEMP_DIRECTORY_PATH);

        $avs_state['downloaded_zip_path'] = $downloaded_zip_path;
        $avs_state['temp_extracted_path'] = $temp_extracted_path;

        avs_clear_temp_data();

    } catch (\Exception $e) {
        echo $e;
    }
}

function avs_get_versions()
{
    avs_log("Api response proceed..");

    $wp_api_response = @file_get_contents("https://api.wordpress.org/plugins/info/1.0/" . AVS_VERSION_SLUG);

    if ( empty($wp_api_response) ) {
        throw new \Exception('Empty API response');
    }

    avs_log("Api response successfully got.");

    avs_log("Seek for versions..\n");

    preg_match_all(AVS_VERSIONS_REGEXP, $wp_api_response, $versions_found);

    if ( empty($versions_found) ) {
        throw new \Exception('No versions found');
    }

    $versions_found = $versions_found[0];

    avs_log("Versions found:");

    avs_log($versions_found);

    return $versions_found;
}

function avs_download_plugin($url, $output_path)
{
    avs_log( "Downloading content of $url ...");

    $version_content = file_get_contents($url);

    if (empty($version_content)) {
        throw new \Exception('Cannot get url content.');
    }

    $downloaded_zip_path = $output_path . AVS_ARCHIVE_NAME;

    avs_log("Writing $downloaded_zip_path ..");

    $result = file_put_contents($downloaded_zip_path, $version_content);

    if (empty($result)) {
        throw new \Exception('Cannot write file.');
    }

    return $downloaded_zip_path;
}

function avs_unpack_zip($downloaded_zip_path, $temp_dir_path)
{

    if (!is_dir($temp_dir_path)) {
        throw new \Exception('Invalid temp dir path');
    }

    avs_log( "Unpacking " . $downloaded_zip_path . " ...");

    $zip = new ZipArchive();
    $zip->open($downloaded_zip_path);
    $plugin_folder_name = $zip->getNameIndex(0);
    $plugin_folder_name = substr($plugin_folder_name, 0, strlen($plugin_folder_name)-1);
    $zip->extractTo($temp_dir_path);
    $zip->close();

    $extracted_plugin_path = $temp_dir_path . $plugin_folder_name;

    if (!is_dir($extracted_plugin_path)) {
        throw new \Exception('Invalid completed temp path. Roll back..');
    }

    avs_log("Unpacking success: $extracted_plugin_path");

    return $extracted_plugin_path;
}

function avs_clear_temp_data()
{
    global $avs_state;
    avs_log("Delete " . AVS_ARCHIVE_NAME . " ...");

    $result = unlink($avs_state['downloaded_zip_path']);

    if (empty($result)) {
        throw new \Exception('Cannot delete file.');
    }

    avs_log("Deleting success.");
}

function avs_log($msg)
{
    error_log('AVS_DEBUG: ' . var_export($msg,true));
    if (AVS_DEBUG_DISPLAY) {
        if (is_string($msg)) {
            echo $msg . "\n";
        } else {
            echo var_export($msg) . "\n";
        }
    }
}
