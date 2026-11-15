# Groundmail for PrestaShop

The official Groundmail module for PrestaShop 8 and 9. It sends every email your shop sends through the [Groundmail](https://groundmail.dev?utm_source=github&utm_medium=readme&utm_campaign=groundmail-prestashop) email API over HTTPS: order confirmations with the invoice attached, payment and shipping updates, account and password emails, contact form messages, and the emails other modules send.

- Uses your shop's own email templates, translations and logo, exactly as PrestaShop renders them
- Attachments, such as the invoice PDF on order emails
- Optional sender email and name for every email
- A test email button on the module page
- Falls back to your shop's own mail settings if Groundmail can't send an email (you can turn this off)
- Keeps the hooks other modules use to change email: `sendMailAlterTemplateVars`, `actionEmailAddBeforeContent`, `actionEmailAddAfterContent`, `actionGetExtraMailTemplateVars` and `actionMailAlterMessageBeforeSend`
- Logs failed sends under **Advanced Parameters > Logs**, and adds sent emails to the email log when it's on

Until you add an API key and turn sending on, the module does nothing and your shop sends email as before.

Requires PrestaShop 8.0 to 9.x, PHP 7.2 or later (as your PrestaShop version requires), and the PHP curl extension. Licensed MIT.

## Install

1. Download `groundmail.zip` from the latest release. In the back office, open **Modules > Module Manager**, choose **Upload a module** and drop the zip.
2. In Groundmail, verify your sending domain and create an API key under [API Keys](https://app.groundmail.dev/api-keys?utm_source=github&utm_medium=readme&utm_campaign=groundmail-prestashop). A send-only key is enough.
3. On the module page (**Configure**), paste the key and save. Your shop email (**Shop Parameters > Contact > Stores**) must be on the verified domain, or set **Sender email** to an address on it.
4. Send a test email from the same page. `delivered@simulator.groundmail.dev` checks the connection without emailing a real inbox.
5. Turn on **Send email through Groundmail** and save.

Every email the shop sends now goes through Groundmail. Each one carries a `template` tag with the PrestaShop template name (`order_conf`, `shipped`, `password_query`…), so you can filter them in the Groundmail dashboard.

## How it works

PrestaShop calls the `actionEmailSendBefore` hook at the start of every `Mail::send()`. The module builds the email there the way PrestaShop does: same checks, templates, template variables, logo and attachments, and the same hooks for other modules. It then sends it with `POST https://api.groundmail.dev/emails` and tells PrestaShop the email is handled, so PrestaShop doesn't send it again with SMTP or sendmail.

When sending through Groundmail is off, when email is turned off under **Advanced Parameters > E-mail**, or when PrestaShop would refuse the email itself, the module steps aside and PrestaShop handles the email as usual.

The test email under **Advanced Parameters > E-mail** still tests PrestaShop's own SMTP settings. Use the module's test email to test Groundmail.

## What the module sends to Groundmail

The module connects to `https://api.groundmail.dev` only after you save an API key:

- **Sending an email:** the sender, recipients (To, CC, BCC, Reply-To), subject, HTML and text content, attachments, custom headers, the template name, and your API key.
- **Checking an API key** when you save a new one: only the key.

## Support

Questions and bug reports: the module's page on PrestaShop Addons, or the issues of the GitHub repository (Groundmail/groundmail-prestashop).
