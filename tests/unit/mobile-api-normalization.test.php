<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bmlhs/api/v1/bootstrap.php';

$cases = [
    ['০১৭০০০০০০০০', '1700000000'],
    ['+880 1700-000000', '1700000000'],
    ['01700 000000', '1700000000'],
];

foreach ($cases as [$input, $expected]) {
    $actual = mobileApiNormalizeMobile($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: mobile normalization mismatch for {$input}\n");
        exit(1);
    }
}

foreach ([
    [str_repeat('A', 43), true],
    [str_repeat('a', 42) . '=', false],
    ['short', false],
    [[], false],
    [null, false],
] as [$refreshToken, $expected]) {
    if (mobileApiRefreshTokenIsValid($refreshToken) !== $expected) {
        fwrite(STDERR, "FAIL: refresh token validation mismatch\n");
        exit(1);
    }
}

$accounts = [
    ['id' => 10, 'pass' => password_hash('another-password', PASSWORD_DEFAULT), 'status' => 1],
    ['id' => 11, 'pass' => password_hash('sibling-password', PASSWORD_DEFAULT), 'status' => 1],
    ['id' => 12, 'pass' => password_hash('inactive-password', PASSWORD_DEFAULT), 'status' => 4],
];
$matched = mobileApiMatchStudentCredentials($accounts, 'sibling-password');
if (($matched['account']['id'] ?? null) !== 11) {
    fwrite(STDERR, "FAIL: matching credentials did not select the correct student account\n");
    exit(1);
}
if (mobileApiMatchStudentCredentials($accounts, 'unknown-password') !== null) {
    fwrite(STDERR, "FAIL: unknown student credentials were accepted\n");
    exit(1);
}
if (mobileApiMatchStudentCredentials([
    ['id' => 13, 'pass' => 'legacy-password', 'status' => 1],
], 'legacy-password')['account']['id'] !== 13) {
    fwrite(STDERR, "FAIL: legacy plaintext student credentials were not accepted\n");
    exit(1);
}

echo "PASS\n";
