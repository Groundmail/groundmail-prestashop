{**
 * Groundmail for PrestaShop
 *
 * @author    Groundmail
 * @copyright Groundmail
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel">
  <h3><i class="icon-envelope"></i> {l s='Groundmail' d='Modules.Groundmail.Admin'}</h3>
  {if $groundmail_mail_off}
    <div class="alert alert-warning">
      {l s='Email is turned off for this shop under Advanced Parameters > E-mail, so nothing is sent, through Groundmail or otherwise.' d='Modules.Groundmail.Admin'}
    </div>
  {elseif $groundmail_enabled}
    <div class="alert alert-success">
      {l s='Your shop sends its email through Groundmail: order confirmations, shipping updates, account and password emails, contact form messages and every module that sends email.' d='Modules.Groundmail.Admin'}
    </div>
  {else}
    <div class="alert alert-info">
      {l s='Groundmail is not sending your shop\'s email yet. Your shop uses its own mail settings until you add an API key and turn sending on.' d='Modules.Groundmail.Admin'}
    </div>
  {/if}
  <ol>
    <li>
      {l s='Verify your sending domain in Groundmail:' d='Modules.Groundmail.Admin'}
      <a href="{$groundmail_domains_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Domains' d='Modules.Groundmail.Admin'}</a>
    </li>
    <li>
      {l s='Create an API key and paste it below:' d='Modules.Groundmail.Admin'}
      <a href="{$groundmail_keys_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='API Keys' d='Modules.Groundmail.Admin'}</a>
    </li>
    <li>{l s='Use a shop email on that domain (Shop Parameters > Contact > Stores), or set a sender email below.' d='Modules.Groundmail.Admin'}</li>
    <li>{l s='Send a test email, then turn sending on.' d='Modules.Groundmail.Admin'}</li>
  </ol>
  <p>
    <a href="{$groundmail_docs_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Read the setup guide' d='Modules.Groundmail.Admin'}</a>
  </p>
</div>
