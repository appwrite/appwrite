<?php

namespace Appwrite\Platform\Modules\Messaging\Services;

use Appwrite\Platform\Modules\Messaging\Http\Providers\Apns\Create as CreateApnsProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Apns\Update as UpdateApnsProvider;
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
        $this->addAction(ListProviders::getName(), new ListProviders());
        $this->addAction(GetProvider::getName(), new GetProvider());
        $this->addAction(DeleteProvider::getName(), new DeleteProvider());
    }
}
