<?php

namespace Appwrite\Platform\Modules\Reviews\Services;

use Appwrite\Platform\Modules\Reviews\Http\Repositories\Create as CreateRepository;
use Appwrite\Platform\Modules\Reviews\Http\Repositories\Delete as DeleteRepository;
use Appwrite\Platform\Modules\Reviews\Http\Repositories\Tokens\Create as CreateRepositoryToken;
use Appwrite\Platform\Modules\Reviews\Http\Repositories\Usage\Create as CreateRepositoryUsage;
use Appwrite\Platform\Modules\Reviews\Http\Repositories\XList as ListRepositories;
use Appwrite\Platform\Modules\Reviews\Http\Reviews\Events\Create as CreateReviewEvents;
use Appwrite\Platform\Modules\Reviews\Http\Reviews\Get as GetReview;
use Appwrite\Platform\Modules\Reviews\Http\Reviews\Status\Update as UpdateReviewStatus;
use Appwrite\Platform\Modules\Reviews\Http\Reviews\Usage\Create as CreateReviewUsage;
use Appwrite\Platform\Modules\Reviews\Http\Reviews\XList as ListReviews;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(CreateRepository::getName(), new CreateRepository());
        $this->addAction(ListRepositories::getName(), new ListRepositories());
        $this->addAction(DeleteRepository::getName(), new DeleteRepository());

        $this->addAction(ListReviews::getName(), new ListReviews());
        $this->addAction(GetReview::getName(), new GetReview());

        // Called by the review service, not by projects
        $this->addAction(CreateRepositoryToken::getName(), new CreateRepositoryToken());
        $this->addAction(UpdateReviewStatus::getName(), new UpdateReviewStatus());
        $this->addAction(CreateReviewEvents::getName(), new CreateReviewEvents());
        $this->addAction(CreateReviewUsage::getName(), new CreateReviewUsage());
        $this->addAction(CreateRepositoryUsage::getName(), new CreateRepositoryUsage());
    }
}
