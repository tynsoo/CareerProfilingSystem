<?php

require_once __DIR__ . '/../lib/ModifiedAES256.php';
require_once __DIR__ . '/../lib/Crypto.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition, string &$failuresRef, string &$passedRef): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

function hex2bin_(string $hex): string
{
    return hex2bin($hex);
}

// ---------------------------------------------------------------------
// Stage 1: round structure correctness, forcing the STANDARD S-box, against
// the official NIST SP 800-38A Appendix F.2.5/F.2.6 AES-256-CBC test vector.
// This proves the key schedule / ShiftRows / MixColumns / CBC chaining are
// correct BEFORE the custom S-box modification is introduced at all.
// ---------------------------------------------------------------------
echo "=== Stage 1: NIST AES-256-CBC test vector (standard S-box forced) ===\n";

// Vectors transcribed directly from NIST SP 800-38A (2001), Appendix F.2.5
// CBC-AES256.Encrypt, fetched and read from the source PDF (not from memory).
$nistKey = hex2bin_('603deb1015ca71be2b73aef0857d77811f352c073b6108d72d9810a30914dff4');
$nistIv  = hex2bin_('000102030405060708090a0b0c0d0e0f');
$nistPlaintextBlocks = [
    hex2bin_('6bc1bee22e409f96e93d7e117393172a'),
    hex2bin_('ae2d8a571e03ac9c9eb76fac45af8e51'),
    hex2bin_('30c81c46a35ce411e5fbc1191a0a52ef'),
    hex2bin_('f69f2445df4f9b17ad2b417be66c3710'),
];
$nistExpectedHex = [
    'f58c4c04d6e5f1ba779eabfb5f7bfbd6',
    '9cfc4e967edb808d679f777bc6702c7d',
    '39f23369a9d9bacfa530e26304231461',
    'b2eb05e2c39be9fcda6c19078c6a9d1b',
];

$standardSbox = ModifiedAES256::standardSbox();
$standardInvSbox = ModifiedAES256::invertSBox($standardSbox);

$cipher = new ModifiedAES256($nistKey);
$cipher->overrideSboxForTesting($standardSbox, $standardInvSbox);

$prev = $nistIv;
$actualCipherBlocks = [];
foreach ($nistPlaintextBlocks as $block) {
    $xored = $block ^ $prev;
    $enc = $cipher->encryptBlock($xored);
    $actualCipherBlocks[] = $enc;
    $prev = $enc;
}

foreach ($actualCipherBlocks as $i => $enc) {
    check(
        "NIST CBC block " . ($i + 1) . " ciphertext matches",
        bin2hex($enc) === $nistExpectedHex[$i],
        $failures,
        $passed
    );
}

// Stage 1b: internal consistency — decrypt what we just encrypted (standard
// S-box) and confirm we recover the exact original NIST plaintext blocks.
$prev = $nistIv;
foreach ($actualCipherBlocks as $i => $enc) {
    $dec = $cipher->decryptBlock($enc) ^ $prev;
    check(
        "NIST CBC block " . ($i + 1) . " decrypts back to original plaintext",
        $dec === $nistPlaintextBlocks[$i],
        $failures,
        $passed
    );
    $prev = $enc;
}

// ---------------------------------------------------------------------
// Stage 2: key-dependent S-box properties
// ---------------------------------------------------------------------
echo "\n=== Stage 2: key-dependent S-box properties ===\n";

$keyA = random_bytes(32);
$keyB = random_bytes(32);

$sboxA1 = ModifiedAES256::deriveKeyDependentSBox($keyA);
$sboxA2 = ModifiedAES256::deriveKeyDependentSBox($keyA);
$sboxB = ModifiedAES256::deriveKeyDependentSBox($keyB);

$sortedA1 = $sboxA1;
sort($sortedA1);
check('S-box is bijective (a permutation of 0..255)', $sortedA1 === range(0, 255), $failures, $passed);

$invA1 = ModifiedAES256::invertSBox($sboxA1);
$invertible = true;
for ($x = 0; $x < 256; $x++) {
    if ($invA1[$sboxA1[$x]] !== $x) {
        $invertible = false;
        break;
    }
}
check('S-box is correctly invertible (invSbox[sbox[x]] == x for all x)', $invertible, $failures, $passed);

check('Same key produces identical S-box across calls (deterministic)', $sboxA1 === $sboxA2, $failures, $passed);
check('Different keys produce different S-boxes (key-sensitive)', $sboxA1 !== $sboxB, $failures, $passed);
check('Key-dependent S-box differs from the standard AES S-box', $sboxA1 !== $standardSbox, $failures, $passed);

// ---------------------------------------------------------------------
// Stage 3: full Crypto (CBC + PKCS#7 + key-dependent S-box) round-trips
// ---------------------------------------------------------------------
echo "\n=== Stage 3: full Crypto round-trip (real key-dependent S-box) ===\n";

putenv('APP_AES_KEY=' . base64_encode(random_bytes(32)));

$testStrings = [
    '',
    'a',
    str_repeat('x', 15),
    str_repeat('x', 16),
    str_repeat('x', 17),
    str_repeat('x', 100),
    'Dela Cruz, Juan Miguel',
    "Multi\nline\nvalue with special chars: äöü 日本語 🎓",
];

foreach ($testStrings as $i => $plain) {
    $enc = Crypto::enc($plain);
    $dec = Crypto::dec($enc);
    check("Round-trip #" . ($i + 1) . " (len=" . strlen($plain) . ") matches original", $dec === $plain, $failures, $passed);
}

// Same plaintext encrypted twice must produce different ciphertext (random IV per call)
$e1 = Crypto::enc('same plaintext');
$e2 = Crypto::enc('same plaintext');
check('Same plaintext produces different ciphertext across calls (random IV)', $e1 !== $e2, $failures, $passed);
check('...but both still decrypt to the same original value', Crypto::dec($e1) === Crypto::dec($e2), $failures, $passed);

// ---------------------------------------------------------------------
echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
