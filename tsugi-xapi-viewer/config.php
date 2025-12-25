<?php
/**
 * Configuration for xAPI Learning Records Viewer
 *
 * Copy this file to config.php and update with your settings.
 * Environment variables take precedence over values set here.
 */

// Create configuration object if it doesn't exist
if (!isset($CFG)) {
    $CFG = new stdClass();
}

/**
 * LTI 1.1 Credentials
 * These are used for OAuth signature verification
 */
$CFG->lti_consumer_key = getenv('LTI_CONSUMER_KEY') ?: 'xapi_viewer_key';
$CFG->lti_consumer_secret = getenv('LTI_CONSUMER_SECRET') ?: 'xapi_viewer_secret';

/**
 * LTI 1.3 Configuration
 * Required for LTI 1.3 OIDC authentication
 */
$CFG->lti13_issuer = getenv('LTI13_ISSUER') ?: '';
$CFG->lti13_client_id = getenv('LTI13_CLIENT_ID') ?: '';
$CFG->lti13_deployment_id = getenv('LTI13_DEPLOYMENT_ID') ?: '';
$CFG->lti13_keyset_url = getenv('LTI13_KEYSET_URL') ?: '';
$CFG->lti13_auth_url = getenv('LTI13_AUTH_URL') ?: '';
$CFG->lti13_token_url = getenv('LTI13_TOKEN_URL') ?: '';

// Tool's private key for LTI 1.3 (path to PEM file or key string)
$CFG->lti13_private_key = getenv('LTI13_PRIVATE_KEY') ?: '';

/**
 * LRS (Learning Record Store) Configuration
 * Settings for connecting to your xAPI LRS
 */
$CFG->lrs_endpoint = getenv('LRS_ENDPOINT') ?: 'http://sql-lrs:8080/xapi';
$CFG->lrs_api_key = getenv('LRS_API_KEY') ?: 'my_api_key';
$CFG->lrs_api_secret = getenv('LRS_API_SECRET') ?: 'my_api_secret';

/**
 * Tool Information
 * Displayed in the LTI tool registration
 */
$CFG->tool_title = 'xAPI Learning Records Viewer';
$CFG->tool_description = 'View your xAPI learning records and automatically sync grades to your LMS gradebook.';
$CFG->tool_icon = '';  // URL to tool icon (optional)
$CFG->tool_vendor_code = 'xapi-viewer';
$CFG->tool_vendor_name = 'xAPI Viewer';

/**
 * Application Settings
 */
$CFG->timezone = getenv('APP_TIMEZONE') ?: 'America/New_York';
$CFG->debug = (getenv('APP_DEBUG') === 'true') ?: false;

/**
 * Tsugi Integration (if using full Tsugi)
 * Set the path to your Tsugi installation
 */
// $CFG->tsugi_path = '/var/www/tsugi';

/**
 * Database Configuration (for full Tsugi integration)
 * Only needed if you're using Tsugi's database features
 */
// $CFG->pdo = '';
// $CFG->dbhost = 'localhost';
// $CFG->dbname = 'tsugi';
// $CFG->dbuser = 'root';
// $CFG->dbpass = '';

/**
 * Session Settings
 */
$CFG->session_lifetime = 3600;  // 1 hour

// Set timezone
date_default_timezone_set($CFG->timezone);

// Debug mode settings
if ($CFG->debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
