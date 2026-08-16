<?php

require_once __DIR__ . '/ModifiedAES256.php';
require_once __DIR__ . '/../config/env.php';

/**
 * Public encryption facade. CBC mode + PKCS#7 padding wrapped around
 * ModifiedAES256's block cipher. Storage format (before base64):
 *   version_byte(1) + iv(16 bytes) + ciphertext
 */
class Crypto
{
    private const BLOCK_SIZE = 16;
    private const VERSION = 1;

    private static ?ModifiedAES256 $cipher = null;

    public static function enc(string $plaintext): string
    {
        $iv = random_bytes(self::BLOCK_SIZE);
        $padded = self::pkcs7Pad($plaintext);
        $cipher = self::cipher();

        $ciphertext = '';
        $prev = $iv;
        foreach (str_split($padded, self::BLOCK_SIZE) as $block) {
            $xored = $block ^ $prev;
            $encrypted = $cipher->encryptBlock($xored);
            $ciphertext .= $encrypted;
            $prev = $encrypted;
        }

        return base64_encode(chr(self::VERSION) . $iv . $ciphertext);
    }

    public static function dec(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 1 + self::BLOCK_SIZE) {
            throw new RuntimeException('Malformed ciphertext blob');
        }

        $version = ord($raw[0]);
        if ($version !== self::VERSION) {
            throw new RuntimeException("Unsupported ciphertext version: $version");
        }

        $iv = substr($raw, 1, self::BLOCK_SIZE);
        $ciphertext = substr($raw, 1 + self::BLOCK_SIZE);
        if (strlen($ciphertext) % self::BLOCK_SIZE !== 0) {
            throw new RuntimeException('Ciphertext length is not a multiple of the block size');
        }

        $cipher = self::cipher();
        $padded = '';
        $prev = $iv;
        foreach (str_split($ciphertext, self::BLOCK_SIZE) as $block) {
            $decrypted = $cipher->decryptBlock($block);
            $padded .= $decrypted ^ $prev;
            $prev = $block;
        }

        return self::pkcs7Unpad($padded);
    }

    private static function cipher(): ModifiedAES256
    {
        if (self::$cipher === null) {
            $key = base64_decode((string) getenv('APP_AES_KEY'), true);
            if ($key === false || strlen($key) !== 32) {
                throw new RuntimeException('APP_AES_KEY must be set to a base64-encoded 32-byte key');
            }
            self::$cipher = new ModifiedAES256($key);
        }
        return self::$cipher;
    }

    private static function pkcs7Pad(string $data): string
    {
        $padLen = self::BLOCK_SIZE - (strlen($data) % self::BLOCK_SIZE);
        return $data . str_repeat(chr($padLen), $padLen);
    }

    private static function pkcs7Unpad(string $data): string
    {
        $len = strlen($data);
        if ($len === 0) {
            throw new RuntimeException('Cannot unpad empty data');
        }
        $padLen = ord($data[$len - 1]);
        if ($padLen < 1 || $padLen > self::BLOCK_SIZE || $padLen > $len) {
            throw new RuntimeException('Invalid PKCS#7 padding');
        }
        for ($i = $len - $padLen; $i < $len; $i++) {
            if (ord($data[$i]) !== $padLen) {
                throw new RuntimeException('Invalid PKCS#7 padding');
            }
        }
        return substr($data, 0, $len - $padLen);
    }
}
