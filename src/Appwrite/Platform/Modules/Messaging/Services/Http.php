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
        $this->addAction(CreateMailgunProvider::getName(), CreateMailgunProvider::class);
        $this->addAction(UpdateMailgunProvider::getName(), UpdateMailgunProvider::class);
        $this->addAction(CreateSendgridProvider::getName(), CreateSendgridProvider::class);
        $this->addAction(UpdateSendgridProvider::getName(), UpdateSendgridProvider::class);
        $this->addAction(CreateSesProvider::getName(), CreateSesProvider::class);
        $this->addAction(UpdateSesProvider::getName(), UpdateSesProvider::class);
        $this->addAction(CreateResendProvider::getName(), CreateResendProvider::class);
        $this->addAction(UpdateResendProvider::getName(), UpdateResendProvider::class);
        $this->addAction(CreateSmtpProvider::getName(), CreateSmtpProvider::class);
        $this->addAction(UpdateSmtpProvider::getName(), UpdateSmtpProvider::class);
        $this->addAction(CreateMsg91Provider::getName(), CreateMsg91Provider::class);
        $this->addAction(UpdateMsg91Provider::getName(), UpdateMsg91Provider::class);
        $this->addAction(CreateTelesignProvider::getName(), CreateTelesignProvider::class);
        $this->addAction(UpdateTelesignProvider::getName(), UpdateTelesignProvider::class);
        $this->addAction(CreateTextmagicProvider::getName(), CreateTextmagicProvider::class);
        $this->addAction(UpdateTextmagicProvider::getName(), UpdateTextmagicProvider::class);
        $this->addAction(CreateTwilioProvider::getName(), CreateTwilioProvider::class);
        $this->addAction(UpdateTwilioProvider::getName(), UpdateTwilioProvider::class);
        $this->addAction(CreateVonageProvider::getName(), CreateVonageProvider::class);
        $this->addAction(UpdateVonageProvider::getName(), UpdateVonageProvider::class);
        $this->addAction(CreateFcmProvider::getName(), CreateFcmProvider::class);
        $this->addAction(UpdateFcmProvider::getName(), UpdateFcmProvider::class);
        $this->addAction(CreateApnsProvider::getName(), CreateApnsProvider::class);
        $this->addAction(UpdateApnsProvider::getName(), UpdateApnsProvider::class);
        $this->addAction(CreateAppwriteProvider::getName(), CreateAppwriteProvider::class);
        $this->addAction(UpdateAppwriteProvider::getName(), UpdateAppwriteProvider::class);
        $this->addAction(ListProviders::getName(), ListProviders::class);
        $this->addAction(GetProvider::getName(), GetProvider::class);
        $this->addAction(DeleteProvider::getName(), DeleteProvider::class);

        // Topics
        $this->addAction(CreateTopic::getName(), CreateTopic::class);
        $this->addAction(ListTopics::getName(), ListTopics::class);
        $this->addAction(GetTopic::getName(), GetTopic::class);
        $this->addAction(UpdateTopic::getName(), UpdateTopic::class);
        $this->addAction(DeleteTopic::getName(), DeleteTopic::class);

        // Subscribers
        $this->addAction(CreateSubscriber::getName(), CreateSubscriber::class);
        $this->addAction(ListSubscribers::getName(), ListSubscribers::class);
        $this->addAction(GetSubscriber::getName(), GetSubscriber::class);
        $this->addAction(DeleteSubscriber::getName(), DeleteSubscriber::class);

        // Messages
        $this->addAction(CreateEmail::getName(), CreateEmail::class);
        $this->addAction(CreateSms::getName(), CreateSms::class);
        $this->addAction(CreatePush::getName(), CreatePush::class);
        $this->addAction(ListMessages::getName(), ListMessages::class);
        $this->addAction(ListTargets::getName(), ListTargets::class);
        $this->addAction(GetMessage::getName(), GetMessage::class);
        $this->addAction(UpdateEmail::getName(), UpdateEmail::class);
        $this->addAction(UpdateSms::getName(), UpdateSms::class);
        $this->addAction(UpdatePush::getName(), UpdatePush::class);
        $this->addAction(DeleteMessage::getName(), DeleteMessage::class);
    }
}
