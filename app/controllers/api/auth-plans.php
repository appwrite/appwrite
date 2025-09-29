<?php

use Appwrite\Auth\Validator\PlanData;
use Appwrite\Auth\Subscription\StripeService;
use Appwrite\Auth\Subscription\Exception\SubscriptionException;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::post('/v1/projects/:projectId/auth/plans')
    ->desc('Create auth plan')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'createAuthPlan',
        description: '/docs/references/projects/create-auth-plan.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_ANY,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('planId', '', new Text(128), 'Plan unique ID.')
    ->param('name', '', new Text(128), 'Plan name.')
    ->param('price', 0, new Integer(true), 'Price in cents.')
    ->param('currency', '', new Text(3), 'Currency code (3 letters).')
    ->param('interval', null, new WhiteList(['month', 'year', 'week', 'day'], true), 'Billing interval.', true)
    ->param('description', '', new Text(256), 'Plan description.', true)
    ->param('features', [], new ArrayList(new Text(256)), 'Plan features list.', true)
    ->param('maxUsers', null, new Integer(true), 'Maximum users allowed.', true)
    ->param('isDefault', false, new Boolean(), 'Is this the default plan.', true)
    ->param('isFree', false, new Boolean(), 'Is this a free plan.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->action(function (
        string $projectId,
        string $planId,
        string $name,
        int $price,
        string $currency,
        ?string $interval,
        string $description,
        array $features,
        ?int $maxUsers,
        bool $isDefault,
        bool $isFree,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject
    ) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if (!$project->getAttribute('authSubscriptionsEnabled')) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Auth subscriptions not configured for this project');
        }

        $stripeProductId = null;
        $stripePriceId = null;

        if (!$isFree) {
            try {
                $stripeService = new StripeService(
                    $project->getAttribute('authStripeSecretKey'),
                    $dbForProject,
                    $project
                );

                $product = $stripeService->createProduct($name, $description, $planId);
                $stripeProductId = $product['id'];

                $priceData = $stripeService->createPrice($stripeProductId, $price, $currency, $interval, $planId);
                $stripePriceId = $priceData['id'];
            } catch (SubscriptionException $e) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, $e->getMessage());
            }
        }

        if ($isDefault) {
            $existingDefault = $dbForPlatform->findOne('auth_plans', [
                Query::equal('projectId', [$projectId]),
                Query::equal('isDefault', [true])
            ]);

            if ($existingDefault instanceof Document) {
                $existingId = $existingDefault->getId();
                if (!empty($existingId)) {
                    $dbForPlatform->updateDocument('auth_plans', $existingId, $existingDefault
                        ->setAttribute('isDefault', false));
                }
            }
        }

        $plan = $dbForPlatform->createDocument('auth_plans', new Document([
            '$id' => ID::unique(),
            'projectInternalId' => $project->getSequence(),
            'projectId' => $projectId,
            'planId' => $planId,
            'name' => $name,
            'description' => $description,
            'stripeProductId' => $stripeProductId,
            'stripePriceId' => $stripePriceId,
            'price' => $price,
            'currency' => $currency,
            'interval' => $interval,
            'features' => $features,
            'isDefault' => $isDefault,
            'isFree' => $isFree,
            'maxUsers' => $maxUsers,
            'active' => true,
            'search' => implode(' ', [$planId, $name, $description])
        ]));

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($plan, Response::MODEL_ANY);
    });

App::get('/v1/projects/:projectId/auth/plans')
    ->desc('List auth plans')
    ->groups(['api'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'listAuthPlans',
        description: '/docs/references/projects/list-auth-plans.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('queries', [], new ArrayList(new Text(256)), 'Array of query strings.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, array $queries, Response $response, Database $dbForPlatform) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $queries[] = Query::equal('projectId', [$projectId]);
        $queries[] = Query::equal('active', [true]);

        $plans = $dbForPlatform->find('auth_plans', $queries);

        $response->dynamic(new Document([
            'total' => count($plans),
            'documents' => $plans
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/projects/:projectId/auth/plans/:planId')
    ->desc('Get auth plan')
    ->groups(['api'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'getAuthPlan',
        description: '/docs/references/projects/get-auth-plan.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ANY,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('planId', '', new Text(128), 'Plan ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->action(function (string $projectId, string $planId, Response $response, Database $dbForPlatform, Database $dbForProject) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $plan = $dbForPlatform->findOne('auth_plans', [
            Query::equal('projectId', [$projectId]),
            Query::equal('planId', [$planId])
        ]);

        if (!$plan || !$plan->getAttribute('active', true)) {
            throw new Exception(Exception::GENERAL_NOT_FOUND, 'Plan not found');
        }

        $response->dynamic($plan, Response::MODEL_ANY);
    });

App::put('/v1/projects/:projectId/auth/plans/:planId')
    ->desc('Update auth plan')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'updateAuthPlan',
        description: '/docs/references/projects/update-auth-plan.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ANY,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('planId', '', new Text(128), 'Plan ID.')
    ->param('name', null, new Text(128), 'Plan name.', true)
    ->param('description', null, new Text(256), 'Plan description.', true)
    ->param('features', null, new ArrayList(new Text(256)), 'Plan features list.', true)
    ->param('maxUsers', null, new Integer(true), 'Maximum users allowed.', true)
    ->param('isDefault', null, new Boolean(), 'Is this the default plan.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (
        string $projectId,
        string $planId,
        ?string $name,
        ?string $description,
        ?array $features,
        ?int $maxUsers,
        ?bool $isDefault,
        Response $response,
        Database $dbForPlatform
    ) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $plan = $dbForPlatform->findOne('auth_plans', [
            Query::equal('projectId', [$projectId]),
            Query::equal('planId', [$planId])
        ]);

        if (!$plan) {
            throw new Exception(Exception::GENERAL_NOT_FOUND, 'Plan not found');
        }

        if ($plan->getAttribute('isDefault', false) === true) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Cannot delete default plan');
        }

        if ($isDefault === true) {
            $existingDefault = $dbForPlatform->findOne('auth_plans', [
                Query::equal('projectId', [$projectId]),
                Query::equal('isDefault', [true]),
                Query::notEqual('$id', [$plan->getId()])
            ]);

            if ($existingDefault) {
                $dbForPlatform->updateDocument('auth_plans', $existingDefault->getId(), $existingDefault
                    ->setAttribute('isDefault', false));
            }
        }

        // Prevent removing the only default plan
        if ($isDefault === false && $plan->getAttribute('isDefault', false) === true) {
            $otherDefault = $dbForPlatform->findOne('auth_plans', [
                Query::equal('projectId', [$projectId]),
                Query::equal('isDefault', [true]),
                Query::notEqual('$id', [$plan->getId()])
            ]);
            if (!$otherDefault) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Cannot unset default plan without another default');
            }
        }

        if ($name !== null) $plan->setAttribute('name', $name);
        if ($description !== null) $plan->setAttribute('description', $description);
        if ($features !== null) $plan->setAttribute('features', $features);
        if ($maxUsers !== null) $plan->setAttribute('maxUsers', $maxUsers);
        if ($isDefault !== null) $plan->setAttribute('isDefault', $isDefault);

        $plan->setAttribute('search', implode(' ', [
            $plan->getAttribute('planId'),
            $plan->getAttribute('name'),
            $plan->getAttribute('description')
        ]));

        $plan = $dbForPlatform->updateDocument('auth_plans', $plan->getId(), $plan);

        $response->dynamic($plan, Response::MODEL_ANY);
    });

App::delete('/v1/projects/:projectId/auth/plans/:planId')
    ->desc('Delete auth plan')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'deleteAuthPlan',
        description: '/docs/references/projects/delete-auth-plan.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('planId', '', new Text(128), 'Plan ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->action(function (string $projectId, string $planId, Response $response, Database $dbForPlatform, Database $dbForProject) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $plan = $dbForPlatform->findOne('auth_plans', [
            Query::equal('projectId', [$projectId]),
            Query::equal('planId', [$planId])
        ]);

        if (!$plan) {
            throw new Exception(Exception::GENERAL_NOT_FOUND, 'Plan not found');
        }

        // Archive on Stripe first if non-free and keys configured
        if (!$plan->getAttribute('isFree', false) && $project->getAttribute('authSubscriptionsEnabled')) {
            try {
                $stripeService = new StripeService(
                    $project->getAttribute('authStripeSecretKey'),
                    $dbForProject,
                    $project
                );
                $priceId = $plan->getAttribute('stripePriceId');
                $productId = $plan->getAttribute('stripeProductId');
                if ($priceId) {
                    // Deactivate price
                    $stripeServicePrivate = new \ReflectionClass(StripeService::class);
                    $method = $stripeServicePrivate->getMethod('makeRequest');
                    $method->setAccessible(true);
                    $method->invoke($stripeService, 'POST', '/prices/' . $priceId, ['active' => 'false']);
                }
                if ($productId) {
                    // Deactivate product
                    $stripeServicePrivate = new \ReflectionClass(StripeService::class);
                    $method = $stripeServicePrivate->getMethod('makeRequest');
                    $method->setAccessible(true);
                    $method->invoke($stripeService, 'POST', '/products/' . $productId, ['active' => 'false']);
                }
            } catch (\Throwable $_) {
                // Swallow Stripe errors on delete to ensure local cleanup
            }
        }

        $dbForPlatform->deleteDocument('auth_plans', $plan->getId());

        $response->noContent();
    });