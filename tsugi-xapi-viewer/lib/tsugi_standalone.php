<?php
/**
 * Tsugi Standalone Bootstrap
 *
 * A minimal LTI implementation for when a full Tsugi installation is not available.
 * Supports both LTI 1.1 (OAuth 1.0a) and LTI 1.3 (OAuth 2.0 / JWT).
 */

namespace Tsugi\Core;

class LTIX {
    /**
     * Require LTI data and return a LAUNCH object
     */
    public static function requireData($needed = self::ALL, $pdox = false) {
        global $CFG;

        // Initialize session if not already started
        self::initSession();

        $launch = new \StandaloneLaunch();

        // Check for existing valid session
        if (isset($_SESSION['lti_valid']) && $_SESSION['lti_valid']) {
            $launch->loadFromSession();
            return $launch;
        }

        // Handle POST request (LTI launch)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Detect LTI version
            if (isset($_POST['lti_message_type'])) {
                // LTI 1.1 Launch
                $result = self::handleLTI11Launch($launch);
            } elseif (isset($_POST['id_token'])) {
                // LTI 1.3 Launch (OIDC flow)
                $result = self::handleLTI13Launch($launch);
            } else {
                self::abort('Invalid LTI launch request');
            }

            if (!$result['valid']) {
                self::abort($result['error']);
            }

            // Store in session
            $launch->saveToSession();
            return $launch;
        }

        self::abort('Please launch this tool from your LMS');
    }

    /**
     * Initialize session with proper cookie settings for LTI iframes
     */
    private static function initSession() {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            return;
        }

        // Configure session cookie for iframe/cross-origin LTI usage
        if (\PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'None'
            ]);
        } else {
            session_set_cookie_params(0, '/; SameSite=None; Secure', '', true, true);
        }

        session_start();
    }

    /**
     * Handle LTI 1.1 OAuth launch
     */
    private static function handleLTI11Launch(&$launch) {
        global $CFG;

        $consumerKey = $CFG->lti_consumer_key ?? getenv('LTI_CONSUMER_KEY') ?: 'xapi_viewer_key';
        $consumerSecret = $CFG->lti_consumer_secret ?? getenv('LTI_CONSUMER_SECRET') ?: 'xapi_viewer_secret';

        // Validate message type
        $validMessageTypes = [
            'basic-lti-launch-request',
            'ContentItemSelectionRequest',
            'ContentItemSelection'
        ];
        $messageType = $_POST['lti_message_type'] ?? '';

        if (!in_array($messageType, $validMessageTypes)) {
            return ['valid' => false, 'error' => 'Invalid LTI message type'];
        }

        // Verify OAuth signature
        $verification = self::verifyOAuthSignature($consumerKey, $consumerSecret);
        if (!$verification['valid']) {
            return $verification;
        }

        // Populate launch object
        $launch->user->email = $_POST['lis_person_contact_email_primary'] ?? null;
        $launch->user->displayname = $_POST['lis_person_name_full'] ??
            trim(($_POST['lis_person_name_given'] ?? '') . ' ' . ($_POST['lis_person_name_family'] ?? '')) ?:
            'Student';
        $launch->user->id = $_POST['user_id'] ?? null;

        $launch->context->title = $_POST['context_title'] ?? 'Course';
        $launch->context->id = $_POST['context_id'] ?? null;

        $launch->link->title = $_POST['resource_link_title'] ?? null;
        $launch->link->id = $_POST['resource_link_id'] ?? null;

        // LTI Outcomes (for grade passback)
        if (!empty($_POST['lis_outcome_service_url']) && !empty($_POST['lis_result_sourcedid'])) {
            $launch->result = new \StandaloneResult();
            $launch->result->sourcedid = $_POST['lis_result_sourcedid'];
            $launch->result->serviceUrl = $_POST['lis_outcome_service_url'];
            $launch->result->consumerKey = $consumerKey;
            $launch->result->consumerSecret = $consumerSecret;
        }

        $launch->ltiVersion = '1.1';
        return ['valid' => true];
    }

    /**
     * Handle LTI 1.3 OIDC Launch
     */
    private static function handleLTI13Launch(&$launch) {
        global $CFG;

        // Get the id_token (JWT)
        $idToken = $_POST['id_token'] ?? '';
        if (empty($idToken)) {
            return ['valid' => false, 'error' => 'Missing id_token'];
        }

        // Decode JWT (without verification for basic implementation)
        // In production, you would verify the signature using the platform's public key
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return ['valid' => false, 'error' => 'Invalid JWT format'];
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload) {
            return ['valid' => false, 'error' => 'Could not decode JWT payload'];
        }

        // Verify required claims
        $requiredClaims = [
            'https://purl.imsglobal.org/spec/lti/claim/message_type',
            'https://purl.imsglobal.org/spec/lti/claim/version',
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id',
        ];

        foreach ($requiredClaims as $claim) {
            if (!isset($payload[$claim])) {
                return ['valid' => false, 'error' => "Missing required claim: $claim"];
            }
        }

        // Verify LTI version
        if ($payload['https://purl.imsglobal.org/spec/lti/claim/version'] !== '1.3.0') {
            return ['valid' => false, 'error' => 'Unsupported LTI version'];
        }

        // Populate launch object from LTI 1.3 claims
        $launch->user->email = $payload['email'] ?? null;
        $launch->user->displayname = $payload['name'] ?? $payload['given_name'] ?? 'Student';
        $launch->user->id = $payload['sub'] ?? null;

        $contextClaim = $payload['https://purl.imsglobal.org/spec/lti/claim/context'] ?? [];
        $launch->context->title = $contextClaim['title'] ?? 'Course';
        $launch->context->id = $contextClaim['id'] ?? null;

        $resourceLinkClaim = $payload['https://purl.imsglobal.org/spec/lti/claim/resource_link'] ?? [];
        $launch->link->title = $resourceLinkClaim['title'] ?? null;
        $launch->link->id = $resourceLinkClaim['id'] ?? null;

        // LTI 1.3 Assignment and Grade Services (AGS)
        $agsClaim = $payload['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? null;
        if ($agsClaim) {
            $launch->result = new \StandaloneLTI13Result();
            $launch->result->lineItemUrl = $agsClaim['lineitem'] ?? null;
            $launch->result->lineItemsUrl = $agsClaim['lineitems'] ?? null;
            $launch->result->scopes = $agsClaim['scope'] ?? [];
            $launch->result->accessToken = $_SESSION['lti13_access_token'] ?? null;
            $launch->result->sourcedid = $payload['sub'] ?? null;
        }

        $launch->ltiVersion = '1.3';
        return ['valid' => true];
    }

    /**
     * Verify OAuth 1.0a signature for LTI 1.1
     */
    private static function verifyOAuthSignature($consumerKey, $consumerSecret) {
        // Check for required OAuth parameters
        $requiredParams = ['oauth_consumer_key', 'oauth_signature_method', 'oauth_timestamp', 'oauth_nonce', 'oauth_signature'];
        foreach ($requiredParams as $param) {
            if (!isset($_POST[$param])) {
                return ['valid' => false, 'error' => "Missing OAuth parameter: $param"];
            }
        }

        // Verify consumer key
        if ($_POST['oauth_consumer_key'] !== $consumerKey) {
            return ['valid' => false, 'error' => 'Invalid consumer key'];
        }

        // Verify timestamp (5 minute window)
        $timestamp = (int)$_POST['oauth_timestamp'];
        if (abs(time() - $timestamp) > 300) {
            return ['valid' => false, 'error' => 'OAuth timestamp expired'];
        }

        // Reconstruct URL
        $scheme = 'http';
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $scheme = 'https';
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }

        $host = preg_replace('/:443$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/:80$/', '', $host);
        $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $url = "$scheme://$host$path";

        // Build signature
        $params = $_POST;
        $signature = $params['oauth_signature'];
        unset($params['oauth_signature']);
        ksort($params);

        $paramString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $baseString = 'POST&' . rawurlencode($url) . '&' . rawurlencode($paramString);
        $key = rawurlencode($consumerSecret) . '&';
        $expectedSignature = base64_encode(hash_hmac('sha1', $baseString, $key, true));

        if ($signature === $expectedSignature) {
            return ['valid' => true];
        }

        // Try URL variations for different LMS platforms
        $variations = [
            $url,
            str_replace('https://', 'http://', $url),
            str_replace('http://', 'https://', $url),
            rtrim($url, '/'),
            rtrim($url, '/') . '/',
            str_replace('/index.php', '/', $url),
            str_replace('/index.php', '', $url),
        ];

        foreach ($variations as $testUrl) {
            $testBase = 'POST&' . rawurlencode($testUrl) . '&' . rawurlencode($paramString);
            $testSig = base64_encode(hash_hmac('sha1', $testBase, $key, true));
            if ($signature === $testSig) {
                return ['valid' => true];
            }
        }

        return ['valid' => false, 'error' => 'Invalid OAuth signature'];
    }

    /**
     * Abort with error message
     */
    public static function abort($message) {
        http_response_code(400);
        echo '<div style="padding: 40px; text-align: center; font-family: sans-serif;">';
        echo '<h2>Launch Error</h2>';
        echo '<p>' . htmlspecialchars($message) . '</p>';
        echo '</div>';
        exit;
    }

    const ALL = 0;
    const USER = 1;
    const CONTEXT = 2;
    const LINK = 3;
}

namespace Tsugi\Util;

class LTI {
    // Placeholder for LTI utility functions
}

// Return to global namespace for helper classes and initialization
namespace {

// Global configuration
if (!isset($CFG)) {
    $CFG = new stdClass();
}

$CFG->wwwroot = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . dirname($_SERVER['REQUEST_URI'] ?? '/');
$CFG->staticroot = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0';

/**
 * Standalone Launch Object
 */
class StandaloneLaunch {
    public $user;
    public $context;
    public $link;
    public $result;
    public $ltiVersion = '1.1';

    public function __construct() {
        $this->user = new StdClass();
        $this->user->email = null;
        $this->user->displayname = 'Student';
        $this->user->id = null;

        $this->context = new StdClass();
        $this->context->title = 'Course';
        $this->context->id = null;

        $this->link = new StandaloneLink();
        $this->result = null;
    }

    public function loadFromSession() {
        $this->user->email = $_SESSION['lti_user_email'] ?? null;
        $this->user->displayname = $_SESSION['lti_user_name'] ?? 'Student';
        $this->user->id = $_SESSION['lti_user_id'] ?? null;
        $this->context->title = $_SESSION['lti_context_title'] ?? 'Course';
        $this->context->id = $_SESSION['lti_context_id'] ?? null;
        $this->link->title = $_SESSION['lti_link_title'] ?? null;
        $this->link->id = $_SESSION['lti_link_id'] ?? null;
        $this->ltiVersion = $_SESSION['lti_version'] ?? '1.1';

        if (!empty($_SESSION['lti_outcome_service_url'])) {
            if ($this->ltiVersion === '1.3') {
                $this->result = new StandaloneLTI13Result();
                $this->result->lineItemUrl = $_SESSION['lti13_lineitem_url'] ?? null;
                $this->result->sourcedid = $_SESSION['lti_user_id'] ?? null;
            } else {
                $this->result = new StandaloneResult();
                $this->result->sourcedid = $_SESSION['lti_result_sourcedid'] ?? null;
                $this->result->serviceUrl = $_SESSION['lti_outcome_service_url'] ?? null;
                $this->result->consumerKey = $_SESSION['lti_consumer_key'] ?? null;
                $this->result->consumerSecret = $_SESSION['lti_consumer_secret'] ?? null;
            }
        }
    }

    public function saveToSession() {
        $_SESSION['lti_valid'] = true;
        $_SESSION['lti_user_email'] = $this->user->email;
        $_SESSION['lti_user_name'] = $this->user->displayname;
        $_SESSION['lti_user_id'] = $this->user->id;
        $_SESSION['lti_context_title'] = $this->context->title;
        $_SESSION['lti_context_id'] = $this->context->id;
        $_SESSION['lti_link_title'] = $this->link->title;
        $_SESSION['lti_link_id'] = $this->link->id;
        $_SESSION['lti_version'] = $this->ltiVersion;

        if ($this->result) {
            if ($this->result instanceof StandaloneLTI13Result) {
                $_SESSION['lti13_lineitem_url'] = $this->result->lineItemUrl;
                $_SESSION['lti_outcome_service_url'] = $this->result->lineItemUrl;
            } else {
                $_SESSION['lti_outcome_service_url'] = $this->result->serviceUrl;
                $_SESSION['lti_result_sourcedid'] = $this->result->sourcedid;
                $_SESSION['lti_consumer_key'] = $this->result->consumerKey;
                $_SESSION['lti_consumer_secret'] = $this->result->consumerSecret;
            }
        }
    }
}

/**
 * Standalone Link Object
 */
class StandaloneLink {
    public $title;
    public $id;
    private $settings = [];

    public function settingsGet($key, $default = null) {
        return $this->settings[$key] ?? $_POST["custom_$key"] ?? $default;
    }

    public function settingsSet($key, $value) {
        $this->settings[$key] = $value;
    }
}

/**
 * Standalone Result Object for LTI 1.1
 */
class StandaloneResult {
    public $sourcedid;
    public $serviceUrl;
    public $consumerKey;
    public $consumerSecret;

    /**
     * Send grade to LMS via LTI 1.1 Outcomes Service
     */
    public function gradeSend($grade) {
        if (empty($this->serviceUrl) || empty($this->sourcedid)) {
            return 'Grade passback not configured';
        }

        $score = max(0, min(1, floatval($grade)));
        $messageId = uniqid('msg_', true);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<imsx_POXEnvelopeRequest xmlns="http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0">
    <imsx_POXHeader>
        <imsx_POXRequestHeaderInfo>
            <imsx_version>V1.0</imsx_version>
            <imsx_messageIdentifier>' . $messageId . '</imsx_messageIdentifier>
        </imsx_POXRequestHeaderInfo>
    </imsx_POXHeader>
    <imsx_POXBody>
        <replaceResultRequest>
            <resultRecord>
                <sourcedGUID>
                    <sourcedId>' . htmlspecialchars($this->sourcedid, ENT_XML1) . '</sourcedId>
                </sourcedGUID>
                <result>
                    <resultScore>
                        <language>en</language>
                        <textString>' . number_format($score, 4) . '</textString>
                    </resultScore>
                </result>
            </resultRecord>
        </replaceResultRequest>
    </imsx_POXBody>
</imsx_POXEnvelopeRequest>';

        // OAuth 1.0 parameters
        $oauth = [
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string)time(),
            'oauth_version' => '1.0',
            'oauth_body_hash' => base64_encode(sha1($xml, true))
        ];

        ksort($oauth);
        $paramString = http_build_query($oauth, '', '&', PHP_QUERY_RFC3986);
        $baseString = 'POST&' . rawurlencode($this->serviceUrl) . '&' . rawurlencode($paramString);
        $key = rawurlencode($this->consumerSecret) . '&';
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $baseString, $key, true));

        $authParts = [];
        foreach ($oauth as $k => $v) {
            $authParts[] = $k . '="' . rawurlencode($v) . '"';
        }
        $authHeader = 'OAuth ' . implode(', ', $authParts);

        $ch = curl_init($this->serviceUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'Authorization: ' . $authHeader
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && strpos($response, 'success') !== false) {
            return true;
        }

        return 'Grade passback failed (HTTP ' . $httpCode . ')';
    }
}

/**
 * Standalone Result Object for LTI 1.3 AGS
 */
class StandaloneLTI13Result {
    public $lineItemUrl;
    public $lineItemsUrl;
    public $scopes = [];
    public $accessToken;
    public $sourcedid;

    /**
     * Send grade to LMS via LTI 1.3 AGS
     */
    public function gradeSend($grade) {
        if (empty($this->lineItemUrl) || empty($this->accessToken)) {
            return 'LTI 1.3 grade passback not configured';
        }

        $score = max(0, min(1, floatval($grade)));

        $scoreData = [
            'userId' => $this->sourcedid,
            'scoreGiven' => $score * 100,
            'scoreMaximum' => 100,
            'activityProgress' => 'Completed',
            'gradingProgress' => 'FullyGraded',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
        ];

        $scoreUrl = rtrim($this->lineItemUrl, '/') . '/scores';

        $ch = curl_init($scoreUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($scoreData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/vnd.ims.lis.v1.score+json',
                'Authorization: Bearer ' . $this->accessToken
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        return 'LTI 1.3 grade passback failed (HTTP ' . $httpCode . ')';
    }
}

/**
 * Minimal Output class for Tsugi compatibility
 */
class MinimalOutput {
    public function header() {
        // Output standard headers
    }

    public function bodyStart() {
        // Body start hook
    }

    public function flashMessages() {
        // Display any flash messages
    }

    public function footerStart() {
        // Footer start hook
    }

    public function footerEnd() {
        // Footer end hook
    }
}

// Create global OUTPUT object
$OUTPUT = new MinimalOutput();

} // End global namespace
