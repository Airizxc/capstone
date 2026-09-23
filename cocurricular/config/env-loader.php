<?php
/**
 * Co-Curricular Module — Lightweight Environment Variable (.env) Loader
 *
 * Automatically locates and loads key-value pairs from .env files into
 * getenv(), $_ENV, and $_SERVER without external dependencies.
 */
declare(strict_types=1);

if (!function_exists('cocurricular_load_env')) {
    /**
     * Parse and load an environment file if it exists.
     *
     * @param string|null $customPath Optional explicit path to .env file.
     * @return bool True if a .env file was found and parsed, false otherwise.
     */
    function cocurricular_load_env(?string $customPath = null): bool
    {
        static $loadedPaths = [];

        $candidatePaths = [];

        if ($customPath !== null && $customPath !== '') {
            $candidatePaths[] = $customPath;
        }

        // Candidate 1: Inside modules/cocurricular/.env or cocurricular/.env
        $candidatePaths[] = dirname(__DIR__) . '/.env';

        // Candidate 2: Inside modules/cocurricular/config/.env
        $candidatePaths[] = __DIR__ . '/.env';

        // Candidate 3: Root SMS2 system .env (3 levels up if in modules/cocurricular/config)
        $candidatePaths[] = dirname(__DIR__, 3) . '/.env';

        // Candidate 4: Root repository .env (2 levels up if in cocurricular/config)
        $candidatePaths[] = dirname(__DIR__, 2) . '/.env';

        $found = false;

        foreach ($candidatePaths as $path) {
            $realPath = realpath($path);
            if ($realPath !== false && is_file($realPath) && is_readable($realPath)) {
                if (isset($loadedPaths[$realPath])) {
                    $found = true;
                    continue;
                }

                $lines = file($realPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    continue;
                }

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Skip empty lines or comment lines
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    // Must contain an equals sign
                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }

                    $key = trim(substr($line, 0, $pos));
                    $value = trim(substr($line, $pos + 1));

                    if ($key === '') {
                        continue;
                    }

                    // Strip surrounding quotes if present
                    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                        $value = substr($value, 1, -1);
                    }

                    // Populate getenv, $_ENV, and $_SERVER
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }

                $loadedPaths[$realPath] = true;
                $found = true;
            }
        }

        return $found;
    }
}

if (!function_exists('cocurricular_env')) {
    /**
     * Safely retrieve an environment variable with optional default fallback.
     *
     * @param string $key Variable name
     * @param string|null $default Fallback value if unset or empty
     * @return string|null
     */
    function cocurricular_env(string $key, ?string $default = null): ?string
    {
        // 1. Check getenv()
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string) $val;
        }

        // 2. Check $_ENV
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        // 3. Check $_SERVER
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        // 4. Check global system function sms2_env if present
        if (function_exists('sms2_env')) {
            $smsVal = sms2_env($key);
            if ($smsVal !== null && $smsVal !== '') {
                return (string) $smsVal;
            }
        }

        return $default;
    }
}

// Auto-load on include
cocurricular_load_env();
