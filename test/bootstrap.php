<?php

/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright ElephantIO
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

error_reporting(E_ALL);
set_error_handler('error_to_exception');

/**
 * Throw exceptions for all unhandled errors, deprecations and warnings while running the examples.
 *
 * @param int $code
 * @param string $message
 * @param string $filename
 * @param int $line
 * @return bool
 */
function error_to_exception($code, $message, $filename, $line)
{
    if (error_reporting() & $code) {
        throw new ErrorException($message, 0, $code, $filename, $line);
    }

    return true;
}