<?php
namespace Appwrite\Log\Adapters;

use Appwrite\Log\Log;

class LogRocket extends Log
{
    public function send(string $message, array $context = [])
    {
        // Example: send log to LogRocket
        $apiKey = getenv('LOGROCKET_API_KEY');
        if(!$apiKey) return false;

        $payload = [
            'message' => $message,
            'context' => $context,
        ];

        // You can use curl to send logs to LogRocket API
        $ch = curl_init('https://api.logrocket.com/logs');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result !== false;
    }
}

