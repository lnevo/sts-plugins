<?php
/**
 * Checks and writes track_scale_config.json for the settings page.
 */

/**
 * Errors stop a save (not JSON, not an object). Warnings are shown: keys the plugin
 * does not read, a value of a different kind than its default, and default keys a
 * replaced group leaves out (the file replaces each top-level key whole).
 *
 * @return array{errors: list<string>, warnings: list<string>}
 */
function track_scale_settings_check($text, array $defaults)
{
    $out = ['errors' => [], 'warnings' => []];
    $data = json_decode((string) $text, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $out['errors'][] = 'Not valid JSON: ' . json_last_error_msg();

        return $out;
    }
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        $out['errors'][] = 'The config must be a JSON object ({ ... }).';

        return $out;
    }
    $kind = function ($v) {
        if (is_array($v)) {
            return 'group';
        }
        if (is_int($v) || is_float($v)) {
            return 'number';
        }

        return is_bool($v) ? 'yes/no' : (is_string($v) ? 'text' : 'empty');
    };
    foreach ($data as $key => $value) {
        if (!array_key_exists($key, $defaults)) {
            $out['warnings'][] = $key . ' is not a setting the plugin reads.';
            continue;
        }
        $want = $kind($defaults[$key]);
        $have = $kind($value);
        if ($want !== $have && $want !== 'empty') {
            $out['warnings'][] = $key . ' is ' . $have . '; its default is ' . $want . '.';
            continue;
        }
        if ($want === 'group' && !array_is_list($defaults[$key])) {
            $missing = array_diff(array_keys($defaults[$key]), array_keys($value));
            if ($missing !== []) {
                $out['warnings'][] = $key . ' replaces the default group and leaves out ' . implode(', ', $missing)
                    . '. Those keys are then not set from the defaults; copy them in from "In force" to be sure what is used.';
            }
        }
    }

    return $out;
}

/** Save the text as the config, keeping the previous file as .bak. Returns '' or the problem. */
function track_scale_settings_write($path, $text)
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return 'could not create ' . $dir;
    }
    if (is_file($path)) {
        @copy($path, $path . '.bak');
    }
    $tmp = $path . '.tmp';
    $body = rtrim((string) $text) . "\n";
    if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $path)) {
        @unlink($tmp);

        return 'could not write ' . $path;
    }

    return '';
}
