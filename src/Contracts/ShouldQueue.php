<?php
/**
 * Marker contract for a job that runs in the background queue instead of inline.
 * The queue refuses to restore a stored payload whose class does not implement it, so this
 * interface is also what stops a tampered row from choosing an arbitrary class to run.
 *
 * @package    Framework
 * @subpackage Contracts
 * @since      3.2.0
 */
namespace Framework\Contracts;

defined('ABSPATH') || exit;

interface ShouldQueue
{
    //
}
