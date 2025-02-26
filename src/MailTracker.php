<?php

namespace jdavidbakr\MailTracker;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use jdavidbakr\MailTracker\Contracts\SentEmailModel;
use jdavidbakr\MailTracker\Events\EmailSentEvent;
use jdavidbakr\MailTracker\Model\SentEmail;
use jdavidbakr\MailTracker\Model\SentEmailUrlClicked;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;

class MailTracker
{
    // Set this to "false" to skip this library migrations
    public static $runsMigrations = true;

    protected $hash;

    // Allow developers to provide their own
    protected Closure $messageIdResolver;

    /**
     * The SentEmail model class name.
     *
     * @var string
     */
    public static string $sentEmailModel = SentEmail::class;

    /**
     * The SentEmailUrlClicked model class name.
     *
     * @var string
     */
    public static string $sentEmailUrlClickedModel = SentEmailUrlClicked::class;

    /**
     * Configure this library to not register its migrations.
     *
     * @return static
     */
    public static function ignoreMigrations()
    {
        static::$runsMigrations = false;

        return new static;
    }

    /**
     * Set class name of SentEmail model.
     *
     * @param string $sentEmailModelClass
     * @return void
     */
    public static function useSentEmailModel(string $sentEmailModelClass): void
    {
        static::$sentEmailModel = $sentEmailModelClass;
    }

    /**
     * Create new SentEmail model.
     *
     * @param array $attributes
     * @return Model|SentEmail
     */
    public static function sentEmailModel(array $attributes = []): Model|SentEmail
    {
        return new static::$sentEmailModel($attributes);
    }

    /**
     * Set class name of SentEmailUrlClicked model.
     *
     * @param string $class
     * @return void
     */
    public static function useSentEmailUrlClickedModel(string $class): void
    {
        static::$sentEmailUrlClickedModel = $class;
    }

    /**
     * Create new SentEmailUrlClicked model.
     *
     * @param array $attributes
     * @return Model|SentEmailUrlClicked
     */
    public static function sentEmailUrlClickedModel(array $attributes = []): Model|SentEmailUrlClicked
    {
        return new static::$sentEmailUrlClickedModel($attributes);
    }

    /**
     * Inject the tracking code into the message
     */
    public function messageSending(MessageSending $event)
    {
        $message = $event->message;

        // Create the trackers
        $this->createTrackers($message);

        // Purge old records
        $this->purgeOldRecords();
    }

    public function messageSent(MessageSent $event): void
    {
        $sentMessage = $event->sent;
        $headers = $sentMessage->getOriginalMessage()->getHeaders();
        $hash = optional($headers->get('X-Mailer-Hash'))->getBody();
        $sentEmail = MailTracker::sentEmailModel()->newQuery()->where('hash', $hash)->first();

        if ($sentEmail) {
            $sentEmail->message_id = $this->callMessageIdResolverUsing($sentMessage);
            $sentEmail->save();
        }
    }

    public function getMessageIdResolver(): Closure
    {
        if (! isset($this->messageIdResolver)) {
            $this->resolveMessageIdUsing($this->getDefaultMessageIdResolver());
        }

        return $this->messageIdResolver;
    }

    public function resolveMessageIdUsing(Closure $resolver): self
    {
        $this->messageIdResolver = $resolver;
        return $this;
    }

    protected function getDefaultMessageIdResolver(): Closure
    {
        return function (SentMessage $message) {
            /** @var \Symfony\Component\Mime\Header\Headers $headers */
            $headers = $message->getOriginalMessage()->getHeaders();

            // Laravel supports multiple mail drivers.
            // We try to guess if this email was sent using SES
            if ($messageHeader = $headers->get('X-SES-Message-ID')) {
                return $messageHeader->getBody();
            }

            // Second attempt, get the default message ID from symfony mailer
            return $message->getMessageId();
        };
    }

    protected function callMessageIdResolverUsing(SentMessage $message): string
    {
        return $this->getMessageIdResolver()(...func_get_args());
    }

    protected function addTrackers($html, $hash)
    {
        if (config('mail-tracker.inject-pixel')) {
            $html = $this->injectTrackingPixel($html, $hash);
        }
        if (config('mail-tracker.track-links')) {
            $html = $this->injectLinkTracker($html, $hash);
        }

        return $html;
    }

    protected function injectTrackingPixel($html, $hash)
    {
        // Append the tracking url
        $tracking_pixel = '<img border=0 width=1 alt="" height=1 src="' . route('mailTracker_t', [$hash]) . '" />';

        $linebreak = app(Str::class)->random(32);
        $html = str_replace("\n", $linebreak, $html);

        if (preg_match("/^(.*<body[^>]*>)(.*)$/", $html, $matches)) {
            $html = $matches[1] . $tracking_pixel . $matches[2];
        } else {
            $html = $html . $tracking_pixel;
        }
        $html = str_replace($linebreak, "\n", $html);

        return $html;
    }

    protected function injectLinkTracker($html, $hash)
    {
        $this->hash = $hash;

        $html = preg_replace_callback(
            "/(<a[^>]*href=[\"])([^\"]*)/",
            [$this, 'inject_link_callback'],
            $html
        );

        return $html;
    }

    protected function inject_link_callback($matches)
    {
        if (empty($matches[2])) {
            $url = app()->make('url')->to('/');
        } else {
            $url = str_replace('&amp;', '&', $matches[2]);
        }

        return $matches[1] . route(
            'mailTracker_n',
            [
                'l' => $url,
                'h' => $this->hash
            ]
        );
    }

    /**
     * Legacy function
     *
     * @param [type] $url
     * @return boolean
     */
    public static function hash_url($url)
    {
        // Replace "/" with "$"
        return str_replace("/", "$", base64_encode($url));
    }

    /**
     * Create the trackers
     *
     * @param  Email $message
     * @return void
     */
    protected function createTrackers(Email $message)
    {
        // Process each recipient
        foreach ($message->getTo() as $toAddress) {
            $to_email = $toAddress->getAddress();
            $to_name = $toAddress->getName();

            // Process each sender
            foreach ($message->getFrom() as $fromAddress) {
                $from_email = $fromAddress->getAddress();
                $from_name = $fromAddress->getName();
                $headers = $message->getHeaders();

                // Skip if tracking is disabled for this email
                if ($headers->get('X-No-Track')) {
                    continue;
                }

                // Generate a unique hash for this email
                $hash = $this->generateUniqueHash();
                $headers->addTextHeader('X-Mailer-Hash', $hash);
                $subject = $message->getSubject();

                // Process the email body to add tracking
                $original_html = $this->processMessageBody($message, $hash);

                // Create and save the tracker record
                $this->saveTrackerRecord($hash, $headers, $from_name, $from_email, $to_name, $to_email, $subject, $original_html);
            }
        }

        // Remove tracking opt-out header if it was set
        $message->getHeaders()->remove('X-No-Track');
    }

    /**
     * Generate a unique hash for tracking
     * 
     * @return string
     */
    protected function generateUniqueHash(): string
    {
        do {
            $hash = app(Str::class)->random(32);
            $used = MailTracker::sentEmailModel()->newQuery()->where('hash', $hash)->count();
        } while ($used > 0);

        return $hash;
    }

    /**
     * Process the message body to add tracking elements
     * 
     * @param Email $message
     * @param string $hash
     * @return string The original HTML content
     */
    protected function processMessageBody(Email $message, string $hash): string
    {
        $original_content = $message->getBody();
        $original_html = '';

        // Handle different types of message bodies
        if ($this->isMultipartContent($original_content)) {
            $original_html = $this->processMultipartContent($message, $original_content, $hash);
        } else {
            $original_html = $this->processSinglePartContent($message, $original_content, $hash);
        }

        return $original_html;
    }

    /**
     * Check if content is multipart
     * 
     * @param mixed $content
     * @return bool
     */
    protected function isMultipartContent($content): bool
    {
        return ($content instanceof AlternativePart) ||
            ($content instanceof MixedPart) ||
            ($content instanceof RelatedPart);
    }

    /**
     * Process multipart content
     * 
     * @param Email $message
     * @param mixed $original_content
     * @param string $hash
     * @return string
     */
    protected function processMultipartContent(Email $message, $original_content, string $hash): string
    {
        $messageBody = $message->getBody() ?: [];
        $newParts = [];
        $original_html = '';

        foreach ($messageBody->getParts() as $part) {
            if ($part->getMediaSubtype() == 'html') {
                // Process HTML part
                $original_html = $part->getBody();
                $newParts[] = $this->createTrackedTextPart($original_html, $message, $part, $hash);
            } else if (in_array($part->getMediaSubtype(), ['alternative', 'related'])) {
                // Process nested multipart content
                if (method_exists($part, 'getParts')) {
                    $processed = $this->processNestedParts($part, $message, $hash);
                    $original_html = $processed['html'];
                    $newParts = array_merge($newParts, $processed['parts']);
                }
            } else {
                // Keep other parts unchanged
                $newParts[] = $part;
            }
        }

        // Replace the message body with the new parts
        $message->setBody(new (get_class($original_content))(...$newParts));

        return $original_html;
    }

    /**
     * Process nested parts in multipart content
     * 
     * @param mixed $part
     * @param Email $message
     * @param string $hash
     * @return array
     */
    protected function processNestedParts($part, Email $message, string $hash): array
    {
        $newParts = [];
        $original_html = '';
        $processed = false;

        foreach ($part->getParts() as $p) {
            // Check if this is an HTML part
            if ($p->getMediaSubtype() == 'html') {
                $original_html = $p->getBody();
                $newParts[] = $this->createTrackedTextPart($original_html, $message, $p, $hash);
                $processed = true;
            }
            // Check if this is another nested part that might contain HTML
            else if (method_exists($p, 'getParts') && !empty($p->getParts())) {
                $nestedResult = $this->processNestedParts($p, $message, $hash);
                if ($nestedResult['processed']) {
                    $original_html = $nestedResult['html'];
                    $newParts = array_merge($newParts, $nestedResult['parts']);
                    $processed = true;
                } else {
                    $newParts[] = $p;
                }
            } else {
                $newParts[] = $p;
            }
        }

        return [
            'html' => $original_html,
            'parts' => $newParts,
            'processed' => $processed
        ];
    }

    /**
     * Process single part content
     * 
     * @param Email $message
     * @param mixed $original_content
     * @param string $hash
     * @return string
     */
    protected function processSinglePartContent(Email $message, $original_content, string $hash): string
    {
        $original_html = $original_content->getBody();

        if ($original_content->getMediaSubtype() == 'html') {
            $message->setBody(
                $this->createTrackedTextPart($original_html, $message, $original_content, $hash)
            );
        }

        return $original_html;
    }

    /**
     * Create a TextPart with tracking added
     * 
     * @param string $html
     * @param Email $message
     * @param mixed $part
     * @param string $hash
     * @return TextPart
     */
    protected function createTrackedTextPart(string $html, Email $message, $part, string $hash): TextPart
    {
        return new TextPart(
            $this->addTrackers($html, $hash),
            $message->getHtmlCharset(),
            $part->getMediaSubtype(),
            null
        );
    }

    /**
     * Save the tracker record to the database
     * 
     * @param string $hash
     * @param mixed $headers
     * @param string $from_name
     * @param string $from_email
     * @param string $to_name
     * @param string $to_email
     * @param string $subject
     * @param string $original_html
     * @return void
     */
    protected function saveTrackerRecord(
        string $hash,
        $headers,
        string $from_name,
        string $from_email,
        string $to_name,
        string $to_email,
        string $subject,
        string $original_html
    ): void {
        /** @var SentEmail $tracker */
        $tracker = tap(MailTracker::sentEmailModel([
            'hash' => $hash,
            'headers' => $headers->toString(),
            'sender_name' => $from_name,
            'sender_email' => $from_email,
            'recipient_name' => $to_name,
            'recipient_email' => $to_email,
            'subject' => $subject,
            'opens' => 0,
            'clicks' => 0,
            'message_id' => Str::uuid(),
        ]), function (Model|SentEmailModel $sentEmail) use ($original_html, $hash) {
            $sentEmail->fillContent($original_html, $hash);
            $sentEmail->save();
        });

        Event::dispatch(new EmailSentEvent($tracker));
    }

    /**
     * Purge old records in the database
     *
     * @return void
     */
    protected function purgeOldRecords()
    {
        if (config('mail-tracker.expire-days') > 0) {
            $emails = MailTracker::sentEmailModel()->newQuery()->where('created_at', '<', \Carbon\Carbon::now()
                ->subDays(config('mail-tracker.expire-days')))
                ->select('id', 'meta')
                ->get();
            // remove files
            $emails->each(function ($email) {
                if ($email->meta && ($filePath = $email->meta->get('content_file_path'))) {
                    Storage::disk(config('mail-tracker.tracker-filesystem'))->delete($filePath);
                }
            });

            MailTracker::sentEmailUrlClickedModel()->newQuery()->whereIn('sent_email_id', $emails->pluck('id'))->delete();
            MailTracker::sentEmailModel()->newQuery()->whereIn('id', $emails->pluck('id'))->delete();
        }
    }
}
