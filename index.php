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

    // Reconstruct the URL
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = $_SERVER['REQUEST_URI'];
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
        // Try URL variations (http vs https, with/without port)
        $urlVariations = [
            $url,
            str_replace('https://', 'http://', $url),
            str_replace('http://', 'https://', $url),
            preg_replace('/:8888/', '', $url),
        ];

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
    // Verify LTI message type
    if (!isset($_POST['lti_message_type']) || $_POST['lti_message_type'] !== 'basic-lti-launch-request') {
        $error = 'Invalid LTI message type';
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
 * Group statements by activity and calculate summary
 */
function groupStatementsByActivity($statements) {
    $grouped = [];

    foreach ($statements as $statement) {
        $objectId = $statement['object']['id'] ?? 'unknown';
        $objectName = getObjectName($statement['object']);
        $verb = strtolower(getVerbName($statement['verb']));

        if (!isset($grouped[$objectId])) {
            $grouped[$objectId] = [
                'name' => $objectName,
                'object' => $statement['object'],
                'highestScore' => null,
                'bestAttempt' => null,
                'status' => 'attempted', // attempted, passed, failed, completed
                'attempts' => [],
                'latestTimestamp' => $statement['timestamp']
            ];
        }

        // Track all attempts
        $grouped[$objectId]['attempts'][] = $statement;

        // Update latest timestamp
        if ($statement['timestamp'] > $grouped[$objectId]['latestTimestamp']) {
            $grouped[$objectId]['latestTimestamp'] = $statement['timestamp'];
        }

        // Update status based on verb
        if (in_array($verb, ['passed', 'mastered'])) {
            $grouped[$objectId]['status'] = 'passed';
        } elseif ($verb === 'failed' && $grouped[$objectId]['status'] !== 'passed') {
            $grouped[$objectId]['status'] = 'failed';
        } elseif (in_array($verb, ['completed', 'finished']) && !in_array($grouped[$objectId]['status'], ['passed', 'failed'])) {
            $grouped[$objectId]['status'] = 'completed';
        }

        // Track highest score
        if (isset($statement['result']['score']['scaled'])) {
            $score = $statement['result']['score']['scaled'];
            if ($grouped[$objectId]['highestScore'] === null || $score > $grouped[$objectId]['highestScore']) {
                $grouped[$objectId]['highestScore'] = $score;
                $grouped[$objectId]['bestAttempt'] = $statement;
            }
        }
    }

    // Sort by latest timestamp (most recent first)
    uasort($grouped, function($a, $b) {
        return strcmp($b['latestTimestamp'], $a['latestTimestamp']);
    });

    return $grouped;
}

// Fetch xAPI statements if we have a valid email
$groupedActivities = [];
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
                    $hasMultipleAttempts = count($activity['attempts']) > 1;
                    ?>
                    <div class="summary-card <?= $statusClass ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                                    <span class="attempt-count"><?= count($activity['attempts']) ?> attempt<?= count($activity['attempts']) > 1 ? 's' : '' ?></span>
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

                        <!-- Expandable attempts section -->
                        <?php if ($hasMultipleAttempts): ?>
                            <div class="mt-3">
                                <button class="btn btn-sm btn-outline-secondary toggle-attempts" type="button" data-bs-toggle="collapse" data-bs-target="#attempts-<?= $activityIndex ?>" aria-expanded="false">
                                    <span class="show-text">Show All Attempts</span>
                                    <span class="hide-text" style="display:none;">Hide Attempts</span>
                                </button>
                            </div>
                            <div class="collapse" id="attempts-<?= $activityIndex ?>">
                                <div class="attempts-list mt-3">
                                    <?php foreach ($activity['attempts'] as $attempt): ?>
                                        <?php
                                        $verb = getVerbName($attempt['verb']);
                                        $verbLower = strtolower($verb);
                                        $verbClass = 'verb-default';
                                        if (in_array($verbLower, ['completed', 'finished', 'mastered'])) $verbClass = 'verb-completed';
                                        elseif (in_array($verbLower, ['attempted', 'started', 'launched', 'initialized'])) $verbClass = 'verb-attempted';
                                        elseif ($verbLower === 'passed') $verbClass = 'verb-passed';
                                        elseif ($verbLower === 'failed') $verbClass = 'verb-failed';
                                        ?>
                                        <div class="attempt-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <span class="verb-badge-small <?= $verbClass ?>"><?= htmlspecialchars($verb) ?></span>
                                                    <span class="timestamp ms-2"><?= formatTimestamp($attempt['timestamp']) ?></span>
                                                </div>
                                                <?php if (isset($attempt['result']['score']['scaled'])): ?>
                                                    <div class="attempt-score">
                                                        <?= round($attempt['result']['score']['scaled'] * 100) ?>%
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Single attempt - show inline -->
                            <?php $attempt = $activity['attempts'][0]; ?>
                            <div class="single-attempt mt-2">
                                <?php
                                $verb = getVerbName($attempt['verb']);
                                $verbLower = strtolower($verb);
                                $verbClass = 'verb-default';
                                if (in_array($verbLower, ['completed', 'finished', 'mastered'])) $verbClass = 'verb-completed';
                                elseif (in_array($verbLower, ['attempted', 'started', 'launched', 'initialized'])) $verbClass = 'verb-attempted';
                                elseif ($verbLower === 'passed') $verbClass = 'verb-passed';
                                elseif ($verbLower === 'failed') $verbClass = 'verb-failed';
                                ?>
                                <span class="verb-badge-small <?= $verbClass ?>"><?= htmlspecialchars($verb) ?></span>
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
