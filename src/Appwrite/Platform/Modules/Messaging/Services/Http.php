<?php

namespace Appwrite\Platform\Modules\Messaging\Services;

use Appwrite\Platform\Modules\Messaging\Http\Messages\Delete as DeleteMessage;
use Appwrite\Platform\Modules\Messaging\Http\Messages\Email\Create as CreateEmail;
use Appwrite\Platform\Modules\Messaging\Http\Messages\Email\Update as UpdateEmail;
use Appwrite\Platform\Modules\Messaging\Http\Messages\Get as GetMessage;
use Appwrite\Platform\Modules\Messaging\Http\Messages\Push\Create as CreatePush;
use Appwrite\Platform\Modules\Messaging\Http\Messages\Push\Update as UpdatePush;
use Appwrite\Platform\Modules\Messaging\Http\Messages\Sms\Create as CreateSms;
use Appwrite\Platform\Modules\Messaging\Http\Messages\Sms\Update as UpdateSms;
use Appwrite\Platform\Modules\Messaging\Http\Messages\Targets\XList as ListTargets;
use Appwrite\Platform\Modules\Messaging\Http\Messages\XList as ListMessages;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Apns\Create as CreateApnsProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Apns\Update as UpdateApnsProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Appwrite\Create as CreateAppwriteProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Appwrite\Update as UpdateAppwriteProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Delete as DeleteProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Fcm\Create as CreateFcmProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Fcm\Update as UpdateFcmProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Get as GetProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Mailgun\Create as CreateMailgunProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Mailgun\Update as UpdateMailgunProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Msg91\Create as CreateMsg91Provider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Msg91\Update as UpdateMsg91Provider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Resend\Create as CreateResendProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Resend\Update as UpdateResendProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Sendgrid\Create as CreateSendgridProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Sendgrid\Update as UpdateSendgridProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Ses\Create as CreateSesProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Ses\Update as UpdateSesProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Smtp\Create as CreateSmtpProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Smtp\Update as UpdateSmtpProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Telesign\Create as CreateTelesignProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Telesign\Update as UpdateTelesignProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Textmagic\Create as CreateTextmagicProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Textmagic\Update as UpdateTextmagicProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Twilio\Create as CreateTwilioProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Twilio\Update as UpdateTwilioProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Vonage\Create as CreateVonageProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Vonage\Update as UpdateVonageProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\XList as ListProviders;
use Appwrite\Platform\Modules\Messaging\Http\Topics\Create as CreateTopic;
use Appwrite\Platform\Modules\Messaging\Http\Topics\Delete as DeleteTopic;
use Appwrite\Platform\Modules\Messaging\Http\Topics\Get as GetTopic;
use Appwrite\Platform\Modules\Messaging\Http\Topics\Subscribers\Create as CreateSubscriber;
use Appwrite\Platform\Modules\Messaging\Http\Topics\Subscribers\Delete as DeleteSubscriber;
use Appwrite\Platform\Modules\Messaging\Http\Topics\Subscribers\Get as GetSubscriber;
use Appwrite\Platform\Modules\Messaging\Http\Topics\Subscribers\XList as ListSubscribers;
use Appwrite\Platform\Modules\Messaging\Http\Topics\Update as UpdateTopic;
use Appwrite\Platform\Modules\Messaging\Http\Topics\XList as ListTopics;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Providers
        $this->addAction('createMailgunProvider', CreateMailgunProvider::class);
        $this->addAction('updateMailgunProvider', UpdateMailgunProvider::class);
        $this->addAction('createSendgridProvider', CreateSendgridProvider::class);
        $this->addAction('updateSendgridProvider', UpdateSendgridProvider::class);
        $this->addAction('createSesProvider', CreateSesProvider::class);
        $this->addAction('updateSesProvider', UpdateSesProvider::class);
        $this->addAction('createResendProvider', CreateResendProvider::class);
        $this->addAction('updateResendProvider', UpdateResendProvider::class);
        $this->addAction('createSmtpProvider', CreateSmtpProvider::class);
        $this->addAction('updateSmtpProvider', UpdateSmtpProvider::class);
        $this->addAction('createMsg91Provider', CreateMsg91Provider::class);
        $this->addAction('updateMsg91Provider', UpdateMsg91Provider::class);
        $this->addAction('createTelesignProvider', CreateTelesignProvider::class);
        $this->addAction('updateTelesignProvider', UpdateTelesignProvider::class);
        $this->addAction('createTextmagicProvider', CreateTextmagicProvider::class);
        $this->addAction('updateTextmagicProvider', UpdateTextmagicProvider::class);
        $this->addAction('createTwilioProvider', CreateTwilioProvider::class);
        $this->addAction('updateTwilioProvider', UpdateTwilioProvider::class);
        $this->addAction('createVonageProvider', CreateVonageProvider::class);
        $this->addAction('updateVonageProvider', UpdateVonageProvider::class);
        $this->addAction('createFcmProvider', CreateFcmProvider::class);
        $this->addAction('updateFcmProvider', UpdateFcmProvider::class);
        $this->addAction('createApnsProvider', CreateApnsProvider::class);
        $this->addAction('updateApnsProvider', UpdateApnsProvider::class);
        $this->addAction('createAppwriteProvider', CreateAppwriteProvider::class);
        $this->addAction('updateAppwriteProvider', UpdateAppwriteProvider::class);
        $this->addAction('listProviders', ListProviders::class);
        $this->addAction('getProvider', GetProvider::class);
        $this->addAction('deleteProvider', DeleteProvider::class);

        // Topics
        $this->addAction('createTopic', CreateTopic::class);
        $this->addAction('listTopics', ListTopics::class);
        $this->addAction('getTopic', GetTopic::class);
        $this->addAction('updateTopic', UpdateTopic::class);
        $this->addAction('deleteTopic', DeleteTopic::class);

        // Subscribers
        $this->addAction('createSubscriber', CreateSubscriber::class);
        $this->addAction('listSubscribers', ListSubscribers::class);
        $this->addAction('getSubscriber', GetSubscriber::class);
        $this->addAction('deleteSubscriber', DeleteSubscriber::class);

        // Messages
        $this->addAction('createEmail', CreateEmail::class);
        $this->addAction('createSMS', CreateSms::class);
        $this->addAction('createPush', CreatePush::class);
        $this->addAction('listMessages', ListMessages::class);
        $this->addAction('listTargets', ListTargets::class);
        $this->addAction('getMessage', GetMessage::class);
        $this->addAction('updateEmail', UpdateEmail::class);
        $this->addAction('updateSMS', UpdateSms::class);
        $this->addAction('updatePush', UpdatePush::class);
        $this->addAction('delete', DeleteMessage::class);
    }
}
