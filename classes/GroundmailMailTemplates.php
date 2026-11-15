<?php

/**
 * Groundmail for PrestaShop
 *
 * @author    Groundmail
 * @copyright Groundmail
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Finds email templates exactly where the installed PrestaShop version looks
 * for them (theme, parent theme, core, then module folders on 9.2+). Never
 * instantiated.
 */
class GroundmailMailTemplates extends Mail
{
    /**
     * @param string $isoTemplate e.g. en/order_conf
     * @param string|false $moduleName
     * @param object $theme
     *
     * @return string
     */
    public static function basePath($isoTemplate, $moduleName, $theme)
    {
        return (string) parent::getTemplateBasePath($isoTemplate, $moduleName, $theme);
    }
}
