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
        $this->addAction(CreateMailgunProvider::getName(), new CreateMailgunProvider());
        $this->addAction(UpdateMailgunProvider::getName(), new UpdateMailgunProvider());
        $this->addAction(CreateSendgridProvider::getName(), new CreateSendgridProvider());
        $this->addAction(UpdateSendgridProvider::getName(), new UpdateSendgridProvider());
        $this->addAction(CreateSesProvider::getName(), new CreateSesProvider());
        $this->addAction(UpdateSesProvider::getName(), new UpdateSesProvider());
        $this->addAction(CreateResendProvider::getName(), new CreateResendProvider());
        $this->addAction(UpdateResendProvider::getName(), new UpdateResendProvider());
        $this->addAction(CreateSmtpProvider::getName(), new CreateSmtpProvider());
        $this->addAction(UpdateSmtpProvider::getName(), new UpdateSmtpProvider());
        $this->addAction(CreateMsg91Provider::getName(), new CreateMsg91Provider());
        $this->addAction(UpdateMsg91Provider::getName(), new UpdateMsg91Provider());
        $this->addAction(CreateTelesignProvider::getName(), new CreateTelesignProvider());
        $this->addAction(UpdateTelesignProvider::getName(), new UpdateTelesignProvider());
        $this->addAction(CreateTextmagicProvider::getName(), new CreateTextmagicProvider());
        $this->addAction(UpdateTextmagicProvider::getName(), new UpdateTextmagicProvider());
        $this->addAction(CreateTwilioProvider::getName(), new CreateTwilioProvider());
        $this->addAction(UpdateTwilioProvider::getName(), new UpdateTwilioProvider());
        $this->addAction(CreateVonageProvider::getName(), new CreateVonageProvider());
        $this->addAction(UpdateVonageProvider::getName(), new UpdateVonageProvider());
        $this->addAction(CreateFcmProvider::getName(), new CreateFcmProvider());
        $this->addAction(UpdateFcmProvider::getName(), new UpdateFcmProvider());
        $this->addAction(CreateApnsProvider::getName(), new CreateApnsProvider());
        $this->addAction(UpdateApnsProvider::getName(), new UpdateApnsProvider());
        $this->addAction(CreateAppwriteProvider::getName(), new CreateAppwriteProvider());
        $this->addAction(UpdateAppwriteProvider::getName(), new UpdateAppwriteProvider());
        $this->addAction(ListProviders::getName(), new ListProviders());
        $this->addAction(GetProvider::getName(), new GetProvider());
        $this->addAction(DeleteProvider::getName(), new DeleteProvider());

        // Topics
        $this->addAction(CreateTopic::getName(), new CreateTopic());
        $this->addAction(ListTopics::getName(), new ListTopics());
        $this->addAction(GetTopic::getName(), new GetTopic());
        $this->addAction(UpdateTopic::getName(), new UpdateTopic());
        $this->addAction(DeleteTopic::getName(), new DeleteTopic());

        // Subscribers
        $this->addAction(CreateSubscriber::getName(), new CreateSubscriber());
        $this->addAction(ListSubscribers::getName(), new ListSubscribers());
        $this->addAction(GetSubscriber::getName(), new GetSubscriber());
        $this->addAction(DeleteSubscriber::getName(), new DeleteSubscriber());

        // Messages
        $this->addAction(CreateEmail::getName(), new CreateEmail());
        $this->addAction(CreateSms::getName(), new CreateSms());
        $this->addAction(CreatePush::getName(), new CreatePush());
        $this->addAction(ListMessages::getName(), new ListMessages());
        $this->addAction(ListTargets::getName(), new ListTargets());
        $this->addAction(GetMessage::getName(), new GetMessage());
        $this->addAction(UpdateEmail::getName(), new UpdateEmail());
        $this->addAction(UpdateSms::getName(), new UpdateSms());
        $this->addAction(UpdatePush::getName(), new UpdatePush());
        $this->addAction(DeleteMessage::getName(), new DeleteMessage());
    }
}
