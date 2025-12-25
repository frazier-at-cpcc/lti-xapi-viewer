<?php
/**
 * LTI 1.3 OIDC Login Initiation Endpoint
 *
 * Handles the first step of LTI 1.3 authentication (OIDC third-party initiated login).
 * The platform sends a login initiation request, and we redirect back with an authentication request.
 */

require_once __DIR__ . '/../app.php';

// Validate required parameters
$requiredParams = ['iss', 'login_hint', 'target_link_uri'];
foreach ($requiredParams as $param) {
    if (empty($_REQUEST[$param])) {
        http_response_code(400);
        die("Missing required parameter: $param");
    }
}

$issuer = $_REQUEST['iss'];
$loginHint = $_REQUEST['login_hint'];
$targetLinkUri = $_REQUEST['target_link_uri'];
$ltiMessageHint = $_REQUEST['lti_message_hint'] ?? '';
$clientId = $_REQUEST['client_id'] ?? $CFG->lti13_client_id ?? '';

// Get the platform's authorization endpoint
// This would typically come from platform registration or dynamic registration
$authEndpoint = $CFG->lti13_auth_url ?? '';

// If we don't have a configured auth endpoint, try to derive it from common patterns
if (empty($authEndpoint)) {
    // Canvas pattern
    if (strpos($issuer, 'instructure.com') !== false) {
        $authEndpoint = $issuer . '/api/lti/authorize_redirect';
    }
    // Moodle pattern
    elseif (strpos($issuer, 'moodle') !== false) {
        $authEndpoint = $issuer . '/mod/lti/auth.php';
    }
    // Brightspace pattern
    elseif (strpos($issuer, 'd2l') !== false || strpos($issuer, 'brightspace') !== false) {
        $authEndpoint = $issuer . '/d2l/lti/authenticate';
    }
    else {
        http_response_code(500);
        die("Authorization endpoint not configured for issuer: $issuer");
    }
}

// Generate state and nonce
$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));

// Store state and nonce in session for validation
session_start();
$_SESSION['lti13_state'] = $state;
$_SESSION['lti13_nonce'] = $nonce;
$_SESSION['lti13_issuer'] = $issuer;
$_SESSION['lti13_client_id'] = $clientId;

// Build authentication request
$authParams = [
    'scope' => 'openid',
    'response_type' => 'id_token',
    'response_mode' => 'form_post',
    'client_id' => $clientId,
    'redirect_uri' => $targetLinkUri,
    'login_hint' => $loginHint,
    'state' => $state,
    'nonce' => $nonce,
    'prompt' => 'none'
];

if (!empty($ltiMessageHint)) {
    $authParams['lti_message_hint'] = $ltiMessageHint;
}

// Redirect to platform's authorization endpoint
$authUrl = $authEndpoint . '?' . http_build_query($authParams);
header('Location: ' . $authUrl);
exit;
