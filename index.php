<?php
/**
 * LTI 1.1 xAPI Learning Records Viewer
 *
 * A simple LTI 1.1 tool that allows students to view their xAPI learning records.
 * Students are identified by their email from the LTI launch (lis_person_contact_email_primary)
 * and records are fetched from SQL LRS filtered by actor mbox (mailto:email).
 */

session_start();

// Configuration - load from environment or use defaults
$config = [
    'lti_consumer_key' => getenv('LTI_CONSUMER_KEY') ?: 'xapi_viewer_key',
    'lti_consumer_secret' => getenv('LTI_CONSUMER_SECRET') ?: 'xapi_viewer_secret',
    'lrs_endpoint' => getenv('LRS_ENDPOINT') ?: 'http://sql-lrs:8080/xapi',
    'lrs_api_key' => getenv('LRS_API_KEY') ?: 'my_api_key',
    'lrs_api_secret' => getenv('LRS_API_SECRET') ?: 'my_api_secret',
];

/**
 * Verify LTI 1.1 OAuth signature
 */
function verifyLTISignature($consumerKey, $consumerSecret) {
    // Check for required OAuth parameters
    $requiredParams = ['oauth_consumer_key', 'oauth_signature_method', 'oauth_timestamp', 'oauth_nonce', 'oauth_signature'];
    foreach ($requiredParams as $param) {
        if (!isset($_POST[$param])) {
            return ['valid' => false, 'error' => "Missing required OAuth parameter: $param"];
        }
    }

    // Verify consumer key
    if ($_POST['oauth_consumer_key'] !== $consumerKey) {
        return ['valid' => false, 'error' => 'Invalid consumer key'];
    }

    // Verify timestamp (allow 5 minute window)
    $timestamp = (int)$_POST['oauth_timestamp'];
    if (abs(time() - $timestamp) > 300) {
        return ['valid' => false, 'error' => 'OAuth timestamp expired'];
    }

    // Build base string for signature verification
    $method = 'POST';

    // Reconstruct the URL (handle reverse proxies and different LMS configurations)
    $scheme = 'http';
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        $scheme = 'https';
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_HOST'];
    // Remove port from host if it's the default for the scheme
    $host = preg_replace('/:443$/', '', $host);
    $host = preg_replace('/:80$/', '', $host);

    $path = strtok($_SERVER['REQUEST_URI'], '?'); // Remove query string if present
    $url = "$scheme://$host$path";

    // Get all POST parameters except oauth_signature
    $params = $_POST;
    $signature = $params['oauth_signature'];
    unset($params['oauth_signature']);

    // Sort parameters
    ksort($params);

    // Build parameter string
    $paramString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    // Build base string
    $baseString = implode('&', [
        rawurlencode($method),
        rawurlencode($url),
        rawurlencode($paramString)
    ]);

    // Generate signature
    $key = rawurlencode($consumerSecret) . '&';
    $expectedSignature = base64_encode(hash_hmac('sha1', $baseString, $key, true));

    // Compare signatures
    if ($signature !== $expectedSignature) {
        // Try URL variations for different LMS platforms (Brightspace, Moodle, Canvas)
        // Different LMS may construct URLs differently (scheme, port, trailing slash)
        $urlVariations = [
            $url,
            str_replace('https://', 'http://', $url),
            str_replace('http://', 'https://', $url),
            rtrim($url, '/'),
            rtrim($url, '/') . '/',
            preg_replace('/:8888/', '', $url),
            preg_replace('/:8080/', '', $url),
            preg_replace('/:443/', '', $url),
            preg_replace('/:80/', '', $url),
        ];

        // Also try with/without index.php
        $additionalVariations = [];
        foreach ($urlVariations as $v) {
            $additionalVariations[] = str_replace('/index.php', '/', $v);
            $additionalVariations[] = str_replace('/index.php', '', $v);
            if (strpos($v, 'index.php') === false) {
                $additionalVariations[] = rtrim($v, '/') . '/index.php';
            }
        }
        $urlVariations = array_unique(array_merge($urlVariations, $additionalVariations));

        $valid = false;
        foreach ($urlVariations as $testUrl) {
            $testBaseString = implode('&', [
                rawurlencode($method),
                rawurlencode($testUrl),
                rawurlencode($paramString)
            ]);
            $testSignature = base64_encode(hash_hmac('sha1', $testBaseString, $key, true));
            if ($signature === $testSignature) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            return ['valid' => false, 'error' => 'Invalid OAuth signature'];
        }
    }

    return ['valid' => true];
}

/**
 * Query xAPI statements from the LRS for a specific actor email
 */
function getXapiStatements($endpoint, $key, $secret, $email, $limit = 100) {
    $agent = json_encode([
        "mbox" => "mailto:" . $email
    ]);

    $url = $endpoint . "/statements?" . http_build_query([
        'agent' => $agent,
        'limit' => $limit
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($key . ':' . $secret),
        'X-Experience-API-Version: 1.0.3',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => "HTTP $httpCode: $error", 'statements' => []];
    }

    $data = json_decode($response, true);
    return ['error' => null, 'statements' => $data['statements'] ?? []];
}

/**
 * Format a timestamp for display
 */
function formatTimestamp($timestamp) {
    $dt = new DateTime($timestamp);
    $dt->setTimezone(new DateTimeZone('America/New_York'));
    return $dt->format('M j, Y g:i A');
}

/**
 * Extract a human-readable verb name
 */
function getVerbName($verb) {
    if (isset($verb['display']['en-US'])) {
        return $verb['display']['en-US'];
    }
    if (isset($verb['display']['en'])) {
        return $verb['display']['en'];
    }
    $parts = explode('/', $verb['id']);
    return ucfirst(end($parts));
}

/**
 * Extract a human-readable object name
 */
function getObjectName($object) {
    if (isset($object['definition']['name']['en-US'])) {
        return $object['definition']['name']['en-US'];
    }
    if (isset($object['definition']['name']['en'])) {
        return $object['definition']['name']['en'];
    }
    return $object['id'] ?? 'Unknown';
}

// Handle LTI launch
$error = null;
$userEmail = null;
$userName = null;
$statements = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify LTI message type - accept multiple valid types
    $validMessageTypes = [
        'basic-lti-launch-request',
        'ContentItemSelectionRequest',
        'ContentItemSelection'
    ];
    $messageType = $_POST['lti_message_type'] ?? '';

    if (!in_array($messageType, $validMessageTypes)) {
        $error = 'Invalid LTI message type: ' . htmlspecialchars($messageType);
    } else {
        // Verify OAuth signature
        $verification = verifyLTISignature($config['lti_consumer_key'], $config['lti_consumer_secret']);

        if (!$verification['valid']) {
            $error = 'LTI Authentication Failed: ' . $verification['error'];
        } else {
            // Extract user information
            $userEmail = $_POST['lis_person_contact_email_primary'] ?? null;
            $userName = $_POST['lis_person_name_full'] ??
                       (($_POST['lis_person_name_given'] ?? '') . ' ' . ($_POST['lis_person_name_family'] ?? '')) ?:
                       'Student';

            // Store in session for refresh
            $_SESSION['lti_user_email'] = $userEmail;
            $_SESSION['lti_user_name'] = trim($userName);
            $_SESSION['lti_context_title'] = $_POST['context_title'] ?? 'Course';
            $_SESSION['lti_valid'] = true;

            // Store LTI Outcomes parameters for grade passback
            $_SESSION['lis_outcome_service_url'] = $_POST['lis_outcome_service_url'] ?? null;
            $_SESSION['lis_result_sourcedid'] = $_POST['lis_result_sourcedid'] ?? null;
            $_SESSION['resource_link_title'] = $_POST['resource_link_title'] ?? null;
            $_SESSION['resource_link_id'] = $_POST['resource_link_id'] ?? null;

            // Store custom parameters for lab matching (works across all LMS platforms)
            // custom_lab_id can be set in any LMS as a custom parameter
            $_SESSION['custom_lab_id'] = $_POST['custom_lab_id'] ?? null;

            // Try multiple sources for assignment/activity title (cross-platform)
            // Standard LTI: resource_link_title
            // Canvas: custom_canvas_assignment_title
            // Brightspace: custom parameters or resource_link_title
            // Moodle: resource_link_title
            $_SESSION['activity_title'] = $_POST['resource_link_title']
                ?? $_POST['custom_canvas_assignment_title']
                ?? $_POST['custom_activity_title']
                ?? null;
        }
    }
} elseif (isset($_SESSION['lti_valid']) && $_SESSION['lti_valid']) {
    // Use session data for page refresh
    $userEmail = $_SESSION['lti_user_email'];
    $userName = $_SESSION['lti_user_name'];
} else {
    $error = 'Please launch this tool from your LMS';
}

/**
 * Send grade back to LMS via LTI Outcomes Service
 */
function sendGradeToLMS($outcomeUrl, $sourcedId, $score, $consumerKey, $consumerSecret) {
    // Score must be between 0.0 and 1.0
    $score = max(0, min(1, floatval($score)));

    // Build the XML payload (POX format)
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
                    <sourcedId>' . htmlspecialchars($sourcedId, ENT_XML1) . '</sourcedId>
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
        'oauth_consumer_key' => $consumerKey,
        'oauth_nonce' => bin2hex(random_bytes(16)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => (string)time(),
        'oauth_version' => '1.0',
        'oauth_body_hash' => base64_encode(sha1($xml, true))
    ];

    // Build signature base string
    ksort($oauth);
    $paramString = http_build_query($oauth, '', '&', PHP_QUERY_RFC3986);
    $baseString = 'POST&' . rawurlencode($outcomeUrl) . '&' . rawurlencode($paramString);

    // Generate signature
    $key = rawurlencode($consumerSecret) . '&';
    $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $baseString, $key, true));

    // Build Authorization header
    $authParts = [];
    foreach ($oauth as $k => $v) {
        $authParts[] = $k . '="' . rawurlencode($v) . '"';
    }
    $authHeader = 'OAuth ' . implode(', ', $authParts);

    // Send request
    $ch = curl_init($outcomeUrl);
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
    $curlError = curl_error($ch);
    curl_close($ch);

    // Check for success in response
    $success = ($httpCode >= 200 && $httpCode < 300);
    if ($success && $response) {
        // Check XML response for success status
        if (strpos($response, 'success') === false && strpos($response, 'Success') === false) {
            $success = false;
        }
    }

    return [
        'success' => $success,
        'httpCode' => $httpCode,
        'response' => $response,
        'error' => $curlError
    ];
}

/**
 * Find matching activity for the current LTI launch
 */
function findMatchingActivity($groupedActivities, $resourceLinkTitle, $customLabId = null) {
    // If custom_lab_id is provided, match by activity ID containing it
    if ($customLabId) {
        foreach ($groupedActivities as $activityId => $activity) {
            if (stripos($activityId, $customLabId) !== false) {
                return ['id' => $activityId, 'activity' => $activity];
            }
        }
    }

    // Match by resource_link_title against activity name
    if ($resourceLinkTitle) {
        $titleLower = strtolower($resourceLinkTitle);
        foreach ($groupedActivities as $activityId => $activity) {
            $nameLower = strtolower($activity['name']);
            // Check if title contains activity name or vice versa
            if (stripos($nameLower, $titleLower) !== false ||
                stripos($titleLower, $nameLower) !== false ||
                similar_text($nameLower, $titleLower) > min(strlen($nameLower), strlen($titleLower)) * 0.6) {
                return ['id' => $activityId, 'activity' => $activity];
            }
        }

        // Try matching key parts of the title
        $titleParts = preg_split('/[\s\-_:]+/', $titleLower);
        foreach ($groupedActivities as $activityId => $activity) {
            $nameLower = strtolower($activity['name']);
            foreach ($titleParts as $part) {
                if (strlen($part) > 3 && stripos($nameLower, $part) !== false) {
                    return ['id' => $activityId, 'activity' => $activity];
                }
            }
        }
    }

    return null;
}

/**
 * Calculate grade for an activity (0.0 to 1.0)
 */
function calculateActivityGrade($activity) {
    // If activity has a score, use that
    if ($activity['highestScore'] !== null) {
        return $activity['highestScore'];
    }

    // If activity has children, calculate based on passed/total
    if (!empty($activity['children'])) {
        $passed = 0;
        $total = count($activity['children']);
        foreach ($activity['children'] as $child) {
            if ($child['status'] === 'passed') {
                $passed++;
            }
        }
        return $total > 0 ? $passed / $total : 0;
    }

    // Based on status alone
    switch ($activity['status']) {
        case 'passed':
        case 'mastered':
            return 1.0;
        case 'completed':
            return 1.0;
        case 'failed':
            return 0.0;
        default:
            return 0.0;
    }
}

/**
 * Get parent activity ID from statement context
 */
function getParentActivityId($statement) {
    // Check for parent in contextActivities
    if (isset($statement['context']['contextActivities']['parent'][0]['id'])) {
        return $statement['context']['contextActivities']['parent'][0]['id'];
    }
    // Check for grouping as fallback
    if (isset($statement['context']['contextActivities']['grouping'][0]['id'])) {
        return $statement['context']['contextActivities']['grouping'][0]['id'];
    }
    return null;
}

/**
 * Group statements by parent activity with children nested
 */
function groupStatementsByActivity($statements) {
    $parents = [];
    $children = [];
    $parentIds = [];

    // First pass: identify all parent IDs
    foreach ($statements as $statement) {
        $parentId = getParentActivityId($statement);
        if ($parentId) {
            $parentIds[$parentId] = true;
        }
    }

    // Second pass: categorize statements as parents or children
    foreach ($statements as $statement) {
        $objectId = $statement['object']['id'] ?? 'unknown';
        $parentId = getParentActivityId($statement);

        // If this statement's object is referenced as a parent by others, or has no parent itself, it's a parent
        if (isset($parentIds[$objectId]) || $parentId === null) {
            if (!isset($parents[$objectId])) {
                $parents[$objectId] = [
                    'name' => getObjectName($statement['object']),
                    'object' => $statement['object'],
                    'highestScore' => null,
                    'bestAttempt' => null,
                    'status' => 'attempted',
                    'attempts' => [],
                    'children' => [],
                    'latestTimestamp' => $statement['timestamp']
                ];
            }
            $parents[$objectId]['attempts'][] = $statement;
            updateActivityStats($parents[$objectId], $statement);
        } else {
            // This is a child statement
            if (!isset($children[$parentId])) {
                $children[$parentId] = [];
            }
            if (!isset($children[$parentId][$objectId])) {
                $children[$parentId][$objectId] = [
                    'name' => getObjectName($statement['object']),
                    'object' => $statement['object'],
                    'highestScore' => null,
                    'bestAttempt' => null,
                    'status' => 'attempted',
                    'attempts' => [],
                    'latestTimestamp' => $statement['timestamp']
                ];
            }
            $children[$parentId][$objectId]['attempts'][] = $statement;
            updateActivityStats($children[$parentId][$objectId], $statement);
        }
    }

    // Attach children to parents
    foreach ($children as $parentId => $childActivities) {
        if (isset($parents[$parentId])) {
            $parents[$parentId]['children'] = $childActivities;
            // Update parent status based on children
            $allPassed = true;
            $anyFailed = false;
            foreach ($childActivities as $child) {
                if ($child['status'] === 'failed') {
                    $anyFailed = true;
                    $allPassed = false;
                } elseif ($child['status'] !== 'passed') {
                    $allPassed = false;
                }
            }
            // Only override if parent doesn't have its own definitive status
            if (!empty($childActivities)) {
                if ($allPassed && count($childActivities) > 0) {
                    $parents[$parentId]['status'] = 'passed';
                } elseif ($anyFailed) {
                    $parents[$parentId]['status'] = 'failed';
                }
            }
        } else {
            // Parent doesn't exist in statements, create a placeholder
            // This shouldn't happen normally, but handle it gracefully
            foreach ($childActivities as $childId => $child) {
                $parents[$childId] = $child;
                $parents[$childId]['children'] = [];
            }
        }
    }

    // Sort by latest timestamp (most recent first)
    uasort($parents, function($a, $b) {
        return strcmp($b['latestTimestamp'], $a['latestTimestamp']);
    });

    return $parents;
}

/**
 * Update activity statistics from a statement
 */
function updateActivityStats(&$activity, $statement) {
    $verb = strtolower(getVerbName($statement['verb']));

    // Update latest timestamp
    if ($statement['timestamp'] > $activity['latestTimestamp']) {
        $activity['latestTimestamp'] = $statement['timestamp'];
    }

    // Update status based on verb
    if (in_array($verb, ['passed', 'mastered'])) {
        $activity['status'] = 'passed';
    } elseif ($verb === 'failed' && $activity['status'] !== 'passed') {
        $activity['status'] = 'failed';
    } elseif (in_array($verb, ['completed', 'finished']) && !in_array($activity['status'], ['passed', 'failed'])) {
        $activity['status'] = 'completed';
    }

    // Track highest score
    if (isset($statement['result']['score']['scaled'])) {
        $score = $statement['result']['score']['scaled'];
        if ($activity['highestScore'] === null || $score > $activity['highestScore']) {
            $activity['highestScore'] = $score;
            $activity['bestAttempt'] = $statement;
        }
    }
}

// Fetch xAPI statements if we have a valid email
$groupedActivities = [];
$matchedActivity = null;
$gradePassbackResult = null;
$canPassbackGrade = false;

if (!$error && $userEmail) {
    $result = getXapiStatements(
        $config['lrs_endpoint'],
        $config['lrs_api_key'],
        $config['lrs_api_secret'],
        $userEmail
    );
    $statements = $result['statements'];
    if ($result['error']) {
        $error = 'Error fetching records: ' . $result['error'];
    } else {
        $groupedActivities = groupStatementsByActivity($statements);

        // Check if grade passback is available
        $canPassbackGrade = !empty($_SESSION['lis_outcome_service_url']) && !empty($_SESSION['lis_result_sourcedid']);

        // Try to find matching activity for this LTI launch
        if ($canPassbackGrade && !empty($groupedActivities)) {
            // Use activity_title (works across Canvas, Brightspace, Moodle)
            $matchedActivity = findMatchingActivity(
                $groupedActivities,
                $_SESSION['activity_title'] ?? null,
                $_SESSION['custom_lab_id'] ?? null
            );
        }

        // Handle grade submission
        if ($canPassbackGrade && isset($_POST['submit_grade']) && $matchedActivity) {
            $grade = calculateActivityGrade($matchedActivity['activity']);
            $gradePassbackResult = sendGradeToLMS(
                $_SESSION['lis_outcome_service_url'],
                $_SESSION['lis_result_sourcedid'],
                $grade,
                $config['lti_consumer_key'],
                $config['lti_consumer_secret']
            );
            $gradePassbackResult['grade'] = $grade;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Learning Records</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .record-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .record-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .verb-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .verb-completed { background-color: #d4edda; color: #155724; }
        .verb-attempted { background-color: #fff3cd; color: #856404; }
        .verb-passed { background-color: #cce5ff; color: #004085; }
        .verb-failed { background-color: #f8d7da; color: #721c24; }
        .verb-default { background-color: #e2e3e5; color: #383d41; }
        .timestamp {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .object-name {
            font-weight: 500;
            color: #333;
        }
        .score-display {
            font-size: 1.2rem;
            font-weight: bold;
        }
        .no-records {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }
        .error-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
        }
        /* Summary card styles */
        .summary-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #e2e3e5;
        }
        .summary-card.status-passed { border-left-color: #28a745; }
        .summary-card.status-failed { border-left-color: #dc3545; }
        .summary-card.status-completed { border-left-color: #17a2b8; }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-passed { background-color: #d4edda; color: #155724; }
        .status-failed { background-color: #f8d7da; color: #721c24; }
        .status-completed { background-color: #d1ecf1; color: #0c5460; }
        .status-attempted { background-color: #fff3cd; color: #856404; }
        .best-score {
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
        }
        .attempt-count {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .attempts-list {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
        }
        .attempt-item {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        .attempt-item:last-child {
            border-bottom: none;
        }
        .attempt-score {
            font-weight: 600;
            color: #495057;
        }
        .verb-badge-small {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .toggle-attempts .hide-text { display: none; }
        .toggle-attempts[aria-expanded="true"] .show-text { display: none; }
        .toggle-attempts[aria-expanded="true"] .hide-text { display: inline; }
        /* Children/task styles */
        .task-count {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .children-list {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
        }
        .child-item {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
            border-left: 3px solid #e2e3e5;
            background: white;
            margin-bottom: 5px;
            border-radius: 4px;
        }
        .child-item:last-child {
            margin-bottom: 0;
        }
        .child-item.status-passed { border-left-color: #28a745; }
        .child-item.status-failed { border-left-color: #dc3545; }
        .child-item.status-completed { border-left-color: #17a2b8; }
        .child-status-icon {
            font-weight: bold;
            font-size: 1rem;
            width: 20px;
            text-align: center;
        }
        .child-status-icon.status-passed { color: #28a745; }
        .child-status-icon.status-failed { color: #dc3545; }
        .child-status-icon.status-completed { color: #17a2b8; }
        .child-status-icon.status-attempted { color: #856404; }
        .child-name {
            font-weight: 500;
            color: #333;
        }
        .child-score {
            font-weight: 600;
            color: #495057;
        }
        /* Grade passback styles */
        .grade-box {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        .grade-box.grade-success {
            border-left-color: #28a745;
            background: #f8fff8;
        }
        .grade-box.grade-error {
            border-left-color: #dc3545;
            background: #fff8f8;
        }
        .grade-box h5 {
            margin-bottom: 15px;
            color: #333;
        }
        .grade-display {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }
        .grade-label {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .matched-activity {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 6px;
            margin: 10px 0;
        }
        .no-match-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 15px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <div class="error-box">
                <h3>Unable to Load Records</h3>
                <p class="text-danger"><?= htmlspecialchars($error) ?></p>
                <?php if (strpos($error, 'launch') !== false): ?>
                    <p class="text-muted">This tool must be accessed through your Learning Management System (LMS).</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="header">
                <h1>My Learning Records</h1>
                <p class="mb-0">Welcome, <?= htmlspecialchars($userName) ?>!</p>
                <?php if ($userEmail): ?>
                    <small>Tracking records for: <?= htmlspecialchars($userEmail) ?></small>
                <?php endif; ?>
                <?php if (isset($_SESSION['lti_context_title'])): ?>
                    <br><small>Course: <?= htmlspecialchars($_SESSION['lti_context_title']) ?></small>
                <?php endif; ?>
            </div>

            <!-- Grade Passback Section -->
            <?php if ($canPassbackGrade): ?>
                <?php if ($gradePassbackResult): ?>
                    <!-- Show result of grade submission -->
                    <div class="grade-box <?= $gradePassbackResult['success'] ? 'grade-success' : 'grade-error' ?>">
                        <?php if ($gradePassbackResult['success']): ?>
                            <h5>Grade Submitted Successfully!</h5>
                            <p class="mb-0">
                                Your grade of <strong><?= round($gradePassbackResult['grade'] * 100) ?>%</strong> has been sent to the gradebook.
                            </p>
                        <?php else: ?>
                            <h5>Grade Submission Failed</h5>
                            <p class="text-danger mb-0">
                                There was an error submitting your grade. Please try again or contact your instructor.
                            </p>
                            <small class="text-muted">Error: <?= htmlspecialchars($gradePassbackResult['error'] ?: 'Unknown error') ?></small>
                        <?php endif; ?>
                    </div>
                <?php elseif ($matchedActivity): ?>
                    <!-- Show grade submission form -->
                    <div class="grade-box">
                        <h5>Submit Grade to Gradebook</h5>
                        <div class="matched-activity">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>Matched Activity:</strong> <?= htmlspecialchars($matchedActivity['activity']['name']) ?>
                                    <?php
                                    $currentGrade = calculateActivityGrade($matchedActivity['activity']);
                                    $childCount = count($matchedActivity['activity']['children']);
                                    $passedCount = 0;
                                    foreach ($matchedActivity['activity']['children'] as $child) {
                                        if ($child['status'] === 'passed') $passedCount++;
                                    }
                                    ?>
                                    <?php if ($childCount > 0): ?>
                                        <br><small class="text-muted"><?= $passedCount ?>/<?= $childCount ?> tasks passed</small>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <div class="grade-display"><?= round($currentGrade * 100) ?>%</div>
                                    <div class="grade-label">Current Grade</div>
                                </div>
                            </div>
                        </div>
                        <form method="POST" class="mt-3">
                            <button type="submit" name="submit_grade" class="btn btn-success">
                                Submit Grade to Gradebook
                            </button>
                            <small class="text-muted d-block mt-2">
                                This will send your current grade (<?= round($currentGrade * 100) ?>%) to the Canvas gradebook.
                            </small>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- No matching activity found -->
                    <div class="grade-box">
                        <h5>Grade Submission Available</h5>
                        <div class="no-match-warning">
                            <strong>No Matching Activity Found</strong>
                            <p class="mb-0 mt-2">
                                Could not automatically match this assignment to a lab activity.
                                <?php if (!empty($_SESSION['resource_link_title'])): ?>
                                    <br><small>Assignment: "<?= htmlspecialchars($_SESSION['resource_link_title']) ?>"</small>
                                <?php endif; ?>
                            </p>
                        </div>
                        <small class="text-muted">
                            Tip: Make sure the Canvas assignment name matches the lab name in xAPI, or configure a custom_lab_id parameter.
                        </small>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$userEmail): ?>
                <div class="alert alert-warning">
                    <strong>Email Not Available</strong><br>
                    Your email address was not provided by the LMS. Please contact your instructor.
                </div>
            <?php elseif (empty($groupedActivities)): ?>
                <div class="no-records">
                    <h3>No Learning Records Found</h3>
                    <p class="text-muted">
                        You don't have any learning activity recorded yet.<br>
                        Complete some activities and check back later!
                    </p>
                </div>
            <?php else: ?>
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="stats-number"><?= count($groupedActivities) ?></div>
                            <div>Total Activities</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <?php
                            $passedCount = count(array_filter($groupedActivities, function($a) {
                                return $a['status'] === 'passed';
                            }));
                            ?>
                            <div class="stats-number"><?= $passedCount ?></div>
                            <div>Passed</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <?php
                            $scores = array_filter(array_column($groupedActivities, 'highestScore'), function($s) {
                                return $s !== null;
                            });
                            $avgScore = count($scores) > 0 ? round((array_sum($scores) / count($scores)) * 100, 1) : '-';
                            ?>
                            <div class="stats-number"><?= $avgScore ?><?= $avgScore !== '-' ? '%' : '' ?></div>
                            <div>Avg Best Score</div>
                        </div>
                    </div>
                </div>

                <!-- Activity Summary List -->
                <h4 class="mb-3">Activity Summary</h4>
                <?php $activityIndex = 0; foreach ($groupedActivities as $objectId => $activity): ?>
                    <?php
                    $statusClass = 'status-attempted';
                    $statusLabel = 'Attempted';
                    if ($activity['status'] === 'passed') {
                        $statusClass = 'status-passed';
                        $statusLabel = 'Passed';
                    } elseif ($activity['status'] === 'failed') {
                        $statusClass = 'status-failed';
                        $statusLabel = 'Failed';
                    } elseif ($activity['status'] === 'completed') {
                        $statusClass = 'status-completed';
                        $statusLabel = 'Completed';
                    }
                    $hasChildren = !empty($activity['children']);
                    $childCount = count($activity['children']);
                    $passedChildren = 0;
                    $failedChildren = 0;
                    foreach ($activity['children'] as $child) {
                        if ($child['status'] === 'passed') $passedChildren++;
                        elseif ($child['status'] === 'failed') $failedChildren++;
                    }
                    ?>
                    <div class="summary-card <?= $statusClass ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                                    <?php if ($hasChildren): ?>
                                        <span class="task-count"><?= $passedChildren ?>/<?= $childCount ?> tasks passed</span>
                                    <?php endif; ?>
                                </div>
                                <h5 class="object-name mt-2 mb-1">
                                    <?= htmlspecialchars($activity['name']) ?>
                                </h5>
                                <div class="timestamp">
                                    Last activity: <?= formatTimestamp($activity['latestTimestamp']) ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <?php if ($activity['highestScore'] !== null): ?>
                                    <div class="best-score">
                                        <?= round($activity['highestScore'] * 100) ?>%
                                    </div>
                                    <small class="text-muted">Best Score</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Nested children tasks -->
                        <?php if ($hasChildren): ?>
                            <div class="mt-3">
                                <button class="btn btn-sm btn-outline-secondary toggle-attempts" type="button" data-bs-toggle="collapse" data-bs-target="#children-<?= $activityIndex ?>" aria-expanded="false">
                                    <span class="show-text">Show Tasks (<?= $childCount ?>)</span>
                                    <span class="hide-text" style="display:none;">Hide Tasks</span>
                                </button>
                            </div>
                            <div class="collapse" id="children-<?= $activityIndex ?>">
                                <div class="children-list mt-3">
                                    <?php foreach ($activity['children'] as $childId => $child): ?>
                                        <?php
                                        $childStatusClass = 'status-attempted';
                                        if ($child['status'] === 'passed') $childStatusClass = 'status-passed';
                                        elseif ($child['status'] === 'failed') $childStatusClass = 'status-failed';
                                        elseif ($child['status'] === 'completed') $childStatusClass = 'status-completed';
                                        ?>
                                        <div class="child-item <?= $childStatusClass ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="child-status-icon <?= $childStatusClass ?>">
                                                        <?php if ($child['status'] === 'passed'): ?>
                                                            &#10003;
                                                        <?php elseif ($child['status'] === 'failed'): ?>
                                                            &#10007;
                                                        <?php else: ?>
                                                            &#9679;
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="child-name"><?= htmlspecialchars($child['name']) ?></span>
                                                </div>
                                                <?php if ($child['highestScore'] !== null): ?>
                                                    <div class="child-score">
                                                        <?= round($child['highestScore'] * 100) ?>%
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php $activityIndex++; endforeach; ?>
            <?php endif; ?>

            <div class="text-center mt-4">
                <button class="btn btn-primary" onclick="location.reload()">
                    Refresh Records
                </button>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
