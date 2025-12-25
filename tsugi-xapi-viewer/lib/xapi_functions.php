<?php
/**
 * xAPI Helper Functions
 *
 * Functions for querying and processing xAPI statements from an LRS.
 */

/**
 * Query xAPI statements from the LRS for a specific actor email
 *
 * @param string $endpoint LRS endpoint URL
 * @param string $key API key for authentication
 * @param string $secret API secret for authentication
 * @param string $email Actor email address
 * @param int $limit Maximum number of statements to retrieve
 * @return array Contains 'error' (string|null) and 'statements' (array)
 */
function getXapiStatements($endpoint, $key, $secret, $email, $limit = 100) {
    $agent = json_encode([
        "mbox" => "mailto:" . $email
    ]);

    $url = rtrim($endpoint, '/') . "/statements?" . http_build_query([
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
 *
 * @param string $timestamp ISO 8601 timestamp
 * @return string Formatted date string
 */
function formatTimestamp($timestamp) {
    try {
        $dt = new DateTime($timestamp);
        $dt->setTimezone(new DateTimeZone('America/New_York'));
        return $dt->format('M j, Y g:i A');
    } catch (Exception $e) {
        return $timestamp;
    }
}

/**
 * Extract a human-readable verb name from an xAPI verb object
 *
 * @param array $verb xAPI verb object
 * @return string Human-readable verb name
 */
function getVerbName($verb) {
    if (isset($verb['display']['en-US'])) {
        return $verb['display']['en-US'];
    }
    if (isset($verb['display']['en'])) {
        return $verb['display']['en'];
    }
    $parts = explode('/', $verb['id'] ?? '');
    return ucfirst(end($parts));
}

/**
 * Extract a human-readable object name from an xAPI object
 *
 * @param array $object xAPI object
 * @return string Human-readable object name
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

/**
 * Get parent activity ID from statement context
 *
 * @param array $statement xAPI statement
 * @return string|null Parent activity ID or null
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
 * Update activity statistics from a statement
 *
 * @param array &$activity Activity data (passed by reference)
 * @param array $statement xAPI statement
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

/**
 * Group statements by parent activity with children nested
 *
 * @param array $statements Array of xAPI statements
 * @return array Grouped activities with parent-child relationships
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
 * Find matching activity for the current LTI launch
 *
 * @param array $groupedActivities Grouped activities from groupStatementsByActivity
 * @param string|null $resourceLinkTitle Title of the resource link
 * @param string|null $customLabId Custom lab ID from LTI parameters
 * @return array|null Matched activity info or null
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
 *
 * @param array $activity Activity data
 * @return float Grade between 0.0 and 1.0
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
 * Send grade to LMS via Tsugi (works with LTI 1.1 and 1.3)
 *
 * @param object $LAUNCH Tsugi LAUNCH object
 * @param float $grade Grade between 0.0 and 1.0
 * @return array Result with 'success' (bool) and 'error' (string|null)
 */
function sendGradeViaTsugi($LAUNCH, $grade) {
    try {
        // Tsugi's result object handles both LTI 1.1 and 1.3 grade passback
        if ($LAUNCH->result && $LAUNCH->result->sourcedid) {
            // Use Tsugi's grade sending method
            $result = $LAUNCH->result->gradeSend($grade);

            if ($result === true) {
                return ['success' => true, 'error' => null];
            } else {
                return ['success' => false, 'error' => is_string($result) ? $result : 'Grade send failed'];
            }
        }

        return ['success' => false, 'error' => 'Grade passback not available for this launch'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
