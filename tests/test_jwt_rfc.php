<?php

/**
 * JWT RFC test vectors.
 *
 * Verifies our hand-rolled JWT functions against the official examples from:
 *  - RFC 7515 Appendix A.1: "Example JWS Using HMAC SHA-256"
 *  - RFC 7519 Section 3.1:  "Example JWT" (same token, referenced from 7515)
 *
 * If these pass, our base64url encoding, HMAC signing, and token structure
 * match the spec byte-for-byte.
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== JWT RFC Test Vectors ===\n\n";

// ---------------------------------------------------------------------------
// The HMAC key from RFC 7515 Appendix A.1 (JWK with kty: "oct")
//
// JWK "k" value (base64url-encoded symmetric key):
//   AyM1SysPpbyDfgZld3umj1qzKObwVMkoqQ-EstJQLr_T-1qS0gZH75aKtMN3Yj0iPS4hcgUuTwjAzZr1Z9CAow
//
// This decodes to 64 raw bytes — the HMAC-SHA256 signing key.
// ---------------------------------------------------------------------------
$jwk_k = 'AyM1SysPpbyDfgZld3umj1qzKObwVMkoqQ-EstJQLr_T-1qS0gZH75aKtMN3Yj0iPS4hcgUuTwjAzZr1Z9CAow';
$key = TestLF::call('base64url_decode', $jwk_k);

// ---------------------------------------------------------------------------
// RFC 7515 A.1 uses JSON with \r\n line breaks and leading spaces.
// These exact octet sequences produce the expected base64url values.
// Our _lf_jwt_encode() uses compact JSON (no whitespace), so it won't produce
// the same base64url strings — but we can still verify the primitives directly.
// ---------------------------------------------------------------------------

// --- Test 1: base64url encoding matches RFC examples ---

// The RFC header is: {"typ":"JWT",\r\n "alg":"HS256"}  (with literal CRLF + space)
$rfc_header_json = "{\"typ\":\"JWT\",\r\n \"alg\":\"HS256\"}";
$rfc_header_b64  = 'eyJ0eXAiOiJKV1QiLA0KICJhbGciOiJIUzI1NiJ9';

assert(
    TestLF::call('base64url_encode', $rfc_header_json) === $rfc_header_b64,
    'base64url of RFC header should match'
);
echo "[PASS] base64url(header) matches RFC 7515 A.1\n";

// The RFC payload is: {"iss":"joe",\r\n "exp":1300819380,\r\n "http://example.com/is_root":true}
$rfc_payload_json = "{\"iss\":\"joe\",\r\n \"exp\":1300819380,\r\n \"http://example.com/is_root\":true}";
$rfc_payload_b64  = 'eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ';

assert(
    TestLF::call('base64url_encode', $rfc_payload_json) === $rfc_payload_b64,
    'base64url of RFC payload should match'
);
echo "[PASS] base64url(payload) matches RFC 7515 A.1\n";

// --- Test 2: base64url round-trip ---

assert(TestLF::call('base64url_decode', $rfc_header_b64) === $rfc_header_json);
assert(TestLF::call('base64url_decode', $rfc_payload_b64) === $rfc_payload_json);
echo "[PASS] base64url round-trip (encode → decode) is lossless\n";

// --- Test 3: HMAC-SHA256 signature matches RFC expected value ---

// The JWS Signing Input is: base64url(header) "." base64url(payload)
$signing_input = "{$rfc_header_b64}.{$rfc_payload_b64}";

// RFC 7515 A.1 expected signature
$rfc_signature = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

$computed_signature = TestLF::call('base64url_encode',
    hash_hmac('sha256', $signing_input, $key, true)
);

assert(
    $computed_signature === $rfc_signature,
    "HMAC signature mismatch: got {$computed_signature}, expected {$rfc_signature}"
);
echo "[PASS] HMAC-SHA256 signature matches RFC 7515 A.1\n";

// --- Test 4: Full compact serialization matches RFC ---

$rfc_compact = 'eyJ0eXAiOiJKV1QiLA0KICJhbGciOiJIUzI1NiJ9'
    . '.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ'
    . '.dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

$assembled = "{$rfc_header_b64}.{$rfc_payload_b64}.{$computed_signature}";
assert($assembled === $rfc_compact, 'Full compact serialization should match');
echo "[PASS] Full JWT compact serialization matches RFC 7515/7519\n";

// --- Test 5: _lf_jwt_decode validates the RFC token ---
// The token's exp (1300819380) is 2011-03-22, so it's expired.
// We test signature validation by temporarily removing the exp check:
// decode the parts manually and verify the signature matches.

$parts = explode('.', $rfc_compact);
[$h, $p, $s] = $parts;
$expected_sig = TestLF::call('base64url_encode', hash_hmac('sha256', "{$h}.{$p}", $key, true));
assert(hash_equals($expected_sig, $s), 'Signature verification of RFC token should pass');
$decoded_payload = json_decode(TestLF::call('base64url_decode', $p), true);
assert($decoded_payload['iss'] === 'joe');
assert($decoded_payload['exp'] === 1300819380);
assert($decoded_payload['http://example.com/is_root'] === true);
echo "[PASS] RFC token signature verifies and claims decode correctly\n";

// --- Test 6: _lf_jwt_decode correctly rejects expired RFC token ---
// The RFC token expired in 2011, so _lf_jwt_decode should return null.
$result = TestLF::call('jwt_decode', $rfc_compact, $key);
assert($result === null, 'Expired RFC token should be rejected by _lf_jwt_decode');
echo "[PASS] _lf_jwt_decode correctly rejects expired RFC token\n";

// --- Test 7: _lf_jwt_encode → _lf_jwt_decode round-trip with RFC key ---
// Prove our encode/decode pair work together using the RFC key material.
$my_payload = ['sub' => 'rfc-test', 'data' => 42, 'exp' => time() + 3600];
$my_token = TestLF::call('jwt_encode', $my_payload, $key);
$decoded = TestLF::call('jwt_decode', $my_token, $key);
assert($decoded !== null, 'Round-trip token should decode');
assert($decoded['sub'] === 'rfc-test');
assert($decoded['data'] === 42);
echo "[PASS] _lf_jwt_encode/_lf_jwt_decode round-trip with RFC key\n";

// --- Test 8: Wrong key is rejected ---
$wrong_key = str_repeat("\x00", 64);
assert(TestLF::call('jwt_decode', $my_token, $wrong_key) === null, 'Wrong key should fail');
echo "[PASS] Wrong key correctly rejected\n";

// --- Test 9: Compact JSON header (our format) vs RFC whitespace header ---
// Our _lf_jwt_encode produces {"alg":"HS256","typ":"JWT"} (compact, no CRLF).
// Both are valid JWT — the spec allows any JSON serialization for the header.
// This test documents that our tokens differ from the RFC example only in
// JSON whitespace, not in cryptographic correctness.
$our_header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
$our_header_b64 = TestLF::call('base64url_encode', $our_header);
assert(
    $our_header_b64 !== $rfc_header_b64,
    'Our compact header differs from RFC whitespace header (expected)'
);
$our_header_decoded = json_decode(TestLF::call('base64url_decode', $our_header_b64), true);
$rfc_header_decoded = json_decode(TestLF::call('base64url_decode', $rfc_header_b64), true);
assert($our_header_decoded['alg'] === $rfc_header_decoded['alg']);
assert($our_header_decoded['typ'] === $rfc_header_decoded['typ']);
echo "[PASS] Our compact JSON and RFC whitespace JSON decode to identical claims\n";

echo "\n=== All RFC tests passed ===\n";
