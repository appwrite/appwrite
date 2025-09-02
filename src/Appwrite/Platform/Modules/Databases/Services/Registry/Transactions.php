<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\Transactions\Create as CreateTransaction;
use Appwrite\Platform\Modules\Databases\Http\Transactions\Delete as DeleteTransaction;
use Appwrite\Platform\Modules\Databases\Http\Transactions\Get as GetTransaction;
use Appwrite\Platform\Modules\Databases\Http\Transactions\Operations\Create as CreateOperations;
use Appwrite\Platform\Modules\Databases\Http\Transactions\Update as UpdateTransaction;
use Appwrite\Platform\Modules\Databases\Http\Transactions\XList as ListTransactions;
use Utopia\Platform\Service;

/**
 * Registers all HTTP actions related to transactions in the module.
 */
class Transactions extends Base
{
    public function register(Service $service): void
    {
        $service->addAction(CreateTransaction::getName(), new CreateTransaction());
        $service->addAction(GetTransaction::getName(), new GetTransaction());
        $service->addAction(UpdateTransaction::getName(), new UpdateTransaction());
        $service->addAction(DeleteTransaction::getName(), new DeleteTransaction());
        $service->addAction(ListTransactions::getName(), new ListTransactions());
        $service->addAction(CreateOperations::getName(), new CreateOperations());
    }
}
