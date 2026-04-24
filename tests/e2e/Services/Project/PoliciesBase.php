<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Query;

trait PoliciesBase
{
    // =========================================================================
    // Get Policy
    // =========================================================================

    public function testGetPolicy(): void
    {
        $expectedFields = [
            'password-dictionary' => ['enabled'],
            'password-history' => ['total'],
            'password-personal-data' => ['enabled'],
            'session-alert' => ['enabled'],
            'session-duration' => ['duration'],
            'session-invalidation' => ['enabled'],
            'session-limit' => ['total'],
            'user-limit' => ['total'],
            'membership-privacy' => ['userId', 'userEmail', 'userPhone', 'userName', 'userMFA'],
        ];

        foreach ($expectedFields as $policyId => $fields) {
            $response = $this->getPolicy($policyId);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($policyId, $response['body']['$id']);

            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $response['body']);
            }
        }
    }

    public function testGetPolicyMatchesListPolicies(): void
    {
        $list = $this->listPolicies();

        $this->assertSame(200, $list['headers']['status-code']);

        $byId = [];
        foreach ($list['body']['policies'] as $policy) {
            $byId[$policy['$id']] = $policy;
        }

        foreach (\array_keys($byId) as $policyId) {
            $response = $this->getPolicy($policyId);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($byId[$policyId], $response['body']);
        }
    }

    public function testGetPolicyReflectsUpdates(): void
    {
        $this->updatePasswordDictionaryPolicy(true);
        $this->updatePasswordHistoryPolicy(5);
        $this->updateSessionDurationPolicy(3600);
        $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => false,
            'userName' => true,
            'userMFA' => true,
        ]);

        $passwordDictionary = $this->getPolicy('password-dictionary');
        $passwordHistory = $this->getPolicy('password-history');
        $sessionDuration = $this->getPolicy('session-duration');
        $membershipPrivacy = $this->getPolicy('membership-privacy');

        $this->assertSame(200, $passwordDictionary['headers']['status-code']);
        $this->assertSame(true, $passwordDictionary['body']['enabled']);

        $this->assertSame(200, $passwordHistory['headers']['status-code']);
        $this->assertSame(5, $passwordHistory['body']['total']);

        $this->assertSame(200, $sessionDuration['headers']['status-code']);
        $this->assertSame(3600, $sessionDuration['body']['duration']);

        $this->assertSame(200, $membershipPrivacy['headers']['status-code']);
        $this->assertSame(true, $membershipPrivacy['body']['userId']);
        $this->assertSame(true, $membershipPrivacy['body']['userEmail']);
        $this->assertSame(false, $membershipPrivacy['body']['userPhone']);
        $this->assertSame(true, $membershipPrivacy['body']['userName']);
        $this->assertSame(true, $membershipPrivacy['body']['userMFA']);

        // Cleanup
        $this->updatePasswordDictionaryPolicy(false);
        $this->updatePasswordHistoryPolicy(null);
        $this->updateSessionDurationPolicy(31536000);
        $this->updateMembershipPrivacyPolicy([
            'userId' => false,
            'userEmail' => false,
            'userPhone' => false,
            'userName' => false,
            'userMFA' => false,
        ]);
    }

    public function testGetPolicyWithoutAuthentication(): void
    {
        $response = $this->getPolicy('password-dictionary', authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testGetPolicyInvalidPolicyId(): void
    {
        $response = $this->getPolicy('invalid-policy');

        $this->assertSame(400, $response['headers']['status-code']);
    }

    // =========================================================================
    // List Policies
    // =========================================================================

    public function testListPolicies(): void
    {
        $response = $this->listPolicies();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('policies', $response['body']);
        $this->assertArrayHasKey('total', $response['body']);
        $this->assertIsArray($response['body']['policies']);
        $this->assertIsInt($response['body']['total']);
        $this->assertSame(9, $response['body']['total']);
        $this->assertCount(9, $response['body']['policies']);

        $policyIds = \array_column($response['body']['policies'], '$id');

        $this->assertContains('password-dictionary', $policyIds);
        $this->assertContains('password-history', $policyIds);
        $this->assertContains('password-personal-data', $policyIds);
        $this->assertContains('session-alert', $policyIds);
        $this->assertContains('session-duration', $policyIds);
        $this->assertContains('session-invalidation', $policyIds);
        $this->assertContains('session-limit', $policyIds);
        $this->assertContains('user-limit', $policyIds);
        $this->assertContains('membership-privacy', $policyIds);
    }

    public function testListPoliciesResponseModel(): void
    {
        $response = $this->listPolicies();

        $this->assertSame(200, $response['headers']['status-code']);

        foreach ($response['body']['policies'] as $policy) {
            $this->assertArrayHasKey('$id', $policy);
        }

        $byId = [];
        foreach ($response['body']['policies'] as $policy) {
            $byId[$policy['$id']] = $policy;
        }

        $this->assertArrayHasKey('enabled', $byId['password-dictionary']);
        $this->assertArrayHasKey('total', $byId['password-history']);
        $this->assertArrayHasKey('enabled', $byId['password-personal-data']);
        $this->assertArrayHasKey('enabled', $byId['session-alert']);
        $this->assertArrayHasKey('duration', $byId['session-duration']);
        $this->assertArrayHasKey('enabled', $byId['session-invalidation']);
        $this->assertArrayHasKey('total', $byId['session-limit']);
        $this->assertArrayHasKey('total', $byId['user-limit']);
        $this->assertArrayHasKey('userId', $byId['membership-privacy']);
        $this->assertArrayHasKey('userEmail', $byId['membership-privacy']);
        $this->assertArrayHasKey('userPhone', $byId['membership-privacy']);
        $this->assertArrayHasKey('userName', $byId['membership-privacy']);
        $this->assertArrayHasKey('userMFA', $byId['membership-privacy']);
    }

    public function testListPoliciesReflectsUpdates(): void
    {
        $this->updatePasswordDictionaryPolicy(true);
        $this->updatePasswordHistoryPolicy(5);
        $this->updateSessionDurationPolicy(3600);
        $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => false,
            'userName' => true,
            'userMFA' => true,
        ]);

        $response = $this->listPolicies();

        $this->assertSame(200, $response['headers']['status-code']);

        $byId = [];
        foreach ($response['body']['policies'] as $policy) {
            $byId[$policy['$id']] = $policy;
        }

        $this->assertSame(true, $byId['password-dictionary']['enabled']);
        $this->assertSame(5, $byId['password-history']['total']);
        $this->assertSame(3600, $byId['session-duration']['duration']);
        $this->assertSame(true, $byId['membership-privacy']['userId']);
        $this->assertSame(true, $byId['membership-privacy']['userEmail']);
        $this->assertSame(false, $byId['membership-privacy']['userPhone']);
        $this->assertSame(true, $byId['membership-privacy']['userName']);
        $this->assertSame(true, $byId['membership-privacy']['userMFA']);

        // Cleanup
        $this->updatePasswordDictionaryPolicy(false);
        $this->updatePasswordHistoryPolicy(null);
        $this->updateSessionDurationPolicy(31536000);
        $this->updateMembershipPrivacyPolicy([
            'userId' => false,
            'userEmail' => false,
            'userPhone' => false,
            'userName' => false,
            'userMFA' => false,
        ]);
    }

    public function testListPoliciesTotalFalse(): void
    {
        $response = $this->listPolicies(total: false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['total']);
        $this->assertCount(9, $response['body']['policies']);
    }

    public function testListPoliciesWithLimit(): void
    {
        $response = $this->listPolicies([
            Query::limit(1)->toString(),
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['policies']);
        $this->assertSame(9, $response['body']['total']);
    }

    public function testListPoliciesWithOffset(): void
    {
        $listAll = $this->listPolicies();
        $this->assertSame(200, $listAll['headers']['status-code']);

        $listOffset = $this->listPolicies([
            Query::offset(1)->toString(),
        ]);

        $this->assertSame(200, $listOffset['headers']['status-code']);
        $this->assertCount(\count($listAll['body']['policies']) - 1, $listOffset['body']['policies']);
        $this->assertSame($listAll['body']['total'], $listOffset['body']['total']);
    }

    public function testListPoliciesWithoutAuthentication(): void
    {
        $response = $this->listPolicies(authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Password Dictionary Policy
    // =========================================================================

    public function testUpdatePasswordDictionaryPolicyEnable(): void
    {
        $response = $this->updatePasswordDictionaryPolicy(true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['authPasswordDictionary']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(true, $project['body']['authPasswordDictionary']);

        // Cleanup
        $this->updatePasswordDictionaryPolicy(false);
    }

    public function testUpdatePasswordDictionaryPolicyDisable(): void
    {
        $this->updatePasswordDictionaryPolicy(true);

        $response = $this->updatePasswordDictionaryPolicy(false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authPasswordDictionary']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(false, $project['body']['authPasswordDictionary']);
    }

    public function testUpdatePasswordDictionaryPolicyIdempotent(): void
    {
        $first = $this->updatePasswordDictionaryPolicy(true);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(true, $first['body']['authPasswordDictionary']);

        $second = $this->updatePasswordDictionaryPolicy(true);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(true, $second['body']['authPasswordDictionary']);

        // Cleanup
        $this->updatePasswordDictionaryPolicy(false);
    }

    public function testUpdatePasswordDictionaryPolicyWithoutAuth(): void
    {
        $response = $this->updatePasswordDictionaryPolicy(true, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdatePasswordDictionaryPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $this->buildHeaders(), [
            'enabled' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePasswordDictionaryPolicyMissingParam(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $this->buildHeaders(), []);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    // =========================================================================
    // Password History Policy
    // =========================================================================

    public function testUpdatePasswordHistoryPolicyEnable(): void
    {
        $response = $this->updatePasswordHistoryPolicy(5);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(5, $response['body']['authPasswordHistory']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(5, $project['body']['authPasswordHistory']);

        // Cleanup (disable by setting total to null which maps to 0)
        $this->updatePasswordHistoryPolicy(null);
    }

    public function testUpdatePasswordHistoryPolicyMin(): void
    {
        $response = $this->updatePasswordHistoryPolicy(1);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(1, $response['body']['authPasswordHistory']);

        // Cleanup
        $this->updatePasswordHistoryPolicy(null);
    }

    public function testUpdatePasswordHistoryPolicyMax(): void
    {
        $response = $this->updatePasswordHistoryPolicy(5000);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(5000, $response['body']['authPasswordHistory']);

        // Cleanup
        $this->updatePasswordHistoryPolicy(null);
    }

    public function testUpdatePasswordHistoryPolicyDisable(): void
    {
        $this->updatePasswordHistoryPolicy(5);

        $response = $this->updatePasswordHistoryPolicy(null);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['authPasswordHistory']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(0, $project['body']['authPasswordHistory']);
    }

    public function testUpdatePasswordHistoryPolicyBelowMin(): void
    {
        $response = $this->updatePasswordHistoryPolicy(0);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePasswordHistoryPolicyAboveMax(): void
    {
        $response = $this->updatePasswordHistoryPolicy(5001);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePasswordHistoryPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $this->buildHeaders(), [
            'total' => 'not-a-number',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePasswordHistoryPolicyMissingParam(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $this->buildHeaders(), []);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePasswordHistoryPolicyWithoutAuth(): void
    {
        $response = $this->updatePasswordHistoryPolicy(5, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Password Personal Data Policy
    // =========================================================================

    public function testUpdatePasswordPersonalDataPolicyEnable(): void
    {
        $response = $this->updatePasswordPersonalDataPolicy(true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['authPersonalDataCheck']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(true, $project['body']['authPersonalDataCheck']);

        // Cleanup
        $this->updatePasswordPersonalDataPolicy(false);
    }

    public function testUpdatePasswordPersonalDataPolicyDisable(): void
    {
        $this->updatePasswordPersonalDataPolicy(true);

        $response = $this->updatePasswordPersonalDataPolicy(false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authPersonalDataCheck']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(false, $project['body']['authPersonalDataCheck']);
    }

    public function testUpdatePasswordPersonalDataPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $this->buildHeaders(), [
            'enabled' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePasswordPersonalDataPolicyMissingParam(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $this->buildHeaders(), []);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdatePasswordPersonalDataPolicyWithoutAuth(): void
    {
        $response = $this->updatePasswordPersonalDataPolicy(true, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Session Alert Policy
    // =========================================================================

    public function testUpdateSessionAlertPolicyEnable(): void
    {
        $response = $this->updateSessionAlertPolicy(true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['authSessionAlerts']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(true, $project['body']['authSessionAlerts']);

        // Cleanup
        $this->updateSessionAlertPolicy(false);
    }

    public function testUpdateSessionAlertPolicyDisable(): void
    {
        $this->updateSessionAlertPolicy(true);

        $response = $this->updateSessionAlertPolicy(false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authSessionAlerts']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(false, $project['body']['authSessionAlerts']);
    }

    public function testUpdateSessionAlertPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-alert', $this->buildHeaders(), [
            'enabled' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionAlertPolicyMissingParam(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-alert', $this->buildHeaders(), []);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionAlertPolicyWithoutAuth(): void
    {
        $response = $this->updateSessionAlertPolicy(true, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Session Duration Policy
    // =========================================================================

    public function testUpdateSessionDurationPolicy(): void
    {
        $response = $this->updateSessionDurationPolicy(3600);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(3600, $response['body']['authDuration']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(3600, $project['body']['authDuration']);

        // Cleanup (reset to default 1 year)
        $this->updateSessionDurationPolicy(31536000);
    }

    public function testUpdateSessionDurationPolicyMin(): void
    {
        $response = $this->updateSessionDurationPolicy(5);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(5, $response['body']['authDuration']);

        // Cleanup
        $this->updateSessionDurationPolicy(31536000);
    }

    public function testUpdateSessionDurationPolicyMax(): void
    {
        $response = $this->updateSessionDurationPolicy(31536000);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(31536000, $response['body']['authDuration']);
    }

    public function testUpdateSessionDurationPolicyBelowMin(): void
    {
        $response = $this->updateSessionDurationPolicy(4);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionDurationPolicyAboveMax(): void
    {
        $response = $this->updateSessionDurationPolicy(31536001);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionDurationPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-duration', $this->buildHeaders(), [
            'duration' => 'not-a-number',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionDurationPolicyMissingParam(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-duration', $this->buildHeaders(), []);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionDurationPolicyWithoutAuth(): void
    {
        $response = $this->updateSessionDurationPolicy(3600, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Session Invalidation Policy
    // =========================================================================

    public function testUpdateSessionInvalidationPolicyEnable(): void
    {
        $response = $this->updateSessionInvalidationPolicy(true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['authInvalidateSessions']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(true, $project['body']['authInvalidateSessions']);

        // Cleanup
        $this->updateSessionInvalidationPolicy(false);
    }

    public function testUpdateSessionInvalidationPolicyDisable(): void
    {
        $this->updateSessionInvalidationPolicy(true);

        $response = $this->updateSessionInvalidationPolicy(false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authInvalidateSessions']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(false, $project['body']['authInvalidateSessions']);
    }

    public function testUpdateSessionInvalidationPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-invalidation', $this->buildHeaders(), [
            'enabled' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionInvalidationPolicyMissingParam(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-invalidation', $this->buildHeaders(), []);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionInvalidationPolicyWithoutAuth(): void
    {
        $response = $this->updateSessionInvalidationPolicy(true, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Session Limit Policy
    // =========================================================================

    public function testUpdateSessionLimitPolicy(): void
    {
        $response = $this->updateSessionLimitPolicy(5);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(5, $response['body']['authSessionsLimit']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(5, $project['body']['authSessionsLimit']);

        // Cleanup (reset to default)
        $this->updateSessionLimitPolicy(10);
    }

    public function testUpdateSessionLimitPolicyMin(): void
    {
        $response = $this->updateSessionLimitPolicy(1);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(1, $response['body']['authSessionsLimit']);

        // Cleanup
        $this->updateSessionLimitPolicy(10);
    }

    public function testUpdateSessionLimitPolicyMax(): void
    {
        $response = $this->updateSessionLimitPolicy(5000);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(5000, $response['body']['authSessionsLimit']);

        // Cleanup
        $this->updateSessionLimitPolicy(10);
    }

    public function testUpdateSessionLimitPolicyDisable(): void
    {
        $response = $this->updateSessionLimitPolicy(null);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['authSessionsLimit']);

        // Cleanup
        $this->updateSessionLimitPolicy(10);
    }

    public function testUpdateSessionLimitPolicyBelowMin(): void
    {
        $response = $this->updateSessionLimitPolicy(0);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionLimitPolicyAboveMax(): void
    {
        $response = $this->updateSessionLimitPolicy(5001);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionLimitPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-limit', $this->buildHeaders(), [
            'total' => 'not-a-number',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionLimitPolicyMissingParam(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/session-limit', $this->buildHeaders(), []);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSessionLimitPolicyWithoutAuth(): void
    {
        $response = $this->updateSessionLimitPolicy(5, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // User Limit Policy
    // =========================================================================

    public function testUpdateUserLimitPolicy(): void
    {
        $response = $this->updateUserLimitPolicy(100);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(100, $response['body']['authLimit']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(100, $project['body']['authLimit']);

        // Cleanup
        $this->updateUserLimitPolicy(null);
    }

    public function testUpdateUserLimitPolicyMin(): void
    {
        $response = $this->updateUserLimitPolicy(1);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(1, $response['body']['authLimit']);

        // Cleanup
        $this->updateUserLimitPolicy(null);
    }

    public function testUpdateUserLimitPolicyMax(): void
    {
        $response = $this->updateUserLimitPolicy(5000);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(5000, $response['body']['authLimit']);

        // Cleanup
        $this->updateUserLimitPolicy(null);
    }

    public function testUpdateUserLimitPolicyDisable(): void
    {
        $this->updateUserLimitPolicy(100);

        $response = $this->updateUserLimitPolicy(null);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['authLimit']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(0, $project['body']['authLimit']);
    }

    public function testUpdateUserLimitPolicyBelowMin(): void
    {
        $response = $this->updateUserLimitPolicy(0);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateUserLimitPolicyAboveMax(): void
    {
        $response = $this->updateUserLimitPolicy(5001);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateUserLimitPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/user-limit', $this->buildHeaders(), [
            'total' => 'not-a-number',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateUserLimitPolicyMissingParam(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/user-limit', $this->buildHeaders(), []);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateUserLimitPolicyWithoutAuth(): void
    {
        $response = $this->updateUserLimitPolicy(100, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Membership Privacy Policy
    // =========================================================================

    public function testUpdateMembershipPrivacyPolicyAllEnabled(): void
    {
        $response = $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['authMembershipsUserId']);
        $this->assertSame(true, $response['body']['authMembershipsUserEmail']);
        $this->assertSame(true, $response['body']['authMembershipsUserPhone']);
        $this->assertSame(true, $response['body']['authMembershipsUserName']);
        $this->assertSame(true, $response['body']['authMembershipsMfa']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(true, $project['body']['authMembershipsUserId']);
        $this->assertSame(true, $project['body']['authMembershipsUserEmail']);
        $this->assertSame(true, $project['body']['authMembershipsUserPhone']);
        $this->assertSame(true, $project['body']['authMembershipsUserName']);
        $this->assertSame(true, $project['body']['authMembershipsMfa']);
    }

    public function testUpdateMembershipPrivacyPolicyAllDisabled(): void
    {
        $response = $this->updateMembershipPrivacyPolicy([
            'userId' => false,
            'userEmail' => false,
            'userPhone' => false,
            'userName' => false,
            'userMFA' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authMembershipsUserId']);
        $this->assertSame(false, $response['body']['authMembershipsUserEmail']);
        $this->assertSame(false, $response['body']['authMembershipsUserPhone']);
        $this->assertSame(false, $response['body']['authMembershipsUserName']);
        $this->assertSame(false, $response['body']['authMembershipsMfa']);

        $project = $this->getProjectDocument();
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame(false, $project['body']['authMembershipsUserId']);
        $this->assertSame(false, $project['body']['authMembershipsUserEmail']);
        $this->assertSame(false, $project['body']['authMembershipsUserPhone']);
        $this->assertSame(false, $project['body']['authMembershipsUserName']);
        $this->assertSame(false, $project['body']['authMembershipsMfa']);

        // Cleanup (restore defaults)
        $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
        ]);
    }

    public function testUpdateMembershipPrivacyPolicyMixed(): void
    {
        $response = $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => false,
            'userPhone' => true,
            'userName' => false,
            'userMFA' => true,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['authMembershipsUserId']);
        $this->assertSame(false, $response['body']['authMembershipsUserEmail']);
        $this->assertSame(true, $response['body']['authMembershipsUserPhone']);
        $this->assertSame(false, $response['body']['authMembershipsUserName']);
        $this->assertSame(true, $response['body']['authMembershipsMfa']);

        // Cleanup
        $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
        ]);
    }

    public function testUpdateMembershipPrivacyPolicyIndividualFields(): void
    {
        // Start from a known baseline where every field is enabled
        $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
        ]);

        $fields = [
            'userId' => 'authMembershipsUserId',
            'userEmail' => 'authMembershipsUserEmail',
            'userPhone' => 'authMembershipsUserPhone',
            'userName' => 'authMembershipsUserName',
            'userMFA' => 'authMembershipsMfa',
        ];

        // Each field can be toggled individually without clobbering the others
        foreach ($fields as $param => $attribute) {
            $response = $this->updateMembershipPrivacyPolicy([$param => false]);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame(false, $response['body'][$attribute]);

            foreach ($fields as $otherParam => $otherAttribute) {
                if ($otherParam === $param) {
                    continue;
                }
                $this->assertSame(true, $response['body'][$otherAttribute], $otherAttribute . ' should be untouched while only ' . $param . ' was updated');
            }

            // Restore the field before the next iteration
            $restore = $this->updateMembershipPrivacyPolicy([$param => true]);
            $this->assertSame(200, $restore['headers']['status-code']);
            $this->assertSame(true, $restore['body'][$attribute]);
        }
    }

    public function testUpdateMembershipPrivacyPolicyMultipleFields(): void
    {
        $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
        ]);

        $response = $this->updateMembershipPrivacyPolicy([
            'userId' => false,
            'userPhone' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authMembershipsUserId']);
        $this->assertSame(false, $response['body']['authMembershipsUserPhone']);
        $this->assertSame(true, $response['body']['authMembershipsUserEmail']);
        $this->assertSame(true, $response['body']['authMembershipsUserName']);
        $this->assertSame(true, $response['body']['authMembershipsMfa']);

        // Cleanup
        $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
        ]);
    }

    public function testUpdateMembershipPrivacyPolicyEmptyBody(): void
    {
        // PATCH with no fields should be a no-op, leaving state unchanged
        $this->updateMembershipPrivacyPolicy([
            'userId' => false,
            'userEmail' => false,
            'userPhone' => false,
            'userName' => false,
            'userMFA' => false,
        ]);

        $response = $this->updateMembershipPrivacyPolicy([]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['authMembershipsUserId']);
        $this->assertSame(false, $response['body']['authMembershipsUserEmail']);
        $this->assertSame(false, $response['body']['authMembershipsUserPhone']);
        $this->assertSame(false, $response['body']['authMembershipsUserName']);
        $this->assertSame(false, $response['body']['authMembershipsMfa']);

        // Cleanup
        $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
        ]);
    }

    public function testUpdateMembershipPrivacyPolicyInvalidType(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $this->buildHeaders(), [
            'userId' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateMembershipPrivacyPolicyWithoutAuth(): void
    {
        $response = $this->updateMembershipPrivacyPolicy([
            'userId' => true,
            'userEmail' => true,
            'userPhone' => true,
            'userName' => true,
            'userMFA' => true,
        ], false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function buildHeaders(bool $authenticated = true): array
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $headers;
    }

    protected function getProjectDocument(): array
    {
        return $this->client->call(Client::METHOD_GET, '/projects/' . $this->getProject()['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]);
    }

    protected function listPolicies(?array $queries = null, ?bool $total = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($queries !== null) {
            $params['queries'] = $queries;
        }

        if ($total !== null) {
            $params['total'] = $total;
        }

        return $this->client->call(Client::METHOD_GET, '/project/policies', $this->buildHeaders($authenticated), $params);
    }

    protected function getPolicy(string $policyId, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_GET, '/project/policies/' . $policyId, $this->buildHeaders($authenticated));
    }

    protected function updatePasswordDictionaryPolicy(bool $enabled, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/password-dictionary', $this->buildHeaders($authenticated), [
            'enabled' => $enabled,
        ]);
    }

    protected function updatePasswordHistoryPolicy(?int $total, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $this->buildHeaders($authenticated), [
            'total' => $total,
        ]);
    }

    protected function updatePasswordPersonalDataPolicy(bool $enabled, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/password-personal-data', $this->buildHeaders($authenticated), [
            'enabled' => $enabled,
        ]);
    }

    protected function updateSessionAlertPolicy(bool $enabled, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/session-alert', $this->buildHeaders($authenticated), [
            'enabled' => $enabled,
        ]);
    }

    protected function updateSessionDurationPolicy(int $duration, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/session-duration', $this->buildHeaders($authenticated), [
            'duration' => $duration,
        ]);
    }

    protected function updateSessionInvalidationPolicy(bool $enabled, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/session-invalidation', $this->buildHeaders($authenticated), [
            'enabled' => $enabled,
        ]);
    }

    protected function updateSessionLimitPolicy(?int $total, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/session-limit', $this->buildHeaders($authenticated), [
            'total' => $total,
        ]);
    }

    protected function updateUserLimitPolicy(?int $total, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/user-limit', $this->buildHeaders($authenticated), [
            'total' => $total,
        ]);
    }

    /**
     * @param array<string, bool> $params
     */
    protected function updateMembershipPrivacyPolicy(array $params, bool $authenticated = true): mixed
    {
        return $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $this->buildHeaders($authenticated), $params);
    }
}
