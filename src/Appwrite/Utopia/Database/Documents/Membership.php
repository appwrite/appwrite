<?php

namespace Appwrite\Utopia\Database\Documents;

use Utopia\Database\Document;

class Membership extends Document
{
    /**
     * Channel the invite secret was delivered over.
     * Unknown rows fail safe: only infer email or phone when the user has exactly one.
     */
    public function deliveredChannel(Document $user): string
    {
        $channel = $this->getAttribute('channel', '');

        if ($channel === 'email' || $channel === 'phone') {
            return $channel;
        }

        $email = $user->getAttribute('email', '');
        $phone = $user->getAttribute('phone', '');

        if (!empty($email) && empty($phone)) {
            return 'email';
        }

        if (!empty($phone) && empty($email)) {
            return 'phone';
        }

        return '';
    }
}
