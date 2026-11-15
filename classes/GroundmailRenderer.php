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
 * Builds the email Mail::send() would send, from the arguments of the
 * actionEmailSendBefore hook: same checks, template lookup, template
 * variables, logo, attachments and module hooks
 * (sendMailAlterTemplateVars, actionEmailAddBeforeContent,
 * actionEmailAddAfterContent, actionGetExtraMailTemplateVars,
 * actionMailAlterMessageBeforeSend). It follows classes/Mail.php of
 * PrestaShop 8.0 to 9.x step by step, without the transport.
 */
class GroundmailRenderer
{
    /**
     * @param array $params actionEmailSendBefore parameters
     *
     * @return array|null ['message' => object, 'template' => string, 'id_lang' => int, 'id_shop' => int],
     *                    or null when PrestaShop should handle the call itself (it will refuse it and log why)
     */
    public static function build(array $params)
    {
        $idLang = isset($params['idLang']) ? $params['idLang'] : null;
        $template = isset($params['template']) ? $params['template'] : '';
        $subject = isset($params['subject']) ? $params['subject'] : '';
        $templateVars = isset($params['templateVars']) ? $params['templateVars'] : [];
        $to = isset($params['to']) ? $params['to'] : null;
        $toName = isset($params['toName']) ? $params['toName'] : null;
        $from = isset($params['from']) ? $params['from'] : null;
        $fromName = isset($params['fromName']) ? $params['fromName'] : null;
        $fileAttachment = isset($params['fileAttachment']) ? $params['fileAttachment'] : null;
        $templatePath = isset($params['templatePath']) ? $params['templatePath'] : _PS_MAIL_DIR_;
        $idShop = isset($params['idShop']) ? $params['idShop'] : null;
        $bcc = isset($params['bcc']) ? $params['bcc'] : null;
        $replyTo = isset($params['replyTo']) ? $params['replyTo'] : null;
        // PrestaShop 8 doesn't pass the Reply-To name to the hook.
        $replyToName = isset($params['replyToName']) ? $params['replyToName'] : null;

        if (!$idShop) {
            $idShop = Context::getContext()->shop->id;
        }
        if (!is_numeric($idShop) || !$idShop) {
            return null;
        }
        $shop = new Shop((int) $idShop);

        $configuration = Configuration::getMultiple(
            ['PS_SHOP_EMAIL', 'PS_SHOP_NAME', 'PS_MAIL_METHOD', 'PS_MAIL_TYPE'],
            null,
            null,
            $idShop
        );
        if ($configuration['PS_MAIL_METHOD'] == Mail::METHOD_DISABLE) {
            return null;
        }

        Hook::exec('sendMailAlterTemplateVars', [
            'template' => $template,
            'template_vars' => &$templateVars,
        ]);

        if (!isset($from) || !Validate::isEmail($from)) {
            $from = $configuration['PS_SHOP_EMAIL'];
        }
        if (!Validate::isEmail($from)) {
            return null;
        }
        if (!isset($fromName) || !Validate::isMailName($fromName)) {
            $fromName = $configuration['PS_SHOP_NAME'];
        }
        if (!Validate::isMailName($fromName)) {
            $fromName = null;
        }
        if (!is_array($to) && !Validate::isEmail($to)) {
            return null;
        }
        if (null !== $bcc && !is_array($bcc) && !Validate::isEmail($bcc)) {
            $bcc = null;
        }
        if (!is_array($templateVars)) {
            $templateVars = [];
        }
        if (is_string($toName) && !empty($toName) && !Validate::isMailName($toName)) {
            $toName = null;
        }
        if (!Validate::isTplName($template) || !Validate::isMailSubject($subject)) {
            return null;
        }

        $native = new GroundmailNativeMessage();

        if (is_array($to)) {
            if (!$to) {
                return null;
            }
            foreach ($to as $key => $addr) {
                $addr = trim((string) $addr);
                if (!Validate::isEmail($addr)) {
                    return null;
                }
                $addrName = is_array($toName) && isset($toName[$key]) ? $toName[$key] : $toName;
                if (is_array($addrName) || $addrName == null || $addrName == $addr || !Validate::isGenericName($addrName)) {
                    $addrName = '';
                }
                $native->addTo(Mail::toPunycode($addr), (string) $addrName);
            }
            $toPlugin = isset($to[0]) ? $to[0] : reset($to);
        } else {
            $toPlugin = $to;
            $toName = ($toName == null || $toName == $to || is_array($toName)) ? '' : (string) $toName;
            $native->addTo(Mail::toPunycode($to), $toName);
        }

        if (is_array($bcc)) {
            foreach ($bcc as $addr) {
                $addr = trim((string) $addr);
                if (!Validate::isEmail($addr)) {
                    return null;
                }
                $native->addBcc(Mail::toPunycode($addr));
            }
        } elseif (isset($bcc)) {
            $native->addBcc(Mail::toPunycode($bcc));
        }

        // Template lookup: the message language, then the shop default, then English.
        $isoArray = [];
        $iso = Language::getIsoById((int) $idLang);
        if ($iso) {
            $isoArray[] = $iso;
        }
        $isoDefault = Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT'));
        if ($isoDefault && $iso !== $isoDefault) {
            $isoArray[] = $isoDefault;
        }
        if (!in_array('en', $isoArray)) {
            $isoArray[] = 'en';
        }

        $moduleName = false;
        $normalizedPath = str_replace(DIRECTORY_SEPARATOR, '/', (string) $templatePath);
        if (preg_match('#' . $shop->physical_uri . 'modules/#', $normalizedPath)
            && preg_match('#modules/([a-z0-9_-]+)/#ui', $normalizedPath, $res)
        ) {
            $moduleName = $res[1];
        }

        $mailType = (int) $configuration['PS_MAIL_TYPE'];
        $automaticText = defined('Mail::TYPE_BOTH_AUTOMATIC_TEXT') ? (int) constant('Mail::TYPE_BOTH_AUTOMATIC_TEXT') : -1;
        $needsText = $mailType == Mail::TYPE_BOTH || $mailType == Mail::TYPE_TEXT;
        $needsHtml = $mailType == Mail::TYPE_BOTH || $mailType == Mail::TYPE_HTML || $mailType === $automaticText;

        $found = false;
        $isoTemplate = '';
        $basePath = '';
        foreach ($isoArray as $isoCode) {
            $isoTemplate = $isoCode . '/' . $template;
            $basePath = GroundmailMailTemplates::basePath($isoTemplate, $moduleName, $shop->theme);
            if ($needsText && !file_exists($basePath . $isoTemplate . '.txt')) {
                continue;
            }
            if ($needsHtml && !file_exists($basePath . $isoTemplate . '.html')) {
                continue;
            }
            $found = true;
            break;
        }
        if (!$found) {
            return null;
        }

        $templateHtml = '';
        $templateTxt = '';
        Hook::exec(
            'actionEmailAddBeforeContent',
            [
                'template' => $template,
                'template_html' => &$templateHtml,
                'template_txt' => &$templateTxt,
                'id_lang' => (int) $idLang,
            ],
            null,
            true
        );
        $templateHtml .= self::readFile($basePath . $isoTemplate . '.html');
        $templateTxt .= strip_tags(html_entity_decode(self::readFile($basePath . $isoTemplate . '.txt'), ENT_COMPAT, 'utf-8'));
        Hook::exec(
            'actionEmailAddAfterContent',
            [
                'template' => $template,
                'template_html' => &$templateHtml,
                'template_txt' => &$templateTxt,
                'id_lang' => (int) $idLang,
            ],
            null,
            true
        );

        // PrestaShop 8.0 always prefixed the subject; 8.1 made it a setting.
        $prefixSubject = version_compare(_PS_VERSION_, '8.1.0', '<') || Configuration::get('PS_MAIL_SUBJECT_PREFIX');
        if ($prefixSubject) {
            $subject = '[' . strip_tags((string) $configuration['PS_SHOP_NAME']) . '] ' . $subject;
        }
        $native->setSubject($subject);

        if (!($replyTo && Validate::isEmail($replyTo))) {
            $replyTo = $from;
        }
        if (!empty($replyTo) && $replyTo != $toPlugin) {
            $native->setReplyTo($replyTo, $replyToName === null ? null : (string) $replyToName);
        }

        $logo = null;
        $logoMail = Configuration::get('PS_LOGO_MAIL', null, null, $idShop);
        if (false !== $logoMail && $logoMail && file_exists(_PS_IMG_DIR_ . $logoMail)) {
            $logo = _PS_IMG_DIR_ . $logoMail;
        } else {
            $logoShop = Configuration::get('PS_LOGO', null, null, $idShop);
            if ($logoShop && file_exists(_PS_IMG_DIR_ . $logoShop)) {
                $logo = _PS_IMG_DIR_ . $logoShop;
            } else {
                $templateVars['{shop_logo}'] = '';
            }
        }

        ShopUrl::cacheMainDomainForShop((int) $idShop);
        try {
            if ($logo !== null && $mailType != Mail::TYPE_TEXT) {
                $templateVars['{shop_logo}'] = $native->embedLogo($logo);
            }

            $context = Context::getContext();
            if (!$context->link instanceof Link) {
                $context->link = new Link();
            }
            $templateVars['{shop_name}'] = Tools::safeOutput($configuration['PS_SHOP_NAME']);
            $templateVars['{shop_url}'] = $context->link->getPageLink('index', null, $idLang, null, false, $idShop);
            $templateVars['{my_account_url}'] = $context->link->getPageLink('my-account', null, $idLang, null, false, $idShop);
            $templateVars['{guest_tracking_url}'] = $context->link->getPageLink('guest-tracking', null, $idLang, null, false, $idShop);
            $templateVars['{history_url}'] = $context->link->getPageLink('history', null, $idLang, null, false, $idShop);
            $templateVars['{order_slip_url}'] = $context->link->getPageLink('order-slip', null, $idLang, null, false, $idShop);
            $templateVars['{color}'] = Tools::safeOutput(Configuration::get('PS_MAIL_COLOR', null, null, $idShop));

            $extraTemplateVars = [];
            Hook::exec(
                'actionGetExtraMailTemplateVars',
                [
                    'template' => $template,
                    'template_vars' => $templateVars,
                    'extra_template_vars' => &$extraTemplateVars,
                    'id_lang' => (int) $idLang,
                ],
                null,
                true
            );
            if (is_array($extraTemplateVars)) {
                $templateVars = array_merge($templateVars, $extraTemplateVars);
            }
        } finally {
            ShopUrl::resetMainDomainCache();
        }

        $replacements = self::replacements($templateVars);
        if (!$native->isSymfony()) {
            // PrestaShop 8 fills the variables in with SwiftMailer's decorator,
            // which also covers the subject.
            $native->setSubject(str_replace(array_keys($replacements), array_values($replacements), $subject));
        }

        $html = null;
        $text = null;
        if ($mailType == Mail::TYPE_TEXT) {
            $text = self::fill($templateTxt, $replacements, $native->isSymfony());
        } else {
            $html = self::fill($templateHtml, $replacements, $native->isSymfony());
            if ($mailType == Mail::TYPE_BOTH) {
                $text = self::fill($templateTxt, $replacements, $native->isSymfony());
            } elseif ($mailType === $automaticText) {
                $text = self::htmlToText($html);
            }
        }
        $native->setContent($html, $text);

        if (!empty($fileAttachment) && is_array($fileAttachment)) {
            if (!is_array(current($fileAttachment))) {
                $fileAttachment = [$fileAttachment];
            }
            foreach ($fileAttachment as $attachment) {
                if (isset($attachment['content'], $attachment['name'], $attachment['mime'])) {
                    $native->attach($attachment['content'], $attachment['name'], $attachment['mime']);
                }
            }
        }

        $native->setFrom($from, $fromName);

        $message = $native->message();
        Hook::exec('actionMailAlterMessageBeforeSend', [
            'message' => &$message,
            'id_lang' => (int) $idLang,
        ]);

        return [
            'message' => $message,
            'template' => (string) $template,
            'id_lang' => (int) $idLang,
            'id_shop' => (int) $idShop,
        ];
    }

    /**
     * Template variables as strings, so a module that passed an array or an
     * object without __toString doesn't raise a notice here.
     *
     * @param array $templateVars
     *
     * @return array
     */
    private static function replacements(array $templateVars)
    {
        $replacements = [];
        foreach ($templateVars as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements[(string) $key] = (string) $value;
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $replacements[(string) $key] = (string) $value;
            } else {
                $replacements[(string) $key] = '';
            }
        }
        unset($replacements['']);

        return $replacements;
    }

    /**
     * @param string $content
     * @param array $replacements
     * @param bool $symfony PrestaShop 9 uses strtr, PrestaShop 8 str_replace
     *
     * @return string
     */
    private static function fill($content, array $replacements, $symfony)
    {
        if ($symfony) {
            return strtr($content, $replacements);
        }

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * @param string $html
     *
     * @return string
     */
    private static function htmlToText($html)
    {
        $class = 'PrestaShop\\PrestaShop\\Core\\MailTemplate\\Transformation\\HTMLToTextTransformation';
        if (class_exists($class)) {
            return (new $class())->apply($html, []);
        }

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private static function readFile($path)
    {
        if (!is_file($path)) {
            return '';
        }
        $content = Tools::file_get_contents($path);

        return is_string($content) ? $content : '';
    }
}
