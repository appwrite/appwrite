<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Messaging\Adapter\SMS\Msg91\MetadataParameter;
use Utopia\Messaging\Messages\SMS;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

class GEOSMS extends SMSAdapter
{
    protected const NAME = 'GEOSMS';

    private Telemetry $telemetry;

    /**
     * @var array<string, SMSAdapter>
     */
    protected array $localAdapters = [];

    public function __construct(protected SMSAdapter $defaultAdapter)
    {
        $this->telemetry = new NoTelemetry();
        parent::__construct($this->telemetry);
        $this->defaultAdapter->setTelemetry($this->telemetry);
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return PHP_INT_MAX;
    }

    public function setLocal(string $callingCode, SMSAdapter $adapter): self
    {
        $this->localAdapters[$callingCode] = $adapter;
        $adapter->setTelemetry($this->telemetry);

        return $this;
    }

    #[\Override]
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
        parent::setTelemetry($telemetry);

        $this->defaultAdapter->setTelemetry($telemetry);

        foreach ($this->localAdapters as $adapter) {
            $adapter->setTelemetry($telemetry);
        }
    }

    /**
     * @return array<string>
     */
    protected function filterCallingCodesByAdapter(SMSAdapter $adapter): array
    {
        $result = [];

        foreach ($this->localAdapters as $callingCode => $localAdapter) {
            if ($localAdapter === $adapter) {
                $result[] = $callingCode;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array{deliveredTo: int, type: string, results: array<array<string, mixed>>}>
     */
    protected function process(SMS $message): array
    {
        $results = [];
        $recipients = $message->getTo();
        $batches = [];

        do {
            [$nextRecipients, $nextAdapter] = $this->getNextRecipientsAndAdapter($recipients);
            $batches[] = [
                'recipients' => $nextRecipients,
                'adapter' => $nextAdapter,
            ];

            $recipients = array_diff($recipients, $nextRecipients);
        } while (\count($recipients) > 0);

        foreach ($batches as $index => $batch) {
            $metadata = $message->getMetadata();
            if (\count($batches) > 1 && $metadata !== null) {
                foreach ([MetadataParameter::CRQID, MetadataParameter::UUID] as $parameter) {
                    $key = $parameter->value;

                    if (!\array_key_exists($key, $metadata)) {
                        continue;
                    }

                    if (!\is_string($metadata[$key])) {
                        throw new \InvalidArgumentException("Msg91 {$key} metadata must be a string.");
                    }

                    if (\strlen($metadata[$key]) > 80 || !preg_match('/^[A-Za-z0-9_.-]+$/', $metadata[$key])) {
                        throw new \InvalidArgumentException("Msg91 {$key} metadata must be 80 characters or less and contain only alphanumeric characters, underscores, dots, or hyphens.");
                    }

                    $suffix = '-' . ($index + 1);
                    $metadata[$key] = substr($metadata[$key], 0, 80 - \strlen($suffix)) . $suffix;
                }
            }

            try {
                $results[$batch['adapter']->getName()] = $batch['adapter']->send(
                    new SMS(
                        to: $batch['recipients'],
                        content: $message->getContent(),
                        from: $message->getFrom(),
                        attachments: $message->getAttachments(),
                        metadata: $metadata,
                    )->setOrigin($message->getOrigin()),
                );
            } catch (\Exception $e) {
                $results[$batch['adapter']->getName()] = [
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param  array<string>  $recipients
     * @return array<array<string>|SMSAdapter>
     */
    protected function getNextRecipientsAndAdapter(array $recipients): array
    {
        $nextRecipients = [];
        $nextAdapter = null;

        foreach ($recipients as $recipient) {
            $adapter = $this->getAdapterByPhoneNumber($recipient);

            if (!$nextAdapter instanceof \Utopia\Messaging\Adapter\SMS || $adapter === $nextAdapter) {
                $nextAdapter = $adapter;
                $nextRecipients[] = $recipient;
            }
        }

        return [$nextRecipients, $nextAdapter];
    }

    protected function getAdapterByPhoneNumber(?string $phoneNumber): SMSAdapter
    {
        $callingCode = CallingCode::fromPhoneNumber($phoneNumber);
        if (\in_array($callingCode, [null, '', '0'], true)) {
            return $this->defaultAdapter;
        }

        return $this->localAdapters[$callingCode] ?? $this->defaultAdapter;
    }
}
