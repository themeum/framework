<?php

namespace Framework\Filesystem;

/**
 * Shadows the real `is_uploaded_file()` for `Framework\Filesystem\UploadedFile::is_valid()`.
 *
 * PHP's own `is_uploaded_file()` only ever returns true for a file the SAPI actually tracked as
 * part of an HTTP upload, which never happens under the CLI test runner. Tests opt a path into
 * "genuinely uploaded" by adding it to `$GLOBALS['framework_test_uploaded_files']`; anything else
 * falls through to the real function (i.e. stays false), so the safety check is not weakened.
 */
if (!function_exists(__NAMESPACE__ . '\\is_uploaded_file')) {
    function is_uploaded_file($filename)
    {
        return in_array($filename, $GLOBALS['framework_test_uploaded_files'] ?? [], true)
            || \is_uploaded_file($filename);
    }
}
