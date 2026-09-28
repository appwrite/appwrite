import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  getTerraformProviderVersion,
  isTerraformActivity,
} from '@/lib/terraform/activity'
import {
  getSdkCallResourcePath,
  getTerraformResourceKind,
} from '@/lib/terraform/resource'
import { summarizeTerraformActivity } from '@/lib/terraform/state'

const LEGACY_PROVIDER = {
  userAgent: 'terraform-provider-appwrite/1.8.0',
  sdk: 'go',
  sdkVersion: 'v6.0.0',
}
const PROVIDER = {
  userAgent:
    'Terraform/1.16.4 terraform-provider-appwrite/2.1.0 AppwriteGoSDK/v7.5.0 (darwin; arm64)',
  sdk: 'terraform',
  sdkVersion: '2.1.0',
}
const CLI = {
  userAgent: 'AppwriteCLI/27.3.0 (darwin; arm64)',
  sdk: 'command line',
  sdkVersion: '27.3.0',
}
const CURL = { userAgent: 'curl/8.7.1', sdk: '', sdkVersion: '' }

let sequence = 0

function event(
  time: string,
  name: string,
  resource: string,
  source: { userAgent: string; sdk: string; sdkVersion: string },
): Models.ActivityEvent {
  const segments = resource.split('/')
  return {
    $id: String(sequence++),
    time: `2026-09-28T${time}.000+00:00`,
    event: name,
    resource,
    resourceType: segments[segments.length - 2],
    actorType: 'keyProject',
    actorName: 'Terraform growth legacy',
    ...source,
  } as Models.ActivityEvent
}

/** Newest first, the way the API returns events. */
function newestFirst(events: Models.ActivityEvent[]) {
  return [...events].reverse()
}

describe('isTerraformActivity', () => {
  test('matches provider releases before and after the SDK identity headers', () => {
    expect(isTerraformActivity(LEGACY_PROVIDER)).toBe(true)
    expect(isTerraformActivity(PROVIDER)).toBe(true)
  })

  test('ignores other clients', () => {
    expect(isTerraformActivity(CLI)).toBe(false)
    expect(isTerraformActivity(CURL)).toBe(false)
    expect(
      isTerraformActivity({ userAgent: 'AppwriteMCP/1.0.0', sdk: 'mcp' }),
    ).toBe(false)
    expect(isTerraformActivity(null)).toBe(false)
  })

  test('reads the provider version', () => {
    expect(getTerraformProviderVersion(LEGACY_PROVIDER)).toBe('1.8.0')
    expect(getTerraformProviderVersion(PROVIDER)).toBe('2.1.0')
    expect(getTerraformProviderVersion(CLI)).toBeNull()
  })
})

describe('summarizeTerraformActivity', () => {
  test('marks resources the provider wrote to and records drift after the last apply', () => {
    const project = summarizeTerraformActivity(
      newestFirst([
        event(
          '08:19:37',
          'function.create',
          'function/growth-legacy',
          LEGACY_PROVIDER,
        ),
        event('08:19:38', 'rule.create', 'rule/f3e8', LEGACY_PROVIDER),
        event('08:23:22', 'deployment.create', 'function/growth-legacy', CLI),
        event(
          '09:08:21',
          'deployment.create',
          'function/growth-legacy',
          LEGACY_PROVIDER,
        ),
        event('09:08:59', 'deployment.delete', 'function/growth-legacy', CURL),
        event('09:10:00', 'bucket.create', 'bucket/manual', CLI),
      ]),
    )

    expect(Object.keys(project.resources).sort()).toEqual([
      'function/growth-legacy',
      'rule/f3e8',
    ])
    const managed = project.resources['function/growth-legacy']
    expect(managed.appliedAt).toBe('2026-09-28T09:08:21.000+00:00')
    expect(managed.providerVersion).toBe('1.8.0')
    expect(managed.drift?.event).toBe('deployment.delete')
    expect(managed.drift?.userAgent).toBe('curl/8.7.1')
    expect(project.resources['rule/f3e8'].drift).toBeNull()
    expect(project.appliedAt).toBe('2026-09-28T09:08:21.000+00:00')
    expect(project.keyName).toBe('Terraform growth legacy')
  })

  test('a later apply clears drift', () => {
    const project = summarizeTerraformActivity(
      newestFirst([
        event('10:00:00', 'function.create', 'function/api', PROVIDER),
        event('10:05:00', 'function.update', 'function/api', CLI),
        event('10:10:00', 'function.update', 'function/api', PROVIDER),
      ]),
    )
    expect(project.resources['function/api'].drift).toBeNull()
  })

  test('drops deleted resources and ignores executions', () => {
    const project = summarizeTerraformActivity(
      newestFirst([
        event('10:00:00', 'function.create', 'function/api', PROVIDER),
        event('10:01:00', 'execution.create', 'function/api', CURL),
        event(
          '10:02:00',
          'table.create',
          'database/main/table/posts',
          PROVIDER,
        ),
        event('10:03:00', 'table.delete', 'database/main/table/posts', CLI),
      ]),
    )
    expect(Object.keys(project.resources)).toEqual(['function/api'])
    expect(project.resources['function/api'].drift).toBeNull()
  })
})

describe('getSdkCallResourcePath', () => {
  test('maps configuration writes to the activity resource path', () => {
    expect(
      getSdkCallResourcePath('functions', 'update', { functionId: 'api' }),
    ).toBe('function/api')
    expect(
      getSdkCallResourcePath('functions', 'updateVariable', {
        functionId: 'api',
        variableId: 'x',
      }),
    ).toBe('function/api')
    expect(
      getSdkCallResourcePath('tablesDB', 'createStringColumn', {
        databaseId: 'main',
        tableId: 'posts',
      }),
    ).toBe('database/main/table/posts')
    expect(
      getSdkCallResourcePath('tablesDB', 'delete', { databaseId: 'main' }),
    ).toBe('database/main')
    expect(
      getSdkCallResourcePath('project', 'deleteKey', { keyId: 'terraform' }),
    ).toBe('project.key/terraform')
  })

  test('skips reads, data writes and positional calls', () => {
    expect(
      getSdkCallResourcePath('functions', 'get', { functionId: 'api' }),
    ).toBeNull()
    expect(
      getSdkCallResourcePath('functions', 'createExecution', {
        functionId: 'api',
      }),
    ).toBeNull()
    expect(
      getSdkCallResourcePath('tablesDB', 'createRow', {
        databaseId: 'main',
        tableId: 'posts',
      }),
    ).toBeNull()
    expect(
      getSdkCallResourcePath('storage', 'createFile', { bucketId: 'uploads' }),
    ).toBeNull()
    expect(getSdkCallResourcePath('functions', 'update', 'api')).toBeNull()
  })

  test('recovers the kind from a path', () => {
    expect(getTerraformResourceKind('database/main/table/posts')).toBe('table')
    expect(getTerraformResourceKind('project.key/terraform')).toBe('key')
    expect(getTerraformResourceKind('user/abc')).toBeNull()
  })
})
