<?php

namespace Appwrite\Platform\Modules\Payments\Services;

use Appwrite\Platform\Modules\Payments\Http\Features\Create as FeaturesCreate;
use Appwrite\Platform\Modules\Payments\Http\Features\Delete as FeaturesDelete;
use Appwrite\Platform\Modules\Payments\Http\Features\Update as FeaturesUpdate;
use Appwrite\Platform\Modules\Payments\Http\Features\XList as FeaturesList;
use Appwrite\Platform\Modules\Payments\Http\PlanFeatures\Assign as PlanFeaturesAssign;
use Appwrite\Platform\Modules\Payments\Http\PlanFeatures\Remove as PlanFeaturesRemove;
use Appwrite\Platform\Modules\Payments\Http\PlanFeatures\XList as PlanFeaturesList;
use Appwrite\Platform\Modules\Payments\Http\Plans\Create as PlansCreate;
use Appwrite\Platform\Modules\Payments\Http\Plans\Delete as PlansDelete;
use Appwrite\Platform\Modules\Payments\Http\Plans\Get as PlansGet;
use Appwrite\Platform\Modules\Payments\Http\Plans\Update as PlansUpdate;
use Appwrite\Platform\Modules\Payments\Http\Plans\XList as PlansList;
use Appwrite\Platform\Modules\Payments\Http\Providers\Actions\Test\Create as ProvidersTest;
use Appwrite\Platform\Modules\Payments\Http\Providers\Get as ProvidersGet;
use Appwrite\Platform\Modules\Payments\Http\Providers\Update as ProvidersUpdate;
use Appwrite\Platform\Modules\Payments\Http\Subscriptions\Cancel as SubscriptionsCancel;
use Appwrite\Platform\Modules\Payments\Http\Subscriptions\Create as SubscriptionsCreate;
use Appwrite\Platform\Modules\Payments\Http\Subscriptions\Get as SubscriptionsGet;
use Appwrite\Platform\Modules\Payments\Http\Subscriptions\Resume as SubscriptionsResume;
use Appwrite\Platform\Modules\Payments\Http\Subscriptions\Update as SubscriptionsUpdate;
use Appwrite\Platform\Modules\Payments\Http\Subscriptions\XList as SubscriptionsList;
use Appwrite\Platform\Modules\Payments\Http\Usage\Create as UsageCreate;
use Appwrite\Platform\Modules\Payments\Http\Usage\Events\XList as UsageEventsList;
use Appwrite\Platform\Modules\Payments\Http\Usage\Get as UsageGet;
use Appwrite\Platform\Modules\Payments\Http\Usage\Reconcile\Create as UsageReconcile;
use Appwrite\Platform\Modules\Payments\Http\Webhooks\Provider\Create as WebhookProviderCreate;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Plans
        $this->addAction(PlansCreate::getName(), new PlansCreate());
        $this->addAction(PlansGet::getName(), new PlansGet());
        $this->addAction(PlansList::getName(), new PlansList());
        $this->addAction(PlansUpdate::getName(), new PlansUpdate());
        $this->addAction(PlansDelete::getName(), new PlansDelete());

        // Features
        $this->addAction(FeaturesCreate::getName(), new FeaturesCreate());
        $this->addAction(FeaturesList::getName(), new FeaturesList());
        $this->addAction(FeaturesUpdate::getName(), new FeaturesUpdate());
        $this->addAction(FeaturesDelete::getName(), new FeaturesDelete());

        // Plan Features
        $this->addAction(PlanFeaturesAssign::getName(), new PlanFeaturesAssign());
        $this->addAction(PlanFeaturesList::getName(), new PlanFeaturesList());
        $this->addAction(PlanFeaturesRemove::getName(), new PlanFeaturesRemove());

        // Subscriptions
        $this->addAction(SubscriptionsCreate::getName(), new SubscriptionsCreate());
        $this->addAction(SubscriptionsList::getName(), new SubscriptionsList());
        $this->addAction(SubscriptionsGet::getName(), new SubscriptionsGet());
        $this->addAction(SubscriptionsUpdate::getName(), new SubscriptionsUpdate());
        $this->addAction(SubscriptionsCancel::getName(), new SubscriptionsCancel());
        $this->addAction(SubscriptionsResume::getName(), new SubscriptionsResume());

        // Usage
        $this->addAction(UsageGet::getName(), new UsageGet());
        $this->addAction(UsageCreate::getName(), new UsageCreate());
        $this->addAction(UsageEventsList::getName(), new UsageEventsList());
        $this->addAction(UsageReconcile::getName(), new UsageReconcile());

        // Providers
        $this->addAction(ProvidersGet::getName(), new ProvidersGet());
        $this->addAction(ProvidersUpdate::getName(), new ProvidersUpdate());
        $this->addAction(ProvidersTest::getName(), new ProvidersTest());

        // Webhooks
        $this->addAction(WebhookProviderCreate::getName(), new WebhookProviderCreate());
    }
}
