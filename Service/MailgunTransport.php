<?php

namespace cspoo\Swiftmailer\MailgunBundle\Service;

use Mailgun\Mailgun;
use Swift_Events_EventListener;
use Swift_Events_SendEvent;
use Swift_Message;
use Swift_Mime_SimpleMessage;
use Swift_Transport;

class MailgunTransport implements Swift_Transport
{
    const DOMAIN_HEADER = 'mg:domain';

    /**
     * @var \Mailgun\Mailgun mailgun
     */
    private $mailgun;

    /**
     * @var string domain
     */
    private $domain;

    /**
     * The event dispatcher from the plugin API.
     *
     * @var \Swift_Events_EventDispatcher eventDispatcher
     */
    private $eventDispatcher;

    /**
     * @param \Swift_Events_EventDispatcher $eventDispatcher
     * @param Mailgun                       $mailgun
     * @param $domain
     */
    public function __construct(\Swift_Events_EventDispatcher $eventDispatcher, Mailgun $mailgun, $domain)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->domain = $domain;
        $this->mailgun = $mailgun;
    }

    /**
     * Get the special o:* headers. https://documentation.mailgun.com/api-sending.html#sending.
     *
     * @return array
     */
    public static function getMailgunHeaders(): array
    {
        return [
                'o:tag',
                'o:campaign',
                'o:deliverytime',
                'o:dkim',
                'o:testmode',
                'o:tracking',
                'o:tracking-clicks',
                'o:tracking-opens',
               ];
    }

    /**
     * Not used.
     */
    public function isStarted(): bool
    {
        return true;
    }

    /**
     * Not used.
     */
    public function start()
    {
    }

    /**
     * Not used.
     */
    public function stop()
    {
    }

    /**
     * Send the given Message.
     *
     * Recipient/sender data will be retrieved from the Message API.
     * The return value is the number of recipients who were accepted for delivery.
     *
     * @param Swift_Mime_SimpleMessage $message
     * @param string[]                 $failedRecipients An array of failures by-reference
     *
     * @return int number of mails sent
     *
     * @throws \Swift_TransportException
     * @throws \Swift_RfcComplianceException
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null): int
    {
        $failedRecipients = (array) $failedRecipients;

        if ($evt = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }

        if (null === $message->getHeaders()->get('To')) {
            throw new \Swift_TransportException('Cannot send message without a recipient');
        }

        $postData = $this->getPostData($message);
        $domain = $this->getDomain($message);
        $sent = \count($postData['to']);
        try {
            $this->mailgun->messages()->sendMime($domain, $postData['to'], $message->toString(), $postData);
            $resultStatus = Swift_Events_SendEvent::RESULT_SUCCESS;
        } catch (\Exception $e) {
            $failedRecipients = $postData['to'];
            $sent = 0;
            $resultStatus = Swift_Events_SendEvent::RESULT_FAILED;
        }

        if ($evt) {
            $evt->setResult($resultStatus);
            $evt->setFailedRecipients($failedRecipients);
            $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
        }

        return $sent;
    }

    /**
     * Register a plugin in the Transport.
     *
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }

    /**
     * Looks at the message headers to find post data.
     *
     * @param Swift_Message $message
     *
     * @return array
     *
     * @throws \Swift_RfcComplianceException
     */
    protected function getPostData(Swift_Message $message): array
    {
        // get "form", "to" etc..
        $postData = $this->prepareRecipients($message);

        $mailgunHeaders = self::getMailgunHeaders();
        $messageHeaders = $message->getHeaders();

        foreach ($mailgunHeaders as $headerName) {
            /**
 * @var \Swift_Mime_Headers_MailboxHeader $value
*/
            if (null !== $value = $messageHeaders->get($headerName)) {
                $postData[$headerName] = $value->getFieldBody();
                $messageHeaders->removeAll($headerName);
            }
        }

        return $postData;
    }

    /**
     * @param Swift_Message $message
     *
     * @return array
     */
    protected function prepareRecipients(Swift_Message $message): array
    {
        $headerNames = [
                        'from',
                        'to',
                        'bcc',
                        'cc',
                       ];
        $messageHeaders = $message->getHeaders();
        $postData = [];
        foreach ($headerNames as $name) {
            /**
 * @var \Swift_Mime_Headers_MailboxHeader $h
*/
            $h = $messageHeaders->get($name);
            $postData[$name] = $h === null ? [] : $h->getAddresses();
        }

        // Merge 'bcc' and 'cc' into 'to'.
        $postData['to'] = array_merge($postData['to'], $postData['bcc'], $postData['cc']);
        unset($postData['bcc'], $postData['cc']);

        // Remove Bcc to make sure it is hidden
        $messageHeaders->removeAll('bcc');

        return $postData;
    }

    /**
     * If the message header got a domain we should use that instead of $this->domain.
     *
     * @param Swift_Message $message
     *
     * @return string
     */
    protected function getDomain(Swift_Message $message): string
    {
        $messageHeaders = $message->getHeaders();
        if ($messageHeaders->has(self::DOMAIN_HEADER)) {
            $domain = $messageHeaders->get(self::DOMAIN_HEADER)->getValue();
            $messageHeaders->removeAll(self::DOMAIN_HEADER);

            return $domain;
        }

        return $this->domain;
    }

    /**
     * Not used.
     */
    public function ping(): bool
    {
        return true;
    }
}
