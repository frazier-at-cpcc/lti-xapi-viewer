<?php
/**
 * LTI 1.3 JSON Web Key Set (JWKS) Endpoint
 *
 * Provides the tool's public keys for platforms to verify JWTs signed by this tool.
 * Used for LTI Advantage services like Assignment and Grade Services.
 */

require_once __DIR__ . '/../app.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

// Check if we have a configured private key
$privateKeyPath = $CFG->lti13_private_key ?? '';
$privateKey = null;

if (!empty($privateKeyPath) && file_exists($privateKeyPath)) {
    $privateKey = file_get_contents($privateKeyPath);
} elseif (!empty($privateKeyPath) && strpos($privateKeyPath, '-----BEGIN') !== false) {
    // Key is provided directly as a string
    $privateKey = $privateKeyPath;
}

// If no private key configured, return empty keyset
if (empty($privateKey)) {
    echo json_encode(['keys' => []], JSON_PRETTY_PRINT);
    exit;
}

// Extract public key from private key
$keyResource = openssl_pkey_get_private($privateKey);
if (!$keyResource) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid private key'], JSON_PRETTY_PRINT);
    exit;
}

$keyDetails = openssl_pkey_get_details($keyResource);
if (!$keyDetails || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA) {
    http_response_code(500);
    echo json_encode(['error' => 'Only RSA keys are supported'], JSON_PRETTY_PRINT);
    exit;
}

// Convert RSA key components to base64url encoding
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Build JWK from RSA key
$n = base64url_encode($keyDetails['rsa']['n']);
$e = base64url_encode($keyDetails['rsa']['e']);

// Generate key ID from public key hash
$kid = substr(hash('sha256', $keyDetails['key']), 0, 16);

$jwk = [
    'kty' => 'RSA',
    'alg' => 'RS256',
    'use' => 'sig',
    'kid' => $kid,
    'n' => $n,
    'e' => $e
];

$jwks = [
    'keys' => [$jwk]
];

echo json_encode($jwks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
