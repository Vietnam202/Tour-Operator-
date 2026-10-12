<?php
declare(strict_types=1);

/**
 * Read an immutable identity generated into the exact-SHA staging ZIP.
 * This is a build/version hint for operators, not cryptographic attestation
 * of files already extracted to a possibly mutable webroot.
 */
final class RuntimeBuildIdentity
{
    public static function read(string $file): ?array
    {
        if (is_link($file) || !is_file($file) || filesize($file) > 4096) {
            return null;
        }
        $bytes = file_get_contents($file);
        if (!is_string($bytes) || strlen($bytes) < 50) {
            return null;
        }
        $value = json_decode($bytes, true);
        if (!is_array($value)
            || ($value['schema'] ?? null) !== 'vta.rc62.runtime.identity.v1'
            || ($value['release_type'] ?? null) !== 'STAGING_CANDIDATE_NOT_PRODUCTION'
            || !preg_match('/^[a-f0-9]{40}$/D', (string)($value['source_commit'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/D', (string)($value['source_inventory_sha256'] ?? ''))
            || !is_int($value['source_file_count'] ?? null)
            || $value['source_file_count'] < 75
            || $value['source_file_count'] > 2000
        ) {
            return null;
        }
        return [
            'status' => 'PINNED_PACKAGE',
            'source_commit' => $value['source_commit'],
            'source_inventory_sha256' => $value['source_inventory_sha256'],
            'source_file_count' => $value['source_file_count'],
        ];
    }
}
