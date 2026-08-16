<?php

/**
 * From-scratch AES-256 (FIPS-197) with one deliberate modification: the main
 * cipher round's SubBytes/InvSubBytes step uses a key-dependent S-box instead
 * of AES's fixed, public S-box. Everything else — key schedule, ShiftRows,
 * MixColumns, AddRoundKey — is standard, unmodified AES-256.
 *
 * Critically, the key schedule's internal SubWord step still uses the
 * STANDARD S-box (self::STANDARD_SBOX), never the key-dependent one — mixing
 * these two up is the single easiest way to silently break this cipher.
 */
class ModifiedAES256
{
    private const NK = 8;  // key length in 32-bit words (256 bits)
    private const NR = 14; // number of rounds for AES-256
    private const NB = 4;  // block size in words (fixed at 4 for AES)

    private const STANDARD_SBOX = [
        0x63,0x7c,0x77,0x7b,0xf2,0x6b,0x6f,0xc5,0x30,0x01,0x67,0x2b,0xfe,0xd7,0xab,0x76,
        0xca,0x82,0xc9,0x7d,0xfa,0x59,0x47,0xf0,0xad,0xd4,0xa2,0xaf,0x9c,0xa4,0x72,0xc0,
        0xb7,0xfd,0x93,0x26,0x36,0x3f,0xf7,0xcc,0x34,0xa5,0xe5,0xf1,0x71,0xd8,0x31,0x15,
        0x04,0xc7,0x23,0xc3,0x18,0x96,0x05,0x9a,0x07,0x12,0x80,0xe2,0xeb,0x27,0xb2,0x75,
        0x09,0x83,0x2c,0x1a,0x1b,0x6e,0x5a,0xa0,0x52,0x3b,0xd6,0xb3,0x29,0xe3,0x2f,0x84,
        0x53,0xd1,0x00,0xed,0x20,0xfc,0xb1,0x5b,0x6a,0xcb,0xbe,0x39,0x4a,0x4c,0x58,0xcf,
        0xd0,0xef,0xaa,0xfb,0x43,0x4d,0x33,0x85,0x45,0xf9,0x02,0x7f,0x50,0x3c,0x9f,0xa8,
        0x51,0xa3,0x40,0x8f,0x92,0x9d,0x38,0xf5,0xbc,0xb6,0xda,0x21,0x10,0xff,0xf3,0xd2,
        0xcd,0x0c,0x13,0xec,0x5f,0x97,0x44,0x17,0xc4,0xa7,0x7e,0x3d,0x64,0x5d,0x19,0x73,
        0x60,0x81,0x4f,0xdc,0x22,0x2a,0x90,0x88,0x46,0xee,0xb8,0x14,0xde,0x5e,0x0b,0xdb,
        0xe0,0x32,0x3a,0x0a,0x49,0x06,0x24,0x5c,0xc2,0xd3,0xac,0x62,0x91,0x95,0xe4,0x79,
        0xe7,0xc8,0x37,0x6d,0x8d,0xd5,0x4e,0xa9,0x6c,0x56,0xf4,0xea,0x65,0x7a,0xae,0x08,
        0xba,0x78,0x25,0x2e,0x1c,0xa6,0xb4,0xc6,0xe8,0xdd,0x74,0x1f,0x4b,0xbd,0x8b,0x8a,
        0x70,0x3e,0xb5,0x66,0x48,0x03,0xf6,0x0e,0x61,0x35,0x57,0xb9,0x86,0xc1,0x1d,0x9e,
        0xe1,0xf8,0x98,0x11,0x69,0xd9,0x8e,0x94,0x9b,0x1e,0x87,0xe9,0xce,0x55,0x28,0xdf,
        0x8c,0xa1,0x89,0x0d,0xbf,0xe6,0x42,0x68,0x41,0x99,0x2d,0x0f,0xb0,0x54,0xbb,0x16,
    ];

    private const RCON = [
        0x00000000, 0x01000000, 0x02000000, 0x04000000, 0x08000000, 0x10000000,
        0x20000000, 0x40000000, 0x80000000, 0x1b000000, 0x36000000,
    ];

    /** @var int[] 4*(NR+1) 32-bit words, standard AES-256 key expansion */
    private array $roundKeys;

    /** @var int[256] key-dependent forward S-box */
    private array $sbox;

    /** @var int[256] key-dependent inverse S-box, invSbox[sbox[x]] === x */
    private array $invSbox;

    public function __construct(string $key)
    {
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('AES-256 key must be exactly 32 bytes');
        }
        $this->sbox = self::deriveKeyDependentSBox($key);
        $this->invSbox = self::invertSBox($this->sbox);
        $this->roundKeys = $this->keyExpansion($key);
    }

    // ---- Key-dependent S-box -------------------------------------------------

    /**
     * Deterministically derives a bijective 256-entry substitution table from
     * the key via HKDF (RFC 5869) driving a Fisher-Yates shuffle. Same key
     * always produces the same S-box (required so decryption can reproduce
     * it); different keys produce different S-boxes.
     */
    public static function deriveKeyDependentSBox(string $key): array
    {
        $sbox = range(0, 255);
        $stream = hash_hkdf('sha256', $key, 2048, 'ProfilePath-SBox-v1');
        $pos = 0;
        $extCounter = 0;

        $nextByte = function () use (&$stream, &$pos, &$extCounter, $key): int {
            if ($pos >= strlen($stream)) {
                $extCounter++;
                $stream = hash_hkdf('sha256', $key, 2048, 'ProfilePath-SBox-v1-ext' . $extCounter);
                $pos = 0;
            }
            return ord($stream[$pos++]);
        };

        for ($i = 255; $i > 0; $i--) {
            $limit = 256 - (256 % ($i + 1)); // reject bytes >= limit to avoid modulo bias
            do {
                $b = $nextByte();
            } while ($b >= $limit);
            $j = $b % ($i + 1);
            [$sbox[$i], $sbox[$j]] = [$sbox[$j], $sbox[$i]];
        }

        return $sbox;
    }

    public static function invertSBox(array $sbox): array
    {
        $inv = array_fill(0, 256, 0);
        foreach ($sbox as $x => $y) {
            $inv[$y] = $x;
        }
        return $inv;
    }

    // ---- Key schedule (standard AES-256, uses STANDARD_SBOX only) -----------

    private function keyExpansion(string $key): array
    {
        $w = [];
        for ($i = 0; $i < self::NK; $i++) {
            $w[$i] = (ord($key[4 * $i]) << 24) | (ord($key[4 * $i + 1]) << 16)
                | (ord($key[4 * $i + 2]) << 8) | ord($key[4 * $i + 3]);
        }

        $totalWords = self::NB * (self::NR + 1);
        for ($i = self::NK; $i < $totalWords; $i++) {
            $temp = $w[$i - 1];
            if ($i % self::NK === 0) {
                $temp = $this->subWord($this->rotWord($temp)) ^ self::RCON[$i / self::NK];
            } elseif (self::NK > 6 && $i % self::NK === 4) {
                $temp = $this->subWord($temp);
            }
            $w[$i] = $w[$i - self::NK] ^ $temp;
        }

        return $w;
    }

    private function rotWord(int $word): int
    {
        return (($word << 8) | ($word >> 24)) & 0xFFFFFFFF;
    }

    private function subWord(int $word): int
    {
        $b0 = self::STANDARD_SBOX[($word >> 24) & 0xFF];
        $b1 = self::STANDARD_SBOX[($word >> 16) & 0xFF];
        $b2 = self::STANDARD_SBOX[($word >> 8) & 0xFF];
        $b3 = self::STANDARD_SBOX[$word & 0xFF];
        return ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
    }

    // ---- Block cipher (the modified round function) --------------------------

    public function encryptBlock(string $block): string
    {
        $state = $this->bytesToState($block);
        $state = $this->addRoundKey($state, 0);

        for ($round = 1; $round < self::NR; $round++) {
            $state = $this->subBytes($state, $this->sbox);
            $state = $this->shiftRows($state);
            $state = $this->mixColumns($state);
            $state = $this->addRoundKey($state, $round);
        }

        $state = $this->subBytes($state, $this->sbox);
        $state = $this->shiftRows($state);
        $state = $this->addRoundKey($state, self::NR);

        return $this->stateToBytes($state);
    }

    public function decryptBlock(string $block): string
    {
        $state = $this->bytesToState($block);
        $state = $this->addRoundKey($state, self::NR);

        for ($round = self::NR - 1; $round >= 1; $round--) {
            $state = $this->invShiftRows($state);
            $state = $this->subBytes($state, $this->invSbox);
            $state = $this->addRoundKey($state, $round);
            $state = $this->invMixColumns($state);
        }

        $state = $this->invShiftRows($state);
        $state = $this->subBytes($state, $this->invSbox);
        $state = $this->addRoundKey($state, 0);

        return $this->stateToBytes($state);
    }

    // ---- Round steps -----------------------------------------------------

    /** @return int[4][4] state[row][col], column-major per FIPS-197 */
    private function bytesToState(string $block): array
    {
        $state = [[], [], [], []];
        for ($i = 0; $i < 16; $i++) {
            $state[$i % 4][intdiv($i, 4)] = ord($block[$i]);
        }
        return $state;
    }

    private function stateToBytes(array $state): string
    {
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= chr($state[$i % 4][intdiv($i, 4)]);
        }
        return $out;
    }

    private function addRoundKey(array $state, int $round): array
    {
        for ($c = 0; $c < 4; $c++) {
            $word = $this->roundKeys[$round * 4 + $c];
            $state[0][$c] ^= ($word >> 24) & 0xFF;
            $state[1][$c] ^= ($word >> 16) & 0xFF;
            $state[2][$c] ^= ($word >> 8) & 0xFF;
            $state[3][$c] ^= $word & 0xFF;
        }
        return $state;
    }

    private function subBytes(array $state, array $box): array
    {
        for ($r = 0; $r < 4; $r++) {
            for ($c = 0; $c < 4; $c++) {
                $state[$r][$c] = $box[$state[$r][$c]];
            }
        }
        return $state;
    }

    private function shiftRows(array $state): array
    {
        for ($r = 1; $r < 4; $r++) {
            $row = $state[$r];
            for ($c = 0; $c < 4; $c++) {
                $state[$r][$c] = $row[($c + $r) % 4];
            }
        }
        return $state;
    }

    private function invShiftRows(array $state): array
    {
        for ($r = 1; $r < 4; $r++) {
            $row = $state[$r];
            for ($c = 0; $c < 4; $c++) {
                $state[$r][$c] = $row[($c - $r + 4) % 4];
            }
        }
        return $state;
    }

    private function mixColumns(array $state): array
    {
        for ($c = 0; $c < 4; $c++) {
            $a0 = $state[0][$c]; $a1 = $state[1][$c]; $a2 = $state[2][$c]; $a3 = $state[3][$c];
            $state[0][$c] = self::gmul($a0, 2) ^ self::gmul($a1, 3) ^ $a2 ^ $a3;
            $state[1][$c] = $a0 ^ self::gmul($a1, 2) ^ self::gmul($a2, 3) ^ $a3;
            $state[2][$c] = $a0 ^ $a1 ^ self::gmul($a2, 2) ^ self::gmul($a3, 3);
            $state[3][$c] = self::gmul($a0, 3) ^ $a1 ^ $a2 ^ self::gmul($a3, 2);
        }
        return $state;
    }

    private function invMixColumns(array $state): array
    {
        for ($c = 0; $c < 4; $c++) {
            $a0 = $state[0][$c]; $a1 = $state[1][$c]; $a2 = $state[2][$c]; $a3 = $state[3][$c];
            $state[0][$c] = self::gmul($a0, 0x0e) ^ self::gmul($a1, 0x0b) ^ self::gmul($a2, 0x0d) ^ self::gmul($a3, 0x09);
            $state[1][$c] = self::gmul($a0, 0x09) ^ self::gmul($a1, 0x0e) ^ self::gmul($a2, 0x0b) ^ self::gmul($a3, 0x0d);
            $state[2][$c] = self::gmul($a0, 0x0d) ^ self::gmul($a1, 0x09) ^ self::gmul($a2, 0x0e) ^ self::gmul($a3, 0x0b);
            $state[3][$c] = self::gmul($a0, 0x0b) ^ self::gmul($a1, 0x0d) ^ self::gmul($a2, 0x09) ^ self::gmul($a3, 0x0e);
        }
        return $state;
    }

    /** Multiplication in GF(2^8) with the AES reduction polynomial x^8+x^4+x^3+x+1 (0x11B). */
    private static function gmul(int $a, int $b): int
    {
        $p = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($b & 1) {
                $p ^= $a;
            }
            $hiBitSet = $a & 0x80;
            $a = ($a << 1) & 0xFF;
            if ($hiBitSet) {
                $a ^= 0x1B;
            }
            $b >>= 1;
        }
        return $p & 0xFF;
    }

    // ---- Exposed for tests only -------------------------------------------

    public function getSbox(): array
    {
        return $this->sbox;
    }

    public function getInvSbox(): array
    {
        return $this->invSbox;
    }

    /**
     * Test-only: swaps in a different (e.g. the standard) S-box after
     * construction, without touching the already-computed round keys. Used
     * to validate the round structure against official NIST test vectors
     * independent of the key-dependent S-box modification.
     */
    public function overrideSboxForTesting(array $sbox, array $invSbox): void
    {
        $this->sbox = $sbox;
        $this->invSbox = $invSbox;
    }

    public static function standardSbox(): array
    {
        return self::STANDARD_SBOX;
    }
}
