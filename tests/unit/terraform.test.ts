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
import {
  isTerraformDriftSettled,
  summarizeTerraformActivity,
} from '@/lib/terraform/state'
import prodEvents from './fixtures/terraform-activity-prod.json'
import stagingEvents from './fixtures/terraform-activity-staging.json'

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

/** Real `listEvents` payloads (newest first), stripped of IPs and emails. */
const PROD_EVENTS = prodEvents as Models.ActivityEvent[]
const STAGING_EVENTS = stagingEvents as Models.ActivityEvent[]

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
  test('provider 1.8.0 on prod: drift from curl after the last apply', () => {
    const project = summarizeTerraformActivity(PROD_EVENTS)

    expect(Object.keys(project.resources).sort()).toEqual([
      'function/growth-legacy',
      'project.key/terraform-growth-legacy',
      'rule/f3e873a8365b73f9bb5d96185cb2040a',
    ])
    const managed = project.resources['function/growth-legacy']
    expect(managed.appliedAt).toBe('2026-09-28T09:08:21.000+00:00')
    expect(managed.drift?.event).toBe('deployment.delete')
    expect(managed.drift?.userAgent).toBe('curl/8.7.1')
    expect(
      project.resources['rule/f3e873a8365b73f9bb5d96185cb2040a'].drift,
    ).toBeNull()
    expect(project.keyName).toBe('Terraform growth legacy')
  })

  test('provider with SDK identity headers on staging', () => {
    const project = summarizeTerraformActivity(STAGING_EVENTS)

    expect(Object.keys(project.resources).sort()).toEqual([
      'bucket/uploads',
      'database/main',
      'database/main/table/posts',
      'function/api',
      'webhook/65c88b2001b8c18f2fe8',
    ])
    expect(project.resources['function/api'].drift?.event).toBe(
      'function.update',
    )
    expect(project.resources['bucket/uploads'].drift).toBeNull()
  })

  test('a Terraform write only clears drift of the same kind', () => {
    // Up to the apply that rewrote the function settings but not yet the variable.
    const beforeVariableApply = STAGING_EVENTS.filter(
      (event) =>
        event.time <= '2026-09-28T11:41:20.000+00:00' &&
        !(event.event === 'variable.create' && event.time.includes('11:41:20')),
    )
    const drifted = summarizeTerraformActivity(beforeVariableApply)
    expect(drifted.resources['function/api'].drift?.event).toBe(
      'variable.delete',
    )

    const reconciled = summarizeTerraformActivity(
      STAGING_EVENTS.filter(
        (event) => event.time <= '2026-09-28T11:41:20.000+00:00',
      ),
    )
    expect(reconciled.resources['function/api'].drift).toBeNull()
  })

  test('drops deleted resources and ignores executions', () => {
    const table = STAGING_EVENTS.find(
      (event) => event.event === 'table.create',
    )!
    // A console admin deleting the table and running the function, after the last apply.
    const admin = STAGING_EVENTS.find((event) => event.actorType === 'admin')!
    const later = '2026-09-28T13:00:00.000+00:00'
    const events = [
      {
        ...admin,
        $id: 'delete',
        event: 'table.delete',
        resource: table.resource,
        resourceType: table.resourceType,
        resourceParent: table.resourceParent,
        time: later,
      },
      { ...admin, $id: 'run', event: 'execution.create', time: later },
      ...STAGING_EVENTS.filter(
        (event) => event.time <= '2026-09-28T11:41:20.000+00:00',
      ),
    ]
    const project = summarizeTerraformActivity(events)
    expect(project.resources['database/main/table/posts']).toBeUndefined()
    expect(project.resources['function/api'].drift).toBeNull()
  })
})

describe('isTerraformDriftSettled', () => {
  const managed = summarizeTerraformActivity(
    STAGING_EVENTS.filter(
      (event) => event.time <= '2026-09-28T11:41:20.000+00:00',
    ),
  ).resources['function/api']
  const change = {
    time: '2026-09-28T12:02:38.000+00:00',
    event: 'functions.update',
    actorName: 'Console admin',
    actorType: 'admin',
    userAgent: '',
  }
  const soon = Date.parse(change.time) + 60 * 1000

  test('keeps a console change until the log records it', () => {
    expect(isTerraformDriftSettled(managed, change, soon)).toBe(false)

    const recorded =
      summarizeTerraformActivity(STAGING_EVENTS).resources['function/api']
    expect(isTerraformDriftSettled(recorded, change, soon)).toBe(true)
  })

  test('settles when Terraform applies afterwards or the resource goes away', () => {
    const applied = { ...managed, appliedAt: '2026-09-28T12:10:00.000+00:00' }
    expect(isTerraformDriftSettled(applied, change, soon)).toBe(true)
    expect(isTerraformDriftSettled(undefined, change, soon)).toBe(true)
  })

  test('stops waiting when the log never catches up', () => {
    const muchLater = Date.parse(change.time) + 60 * 60 * 1000
    expect(isTerraformDriftSettled(managed, change, muchLater)).toBe(true)
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
