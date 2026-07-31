<?php
/**
 * Optional plain-PHP loader for the ALTCHA protocol core.
 *
 * The four class files below have no WordPress dependencies and no dependency on
 * any autoloader, so they can be loaded either by the plugin's own autoloader or
 * by requiring this file. It exists mainly so the standalone unit/differential
 * test harness in `tools/oracle/` can bootstrap the core without WordPress.
 *
 * @package Kreiswolke\FormAntispam
 */

// phpcs:disable Squiz.Commenting.FileComment.Missing -- False positive: the file comment above is present, but the sniff does not recognise it on a file whose first statement is require_once rather than a namespace or class declaration.

require_once __DIR__ . '/class-protocol.php';
require_once __DIR__ . '/class-verification.php';
require_once __DIR__ . '/class-challenge.php';
require_once __DIR__ . '/class-verifier.php';
