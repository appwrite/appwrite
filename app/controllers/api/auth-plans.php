<?php

use Appwrite\Auth\Subscription\Exception\SubscriptionException;
use Appwrite\Auth\Subscription\StripeService;
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
use Utopia\Validator\Assoc;
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
    ->param('features', [], new ArrayList(new Assoc()), 'Plan features list.', true)
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

App::get('/v1/projects/:projectId/auth/plans/:planId/features')
    ->desc('List features assigned to plan')
    ->groups(['api'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'listPlanFeatures',
        description: '/docs/references/projects/list-plan-features.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('planId', '', new Text(128), 'Plan ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $planId, Response $response, Database $dbForPlatform) {
        $project = $dbForPlatform->getDocument('projects', $projectId);
        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }
        $docs = $dbForPlatform->find('auth_plan_features', [
            Query::equal('projectId', [$projectId]),
            Query::equal('planId', [$planId]),
            Query::equal('active', [true])
        ]);
        $response->dynamic(new Document([
            'total' => count($docs),
            'documents' => $docs
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::post('/v1/projects/:projectId/auth/plans/:planId/features')
    ->desc('Assign features to plan')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'assignPlanFeatures',
        description: '/docs/references/projects/assign-plan-features.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('planId', '', new Text(128), 'Plan ID.')
    ->param('features', [], new ArrayList(new Assoc()), 'Array of feature assignments.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->action(function (
        string $projectId,
        string $planId,
        array $features,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject
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

        $stripeService = null;
        if ($project->getAttribute('authSubscriptionsEnabled')) {
            $stripeService = new StripeService(
                $project->getAttribute('authStripeSecretKey'),
                $dbForProject,
                $project,
                $dbForPlatform
            );
        }

        $created = [];

        foreach ($features as $config) {
            $featureId = (string) ($config['featureId'] ?? '');
            $type = (string) ($config['type'] ?? '');
            if ($featureId === '' || ($type !== 'boolean' && $type !== 'metered')) {
                continue;
            }

            $feature = $dbForPlatform->findOne('auth_features', [
                Query::equal('projectId', [$projectId]),
                Query::equal('featureId', [$featureId]),
                Query::equal('active', [true])
            ]);
            if (!$feature) {
                throw new Exception(Exception::GENERAL_NOT_FOUND, 'Feature not found: ' . $featureId);
            }

            $existing = $dbForPlatform->findOne('auth_plan_features', [
                Query::equal('projectId', [$projectId]),
                Query::equal('planId', [$planId]),
                Query::equal('featureId', [$featureId])
            ]);

            $exists = ($existing instanceof Document) && !$existing->isEmpty();
            $docId = $exists ? (string) $existing->getId() : ID::unique();
            $doc = $exists ? $existing : new Document(['$id' => $docId]);

            $doc
                ->setAttribute('projectInternalId', $project->getSequence())
                ->setAttribute('projectId', $projectId)
                ->setAttribute('planId', $planId)
                ->setAttribute('featureId', $featureId)
                ->setAttribute('type', $type)
                ->setAttribute('active', true);

            if ($type === 'boolean') {
                $doc->setAttribute('enabled', (bool) ($config['enabled'] ?? true));
                $doc->setAttribute('currency', null);
                $doc->setAttribute('interval', null);
                $doc->setAttribute('includedUnits', 0);
                $doc->setAttribute('tiersMode', 'graduated');
                $doc->setAttribute('tiers', []);
                $doc->setAttribute('usageCap', null);
            } else {
                $currency = (string) ($config['currency'] ?? $plan->getAttribute('currency'));
                $interval = (string) ($config['interval'] ?? $plan->getAttribute('interval'));
                $included = (int) ($config['includedUnits'] ?? 0);
                $tiersMode = (string) ($config['tiersMode'] ?? 'graduated');
                $tiers = (array) ($config['tiers'] ?? []);
                $usageCap = isset($config['usageCap']) ? (int)$config['usageCap'] : null;

                $doc->setAttribute('enabled', true);
                $doc->setAttribute('currency', $currency);
                $doc->setAttribute('interval', $interval);
                $doc->setAttribute('includedUnits', $included);
                $doc->setAttribute('tiersMode', $tiersMode);
                $doc->setAttribute('tiers', $tiers);
                $doc->setAttribute('usageCap', $usageCap);

                if ($stripeService) {
                    $productId = $plan->getAttribute('stripeProductId');
                    if (!$productId) {
                        $product = $stripeService->createProduct($plan->getAttribute('name'), $plan->getAttribute('description', ''), (string) $plan->getAttribute('planId'));
                        $productId = $product['id'];
                        $plan->setAttribute('stripeProductId', $productId);
                        $dbForPlatform->updateDocument('auth_plans', $plan->getId(), $plan);
                    }

                    $meterId = $stripeService->ensureMeterForFeature(
                        (string) $plan->getAttribute('planId'),
                        $featureId,
                        (string) $feature->getAttribute('name')
                    );
                    if ($meterId) {
                        $doc->setAttribute('stripeMeterId', $meterId);
                    }

                    $price = $stripeService->createMeteredTieredPrice(
                        $productId,
                        $currency,
                        $interval ?: null,
                        $included,
                        $tiersMode,
                        $tiers,
                        (string) $plan->getAttribute('planId'),
                        $featureId,
                        (string) $feature->getAttribute('name')
                    );
                    $doc->setAttribute('stripePriceId', $price['id'] ?? null);
                }
            }

            if ($exists) {
                $updated = $dbForPlatform->updateDocument('auth_plan_features', $docId, $doc);
                $created[] = $updated;
            } else {
                $created[] = $dbForPlatform->createDocument('auth_plan_features', $doc);
            }
        }

        $response->dynamic(new Document([
            'total' => count($created),
            'documents' => $created
        ]), Response::MODEL_DOCUMENT_LIST);
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
        foreach ($plans as $plan) {
            $pf = $dbForPlatform->find('auth_plan_features', [
                Query::equal('projectId', [$projectId]),
                Query::equal('planId', [(string)$plan->getAttribute('planId')]),
                Query::equal('active', [true])
            ]);
            $features = [];
            foreach ($pf as $f) {
                $type = (string)$f->getAttribute('type');
                if ($type === 'boolean') {
                    $features[] = [
                        'featureId' => (string)$f->getAttribute('featureId'),
                        'type' => 'boolean',
                        'enabled' => (bool)$f->getAttribute('enabled', true)
                    ];
                } else {
                    $features[] = [
                        'featureId' => (string)$f->getAttribute('featureId'),
                        'type' => 'metered',
                        'currency' => $f->getAttribute('currency'),
                        'interval' => $f->getAttribute('interval'),
                        'includedUnits' => (int)$f->getAttribute('includedUnits', 0),
                        'tiersMode' => $f->getAttribute('tiersMode'),
                        'tiers' => (array)$f->getAttribute('tiers', []),
                        'stripePriceId' => $f->getAttribute('stripePriceId'),
                        'usageCap' => $f->getAttribute('usageCap')
                    ];
                }
            }
            $plan->setAttribute('features', $features);
        }

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

        $pf = $dbForPlatform->find('auth_plan_features', [
            Query::equal('projectId', [$projectId]),
            Query::equal('planId', [$planId]),
            Query::equal('active', [true])
        ]);
        $features = [];
        foreach ($pf as $f) {
            $type = (string)$f->getAttribute('type');
            if ($type === 'boolean') {
                $features[] = [
                    'featureId' => (string)$f->getAttribute('featureId'),
                    'type' => 'boolean',
                    'enabled' => (bool)$f->getAttribute('enabled', true)
                ];
            } else {
                $features[] = [
                    'featureId' => (string)$f->getAttribute('featureId'),
                    'type' => 'metered',
                    'currency' => $f->getAttribute('currency'),
                    'interval' => $f->getAttribute('interval'),
                    'includedUnits' => (int)$f->getAttribute('includedUnits', 0),
                    'tiersMode' => $f->getAttribute('tiersMode'),
                    'tiers' => (array)$f->getAttribute('tiers', []),
                    'stripePriceId' => $f->getAttribute('stripePriceId'),
                    'usageCap' => $f->getAttribute('usageCap')
                ];
            }
        }
        $plan->setAttribute('features', $features);

        $response->dynamic($plan, Response::MODEL_ANY);
    });

App::delete('/v1/projects/:projectId/auth/plans/:planId/features/:featureId')
    ->desc('Delete feature assignment from plan')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'deletePlanFeature',
        description: '/docs/references/projects/delete-plan-feature.md',
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
    ->param('featureId', '', new Text(128), 'Feature ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->action(function (
        string $projectId,
        string $planId,
        string $featureId,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject
    ) {
        $project = $dbForPlatform->getDocument('projects', $projectId);
        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $assignment = $dbForPlatform->findOne('auth_plan_features', [
            Query::equal('projectId', [$projectId]),
            Query::equal('planId', [$planId]),
            Query::equal('featureId', [$featureId])
        ]);

        if (!$assignment) {
            throw new Exception(Exception::GENERAL_NOT_FOUND, 'Plan feature not found');
        }

        if ($project->getAttribute('authSubscriptionsEnabled')) {
            try {
                $stripeService = new StripeService(
                    $project->getAttribute('authStripeSecretKey'),
                    $dbForProject,
                    $project
                );
                $priceId = (string) $assignment->getAttribute('stripePriceId', '');
                if ($priceId !== '') {
                    $stripeService->deactivatePrice($priceId);
                }
            } catch (SubscriptionException $_) {
            }
        }

        $assignment->setAttribute('active', false);
        $dbForPlatform->updateDocument('auth_plan_features', $assignment->getId(), $assignment);

        $response->noContent();
    });

App::delete('/v1/projects/:projectId/auth/plans/:planId/features')
    ->desc('Delete multiple feature assignments from plan')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'authPlans',
        name: 'deletePlanFeatures',
        description: '/docs/references/projects/delete-plan-features.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('planId', '', new Text(128), 'Plan ID.')
    ->param('featureIds', [], new ArrayList(new Text(128)), 'Array of feature IDs to remove.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->action(function (
        string $projectId,
        string $planId,
        array $featureIds,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject
    ) {
        $project = $dbForPlatform->getDocument('projects', $projectId);
        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $updated = [];
        foreach ($featureIds as $fid) {
            $assignment = $dbForPlatform->findOne('auth_plan_features', [
                Query::equal('projectId', [$projectId]),
                Query::equal('planId', [$planId]),
                Query::equal('featureId', [$fid])
            ]);
            if (!$assignment) {
                continue;
            }

            if ($project->getAttribute('authSubscriptionsEnabled')) {
                try {
                    $stripeService = new StripeService(
                        $project->getAttribute('authStripeSecretKey'),
                        $dbForProject,
                        $project
                    );
                    $priceId = (string) $assignment->getAttribute('stripePriceId', '');
                    if ($priceId !== '') {
                        $stripeService->deactivatePrice($priceId);
                    }
                } catch (SubscriptionException $_) {
                }
            }

            $assignment->setAttribute('active', false);
            $updated[] = $dbForPlatform->updateDocument('auth_plan_features', $assignment->getId(), $assignment);
        }

        $response->dynamic(new Document([
            'total' => count($updated),
            'documents' => $updated
        ]), Response::MODEL_DOCUMENT_LIST);
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
    ->param('features', null, new ArrayList(new Assoc()), 'Full list of feature assignments for this plan.')
    ->param('maxUsers', null, new Integer(true), 'Maximum users allowed.', true)
    ->param('isDefault', null, new Boolean(), 'Is this the default plan.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->action(function (
        string $projectId,
        string $planId,
        ?string $name,
        ?string $description,
        ?array $features,
        ?int $maxUsers,
        ?bool $isDefault,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject
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

        if ($name !== null) {
            $plan->setAttribute('name', $name);
        }
        if ($description !== null) {
            $plan->setAttribute('description', $description);
        }
        if ($features !== null) {
            $plan->setAttribute('features', $features);
        }
        if ($maxUsers !== null) {
            $plan->setAttribute('maxUsers', $maxUsers);
        }
        if ($isDefault !== null) {
            $plan->setAttribute('isDefault', $isDefault);
        }

        $plan->setAttribute('search', implode(' ', [
            $plan->getAttribute('planId'),
            $plan->getAttribute('name'),
            $plan->getAttribute('description')
        ]));

        $plan = $dbForPlatform->updateDocument('auth_plans', $plan->getId(), $plan);

        if (is_array($features)) {
            $existing = $dbForPlatform->find('auth_plan_features', [
                Query::equal('projectId', [$projectId]),
                Query::equal('planId', [$planId])
            ]);
            $existingById = [];
            foreach ($existing as $a) {
                $existingById[(string)$a->getAttribute('featureId')] = $a;
            }

            $providedIds = [];
            foreach ($features as $cfg) {
                if (!is_array($cfg)) {
                    continue;
                }
                $fid = (string)($cfg['featureId'] ?? '');
                $type = (string)($cfg['type'] ?? '');
                if ($fid === '' || ($type !== 'boolean' && $type !== 'metered')) {
                    continue;
                }
                $providedIds[] = $fid;
                $assignment = $existingById[$fid] ?? null;
                $doc = $assignment ?: new Document(['$id' => ID::unique()]);
                $doc
                    ->setAttribute('projectInternalId', $project->getSequence())
                    ->setAttribute('projectId', $projectId)
                    ->setAttribute('planId', $planId)
                    ->setAttribute('featureId', $fid)
                    ->setAttribute('type', $type)
                    ->setAttribute('active', true);
                if ($type === 'boolean') {
                    $doc->setAttribute('enabled', (bool)($cfg['enabled'] ?? true));
                    $doc->setAttribute('currency', null);
                    $doc->setAttribute('interval', null);
                    $doc->setAttribute('includedUnits', 0);
                    $doc->setAttribute('tiersMode', 'graduated');
                    $doc->setAttribute('tiers', []);
                    $doc->setAttribute('usageCap', null);
                } else {
                    $doc->setAttribute('enabled', true);
                    $doc->setAttribute('currency', (string)($cfg['currency'] ?? $plan->getAttribute('currency')));
                    $doc->setAttribute('interval', (string)($cfg['interval'] ?? $plan->getAttribute('interval')));
                    $doc->setAttribute('includedUnits', (int)($cfg['includedUnits'] ?? 0));
                    $doc->setAttribute('tiersMode', (string)($cfg['tiersMode'] ?? 'graduated'));
                    $doc->setAttribute('tiers', (array)($cfg['tiers'] ?? []));
                    if (array_key_exists('usageCap', $cfg)) {
                        $doc->setAttribute('usageCap', $cfg['usageCap'] === null ? null : (int)$cfg['usageCap']);
                    }
                }
                if ($assignment) {
                    if ($type === 'metered' && $project->getAttribute('authSubscriptionsEnabled')) {
                        try {
                            $ss = new StripeService(
                                $project->getAttribute('authStripeSecretKey'),
                                $dbForProject,
                                $project,
                                $dbForPlatform
                            );
                            $productId = (string)$plan->getAttribute('stripeProductId');
                            if (!$productId) {
                                $p = $ss->createProduct($plan->getAttribute('name'), $plan->getAttribute('description', ''), (string)$plan->getAttribute('planId'));
                                $productId = (string)$p['id'];
                                $plan->setAttribute('stripeProductId', $productId);
                                $dbForPlatform->updateDocument('auth_plans', $plan->getId(), $plan);
                            }
                            $feature = $dbForPlatform->findOne('auth_features', [
                                Query::equal('projectId', [$projectId]),
                                Query::equal('featureId', [$fid])
                            ]);
                            $featureName = $feature ? (string)$feature->getAttribute('name', $fid) : $fid;
                            $prev = [
                                'currency' => (string)$assignment->getAttribute('currency'),
                                'interval' => (string)$assignment->getAttribute('interval'),
                                'includedUnits' => (int)$assignment->getAttribute('includedUnits', 0),
                                'tiersMode' => (string)$assignment->getAttribute('tiersMode'),
                                'tiers' => (array)$assignment->getAttribute('tiers', [])
                            ];
                            $next = [
                                'currency' => (string)$doc->getAttribute('currency'),
                                'interval' => (string)$doc->getAttribute('interval'),
                                'includedUnits' => (int)$doc->getAttribute('includedUnits', 0),
                                'tiersMode' => (string)$doc->getAttribute('tiersMode'),
                                'tiers' => (array)$doc->getAttribute('tiers', [])
                            ];
                            $tiersChanged = json_encode(array_values($prev['tiers'])) !== json_encode(array_values($next['tiers'])) || ($prev['tiersMode'] !== $next['tiersMode']);
                            $metaChanged = $prev['currency'] !== $next['currency'] || $prev['interval'] !== $next['interval'] || $prev['includedUnits'] !== $next['includedUnits'];
                            $oldPriceId = (string)$assignment->getAttribute('stripePriceId', '');
                            // If nothing changed, still verify on Stripe whether the existing price matches the expected shape.
                            $forceRecreate = false;
                            if ($oldPriceId !== '') {
                                try {
                                    $curr = $ss->getPrice($oldPriceId);
                                    $currMode = (string)($curr['tiers_mode'] ?? '');
                                    $currUsage = (string)($curr['recurring']['usage_type'] ?? '');
                                    $currCurrency = (string)($curr['currency'] ?? '');
                                    $currInterval = (string)($curr['recurring']['interval'] ?? '');
                                    if ($currMode !== $next['tiersMode'] || $currUsage !== 'metered' || $currCurrency !== $next['currency'] || ($next['interval'] && $currInterval !== $next['interval'])) {
                                        $forceRecreate = true;
                                    }
                                    // Compare tier boundaries with expected (including includedUnits free tier)
                                    $currTiers = isset($curr['tiers']) && is_array($curr['tiers']) ? $curr['tiers'] : [];
                                    $currUpTo = [];
                                    foreach ($currTiers as $t) {
                                        $currUpTo[] = isset($t['up_to']) ? (string)$t['up_to'] : '';
                                    }
                                    $expectedUpTo = [];
                                    $inc = (int)$next['includedUnits'];
                                    if ($inc > 0) {
                                        $expectedUpTo[] = (string)$inc;
                                    }
                                    foreach ((array)$next['tiers'] as $tierX) {
                                        $to = $tierX['to'] ?? 'inf';
                                        $expectedUpTo[] = $to === 'inf' ? 'inf' : (string)$to;
                                    }
                                    if (json_encode($currUpTo) !== json_encode($expectedUpTo)) {
                                        $forceRecreate = true;
                                    }
                                    // Ensure free tier is truly free if includedUnits > 0
                                    if ($inc > 0 && isset($currTiers[0]['unit_amount']) && (int)$currTiers[0]['unit_amount'] !== 0) {
                                        $forceRecreate = true;
                                    }
                                    // debug log removed
                                } catch (SubscriptionException $e) {
                                    $forceRecreate = true;
                                }
                            }
                            if ($oldPriceId === '' || $tiersChanged || $metaChanged || $forceRecreate) {
                                $meterId = $ss->ensureMeterForFeature((string)$plan->getAttribute('planId'), $fid, $featureName);
                                if ($meterId) {
                                    $doc->setAttribute('stripeMeterId', $meterId);
                                }
                                $newPrice = $ss->createMeteredTieredPrice(
                                    $productId,
                                    $next['currency'],
                                    $next['interval'] ?: null,
                                    $next['includedUnits'],
                                    $next['tiersMode'],
                                    $next['tiers'],
                                    (string)$plan->getAttribute('planId'),
                                    $fid,
                                    $featureName
                                );
                                $doc->setAttribute('stripePriceId', $newPrice['id'] ?? null);
                                if ($oldPriceId !== '') {
                                    $ss->deactivatePrice($oldPriceId);
                                }
                            }
                        } catch (SubscriptionException $e) {
                            throw $e;
                        }
                    }
                    $dbForPlatform->updateDocument('auth_plan_features', $assignment->getId(), $doc);
                } else {
                    if ($type === 'metered' && $project->getAttribute('authSubscriptionsEnabled')) {
                        try {
                            $ss = new StripeService(
                                $project->getAttribute('authStripeSecretKey'),
                                $dbForProject,
                                $project,
                                $dbForPlatform
                            );
                            $productId = (string)$plan->getAttribute('stripeProductId');
                            if (!$productId) {
                                $p = $ss->createProduct($plan->getAttribute('name'), $plan->getAttribute('description', ''), (string)$plan->getAttribute('planId'));
                                $productId = (string)$p['id'];
                                $plan->setAttribute('stripeProductId', $productId);
                                $dbForPlatform->updateDocument('auth_plans', $plan->getId(), $plan);
                            }
                            $feature = $dbForPlatform->findOne('auth_features', [
                                Query::equal('projectId', [$projectId]),
                                Query::equal('featureId', [$fid])
                            ]);
                            $featureName = $feature ? (string)$feature->getAttribute('name', $fid) : $fid;
                            $meterId = $ss->ensureMeterForFeature((string)$plan->getAttribute('planId'), $fid, $featureName);
                            if ($meterId) {
                                $doc->setAttribute('stripeMeterId', $meterId);
                            }
                            $newPrice = $ss->createMeteredTieredPrice(
                                $productId,
                                (string)$doc->getAttribute('currency'),
                                (string)$doc->getAttribute('interval') ?: null,
                                (int)$doc->getAttribute('includedUnits', 0),
                                (string)$doc->getAttribute('tiersMode'),
                                (array)$doc->getAttribute('tiers', []),
                                (string)$plan->getAttribute('planId'),
                                $fid,
                                $featureName
                            );
                            $doc->setAttribute('stripePriceId', $newPrice['id'] ?? null);
                        } catch (SubscriptionException $e) {
                            throw $e;
                        }
                    }
                    $dbForPlatform->createDocument('auth_plan_features', $doc);
                }
            }

            foreach ($existingById as $fid => $assignment) {
                if (!in_array($fid, $providedIds, true) && $assignment->getAttribute('active', true)) {
                    if ($project->getAttribute('authSubscriptionsEnabled')) {
                        try {
                            $ss = new StripeService(
                                $project->getAttribute('authStripeSecretKey'),
                                $dbForProject,
                                $project
                            );
                            $pid = (string)$assignment->getAttribute('stripePriceId', '');
                            if ($pid !== '') {
                                $ss->deactivatePrice($pid);
                            }
                        } catch (SubscriptionException $_) {
                        }
                    }
                    $assignment->setAttribute('active', false);
                    $dbForPlatform->updateDocument('auth_plan_features', $assignment->getId(), $assignment);
                }
            }
        }

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
