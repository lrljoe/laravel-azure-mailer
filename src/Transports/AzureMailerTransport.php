<?php

/*
 * This file is part of the Avantia package.
 *
 * (c) Juan Luis Iglesias <jliglesias@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Avantia\Azure\Mailer\Transports;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\MessageConverter;

class AzureMailerTransport extends AbstractTransport
{

    private const HOST = '%s.communication.azure.com';

    /**
     * Create a new transport instance.
     */
    public function __construct() {
       
        if (!class_exists(HttpClient::class)) {
            throw new \LogicException(sprintf('You cannot use "%s" as the HttpClient component is not installed. Try running "composer require symfony/http-client".', __CLASS__));
        }
        $this->resource_name = config('mail.mailers.azure.resource_name');
        $this->key = config('mail.mailers.azure.access_key');
        $this->api_version= config('mail.mailers.azure.api_version');
        $this->disableTracking = config('mail.mailers.azure.disable_user_tracking');
        $this->endpoint = 'https://'.$this->getEndpoint().'/emails:send?api-version='.$this->api_version;
        $this->client = HttpClient::create();
        
        parent::__construct();

    }

    protected function doSend(SentMessage $message): void {
       
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = $this->getPayload($email, $message->getEnvelope());
        $headers = $this->getSignedHeaders($payload,$email);

        $response = $this->client->request('POST', $this->endpoint, [
            'body' => json_encode($payload),
            'headers' => $headers,
        ]);
       
        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Azure server.', $response, 0, $e);
        }
       
        if (202 !== $statusCode) {
            try {
                $result = $response->toArray(false);
                throw new HttpTransportException('Unable to send an email (.'.$result['error']['code'].'): '.$result['error']['message'], $response, $statusCode);
            } catch (DecodingExceptionInterface $e) {
                throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).sprintf(' (code %d).', $statusCode), $response, 0, $e);
            }
        }

        $message->setMessageId(json_decode($response->getContent(false), true)['id']);

    }

    /**
     * Get the message request body.
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        $addressStringifier = function (Address $address) {
            $stringified = ['address' => $address->getAddress()];

            if ($address->getName()) {
                $stringified['displayName'] = $address->getName();
            }

            return $stringified;
        };
        
        $data = [
            'content' => [
                'html' => $email->getHtmlBody(),
                'plainText' => $email->getTextBody(),
                'subject' => $email->getSubject(),
            ],
            'recipients' => [
                'to' => array_map($addressStringifier, $this->getRecipients($email, $envelope)),
            ],
            'senderAddress' => $envelope->getSender()->getAddress(),
            'attachments' => $this->getMessageAttachments($email),
            'userEngagementTrackingDisabled' => $this->disableTracking,
            'headers' => empty($headers = $this->getMessageCustomHeaders($email)) ? null : $headers,
            'importance' => $this->getPriorityLevel($email->getPriority()),
        ];

        if ($emails = array_map($addressStringifier, $email->getCc())) {
            $data['recipients']['cc'] = $emails;
        }

        if ($emails = array_map($addressStringifier, $email->getBcc())) {
            $data['recipients']['bcc'] = $emails;
        }

        if ($emails = array_map($addressStringifier, $email->getReplyTo())) {
            $data['replyTo'] = $emails;
        }

        return $data;
    }

    /**
     * @return Address[]
     */
    protected function getRecipients(Email $email, Envelope $envelope): array{
        return array_filter($envelope->getRecipients(), fn (Address $address) => false === \in_array($address, array_merge($email->getCc(), $email->getBcc()), true));
    }

     /**
     * The communication domain host, for example my-acs-resource-name.communication.azure.com.
     */
    private function getEndpoint(): string {
        return !empty($this->host) ? $this->host : sprintf(self::HOST, $this->resource_name);
    }

    private function generateContentHash(string $content): string {
        return base64_encode(hash('sha256', $content, true));
    }

    /**
     * Generate sha256 hash and encode to base64 to produces the digest string.
     */
    private function generateAuthenticationSignature(string $content): string {
        $key = base64_decode($this->key);
        $hashedBytes = hash_hmac('sha256', mb_convert_encoding($content, 'UTF-8'), $key, true);

        return base64_encode($hashedBytes);
    }

    /**
     * Get authenticated headers for signed request,.
     */
    private function getSignedHeaders(array $payload, Email $message): array
    {
        // HTTP Method verb (uppercase)
        $verb = 'POST';

        // Request time
        $datetime = new \DateTime('now', new \DateTimeZone('UTC'));
        $utcNow = $datetime->format('D, d M Y H:i:s \G\M\T');

        // Content hash signature
        $contentHash = $this->generateContentHash(json_encode($payload));

        // ACS Endpoint
        $host = str_replace('https://', '', $this->getEndpoint());

        // Sendmail endpoint from communication email delivery service
        $urlPathAndQuery = '/emails:send?api-version='.$this->api_version;

        // Signed request headers
        $stringToSign = "{$verb}\n{$urlPathAndQuery}\n{$utcNow};{$host};{$contentHash}";

        // Authenticate headers with ACS primary or secondary key
        $signature = $this->generateAuthenticationSignature($stringToSign);

        // get GUID part of message id to identify the long running operation
        $messageId = $this->generateMessageId();

        return [
            'Content-Type' => 'application/json',
            'repeatability-request-id' => $messageId,
            'Operation-Id' => $messageId,
            'repeatability-first-sent' => $utcNow,
            'host' =>  $host,
            'x-ms-date' => $utcNow,
            'x-ms-content-sha256' => $contentHash,
            'x-ms-client-request-id' => $messageId,
            'Authorization' => "HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature={$signature}",
        ];
    }

    /**
     * Can be used to identify the long running operation.
     */
    private function generateMessageId(): string {
        $data = random_bytes(16);
        \assert(16 == \strlen($data));
        $data[6] = \chr(\ord($data[6]) & 0x0F | 0x40);
        $data[8] = \chr(\ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

     /**
     * Get the string representation of the transport.
     */
    public function __toString(): string {
        return 'azure';
    }
    
     /**
     * List of attachments. Please note that the service limits the total size
     * of an email request (which includes attachments) to 10 MB.
     */
    private function getMessageAttachments(Email $email): array {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $att = [
                'name' => $filename,
                'contentInBase64' => base64_encode(str_replace("\r\n", '', $attachment->bodyToString())),
                'contentType' => $headers->get('Content-Type')->getBody(),
            ];

            if ('inline' === $disposition) {
                $att['content_id'] = $filename;
            }

            $attachments[] = $att;
        }

        return $attachments;
    }
    private function getMessageCustomHeaders(Email $email): array
    {
        $headers = [];

        $headersToBypass = ['x-ms-client-request-id', 'operation-id', 'authorization', 'x-ms-content-sha256', 'received', 'dkim-signature', 'content-transfer-encoding', 'from', 'to', 'cc', 'bcc', 'subject', 'content-type', 'reply-to'];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }
            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }

    private function getPriorityLevel(string $priority): ?string
    {
        return match ((int) $priority) {
            Email::PRIORITY_HIGHEST => 'highest',
            Email::PRIORITY_HIGH => 'high',
            Email::PRIORITY_NORMAL => 'normal',
            Email::PRIORITY_LOW => 'low',
            Email::PRIORITY_LOWEST => 'lowest',
        };
    }

}