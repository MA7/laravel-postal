<?php

namespace SynergiTech\Postal;

use Illuminate\Mail\Transport\Transport;

use Postal\SendMessage;
use Postal\Client;
use Postal\Error;
use Postal\SendResult;

use Swift_Attachment;
use Swift_Image;
use Swift_MimePart;
use Swift_Mime_SimpleMessage;

class PostalTransport extends Transport
{
    protected $client;
    protected $config;

    public function __construct(array $config)
    {
        $this->client = new Client($config['domain'] ?? null, $config['key'] ?? null);
        $this->config = $config;
    }

    /**
     * Inheritdoc from Swift_Transport
     *
     * @param Swift_Mime_SimpleMessage $swiftmessage
     * @param string[]                 $failedRecipients An array of failures by-reference
     *
     * @return int the number of sent messages? not sure
     */
    public function send(Swift_Mime_SimpleMessage $swiftmessage, &$failedRecipients = null)
    {
        $postalmessage = $this->swiftToPostal($swiftmessage);

        try {
            $response = $postalmessage->send();
        } catch (Error $error) {
            throw new \BadMethodCallException($error->getMessage(), $error->getCode(), $error);
        }

        $newemailids = $this->recordEmailsFromResponse($swiftmessage, $response);

        // return postals response to Laravel
        $swiftmessage->postal = $response;

        // referencing Swift_Transport_SendmailTransport, this seems to be what is required
        // I don't believe this value is used in Laravel
        $count = count($postalmessage->attributes['to']) + count($postalmessage->attributes['cc']) + count($postalmessage->attributes['bcc']);
        return $count;
    }

    /**
     * Convert Swift message into a Postal sendmessage
     *
     * @param Swift_Mime_SimpleMessage $swiftmessage
     *
     * @return SendMessage the resulting sendmessage
     */
    private function swiftToPostal(Swift_Mime_SimpleMessage $swiftmessage) : SendMessage
    {
        // SendMessage cannot be reset so must be instantiated for each use
        $postalmessage = new SendMessage($this->client);

        $recipients = [];
        foreach (['to', 'cc', 'bcc'] as $type) {
            foreach ((array) $swiftmessage->{'get' . ucwords($type)}() as $email => $name) {
                // dedup recipients
                if (! in_array($email, $recipients)) {
                    $recipients[] = $email;
                    $postalmessage->{$type}($name != null ? ($name . ' <' . $email . '>') : $email);
                }
            }
        }

        if ($swiftmessage->getFrom()) {
            foreach ($swiftmessage->getFrom() as $email => $name) {
                $postalmessage->from($name != null ? ($name . ' <' . $email . '>') : $email);
            }
        }

        if ($swiftmessage->getReplyTo()) {
            foreach ($swiftmessage->getReplyTo() as $email => $name) {
                $postalmessage->replyTo($name != null ? ($name . ' <' . $email . '>') : $email);
            }
        }

        if ($swiftmessage->getSubject()) {
            $postalmessage->subject($swiftmessage->getSubject());
        }

        if ($swiftmessage->getContentType() == 'text/plain') {
            $postalmessage->plainBody($swiftmessage->getBody());
        } elseif ($swiftmessage->getContentType() == 'text/html') {
            $postalmessage->htmlBody($swiftmessage->getBody());
        } else {
            foreach ($swiftmessage->getChildren() as $child) {
                if ($child instanceof Swift_MimePart && $child->getContentType() == 'text/plain') {
                    $postalmessage->plainBody($child->getBody());
                }
            }
            $postalmessage->htmlBody($swiftmessage->getBody());
        }

        foreach ($swiftmessage->getChildren() as $attachment) {
            if ($attachment instanceof Swift_Attachment) {
                $postalmessage->attach(
                    $attachment->getFilename(),
                    $attachment->getContentType(),
                    $attachment->getBody()
                );
            } elseif ($attachment instanceof Swift_Image) {
                $postalmessage->attach(
                    $attachment->getId(),
                    $attachment->getContentType(),
                    $attachment->getBody()
                );
            }
        }

        return $postalmessage;
    }

    /**
     * Preserve emails within database for later accounting with webhooks
     *
     * @param Swift_Mime_SimpleMessage $swiftmessage
     * @param SendResult $response
     *
     * @return array a list of emails IDs that were saved in the database
     */
    public function recordEmailsFromResponse(Swift_Mime_SimpleMessage $swiftmessage, SendResult $response) : array
    {
        $recipients = array();

        foreach (array('to', 'cc', 'bcc') as $field) {
            $headers = $swiftmessage->getHeaders()->get($field);

            // headers will be null if there is no CC for example
            if ($headers !== null) {
                $recipients = array_merge($recipients, $headers->getNameAddresses());
            }
        }

        $sender = $swiftmessage->getHeaders()->get('from')->getNameAddresses();

        $ids = array();

        foreach ($response->recipients() as $address => $message) {
            $email = new $this->config['models']['email'];

            $email->to_email = $address;
            $email->to_name = $recipients[$email->to_email];

            $email->from_email = key($sender);
            $email->from_name = $sender[$email->from_email];

            $email->subject = $swiftmessage->getSubject();

            $email->body = $swiftmessage->getBody();

            $email->postal_id = $message->id();
            $email->postal_token = $message->token();

            $email->save();

            $ids[] = $email->id;
        }

        return $ids;
    }
}
