<?php
/**
 * LTI xAPI Learning Records Viewer (Tsugi-based)
 *
 * A Tsugi-powered LTI tool that supports both LTI 1.1 and LTI 1.3.
 * Allows students to view their xAPI learning records and automatically
 * syncs grades back to the LMS.
 *
 * Students are identified by their email from the LTI launch and records
 * are fetched from SQL LRS filtered by actor mbox (mailto:email).
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/xapi_functions.php';

use Tsugi\Core\LTIX;
use Tsugi\Util\LTI;

// Start Tsugi session and verify LTI launch
$LAUNCH = LTIX::requireData();

// Get user information from Tsugi
$userEmail = $LAUNCH->user->email ?? null;
$userName = $LAUNCH->user->displayname ?? 'Student';
$contextTitle = $LAUNCH->context->title ?? 'Course';

// Get LRS configuration from Tsugi settings or environment
$lrsConfig = [
    'endpoint' => $CFG->lrs_endpoint ?? getenv('LRS_ENDPOINT') ?: 'http://sql-lrs:8080/xapi',
    'api_key' => $CFG->lrs_api_key ?? getenv('LRS_API_KEY') ?: 'my_api_key',
    'api_secret' => $CFG->lrs_api_secret ?? getenv('LRS_API_SECRET') ?: 'my_api_secret',
];

// Initialize variables
$error = null;
$statements = [];
$groupedActivities = [];
$matchedActivity = null;
$gradePassbackResult = null;

// Check if we have a valid email
if (empty($userEmail)) {
    $error = 'Your email address was not provided by the LMS. Please contact your instructor.';
}

// Get custom parameters for activity matching
$customLabId = $LAUNCH->link->settingsGet('custom_lab_id') ?? $_POST['custom_lab_id'] ?? $_SESSION['custom_lab_id'] ?? null;
$activityTitle = $LAUNCH->link->title ?? $_POST['resource_link_title'] ?? null;

// Store custom parameters in session
if ($customLabId) {
    $_SESSION['custom_lab_id'] = $customLabId;
}
$_SESSION['activity_title'] = $activityTitle;

// Fetch xAPI statements if we have a valid email
if (!$error && $userEmail) {
    $result = getXapiStatements(
        $lrsConfig['endpoint'],
        $lrsConfig['api_key'],
        $lrsConfig['api_secret'],
        $userEmail
    );

    if ($result['error']) {
        $error = 'Error fetching records: ' . $result['error'];
    } else {
        $statements = $result['statements'];
        $groupedActivities = groupStatementsByActivity($statements);

        // Check if grade passback is available (Tsugi handles this)
        $canPassbackGrade = $LAUNCH->result && $LAUNCH->result->sourcedid;

        // Try to find matching activity for this LTI launch
        if ($canPassbackGrade && !empty($groupedActivities)) {
            $matchedActivity = findMatchingActivity(
                $groupedActivities,
                $activityTitle,
                $customLabId
            );
        }

        // Automatically submit grade on page load if conditions are met
        if ($canPassbackGrade && $matchedActivity) {
            $grade = calculateActivityGrade($matchedActivity['activity']);

            // Use Tsugi's grade passback (works with LTI 1.1 and 1.3)
            $gradePassbackResult = sendGradeViaTsugi($LAUNCH, $grade);
            $gradePassbackResult['grade'] = $grade;
        }
    }
}

// Calculate statistics
$totalActivities = count($groupedActivities);
$passedCount = 0;
$scores = [];

foreach ($groupedActivities as $activity) {
    if ($activity['status'] === 'passed') {
        $passedCount++;
    }
    if ($activity['highestScore'] !== null) {
        $scores[] = $activity['highestScore'];
    }
}

$avgScore = count($scores) > 0 ? round((array_sum($scores) / count($scores)) * 100, 1) : null;

// Start HTML output with Tsugi header
$OUTPUT->header();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Learning Records</title>
    <link rel="stylesheet" href="<?= $CFG->staticroot ?? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' ?>">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<?php
$OUTPUT->bodyStart();
$OUTPUT->flashMessages();
?>
    <div class="container">
        <?php if ($error): ?>
            <div class="error-box">
                <h3>Unable to Load Records</h3>
                <p class="text-danger"><?= htmlspecialchars($error) ?></p>
                <?php if (strpos($error, 'email') !== false): ?>
                    <p class="text-muted">Please ensure your LMS is configured to share your email with this tool.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="header">
                <h1>My Learning Records</h1>
                <p class="mb-0">Welcome, <?= htmlspecialchars($userName) ?>!</p>
                <?php if ($userEmail): ?>
                    <small>Tracking records for: <?= htmlspecialchars($userEmail) ?></small>
                <?php endif; ?>
                <br><small>Course: <?= htmlspecialchars($contextTitle) ?></small>
            </div>

            <!-- Grade Passback Section -->
            <?php if (isset($canPassbackGrade) && $canPassbackGrade): ?>
                <?php if ($gradePassbackResult): ?>
                    <div class="grade-box <?= $gradePassbackResult['success'] ? 'grade-success' : 'grade-error' ?>">
                        <?php if ($gradePassbackResult['success']): ?>
                            <h5>Grade Synced to Gradebook</h5>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Your grade has been automatically sent to the gradebook.</p>
                                    <?php if ($matchedActivity): ?>
                                        <small class="text-muted">Activity: <?= htmlspecialchars($matchedActivity['activity']['name']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <div class="grade-display"><?= round($gradePassbackResult['grade'] * 100) ?>%</div>
                                    <div class="grade-label">Grade Sent</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <h5>Grade Sync Failed</h5>
                            <p class="text-danger mb-0">There was an error syncing your grade to the gradebook.</p>
                            <small class="text-muted">Error: <?= htmlspecialchars($gradePassbackResult['error'] ?? 'Unknown error') ?></small>
                        <?php endif; ?>
                    </div>
                <?php elseif (empty($groupedActivities)): ?>
                    <!-- No activities yet, no grade to send -->
                <?php else: ?>
                    <div class="grade-box">
                        <h5>Grade Submission Available</h5>
                        <div class="no-match-warning">
                            <strong>No Matching Activity Found</strong>
                            <p class="mb-0 mt-2">
                                Could not automatically match this assignment to a lab activity.
                                <?php if ($activityTitle): ?>
                                    <br><small>Assignment: "<?= htmlspecialchars($activityTitle) ?>"</small>
                                <?php endif; ?>
                            </p>
                        </div>
                        <small class="text-muted">
                            Tip: Configure a custom_lab_id parameter in the LTI tool settings to match specific activities.
                        </small>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($groupedActivities)): ?>
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
                            <div class="stats-number"><?= $totalActivities ?></div>
                            <div>Total Activities</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="stats-number"><?= $passedCount ?></div>
                            <div>Passed</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="stats-number"><?= $avgScore !== null ? $avgScore . '%' : '-' ?></div>
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
                                    <span class="hide-text">Hide Tasks</span>
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

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php
$OUTPUT->footerStart();
$OUTPUT->footerEnd();
?>
</body>
</html>
