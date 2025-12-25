<?php
/**
 * LTI Tool Registration
 *
 * Provides LTI 1.1 XML configuration and LTI 1.3 JSON configuration
 * for registering this tool with Learning Management Systems.
 */

require_once __DIR__ . '/app.php';

// Determine the tool URL
$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
}
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path = dirname($_SERVER['REQUEST_URI'] ?? '/');
$baseUrl = rtrim("$scheme://$host$path", '/');
$launchUrl = $baseUrl . '/index.php';

// Handle different output formats
$format = $_GET['format'] ?? 'html';

switch ($format) {
    case 'xml':
        outputLTI11XML($baseUrl, $launchUrl);
        break;
    case 'json':
        outputLTI13JSON($baseUrl, $launchUrl);
        break;
    case 'canvas':
        outputCanvasXML($baseUrl, $launchUrl);
        break;
    default:
        outputHTML($baseUrl, $launchUrl);
}

/**
 * Output LTI 1.1 Basic LTI Configuration XML
 */
function outputLTI11XML($baseUrl, $launchUrl) {
    global $CFG;

    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"
    xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"
    xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0"
    xmlns:lticp="http://www.imsglobal.org/xsd/imslticp_v1p0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.imsglobal.org/xsd/imslticc_v1p0 http://www.imsglobal.org/xsd/lti/ltiv1p0/imslticc_v1p0.xsd
        http://www.imsglobal.org/xsd/imsbasiclti_v1p0 http://www.imsglobal.org/xsd/lti/ltiv1p0/imsbasiclti_v1p0.xsd
        http://www.imsglobal.org/xsd/imslticm_v1p0 http://www.imsglobal.org/xsd/lti/ltiv1p0/imslticm_v1p0.xsd
        http://www.imsglobal.org/xsd/imslticp_v1p0 http://www.imsglobal.org/xsd/lti/ltiv1p0/imslticp_v1p0.xsd">
    <blti:title><?= htmlspecialchars($CFG->tool_title ?? 'xAPI Learning Records Viewer') ?></blti:title>
    <blti:description><?= htmlspecialchars($CFG->tool_description ?? 'View your xAPI learning records and sync grades.') ?></blti:description>
    <blti:launch_url><?= htmlspecialchars($launchUrl) ?></blti:launch_url>
    <blti:extensions platform="canvas.instructure.com">
        <lticm:property name="tool_id">xapi_viewer</lticm:property>
        <lticm:property name="privacy_level">public</lticm:property>
        <lticm:property name="domain"><?= htmlspecialchars(parse_url($baseUrl, PHP_URL_HOST)) ?></lticm:property>
        <lticm:options name="course_navigation">
            <lticm:property name="enabled">true</lticm:property>
            <lticm:property name="visibility">members</lticm:property>
            <lticm:property name="default">disabled</lticm:property>
        </lticm:options>
        <lticm:options name="assignment_selection">
            <lticm:property name="enabled">true</lticm:property>
            <lticm:property name="message_type">ContentItemSelectionRequest</lticm:property>
        </lticm:options>
    </blti:extensions>
    <blti:extensions platform="moodle">
        <lticm:property name="tool_id">xapi_viewer</lticm:property>
    </blti:extensions>
    <blti:extensions platform="brightspace.desire2learn.com">
        <lticm:property name="tool_id">xapi_viewer</lticm:property>
    </blti:extensions>
    <blti:vendor>
        <lticp:code><?= htmlspecialchars($CFG->tool_vendor_code ?? 'xapi-viewer') ?></lticp:code>
        <lticp:name><?= htmlspecialchars($CFG->tool_vendor_name ?? 'xAPI Viewer') ?></lticp:name>
    </blti:vendor>
</cartridge_basiclti_link>
    <?php
    exit;
}

/**
 * Output LTI 1.3 Configuration JSON
 */
function outputLTI13JSON($baseUrl, $launchUrl) {
    global $CFG;

    header('Content-Type: application/json');

    $config = [
        "title" => $CFG->tool_title ?? 'xAPI Learning Records Viewer',
        "description" => $CFG->tool_description ?? 'View your xAPI learning records and sync grades.',
        "oidc_initiation_url" => $baseUrl . '/lti13/login.php',
        "target_link_uri" => $launchUrl,
        "scopes" => [
            "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem",
            "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly",
            "https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly",
            "https://purl.imsglobal.org/spec/lti-ags/scope/score"
        ],
        "extensions" => [
            [
                "platform" => "canvas.instructure.com",
                "privacy_level" => "public",
                "settings" => [
                    "placements" => [
                        [
                            "placement" => "assignment_selection",
                            "message_type" => "LtiDeepLinkingRequest"
                        ],
                        [
                            "placement" => "course_navigation",
                            "visibility" => "members",
                            "default" => "disabled"
                        ]
                    ]
                ]
            ]
        ],
        "public_jwk_url" => $baseUrl . '/lti13/jwks.php',
        "claims" => [
            "sub",
            "iss",
            "name",
            "email",
            "given_name",
            "family_name",
            "https://purl.imsglobal.org/spec/lti/claim/context",
            "https://purl.imsglobal.org/spec/lti/claim/resource_link",
            "https://purl.imsglobal.org/spec/lti/claim/roles"
        ]
    ];

    echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Output Canvas-specific LTI 1.1 XML
 */
function outputCanvasXML($baseUrl, $launchUrl) {
    global $CFG;

    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"
    xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"
    xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0"
    xmlns:lticp="http://www.imsglobal.org/xsd/imslticp_v1p0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <blti:title><?= htmlspecialchars($CFG->tool_title ?? 'xAPI Learning Records Viewer') ?></blti:title>
    <blti:description><?= htmlspecialchars($CFG->tool_description ?? 'View your xAPI learning records and sync grades.') ?></blti:description>
    <blti:launch_url><?= htmlspecialchars($launchUrl) ?></blti:launch_url>
    <blti:extensions platform="canvas.instructure.com">
        <lticm:property name="tool_id">xapi_viewer</lticm:property>
        <lticm:property name="privacy_level">public</lticm:property>
        <lticm:property name="domain"><?= htmlspecialchars(parse_url($baseUrl, PHP_URL_HOST)) ?></lticm:property>
        <lticm:options name="course_navigation">
            <lticm:property name="url"><?= htmlspecialchars($launchUrl) ?></lticm:property>
            <lticm:property name="text">My Learning Records</lticm:property>
            <lticm:property name="visibility">members</lticm:property>
            <lticm:property name="default">disabled</lticm:property>
            <lticm:property name="enabled">true</lticm:property>
        </lticm:options>
        <lticm:options name="assignment_selection">
            <lticm:property name="url"><?= htmlspecialchars($launchUrl) ?></lticm:property>
            <lticm:property name="text">xAPI Lab Activity</lticm:property>
            <lticm:property name="enabled">true</lticm:property>
            <lticm:property name="message_type">ContentItemSelectionRequest</lticm:property>
        </lticm:options>
        <lticm:options name="link_selection">
            <lticm:property name="url"><?= htmlspecialchars($launchUrl) ?></lticm:property>
            <lticm:property name="text">xAPI Lab Activity</lticm:property>
            <lticm:property name="enabled">true</lticm:property>
            <lticm:property name="message_type">ContentItemSelectionRequest</lticm:property>
        </lticm:options>
    </blti:extensions>
    <blti:vendor>
        <lticp:code><?= htmlspecialchars($CFG->tool_vendor_code ?? 'xapi-viewer') ?></lticp:code>
        <lticp:name><?= htmlspecialchars($CFG->tool_vendor_name ?? 'xAPI Viewer') ?></lticp:name>
    </blti:vendor>
</cartridge_basiclti_link>
    <?php
    exit;
}

/**
 * Output HTML registration page
 */
function outputHTML($baseUrl, $launchUrl) {
    global $CFG;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LTI Tool Registration - <?= htmlspecialchars($CFG->tool_title ?? 'xAPI Viewer') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; padding: 40px 20px; }
        .container { max-width: 900px; }
        .card { margin-bottom: 20px; }
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.9rem;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .config-value {
            font-family: monospace;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .copy-btn {
            float: right;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?= htmlspecialchars($CFG->tool_title ?? 'xAPI Learning Records Viewer') ?></h1>
            <p class="mb-0"><?= htmlspecialchars($CFG->tool_description ?? 'LTI Tool Registration') ?></p>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">LTI Tool Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <td width="200"><strong>Launch URL:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars($launchUrl) ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Tool Domain:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars(parse_url($baseUrl, PHP_URL_HOST)) ?></span></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">LTI 1.1 Configuration</h5>
                <div>
                    <a href="?format=xml" class="btn btn-sm btn-outline-primary">Download XML</a>
                    <a href="?format=canvas" class="btn btn-sm btn-outline-secondary">Canvas XML</a>
                </div>
            </div>
            <div class="card-body">
                <p>Use these credentials to configure LTI 1.1 in your LMS:</p>
                <table class="table">
                    <tr>
                        <td width="200"><strong>Consumer Key:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars($CFG->lti_consumer_key ?? 'xapi_viewer_key') ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Consumer Secret:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars($CFG->lti_consumer_secret ?? 'xapi_viewer_secret') ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Launch URL:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars($launchUrl) ?></span></td>
                    </tr>
                </table>

                <h6>Configuration XML URL:</h6>
                <div class="code-block">
                    <?= htmlspecialchars($baseUrl . '/register.php?format=xml') ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">LTI 1.3 Configuration</h5>
                <a href="?format=json" class="btn btn-sm btn-outline-primary">Download JSON</a>
            </div>
            <div class="card-body">
                <p>For LTI 1.3 (Advantage), use these endpoints:</p>
                <table class="table">
                    <tr>
                        <td width="200"><strong>OIDC Login URL:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars($baseUrl . '/lti13/login.php') ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Target Link URI:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars($launchUrl) ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Public Keyset URL:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars($baseUrl . '/lti13/jwks.php') ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>Redirect URIs:</strong></td>
                        <td><span class="config-value"><?= htmlspecialchars($launchUrl) ?></span></td>
                    </tr>
                </table>

                <h6>Required Scopes:</h6>
                <ul>
                    <li><code>https://purl.imsglobal.org/spec/lti-ags/scope/lineitem</code></li>
                    <li><code>https://purl.imsglobal.org/spec/lti-ags/scope/score</code></li>
                </ul>

                <h6>Configuration JSON URL:</h6>
                <div class="code-block">
                    <?= htmlspecialchars($baseUrl . '/register.php?format=json') ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Custom Parameters (Optional)</h5>
            </div>
            <div class="card-body">
                <p>You can configure these custom parameters in your LMS to control activity matching:</p>
                <table class="table">
                    <tr>
                        <td width="200"><code>custom_lab_id</code></td>
                        <td>Match a specific xAPI activity by ID substring (e.g., <code>cli-desktop</code>)</td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Platform-Specific Instructions</h5>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs" id="platformTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#canvas">Canvas</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#moodle">Moodle</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#brightspace">Brightspace</button>
                    </li>
                </ul>
                <div class="tab-content pt-3">
                    <div class="tab-pane fade show active" id="canvas">
                        <h6>Canvas LMS</h6>
                        <ol>
                            <li>Go to <strong>Settings &gt; Apps &gt; +App</strong></li>
                            <li>Select <strong>Configuration Type: By URL</strong></li>
                            <li>Enter the Configuration URL: <code><?= htmlspecialchars($baseUrl . '/register.php?format=canvas') ?></code></li>
                            <li>Enter the Consumer Key and Secret shown above</li>
                            <li>Click <strong>Submit</strong></li>
                        </ol>
                    </div>
                    <div class="tab-pane fade" id="moodle">
                        <h6>Moodle</h6>
                        <ol>
                            <li>Go to <strong>Site administration &gt; Plugins &gt; External tool &gt; Manage tools</strong></li>
                            <li>Click <strong>Configure a tool manually</strong></li>
                            <li>Enter the Tool URL: <code><?= htmlspecialchars($launchUrl) ?></code></li>
                            <li>Enter the Consumer Key and Secret shown above</li>
                            <li>Set <strong>Default launch container</strong> to <strong>Embed</strong></li>
                            <li>Under <strong>Privacy</strong>, enable sharing of email and name</li>
                            <li>Save changes</li>
                        </ol>
                    </div>
                    <div class="tab-pane fade" id="brightspace">
                        <h6>Brightspace (D2L)</h6>
                        <ol>
                            <li>Go to <strong>Admin Tools &gt; External Learning Tools</strong></li>
                            <li>Click <strong>New Link</strong></li>
                            <li>Enter the URL: <code><?= htmlspecialchars($launchUrl) ?></code></li>
                            <li>Enter the Key and Secret shown above</li>
                            <li>Enable <strong>Send user email</strong> and <strong>Send user name</strong></li>
                            <li>Enable <strong>Support Outcomes</strong> for grade passback</li>
                            <li>Save</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
    exit;
}
