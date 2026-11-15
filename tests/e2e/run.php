<?php

/**
 * End-to-end check against a real PrestaShop install with the module
 * installed (bin/console prestashop:module install groundmail).
 *
 *   GROUNDMAIL_API_KEY=gm_… php tests/e2e/run.php /path/to/prestashop
 *
 * Sends real emails through Groundmail, only to the simulator
 * (delivered@simulator.groundmail.dev), from GROUNDMAIL_E2E_FROM
 * (default prestashop@e2e.groundmail.dev). PrestaShop's own mail settings are
 * pointed at a closed port first, so any email PrestaShop tried to send itself
 * would fail: a pass proves Groundmail sent it and PrestaShop didn't.
 *
 * The API key is read from the environment and never printed.
 */
if (PHP_SAPI !== 'cli' || $argc < 2) {
    fwrite(STDERR, "usage: php tests/e2e/run.php /path/to/prestashop\n");
    exit(2);
}
$apiKey = (string) getenv('GROUNDMAIL_API_KEY');
if ($apiKey === '') {
    fwrite(STDERR, "GROUNDMAIL_API_KEY is not set\n");
    exit(2);
}
$from = getenv('GROUNDMAIL_E2E_FROM') ?: 'prestashop@e2e.groundmail.dev';
$simulator = 'delivered@simulator.groundmail.dev';

$root = rtrim($argv[1], '/');
chdir($root);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
require $root . '/config/config.inc.php';

// Order emails format prices with services from the Symfony container, as in the back office.
global $kernel;
$kernelClass = class_exists('AdminKernel') ? 'AdminKernel' : 'AppKernel';
$kernel = new $kernelClass('prod', false);
$kernel->boot();

error_reporting(E_ALL);
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo 'FATAL ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . "\n";
    }
});
$notices = [];
set_error_handler(function ($severity, $message, $file, $line) use (&$notices) {
    if (strpos($file, '/modules/groundmail/') !== false) {
        $notices[] = "$message in $file:$line";
    }

    return false;
});

$failures = 0;
function check($ok, $label, $detail = '')
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? ' - ' . $detail : '') . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function lastResult()
{
    $result = Groundmail::$lastResult;
    Groundmail::$lastResult = null;

    return $result;
}

$module = Module::getInstanceByName('groundmail');
check($module instanceof Groundmail && Module::isInstalled('groundmail'), 'module installed', 'PrestaShop ' . _PS_VERSION_ . ', PHP ' . PHP_VERSION);
check((bool) Hook::getIdByName('actionEmailSendBefore') && $module->isRegisteredInHook('actionEmailSendBefore'), 'registered on actionEmailSendBefore');

$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$idShop = (int) Context::getContext()->shop->id;

// PrestaShop's own sending can't work: SMTP to a closed port.
Configuration::updateValue('PS_MAIL_METHOD', Mail::METHOD_SMTP);
Configuration::updateValue('PS_MAIL_SERVER', '127.0.0.1');
Configuration::updateValue('PS_MAIL_SMTP_PORT', 9);
Configuration::updateValue('PS_MAIL_SMTP_ENCRYPTION', 'off');
Configuration::updateValue('PS_LOG_EMAILS', 1);
Configuration::updateValue('PS_INVOICE', 1);

Configuration::updateValue(Groundmail::CONFIG_API_KEY, $apiKey);
Configuration::updateValue(Groundmail::CONFIG_ENABLED, 1);
Configuration::updateValue(Groundmail::CONFIG_FALLBACK, 0);
Configuration::updateValue(Groundmail::CONFIG_FROM_EMAIL, $from);
Configuration::updateValue(Groundmail::CONFIG_FROM_NAME, 'PrestaShop ' . _PS_VERSION_ . ' e2e');

// 1. The test template, the way the module's test button sends it.
$sent = Mail::send($idLang, 'test', 'Groundmail e2e: test email', ['{firstname}' => 'Ada', '{lastname}' => 'Lovelace'], $simulator, 'Ada Lovelace');
$r = lastResult();
check($sent === true && $r && $r['sent'], 'test email sent through Groundmail', $r ? ($r['id'] ?: $r['error']) : 'module not called');

// 2. Password reset (customer account email).
$sent = Mail::send($idLang, 'password_query', 'Groundmail e2e: password reset', [
    '{email}' => $simulator, '{lastname}' => 'Lovelace', '{firstname}' => 'Ada', '{url}' => 'https://example.com/reset',
], $simulator, 'Ada Lovelace');
$r = lastResult();
check($sent === true && $r && $r['sent'], 'password reset sent', $r ? ($r['id'] ?: $r['error']) : 'module not called');

// 3. Contact form message to the shop, replying to the customer.
$sent = Mail::send($idLang, 'contact', 'Groundmail e2e: contact form', [
    '{email}' => 'customer@example.com', '{message}' => 'Where is my order?', '{order_name}' => '', '{attached_file}' => '',
], $simulator, null, null, null, null, null, _PS_MAIL_DIR_, false, null, null, 'customer@example.com');
$r = lastResult();
check($sent === true && $r && $r['sent'], 'contact form message sent', $r ? ($r['id'] ?: $r['error']) : 'module not called');

// 4. A real order: send its emails to the simulator, then move it to
//    "Payment accepted" (sends `payment` with the invoice PDF attached) and
//    "Shipped" (sends `shipped`), the way the back office does.
$idOrder = (int) Db::getInstance()->getValue('SELECT MAX(`id_order`) FROM `' . _DB_PREFIX_ . 'orders`');
if ($idOrder) {
    $order = new Order($idOrder);
    // What the back office has in its context and a CLI script doesn't.
    Context::getContext()->currency = new Currency((int) $order->id_currency);
    Context::getContext()->employee = new Employee((int) Db::getInstance()->getValue('SELECT MIN(`id_employee`) FROM `' . _DB_PREFIX_ . 'employee`'));
    $customer = new Customer((int) $order->id_customer);
    $customer->email = $simulator;
    $customer->update();

    foreach (['PS_OS_PAYMENT' => 'payment', 'PS_OS_SHIPPING' => 'shipped'] as $state => $template) {
        $order = new Order($idOrder);
        $history = new OrderHistory();
        $history->id_order = $idOrder;
        $history->changeIdOrderState((int) Configuration::get($state), $order, true);
        $ok = $history->addWithemail(true);
        $r = lastResult();
        $detail = $r ? ($r['id'] ?: $r['error']) : 'module not called';
        if ($r && !empty($r['attachments'])) {
            $detail .= ', attachments: ' . implode(', ', $r['attachments']);
        }
        check($ok && $r && $r['sent'], "order #$idOrder $template email sent", $detail);
        if ($template === 'payment') {
            $hasInvoice = $r && !empty($r['attachments']) && preg_grep('/\.pdf$/i', $r['attachments']);
            check((bool) $hasInvoice, 'invoice PDF attached to the payment email');
        }
    }

    // A new order placed with bank wire, as at checkout: sends order_conf.
    $context = Context::getContext();
    $duplicate = (new Cart((int) $order->id_cart))->duplicate();
    $cart = $duplicate['cart'];
    $context->cart = $cart;
    $context->customer = new Customer((int) $cart->id_customer);
    $context->language = new Language((int) $cart->id_lang);
    $payment = Module::getInstanceByName('ps_wirepayment');
    $sentEmails = [];
    if ($payment instanceof PaymentModule) {
        Groundmail::$lastResult = null;
        $payment->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('PS_OS_BANKWIRE'),
            (float) $cart->getOrderTotal(true, Cart::BOTH),
            'Bank wire',
            null,
            [],
            (int) $context->currency->id,
            false,
            $context->customer->secure_key
        );
        $r = lastResult();
        check($r && $r['sent'], 'order #' . $payment->currentOrder . ' confirmation (order_conf) sent at checkout', $r ? ($r['id'] ?: $r['error']) . ', subject: ' . ($r['subject'] ?? '') : 'module not called');
    } else {
        check(false, 'ps_wirepayment installed, to place an order');
    }
} else {
    check(false, 'an order to test with', 'the shop has no orders; install with demo data');
}

// 5. The email log lists what Groundmail sent.
$logged = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'mail` WHERE `recipient` = \'' . pSQL($simulator) . '\'');
check($logged >= 3, 'sent emails are in the email log', $logged . ' rows');

// 6. Turned off: PrestaShop sends the email itself (and fails, on the closed port).
Configuration::updateValue(Groundmail::CONFIG_ENABLED, 0);
$sent = Mail::send($idLang, 'test', 'Groundmail e2e: disabled', [], $simulator);
$r = lastResult();
check($r === null && $sent === false, 'turned off: PrestaShop sends with its own settings');
Configuration::updateValue(Groundmail::CONFIG_ENABLED, 1);

// 7. A key Groundmail refuses, with fallback on: PrestaShop tries its own settings.
Configuration::updateValue(Groundmail::CONFIG_API_KEY, 'gm_e2e_not_a_real_key');
Configuration::updateValue(Groundmail::CONFIG_FALLBACK, 1);
$sent = Mail::send($idLang, 'test', 'Groundmail e2e: fallback', [], $simulator);
$r = lastResult();
check($r && !$r['sent'] && $sent === false, 'refused key with fallback: PrestaShop tries its own settings', $r ? $r['error'] : '');

// 8. Same with fallback off: the email is dropped and logged, PrestaShop doesn't send.
Configuration::updateValue(Groundmail::CONFIG_FALLBACK, 0);
$sent = Mail::send($idLang, 'test', 'Groundmail e2e: no fallback', [], $simulator);
$r = lastResult();
$logRow = (string) Db::getInstance()->getValue('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `object_type` = \'Groundmail\' ORDER BY `id_log` DESC');
check($r && !$r['sent'] && $sent === true && strpos($logRow, 'Groundmail could not send') === 0, 'refused key without fallback: logged, not sent by PrestaShop');

// 9. Email turned off in PrestaShop: nobody sends.
Configuration::updateValue(Groundmail::CONFIG_API_KEY, $apiKey);
Configuration::updateValue('PS_MAIL_METHOD', Mail::METHOD_DISABLE);
$sent = Mail::send($idLang, 'test', 'Groundmail e2e: email off', [], $simulator);
$r = lastResult();
check($sent === true && $r && !$r['handled'], 'email turned off in PrestaShop: nothing sent');
Configuration::updateValue('PS_MAIL_METHOD', Mail::METHOD_SMTP);

check(!$notices, 'no PHP notices or warnings from the module', implode(' | ', $notices));

echo $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
