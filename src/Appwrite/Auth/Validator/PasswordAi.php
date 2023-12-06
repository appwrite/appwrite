<?php

namespace Appwrite\Auth\Validator;

/**
 * Password.
 *
 * Validates user password string
 */
class PasswordAi extends Password
{
    public function __construct()
    {
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Password must be at least 8 characters and must be approved by AI.';
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        $prompt = 'My password is "' . $value . '". Check my password and tell me if it could include day, year, month, date, name, surname, animal name, location, city, animal breed, or state in any shape or form. Answer "Yes" if it does, and "no" if it doesnt. Only answer with 1 word.';

        $answer = '';

        $ch = curl_init('http://appwrite-assistant:3003/');
        $responseHeaders = [];
        $query = json_encode(['prompt' => $prompt]);
        $headers = ['accept: text/event-stream'];
        $handleEvent = function ($ch, $data) use ($answer) {
            $answer .= $data;
            return \strlen($data);
        };

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $handleEvent);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9000);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

        curl_exec($ch);

        curl_close($ch);

        if ($answer !== 'Yes') {
            return false;
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
