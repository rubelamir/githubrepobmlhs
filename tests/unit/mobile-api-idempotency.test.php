<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bmlhs/api/v1/bootstrap.php';

foreach ([
    ['123e4567-e89b-42d3-a456-426614174000', true],
    ['not-a-uuid', false],
    ['123e4567-e89b-02d3-a456-426614174000', false],
    [null, false],
    [[], false],
] as [$key, $expected]) {
    if (mobileApiIdempotencyKeyIsValid($key) !== $expected) {
        fwrite(STDERR, "FAIL: idempotency key validation mismatch\n");
        exit(1);
    }
}

$jsonA = mobileApiCanonicalizeRequestValue(json_decode('{"z":1,"a":{"y":2,"b":3}}', true));
$jsonB = mobileApiCanonicalizeRequestValue(json_decode('{"a":{"b":3,"y":2},"z":1}', true));
if ($jsonA !== $jsonB) {
    fwrite(STDERR, "FAIL: idempotency canonicalization depends on JSON object key order\n");
    exit(1);
}
if (
    mobileApiCanonicalizeRequestValue(['first', 'second'])
    === mobileApiCanonicalizeRequestValue(['second', 'first'])
) {
    fwrite(STDERR, "FAIL: idempotency canonicalization ignored JSON array order\n");
    exit(1);
}

$adminEndpoints = glob(__DIR__ . '/../../bmlhs/api/v1/admin/*.php');
foreach ($adminEndpoints ?: [] as $endpoint) {
    if (basename($endpoint) === 'structure_helpers.php') {
        continue;
    }
    $source = file_get_contents($endpoint);
    if (!is_string($source) || !str_contains($source, 'mobileApiBeginIdempotentWrite($db, $identity)')) {
        fwrite(STDERR, 'FAIL: Admin API endpoint lacks idempotent write handling: ' . basename($endpoint) . "\n");
        exit(1);
    }
}

echo "PASS\n";
