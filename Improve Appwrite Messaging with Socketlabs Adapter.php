<?php
namespace Socketlabs\Appwrite\Adapter;

use Appwrite\Messaging\Adapter;
use Socketlabs\Messaging\Client;
use Socketlabs\Messaging\Email; 

class SocketlabsAdapter extends Adapter
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function send(array $message): bool
    {
        $email = new Email();
        $email
            ->setFrom($message['from'])
            ->setTo($message['to'])
            ->setSubject($message['subject'])
            ->setBody($message['body']);

        $response = $this->client->send($email);

        return $response->isSuccess();
    }

    public function trackOpen(string $messageId): bool
    {
        $response = $this->client->trackOpen($messageId);

        return $response->isSuccess();
    }

    public function trackClick(string $messageId, string $link): bool
    {
        $response = $this->client->trackClick($messageId, $link);

        return $response->isSuccess();
    }

    public function getTemplate(string $templateId): array
    {
        $response = $this->client->getTemplate($templateId);

        return $response->getData();
    }

    public function createTemplate(array $template): string
    {
        $response = $this->client->createTemplate($template);

        return $response->getData()['id'];
    }

    public function updateTemplate(string $templateId, array $template): bool
    {
        $response = $this->client->updateTemplate($templateId, $template);

        return $response->isSuccess();
    }

    public function deleteTemplate(string $templateId): bool
    {
        $response = $this->client->deleteTemplate($templateId);

        return $response->isSuccess();
    }
}
