<?php
/**
 * Tsugi Application Initialization
 *
 * This file sets up the Tsugi environment for the xAPI Learning Records Viewer.
 * It can be configured to use a local Tsugi installation or the hosted Tsugi.org service.
 */

// Try to find Tsugi in common locations
$tsugiPaths = [
    __DIR__ . '/../../tsugi/lib/tsugi.php',           // Sibling folder
    __DIR__ . '/../tsugi/lib/tsugi.php',              // Parent folder
    '/var/www/tsugi/lib/tsugi.php',                   // Standard installation
    getenv('TSUGI_PATH') . '/lib/tsugi.php',          // Environment variable
];

$tsugiFound = false;
foreach ($tsugiPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $tsugiFound = true;
        break;
    }
}

// If Tsugi not found, load our minimal standalone bootstrap
if (!$tsugiFound) {
    require_once __DIR__ . '/lib/tsugi_standalone.php';
}

// Load configuration
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Ensure $CFG is available with required settings
if (!isset($CFG)) {
    $CFG = new stdClass();
}

// LRS Configuration - can be set in config.php or via environment
if (!isset($CFG->lrs_endpoint)) {
    $CFG->lrs_endpoint = getenv('LRS_ENDPOINT') ?: 'http://sql-lrs:8080/xapi';
}
if (!isset($CFG->lrs_api_key)) {
    $CFG->lrs_api_key = getenv('LRS_API_KEY') ?: 'my_api_key';
}
if (!isset($CFG->lrs_api_secret)) {
    $CFG->lrs_api_secret = getenv('LRS_API_SECRET') ?: 'my_api_secret';
}

// Tool-specific settings
if (!isset($CFG->tool_title)) {
    $CFG->tool_title = 'xAPI Learning Records Viewer';
}
if (!isset($CFG->tool_description)) {
    $CFG->tool_description = 'View your xAPI learning records and automatically sync grades to your LMS gradebook.';
}
