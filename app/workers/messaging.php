<?php

use Appwrite\DSN\DSN;
use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Messaging\Adapter;

use Utopia\Messaging\Adapters\SMS as SMSAdapter;
use Utopia\Messaging\Adapters\SMS\Mock;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Messaging\Messages\SMS;

use Utopia\Messaging\Adapters\Push as PushAdapter;
use Utopia\Messaging\Adapters\Push\APNS;
use Utopia\Messaging\Adapters\Push\FCM;
use Utopia\Messaging\Messages\Push;

use Utopia\Messaging\Adapters\Email as EmailAdapter;
use Utopia\Messaging\Adapters\Email\Mailgun;
use Utopia\Messaging\Adapters\Email\SendGrid;
use Utopia\Messaging\Messages\Email;


require_once __DIR__ . '/../init.php';

Console::title('Messaging V1 Worker');
Console::success(APP_NAME . ' messaging worker v1 has started' . "\n");

class MessagingV1 extends Worker
{
    protected ?SMSAdapter $sms = null;
    protected ?PushAdapter $push = null;
    protected ?EmailAdapter $email = null;
    
    
    protected ?string $from = null;

    public function getName(): string
    {
        return "mails";
    }

    public function sms($record): ?SMSAdapter
    {
          return match ($record->getAttribute('provider')) {
              'mock' => new Mock($user, $secret), // used for tests
              'twilio' => new Twilio($user, $secret),
              'text-magic' => new TextMagic($user, $secret),
              'telesign' => new Telesign($user, $secret),
              'msg91' => new Msg91($user, $secret),
              'vonage' => new Vonage($user, $secret),
              default => null
          };  
        }
    }

    function push($record): ?PushAdapter
    {
       return match ($record->getAttribute('provider')) {
            'apns' => new APNS($user, $secret),
            'fcm' => new FCM($user, $secret),
            default => null
        };  
    }

    function email(): ?EmailAdapter
    {
        return match ($record->getAttribute('provider')) {
            'mailgun' => new Mailgun($user, $secret),
            'sendgrid' => new SendGrid($user, $secret),
            default => null
        };  
    }

    public function init(): void
    {
    }

    public function run(): void
    {
      $providerId = $this->args['providerId'];
      $providerRecord = 
        $this
        ->getConsoleDB()
        ->getCollection('providers')
        ->getDocument($providerId);

      $provider = match ($providerRecord->getAttribute('type')) {//stubbbbbbed.
        'sms' => $this->sms($providerRecord),
        'push' => $this->push($providerRecord),
        'email' => $this->email($providerRecord),
        default => null
      };

      // Query for the provider
      // switch on provider name
      // call function passing needed credentials returns required provider.

      $messageId = $this->args['messageId'];
      $message = 
        $this
        ->getConsoleDB()
        ->getCollection('messages')
        ->getDocument($messageId);

      // Get message

      // set up message based on provider

      // send a message.

      $provider->send($message);
    }

    public function shutdown(): void
    {
    }
}
