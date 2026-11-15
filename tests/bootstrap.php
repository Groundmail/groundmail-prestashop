<?php

/**
 * Loads the module's API and mapping classes outside PrestaShop. SwiftMailer
 * (PrestaShop 8) and Symfony Mime (PrestaShop 9) come from Composer, the same
 * libraries PrestaShop bundles.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

define('_PS_VERSION_', '9.0.0');

require dirname(__DIR__) . '/classes/GroundmailApi.php';
require dirname(__DIR__) . '/classes/GroundmailMessage.php';
require dirname(__DIR__) . '/classes/GroundmailNativeMessage.php';
