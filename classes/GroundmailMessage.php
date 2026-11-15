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
 * Turns the message PrestaShop built into a Groundmail API request body.
 *
 * PrestaShop 8 builds a SwiftMailer message (Swift_Message) and PrestaShop 9
 * a Symfony Mime one (Symfony\Component\Mime\Email). Both are read here after
 * every module had its say in actionMailAlterMessageBeforeSend.
 */
class GroundmailMessage
{
    /**
     * Headers the API writes itself or takes as their own fields. A request
     * that names one of them in `headers` is refused, so they are left out.
     */
    public const RESERVED_HEADERS = [
        'authentication-results',
        'bcc',
        'cc',
        'content-disposition',
        'content-id',
        'content-transfer-encoding',
        'content-type',
        'date',
        'delivered-to',
        'dkim-signature',
        'from',
        'message-id',
        'mime-version',
        'received',
        'reply-to',
        'resent-bcc',
        'resent-cc',
        'resent-date',
        'resent-from',
        'resent-message-id',
        'resent-reply-to',
        'resent-sender',
        'resent-to',
        'return-path',
        'sender',
        'subject',
        'to',
        'x-groundmail-send-category',
        'x-groundmail-category-defaulted',
        'x-mailer',
    ];

    /**
     * Request body for POST /emails from a PrestaShop 8 or 9 message.
     *
     * @param mixed $message Swift_Mime_SimpleMessage or Symfony\Component\Mime\Email
     *
     * @return array
     *
     * @throws InvalidArgumentException for any other object
     */
    public static function fromNative($message)
    {
        if ($message instanceof Symfony\Component\Mime\Email) {
            return self::fromSymfony($message);
        }
        if ($message instanceof Swift_Mime_SimpleMessage) {
            return self::fromSwift($message);
        }

        throw new InvalidArgumentException('Unsupported message type: ' . (is_object($message) ? get_class($message) : gettype($message)));
    }

    /**
     * PrestaShop 9: a Symfony Mime email.
     *
     * @param Symfony\Component\Mime\Email $email
     *
     * @return array
     */
    public static function fromSymfony($email)
    {
        $body = [];

        $from = $email->getFrom();
        if ($from) {
            $body['from'] = self::fromField($from[0]->getAddress(), $from[0]->getName());
        }

        $body['to'] = self::symfonyAddresses($email->getTo());
        $cc = self::symfonyAddresses($email->getCc());
        if ($cc) {
            $body['cc'] = $cc;
        }
        $bcc = self::symfonyAddresses($email->getBcc());
        if ($bcc) {
            $body['bcc'] = $bcc;
        }
        $replyTo = self::symfonyAddresses($email->getReplyTo());
        if ($replyTo) {
            $body['reply_to'] = $replyTo;
        }

        $body['subject'] = self::cleanLine((string) $email->getSubject());

        $html = self::stringBody($email->getHtmlBody());
        $text = self::stringBody($email->getTextBody());
        if ($html !== '') {
            $body['html'] = $html;
        }
        if ($text !== '' || $html === '') {
            // The API needs a body; an empty message still sends.
            $body['text'] = $text === '' ? ' ' : $text;
        }

        $headers = [];
        foreach ($email->getHeaders()->all() as $header) {
            $headers[] = [$header->getName(), $header->getBodyAsString()];
        }
        $headers = self::customHeaders($headers);
        if ($headers) {
            $body['headers'] = $headers;
        }

        $attachments = [];
        foreach ($email->getAttachments() as $part) {
            $name = (string) $part->getFilename();
            $row = [
                'name' => $name !== '' ? $name : 'attachment',
                'content_type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                'data' => base64_encode($part->getBody()),
            ];
            if ($part->getDisposition() === 'inline') {
                // An image embedded with embed()/embedFromPath() is referenced
                // from the HTML as cid:<its name> until Symfony rewrites it on
                // send, which never happens here.
                $row['content_id'] = $part->hasContentId() ? $part->getContentId() : $row['name'];
            }
            $attachments[] = $row;
        }
        if ($attachments) {
            $body['attachments'] = $attachments;
        }

        return $body;
    }

    /**
     * PrestaShop 8: a SwiftMailer message.
     *
     * @param Swift_Mime_SimpleMessage $message
     *
     * @return array
     */
    public static function fromSwift($message)
    {
        $body = [];

        $from = $message->getFrom();
        if (is_array($from) && $from) {
            $body['from'] = self::fromField((string) key($from), (string) current($from));
        }

        $body['to'] = self::swiftAddresses($message->getTo());
        $cc = self::swiftAddresses($message->getCc());
        if ($cc) {
            $body['cc'] = $cc;
        }
        $bcc = self::swiftAddresses($message->getBcc());
        if ($bcc) {
            $body['bcc'] = $bcc;
        }
        $replyTo = self::swiftAddresses($message->getReplyTo());
        if ($replyTo) {
            $body['reply_to'] = $replyTo;
        }

        $body['subject'] = self::cleanLine((string) $message->getSubject());

        $html = '';
        $text = '';
        $attachments = [];
        $mainIsText = false;

        foreach ($message->getChildren() as $child) {
            if ($child instanceof Swift_Mime_Attachment) {
                $name = (string) $child->getFilename();
                $row = [
                    'name' => $name !== '' ? $name : 'attachment',
                    'content_type' => (string) $child->getContentType(),
                    'data' => base64_encode((string) $child->getBody()),
                ];
                if ($child instanceof Swift_Mime_EmbeddedFile || $child->getDisposition() === 'inline') {
                    $row['content_id'] = (string) $child->getId();
                }
                $attachments[] = $row;
                continue;
            }
            if ($child instanceof Swift_Mime_MimePart) {
                $type = strtolower((string) $child->getContentType());
                if ($type === 'text/plain' && $text === '') {
                    $text = (string) $child->getBody();
                } elseif ($type === 'text/html' && $html === '') {
                    $html = (string) $child->getBody();
                    $mainIsText = true;
                }
            }
        }

        $main = (string) $message->getBody();
        if ($main !== '') {
            // Once parts are added the message reports a multipart type, so
            // the main body's own type is told by what the parts are not.
            $type = strtolower((string) $message->getContentType());
            if ($type === 'text/plain' || ($mainIsText && strpos($type, 'text/html') !== 0)) {
                $text = $main;
            } else {
                $html = $main;
            }
        }

        if ($html !== '') {
            $body['html'] = $html;
        }
        if ($text !== '' || $html === '') {
            $body['text'] = $text === '' ? ' ' : $text;
        }

        $headers = [];
        foreach ($message->getHeaders()->getAll() as $header) {
            $headers[] = [$header->getFieldName(), $header->getFieldBody()];
        }
        $headers = self::customHeaders($headers);
        if ($headers) {
            $body['headers'] = $headers;
        }

        if ($attachments) {
            $body['attachments'] = $attachments;
        }

        return $body;
    }

    /**
     * Apply the sender saved in the module: the saved address replaces the
     * shop's, and the saved name replaces the name. When the message had its
     * own From and no Reply-To, replies still reach that address.
     *
     * @param array $body
     * @param string $fromEmail
     * @param string $fromName
     *
     * @return array
     */
    public static function overrideSender(array $body, $fromEmail, $fromName)
    {
        $fromEmail = trim((string) $fromEmail);
        $fromName = self::cleanLine((string) $fromName);
        $original = isset($body['from']['email']) ? (string) $body['from']['email'] : '';

        if ($fromEmail !== '' && strtolower($fromEmail) !== strtolower($original)) {
            if ($original !== '' && empty($body['reply_to'])) {
                $originalName = isset($body['from']['name']) ? (string) $body['from']['name'] : '';
                $body['reply_to'] = [self::formatAddress($original, $originalName)];
            }
            if (!isset($body['from']) || !is_array($body['from'])) {
                $body['from'] = [];
            }
            $body['from']['email'] = $fromEmail;
        }

        if ($fromName !== '' && isset($body['from']['email'])) {
            $body['from']['name'] = $fromName;
        }

        return $body;
    }

    /**
     * Every address the message goes to.
     *
     * @param array $body
     *
     * @return string[]
     */
    public static function recipients(array $body)
    {
        $all = [];
        foreach (['to', 'cc', 'bcc'] as $field) {
            if (!empty($body[$field])) {
                $all = array_merge($all, $body[$field]);
            }
        }

        return $all;
    }

    /**
     * `Name <email>` or a bare address, in the forms the API accepts.
     *
     * @param string $email
     * @param string $name
     *
     * @return string
     */
    public static function formatAddress($email, $name = '')
    {
        $email = trim((string) $email);
        $name = trim(str_replace(['"', '\\'], '', self::cleanLine((string) $name)));

        if ($name === '' || strtolower($name) === strtolower($email)) {
            return $email;
        }
        // Names with characters that mean something in an address are quoted.
        if (preg_match('/[()<>\[\]:;@,.]/', $name)) {
            $name = '"' . $name . '"';
        }

        return $name . ' <' . $email . '>';
    }

    /**
     * Custom headers the API accepts, as a name => value map.
     *
     * @param array $pairs array of [name, value]
     *
     * @return array
     */
    public static function customHeaders(array $pairs)
    {
        $headers = [];
        foreach ($pairs as $pair) {
            $name = trim((string) $pair[0]);
            if ($name === '' || !preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name)) {
                continue;
            }
            $lower = strtolower($name);
            if (in_array($lower, self::RESERVED_HEADERS, true) || strpos($lower, 'x-ses-') === 0) {
                continue;
            }
            $value = self::cleanLine((string) $pair[1]);
            if ($value === '') {
                continue;
            }
            $headers[$name] = function_exists('mb_substr') ? mb_substr($value, 0, 1000) : substr($value, 0, 1000);
        }

        return $headers;
    }

    /**
     * @param string $email
     * @param string|null $name
     *
     * @return array
     */
    private static function fromField($email, $name)
    {
        $from = ['email' => trim((string) $email)];
        $name = self::cleanLine((string) $name);
        if ($name !== '') {
            $from['name'] = $name;
        }

        return $from;
    }

    /**
     * @param Symfony\Component\Mime\Address[] $addresses
     *
     * @return string[]
     */
    private static function symfonyAddresses(array $addresses)
    {
        $list = [];
        foreach ($addresses as $address) {
            if ($address->getAddress() !== '') {
                $list[] = self::formatAddress($address->getAddress(), $address->getName());
            }
        }

        return $list;
    }

    /**
     * SwiftMailer address lists: [email => name|null], or a bare string.
     *
     * @param mixed $addresses
     *
     * @return string[]
     */
    private static function swiftAddresses($addresses)
    {
        if (is_string($addresses)) {
            $addresses = [$addresses => null];
        }
        if (!is_array($addresses)) {
            return [];
        }

        $list = [];
        foreach ($addresses as $email => $name) {
            if (is_int($email)) {
                $email = (string) $name;
                $name = '';
            }
            if ((string) $email !== '') {
                $list[] = self::formatAddress((string) $email, (string) $name);
            }
        }

        return $list;
    }

    /**
     * @param mixed $body string, resource or null
     *
     * @return string
     */
    private static function stringBody($body)
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body, -1, 0);

            return is_string($contents) ? $contents : '';
        }

        return (string) $body;
    }

    /**
     * One line of text: no line breaks, trimmed.
     *
     * @param string $text
     *
     * @return string
     */
    private static function cleanLine($text)
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $text));
    }
}
