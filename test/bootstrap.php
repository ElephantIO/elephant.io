<?php

error_reporting(E_ALL);
set_error_handler('error_to_exception');

/**
 * Throw exceptions for all unhandled errors, deprecations and warnings while running the examples.
 *
 * @param int $code
 * @param string $message
 * @param string $filename
 * @param int $line
 * @return void
 */
function error_to_exception($code, $message, $filename, $line) {
    if (error_reporting() & $code) {
        throw new ErrorException($message, 0, $code, $filename, $line);
    }
}