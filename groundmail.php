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

require_once __DIR__ . '/classes/GroundmailApi.php';
require_once __DIR__ . '/classes/GroundmailMessage.php';
require_once __DIR__ . '/classes/GroundmailNativeMessage.php';
require_once __DIR__ . '/classes/GroundmailMailTemplates.php';
require_once __DIR__ . '/classes/GroundmailRenderer.php';

/**
 * Sends the shop's email through the Groundmail email API.
 *
 * PrestaShop calls actionEmailSendBefore at the start of every Mail::send().
 * When the module is on, it builds the email there exactly as PrestaShop
 * would, sends it with the API and returns false, which tells PrestaShop
 * the email is handled. When it is off, or PrestaShop should handle the call
 * (email turned off, invalid arguments), it returns true and PrestaShop sends
 * as before.
 */
class Groundmail extends Module
{
    public const CONFIG_ENABLED = 'GROUNDMAIL_ENABLED';
    public const CONFIG_API_KEY = 'GROUNDMAIL_API_KEY';
    public const CONFIG_FROM_EMAIL = 'GROUNDMAIL_FROM_EMAIL';
    public const CONFIG_FROM_NAME = 'GROUNDMAIL_FROM_NAME';
    public const CONFIG_FALLBACK = 'GROUNDMAIL_FALLBACK';

    public const DOMAIN = 'Modules.Groundmail.Admin';

    /**
     * The result of the last send, for the test email and for scripts.
     *
     * @var array|null
     */
    public static $lastResult;

    public function __construct()
    {
        $this->name = 'groundmail';
        $this->tab = 'emailing';
        $this->version = '1.0.0';
        $this->author = 'Groundmail';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Groundmail', [], self::DOMAIN);
        $this->description = $this->trans('Send every email your shop sends, from order confirmations to password resets, through the Groundmail email API.', [], self::DOMAIN);
        $this->confirmUninstall = $this->trans('Your shop will send email with its own mail settings again. Uninstall?', [], self::DOMAIN);
    }

    /**
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->registerHook('actionEmailSendBefore')
            && Configuration::updateValue(self::CONFIG_ENABLED, 0)
            && Configuration::updateValue(self::CONFIG_API_KEY, '')
            && Configuration::updateValue(self::CONFIG_FROM_EMAIL, '')
            && Configuration::updateValue(self::CONFIG_FROM_NAME, '')
            && Configuration::updateValue(self::CONFIG_FALLBACK, 1);
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        foreach ([self::CONFIG_ENABLED, self::CONFIG_API_KEY, self::CONFIG_FROM_EMAIL, self::CONFIG_FROM_NAME, self::CONFIG_FALLBACK] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * Every Mail::send() call. Returning false stops PrestaShop's own send.
     *
     * @param mixed $params Mail::send() arguments, by reference
     *
     * @return bool
     */
    public function hookActionEmailSendBefore($params)
    {
        if (!is_array($params)) {
            return true;
        }

        $idShop = !empty($params['idShop']) ? (int) $params['idShop'] : (int) $this->context->shop->id;
        if (!$this->isActive($idShop)) {
            return true;
        }

        $fallback = (bool) Configuration::get(self::CONFIG_FALLBACK, null, null, $idShop);
        $result = $this->deliver($params, $idShop, $fallback);
        if (!$result['handled']) {
            // PrestaShop handles it (email turned off, or arguments it refuses).
            return true;
        }
        if ($result['sent']) {
            // Sent: stop PrestaShop's own send.
            return false;
        }

        // Groundmail didn't take it: hand it to the shop's own mail settings, or drop it.
        return $fallback;
    }

    /**
     * Build one Mail::send() email and send it through Groundmail.
     *
     * @param array $params Mail::send() arguments as actionEmailSendBefore passes them
     * @param int $idShop
     * @param bool $fallback whether PrestaShop sends it if Groundmail doesn't (for the log)
     *
     * @return array{handled: bool, sent: bool, id: string, error: string}
     */
    public function deliver(array $params, $idShop, $fallback = false)
    {
        $apiKey = trim((string) Configuration::get(self::CONFIG_API_KEY, null, null, $idShop));
        $subject = isset($params['subject']) ? (string) $params['subject'] : '';

        try {
            $built = GroundmailRenderer::build($params);
            if ($built === null) {
                return self::$lastResult = ['handled' => false, 'sent' => false, 'id' => '', 'error' => ''];
            }
            $body = GroundmailMessage::fromNative($built['message']);
        } catch (Throwable $e) {
            return $this->failed($subject, [], 'The email could not be prepared: ' . $e->getMessage(), $fallback);
        }

        $body = GroundmailMessage::overrideSender(
            $body,
            (string) Configuration::get(self::CONFIG_FROM_EMAIL, null, null, $idShop),
            (string) Configuration::get(self::CONFIG_FROM_NAME, null, null, $idShop)
        );
        $body['tags'] = [['name' => 'template', 'value' => $built['template']]];

        $recipients = GroundmailMessage::recipients($body);
        if ($apiKey === '') {
            return $this->failed($body['subject'], $recipients, 'No Groundmail API key is saved.', $fallback);
        }

        $result = $this->api()->send($body, $apiKey);
        if (!$result['sent']) {
            return $this->failed($body['subject'], $recipients, $result['error'], $fallback);
        }

        if ($result['held_back']) {
            $skipped = [];
            foreach ($result['held_back'] as $held) {
                if (is_array($held) && isset($held['email'])) {
                    $skipped[] = $held['email'] . (isset($held['reason']) ? ' (' . $held['reason'] . ')' : '');
                }
            }
            PrestaShopLogger::addLog(
                sprintf('Groundmail sent %s but not to: %s', $result['id'], implode(', ', $skipped)),
                2,
                null,
                'Groundmail'
            );
        }

        if (Configuration::get('PS_LOG_EMAILS')) {
            $this->logToMailTable($built, $body, $recipients);
        }

        $attachments = [];
        if (!empty($body['attachments'])) {
            foreach ($body['attachments'] as $attachment) {
                $attachments[] = $attachment['name'];
            }
        }

        return self::$lastResult = [
            'handled' => true,
            'sent' => true,
            'id' => $result['id'],
            'error' => '',
            'subject' => $body['subject'],
            'attachments' => $attachments,
        ];
    }

    /**
     * The configuration page.
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitGroundmailSettings')) {
            $output .= $this->saveSettings();
        } elseif (Tools::isSubmit('submitGroundmailTest')) {
            $output .= $this->sendTestEmail();
        }

        $idShop = (int) $this->context->shop->id;
        $hasKey = trim((string) Configuration::get(self::CONFIG_API_KEY)) !== '';
        $this->context->smarty->assign([
            'groundmail_enabled' => $this->isActive($idShop),
            'groundmail_has_key' => $hasKey,
            'groundmail_mail_off' => Configuration::get('PS_MAIL_METHOD') == Mail::METHOD_DISABLE,
            'groundmail_keys_url' => self::link('https://app.groundmail.dev/api-keys'),
            'groundmail_domains_url' => self::link('https://app.groundmail.dev/domains'),
            'groundmail_docs_url' => self::link('https://docs.groundmail.dev/integrations/prestashop'),
        ]);

        return $output
            . $this->display(__FILE__, 'views/templates/admin/configure.tpl')
            . $this->renderForms();
    }

    /**
     * Whether Groundmail sends this shop's email.
     *
     * @param int $idShop
     *
     * @return bool
     */
    public function isActive($idShop)
    {
        return (bool) Configuration::get(self::CONFIG_ENABLED, null, null, $idShop)
            && trim((string) Configuration::get(self::CONFIG_API_KEY, null, null, $idShop)) !== '';
    }

    /**
     * @return GroundmailApi
     */
    protected function api()
    {
        return new GroundmailApi('groundmail-prestashop/' . $this->version . ' PrestaShop/' . _PS_VERSION_);
    }

    /**
     * @return string
     */
    private function saveSettings()
    {
        $errors = [];
        $enabled = (bool) Tools::getValue(self::CONFIG_ENABLED);
        $newKey = trim((string) Tools::getValue(self::CONFIG_API_KEY));
        $fromEmail = trim((string) Tools::getValue(self::CONFIG_FROM_EMAIL));
        $fromName = trim((string) Tools::getValue(self::CONFIG_FROM_NAME));
        $fallback = (bool) Tools::getValue(self::CONFIG_FALLBACK);
        $warning = '';

        if ($newKey !== '') {
            if (!preg_match('/^[A-Za-z0-9_\-]{8,200}$/', $newKey)) {
                $errors[] = $this->trans('That doesn\'t look like a Groundmail API key. Copy it again from API Keys in Groundmail.', [], self::DOMAIN);
            } else {
                $check = $this->api()->checkKey($newKey);
                if ($check === 'invalid') {
                    $errors[] = $this->trans('Groundmail didn\'t accept this API key. Check that you copied all of it and that it hasn\'t been deleted.', [], self::DOMAIN);
                } elseif ($check === 'unknown') {
                    $warning = $this->trans('The API key is saved, but Groundmail couldn\'t be reached to check it. Send a test email to make sure it works.', [], self::DOMAIN);
                }
            }
        }
        if ($fromEmail !== '' && !Validate::isEmail($fromEmail)) {
            $errors[] = $this->trans('The sender email address is not valid.', [], self::DOMAIN);
        }
        if ($fromName !== '' && !Validate::isMailName($fromName)) {
            $errors[] = $this->trans('The sender name can\'t contain these characters: < > ; = # { }', [], self::DOMAIN);
        }
        $currentKey = trim((string) Configuration::get(self::CONFIG_API_KEY));
        if ($enabled && $newKey === '' && $currentKey === '') {
            $errors[] = $this->trans('Add your API key to send email through Groundmail.', [], self::DOMAIN);
        }

        if ($errors) {
            $output = '';
            foreach ($errors as $error) {
                $output .= $this->displayError($error);
            }

            return $output;
        }

        if ($newKey !== '') {
            Configuration::updateValue(self::CONFIG_API_KEY, $newKey);
        }
        Configuration::updateValue(self::CONFIG_ENABLED, $enabled ? 1 : 0);
        Configuration::updateValue(self::CONFIG_FROM_EMAIL, $fromEmail);
        Configuration::updateValue(self::CONFIG_FROM_NAME, $fromName);
        Configuration::updateValue(self::CONFIG_FALLBACK, $fallback ? 1 : 0);

        $output = $this->displayConfirmation($this->trans('Settings saved.', [], self::DOMAIN));
        if ($warning !== '') {
            $output .= $this->displayWarning($warning);
        }

        return $output;
    }

    /**
     * Send PrestaShop's test email template through Groundmail, whether or
     * not sending through Groundmail is turned on yet.
     *
     * @return string
     */
    private function sendTestEmail()
    {
        $to = trim((string) Tools::getValue('GROUNDMAIL_TEST_TO'));
        if (!Validate::isEmail($to)) {
            return $this->displayError($this->trans('Enter a valid email address to send the test to.', [], self::DOMAIN));
        }
        $idShop = (int) $this->context->shop->id;
        if (trim((string) Configuration::get(self::CONFIG_API_KEY, null, null, $idShop)) === '') {
            return $this->displayError($this->trans('Save your API key first.', [], self::DOMAIN));
        }

        $employee = $this->context->employee;
        $result = $this->deliver([
            'idLang' => (int) $this->context->language->id,
            'template' => 'test',
            'subject' => $this->trans('Test email from Groundmail for PrestaShop', [], self::DOMAIN),
            'templateVars' => [
                '{firstname}' => $employee ? (string) $employee->firstname : '',
                '{lastname}' => $employee ? (string) $employee->lastname : '',
            ],
            'to' => $to,
            'toName' => null,
            'from' => null,
            'fromName' => null,
            'fileAttachment' => null,
            'templatePath' => _PS_MAIL_DIR_,
            'idShop' => $idShop,
            'bcc' => null,
            'replyTo' => null,
        ], $idShop);

        if (!$result['handled']) {
            return $this->displayError($this->trans('PrestaShop couldn\'t build the test email. Check that email isn\'t turned off under Advanced Parameters > E-mail, and that the shop email is valid.', [], self::DOMAIN));
        }
        if (!$result['sent']) {
            // Module::trans() escapes HTML in the result, parameters included.
            return $this->displayError($this->trans('Groundmail didn\'t send the test email: %error%', ['%error%' => $result['error']], self::DOMAIN));
        }

        return $this->displayConfirmation($this->trans('Groundmail accepted the test email (%id%). It is on its way to %to%.', ['%id%' => $result['id'], '%to%' => $to], self::DOMAIN));
    }

    /**
     * @return string
     */
    private function renderForms()
    {
        $hasKey = trim((string) Configuration::get(self::CONFIG_API_KEY)) !== '';
        $yesNo = [
            ['id' => 'on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
            ['id' => 'off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
        ];

        $settings = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Send email through Groundmail', [], self::DOMAIN),
                        'name' => self::CONFIG_ENABLED,
                        'is_bool' => true,
                        'values' => $yesNo,
                        'desc' => $this->trans('When this is off, your shop sends email with its own mail settings.', [], self::DOMAIN),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('API key', [], self::DOMAIN),
                        'name' => self::CONFIG_API_KEY,
                        'class' => 'fixed-width-xxl',
                        'desc' => $hasKey
                            ? $this->trans('A key is saved. Leave this empty to keep it, or paste a new one to replace it.', [], self::DOMAIN)
                            : $this->trans('Create one under API Keys in Groundmail. A send-only key is enough.', [], self::DOMAIN),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Sender email', [], self::DOMAIN),
                        'name' => self::CONFIG_FROM_EMAIL,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->trans('Optional. Sends every email from this address instead of the shop email. It must be on a domain you verified in Groundmail.', [], self::DOMAIN),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Sender name', [], self::DOMAIN),
                        'name' => self::CONFIG_FROM_NAME,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->trans('Optional. Replaces the shop name as the sender name.', [], self::DOMAIN),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Fall back to PrestaShop\'s mail settings', [], self::DOMAIN),
                        'name' => self::CONFIG_FALLBACK,
                        'is_bool' => true,
                        'values' => $yesNo,
                        'desc' => $this->trans('If Groundmail can\'t send an email, PrestaShop tries to send it the way it did before. Errors are listed under Advanced Parameters > Logs.', [], self::DOMAIN),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'name' => 'submitGroundmailSettings',
                ],
            ],
        ];

        $test = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Send a test email', [], self::DOMAIN),
                    'icon' => 'icon-envelope',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Send to', [], self::DOMAIN),
                        'name' => 'GROUNDMAIL_TEST_TO',
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->trans('Use delivered@simulator.groundmail.dev to check the connection without emailing a real inbox.', [], self::DOMAIN),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Send test email', [], self::DOMAIN),
                    'name' => 'submitGroundmailTest',
                    'icon' => 'process-icon-envelope',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitGroundmailSettings';
        $helper->tpl_vars = [
            'fields_value' => [
                self::CONFIG_ENABLED => (int) Tools::getValue(self::CONFIG_ENABLED, Configuration::get(self::CONFIG_ENABLED)),
                self::CONFIG_API_KEY => '',
                self::CONFIG_FROM_EMAIL => (string) Tools::getValue(self::CONFIG_FROM_EMAIL, Configuration::get(self::CONFIG_FROM_EMAIL)),
                self::CONFIG_FROM_NAME => (string) Tools::getValue(self::CONFIG_FROM_NAME, Configuration::get(self::CONFIG_FROM_NAME)),
                self::CONFIG_FALLBACK => (int) Tools::getValue(self::CONFIG_FALLBACK, Configuration::get(self::CONFIG_FALLBACK)),
                'GROUNDMAIL_TEST_TO' => (string) Tools::getValue('GROUNDMAIL_TEST_TO', 'delivered@simulator.groundmail.dev'),
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        ];

        return $helper->generateForm([$settings, $test]);
    }

    /**
     * Log a failed send and say so.
     *
     * @param string $subject
     * @param string[] $recipients
     * @param string $error
     * @param bool $fallback
     *
     * @return array{handled: bool, sent: bool, id: string, error: string}
     */
    private function failed($subject, array $recipients, $error, $fallback)
    {
        PrestaShopLogger::addLog(
            sprintf(
                'Groundmail could not send "%s" to %s: %s%s',
                $subject,
                $recipients ? implode(', ', $recipients) : 'its recipients',
                $error,
                $fallback ? ' Handed to PrestaShop\'s own mail settings instead.' : ''
            ),
            3,
            null,
            'Groundmail'
        );

        return self::$lastResult = ['handled' => true, 'sent' => false, 'id' => '', 'error' => (string) $error];
    }

    /**
     * The same rows Mail::send() adds to Advanced Parameters > E-mail when email logging is on.
     *
     * @param array $built
     * @param array $body
     * @param string[] $recipients
     */
    private function logToMailTable(array $built, array $body, array $recipients)
    {
        foreach ($recipients as $recipient) {
            $email = preg_match('/<([^>]+)>\s*$/', $recipient, $match) ? $match[1] : $recipient;
            $mail = new Mail();
            $mail->template = Tools::substr($built['template'], 0, 62);
            $mail->subject = Tools::substr($body['subject'], 0, 255);
            $mail->id_lang = (int) $built['id_lang'];
            $mail->recipient = Tools::substr($email, 0, 255);
            try {
                $mail->add();
            } catch (Exception $e) {
                // The log is a convenience; the email itself was sent.
                continue;
            }
        }
    }

    /**
     * A groundmail.dev link, tagged so Groundmail knows the visit came from the module.
     *
     * @param string $url
     *
     * @return string
     */
    private static function link($url)
    {
        return $url . (strpos($url, '?') === false ? '?' : '&')
            . 'utm_source=prestashop&utm_medium=module&utm_campaign=groundmail-prestashop';
    }
}
