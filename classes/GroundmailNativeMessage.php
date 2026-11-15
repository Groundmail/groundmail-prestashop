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
 * Builds the same message object PrestaShop would: a SwiftMailer message on
 * PrestaShop 8, a Symfony Mime email on PrestaShop 9. Modules that change
 * email in actionMailAlterMessageBeforeSend get the object they expect.
 */
class GroundmailNativeMessage
{
    /**
     * @var bool
     */
    private $symfony;

    /**
     * @var Symfony\Component\Mime\Email|null PrestaShop 9
     */
    private $email;

    /**
     * @var Swift_Message|null PrestaShop 8
     */
    private $swift;

    /**
     * @param bool|null $symfony Symfony Mime (PrestaShop 9) or SwiftMailer (PrestaShop 8); null picks by version
     */
    public function __construct($symfony = null)
    {
        if ($symfony === null) {
            $symfony = version_compare(_PS_VERSION_, '9.0.0', '>=');
        }
        $this->symfony = (bool) $symfony;

        if ($this->symfony) {
            $this->email = new Symfony\Component\Mime\Email();
        } else {
            $this->swift = new Swift_Message();
            $this->swift->setCharset('utf-8');
        }
    }

    /**
     * @return bool
     */
    public function isSymfony()
    {
        return $this->symfony;
    }

    /**
     * @param string $email
     * @param string $name
     */
    public function addTo($email, $name)
    {
        if ($this->symfony) {
            $this->email->addTo(new Symfony\Component\Mime\Address($email, (string) $name));
        } else {
            $this->swift->addTo($email, $name === '' ? null : $name);
        }
    }

    /**
     * @param string $email
     */
    public function addBcc($email)
    {
        if ($this->symfony) {
            $this->email->addBcc(new Symfony\Component\Mime\Address($email));
        } else {
            $this->swift->addBcc($email);
        }
    }

    /**
     * @param string $subject
     */
    public function setSubject($subject)
    {
        if ($this->symfony) {
            $this->email->subject($subject);
        } else {
            $this->swift->setSubject($subject);
        }
    }

    /**
     * @param string $email
     * @param string|null $name
     */
    public function setReplyTo($email, $name)
    {
        if ($this->symfony) {
            $this->email->replyTo(new Symfony\Component\Mime\Address($email, (string) $name));
        } else {
            $this->swift->setReplyTo($email, $name !== '' ? $name : null);
        }
    }

    /**
     * Embed the shop logo and return what the templates use for {shop_logo}.
     *
     * @param string $path
     *
     * @return string a cid: URL
     */
    public function embedLogo($path)
    {
        if ($this->symfony) {
            $this->email->embedFromPath($path, 'shop_logo');

            return 'cid:shop_logo';
        }

        return $this->swift->embed(Swift_Image::fromPath($path));
    }

    /**
     * @param string|null $html
     * @param string|null $text
     */
    public function setContent($html, $text)
    {
        if ($this->symfony) {
            if ($html !== null) {
                $this->email->html($html);
            }
            if ($text !== null) {
                $this->email->text($text);
            }

            return;
        }

        if ($html !== null) {
            $this->swift->setBody($html, 'text/html', 'utf-8');
            if ($text !== null) {
                $this->swift->addPart($text, 'text/plain', 'utf-8');
            }
        } else {
            $this->swift->setBody((string) $text, 'text/plain', 'utf-8');
        }
    }

    /**
     * @param string $content
     * @param string $name
     * @param string $mime
     */
    public function attach($content, $name, $mime)
    {
        if ($this->symfony) {
            $this->email->attach($content, $name, $mime);
        } else {
            $this->swift->attach((new Swift_Attachment())->setFilename($name)->setContentType($mime)->setBody($content));
        }
    }

    /**
     * @param string $email
     * @param string|null $name
     */
    public function setFrom($email, $name)
    {
        if ($this->symfony) {
            $this->email->from(new Symfony\Component\Mime\Address($email, (string) $name));
        } else {
            $this->swift->setFrom([$email => $name]);
        }
    }

    /**
     * @return Swift_Message|Symfony\Component\Mime\Email
     */
    public function message()
    {
        return $this->symfony ? $this->email : $this->swift;
    }
}
