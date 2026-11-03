import { afterEach, describe, expect, test } from 'bun:test'
import {
  buildServiceAccountDisplayName,
  buildServiceAccountId,
  FCM_SENDER_ROLE,
  isGoogleRelayService,
  planProvisionCompletion,
  serviceAccountKeyId,
} from '@/lib/fcm/google'
import { createMintedCredentialTracker } from '@/lib/smtp/quick-setup'
import { proxyGoogleRequest } from '@/lib/fcm/google-proxy'
import {
  checkProvisioningPermissions,
  FcmProvisioningBlockedError,
  GoogleApiError,
  isGoogleUnauthorizedError,
  isServiceDisabledError,
  listFirebaseProjects,
  provisionServiceAccountKey,
} from '@/lib/fcm/google-cloud-api'

describe('buildServiceAccountId', () => {
  // IAM account ids must be 6-30 chars, start with a letter, then lowercase
  // letters, digits, or hyphens.
  const VALID = /^[a-z][-a-z0-9]{4,28}[a-z0-9]$/

  test('is a valid, prefixed IAM account id', () => {
    const id = buildServiceAccountId()
    expect(id.startsWith('appwrite-fcm-')).toBe(true)
    expect(id.length).toBeLessThanOrEqual(30)
    expect(id).toMatch(VALID)
  })

  test('is random across calls so first provisioning never collides', () => {
    const ids = new Set(
      Array.from({ length: 20 }, () => buildServiceAccountId()),
    )
    expect(ids.size).toBe(20)
  })
})

describe('buildServiceAccountDisplayName', () => {
  test('labels the account with the project name', () => {
    expect(buildServiceAccountDisplayName('Acme')).toBe('Appwrite FCM: Acme')
  })

  test('falls back to the bare prefix when the name is blank', () => {
    expect(buildServiceAccountDisplayName('   ')).toBe('Appwrite FCM')
  })

  test('stays within the IAM display name cap', () => {
    expect(
      buildServiceAccountDisplayName('x'.repeat(200)).length,
    ).toBeLessThanOrEqual(100)
  })
})

describe('isGoogleRelayService', () => {
  test('accepts only the allow-listed Google hosts', () => {
    expect(isGoogleRelayService('firebase')).toBe(true)
    expect(isGoogleRelayService('crm')).toBe(true)
    expect(isGoogleRelayService('iam')).toBe(true)
    expect(isGoogleRelayService('serviceusage')).toBe(true)
    expect(isGoogleRelayService('evil')).toBe(false)
    expect(isGoogleRelayService('')).toBe(false)
  })
})

describe('error classification', () => {
  test('only a 401 counts as needing reauthorization', () => {
    expect(isGoogleUnauthorizedError(new GoogleApiError('no', 401))).toBe(true)
    expect(isGoogleUnauthorizedError(new GoogleApiError('no', 403))).toBe(false)
    expect(isGoogleUnauthorizedError(new Error('no'))).toBe(false)
  })

  test('a provisioning block carries the gate that failed', () => {
    const error = new FcmProvisioningBlockedError('org-policy', 'blocked')
    expect(error.block).toBe('org-policy')
    expect(error).toBeInstanceOf(Error)
  })

  test('only a disabled-service reason is treated as an operator API gap', () => {
    const disabled = new GoogleApiError(
      'not enabled',
      403,
      'SERVICE_DISABLED',
      'https://console.developers.google.com/apis/api/firebase.googleapis.com',
    )
    expect(isServiceDisabledError(disabled)).toBe(true)
    expect(disabled.helpUrl).toContain('firebase.googleapis.com')
    expect(
      isServiceDisabledError(
        new GoogleApiError('denied', 403, 'PERMISSION_DENIED'),
      ),
    ).toBe(false)
    expect(isServiceDisabledError(new Error('x'))).toBe(false)
  })
})

describe('proxyGoogleRequest guard rails', () => {
  const url = 'http://localhost/_api/google/crm/v1/projects/p:getIamPolicy'

  const request = (headers: Record<string, string>, method = 'POST') =>
    new Request(url, { method, headers })

  test('rejects cross-site callers before anything else', async () => {
    const response = await proxyGoogleRequest(
      request({ 'sec-fetch-site': 'cross-site', authorization: 'Bearer t' }),
      'crm/v1/projects/p:getIamPolicy',
    )
    expect(response.status).toBe(403)
  })

  test('rejects methods outside GET/POST/DELETE', async () => {
    const response = await proxyGoogleRequest(
      request({ authorization: 'Bearer t' }, 'PUT'),
      'crm/v1/projects/p:getIamPolicy',
    )
    expect(response.status).toBe(405)
  })

  test('requires a bearer token', async () => {
    const response = await proxyGoogleRequest(
      request({}),
      'crm/v1/projects/p:getIamPolicy',
    )
    expect(response.status).toBe(401)
  })

  test('refuses an unknown upstream before reaching the network', async () => {
    const response = await proxyGoogleRequest(
      request({ authorization: 'Bearer t' }),
      'evil/v1/steal',
    )
    expect(response.status).toBe(404)
  })
})

// ---------------------------------------------------------------------------
// Provisioning flow against mocked Google responses (no real GCP calls). Routes
// match on request method + a URL fragment and return canned JSON through the
// `/google/*` relay path the adapter fetches.
// ---------------------------------------------------------------------------

type Route = {
  method: string
  match: string
  status?: number
  body?: unknown
}

const realFetch = globalThis.fetch

function routeFetch(routes: Route[]): void {
  globalThis.fetch = (async (input: unknown, init?: RequestInit) => {
    const url = String(input)
    const method = (init?.method ?? 'GET').toUpperCase()
    const route = routes.find(
      (r) => r.method === method && url.includes(r.match),
    )
    if (!route) throw new Error(`unexpected fetch ${method} ${url}`)
    return new Response(
      route.body === undefined ? '' : JSON.stringify(route.body),
      {
        status: route.status ?? 200,
        headers: { 'Content-Type': 'application/json' },
      },
    )
  }) as typeof fetch
}

function googleError(status: string, message: string, reason?: string) {
  return {
    error: {
      code: status === 'PERMISSION_DENIED' ? 403 : 400,
      status,
      message,
      details: reason
        ? [{ '@type': 'type.googleapis.com/google.rpc.ErrorInfo', reason }]
        : [],
    },
  }
}

afterEach(() => {
  globalThis.fetch = realFetch
})

describe('listFirebaseProjects', () => {
  test('keeps active projects and normalizes the display name', async () => {
    routeFetch([
      {
        method: 'GET',
        match: '/google/firebase/v1beta1/projects',
        body: {
          results: [
            { projectId: 'proj-1', displayName: 'Proj One', state: 'ACTIVE' },
            { projectId: 'proj-2', state: 'DELETED' },
            { projectId: 'proj-3', state: 'ACTIVE' },
          ],
        },
      },
    ])

    const projects = await listFirebaseProjects('tok')
    expect(projects).toEqual([
      { id: 'proj-1', displayName: 'Proj One' },
      { id: 'proj-3', displayName: 'proj-3' },
    ])
  })
})

describe('checkProvisioningPermissions', () => {
  test('is ok only when every required permission is granted', async () => {
    routeFetch([
      {
        method: 'POST',
        match: ':testIamPermissions',
        body: {
          permissions: [
            'iam.serviceAccounts.create',
            'iam.serviceAccountKeys.create',
            'resourcemanager.projects.setIamPolicy',
          ],
        },
      },
    ])
    expect((await checkProvisioningPermissions('tok', 'proj-1')).ok).toBe(true)
  })

  test('reports the missing permission', async () => {
    routeFetch([
      {
        method: 'POST',
        match: ':testIamPermissions',
        body: { permissions: ['iam.serviceAccounts.create'] },
      },
    ])
    const result = await checkProvisioningPermissions('tok', 'proj-1')
    expect(result.ok).toBe(false)
    expect(result.missing).toContain('iam.serviceAccountKeys.create')
  })
})

describe('provisionServiceAccountKey', () => {
  const keyJson = { type: 'service_account', project_id: 'proj-1' }

  const email = 'sa@proj-1.iam.gserviceaccount.com'

  test('grants the sender role to the account, preserving existing bindings', async () => {
    // Existing policy: an unrelated binding and a *conditional* binding for the
    // same FCM role, both of which must survive untouched.
    const viewer = { role: 'roles/viewer', members: ['user:a@b.com'] }
    const conditionalSender = {
      role: FCM_SENDER_ROLE,
      members: ['serviceAccount:other@proj-1.iam.gserviceaccount.com'],
      condition: { title: 'only-staging', expression: 'resource.name == "x"' },
    }
    type WrittenPolicy = {
      version?: number
      bindings?: Array<{
        role?: string
        members?: string[]
        condition?: unknown
      }>
    }
    // Assigned inside the mocked fetch; the assertion stops TS narrowing it to null.
    let setPolicy = null as WrittenPolicy | null

    globalThis.fetch = (async (input: unknown, init?: RequestInit) => {
      const url = String(input)
      if (url.includes(':getIamPolicy')) {
        return new Response(
          JSON.stringify({ bindings: [viewer, conditionalSender], etag: 'e' }),
          { status: 200 },
        )
      }
      if (url.includes(':setIamPolicy')) {
        const parsed = JSON.parse((init?.body as string) ?? '{}') as {
          policy?: WrittenPolicy
        }
        setPolicy = parsed.policy ?? null
        return new Response(JSON.stringify({}), { status: 200 })
      }
      if (url.includes('/serviceAccounts/') && url.includes('/keys')) {
        return new Response(
          JSON.stringify({
            name: `projects/proj-1/serviceAccounts/${email}/keys/abc`,
            privateKeyData: btoa(JSON.stringify(keyJson)),
          }),
          { status: 200 },
        )
      }
      if (url.includes('/serviceAccounts')) {
        return new Response(JSON.stringify({ email }), { status: 200 })
      }
      throw new Error(`unexpected fetch ${url}`)
    }) as typeof fetch

    const key = await provisionServiceAccountKey('tok', {
      projectId: 'proj-1',
      projectName: 'My App',
    })

    expect(key.serviceAccount).toBe(email)
    expect(JSON.parse(key.secret)).toEqual(keyJson)

    // The written policy must grant the sender role to the new account through an
    // unconditional binding, without disturbing the existing ones.
    const bindings = setPolicy?.bindings ?? []
    expect(setPolicy?.version).toBe(3)
    expect(bindings).toContainEqual(viewer)
    expect(bindings).toContainEqual(conditionalSender)
    const granted = bindings.find(
      (b) => b.role === FCM_SENDER_ROLE && !b.condition,
    )
    expect(granted?.members).toContain(`serviceAccount:${email}`)
  })

  test('maps an insufficient-scope failure to the scope gate', async () => {
    routeFetch([
      {
        method: 'POST',
        match: '/serviceAccounts',
        status: 403,
        body: googleError(
          'PERMISSION_DENIED',
          'Request had insufficient authentication scopes.',
          'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
        ),
      },
    ])

    const error = await provisionServiceAccountKey('tok', {
      projectId: 'proj-1',
      projectName: 'My App',
    }).catch((e) => e)
    expect(error).toBeInstanceOf(FcmProvisioningBlockedError)
    expect((error as FcmProvisioningBlockedError).block).toBe('scope')
  })

  test('maps a blocked key creation to the org-policy gate and cleans up the account', async () => {
    // Assigned inside the mocked fetch; the assertion stops TS narrowing it to null.
    let deletedAccount = null as string | null
    globalThis.fetch = (async (input: unknown, init?: RequestInit) => {
      const url = String(input)
      const method = (init?.method ?? 'GET').toUpperCase()
      if (method === 'DELETE' && url.includes('/serviceAccounts/')) {
        deletedAccount = url
        return new Response(JSON.stringify({}), { status: 200 })
      }
      if (url.includes('/keys')) {
        return new Response(
          JSON.stringify(
            googleError(
              'FAILED_PRECONDITION',
              'Key creation is not allowed on this service account.',
            ),
          ),
          { status: 400 },
        )
      }
      if (url.includes(':getIamPolicy')) {
        return new Response(JSON.stringify({ bindings: [], etag: 'e' }), {
          status: 200,
        })
      }
      if (url.includes(':setIamPolicy')) {
        return new Response(JSON.stringify({}), { status: 200 })
      }
      if (url.includes('/serviceAccounts')) {
        return new Response(JSON.stringify({ email }), { status: 200 })
      }
      throw new Error(`unexpected fetch ${method} ${url}`)
    }) as typeof fetch

    const error = await provisionServiceAccountKey('tok', {
      projectId: 'proj-1',
      projectName: 'My App',
    }).catch((e) => e)
    expect(error).toBeInstanceOf(FcmProvisioningBlockedError)
    expect((error as FcmProvisioningBlockedError).block).toBe('org-policy')
    // The account created before the block must be removed, not orphaned.
    expect(deletedAccount).toContain(encodeURIComponent(email))
  })
})

describe('auto-enable on SERVICE_DISABLED', () => {
  function serviceDisabled(service: string, consumer: string) {
    return {
      error: {
        code: 403,
        status: 'PERMISSION_DENIED',
        message: `${service} has not been used in ${consumer} before or it is disabled.`,
        details: [
          {
            '@type': 'type.googleapis.com/google.rpc.ErrorInfo',
            reason: 'SERVICE_DISABLED',
            metadata: {
              service,
              consumer,
              activationUrl: `https://console.developers.google.com/apis/api/${service}`,
            },
          },
        ],
      },
    }
  }

  test('enables the disabled service on its consumer project, then retries', async () => {
    let listCalls = 0
    let enableCalled = false
    globalThis.fetch = (async (input: unknown, init?: RequestInit) => {
      const url = String(input)
      const method = (init?.method ?? 'GET').toUpperCase()
      if (url.includes('/serviceusage/') && url.includes(':batchEnable')) {
        enableCalled = true
        // Enable must target the consumer project from the error.
        expect(url).toContain('/projects/123456/services:batchEnable')
        return new Response(JSON.stringify({ done: true }), { status: 200 })
      }
      if (url.includes('/firebase/v1beta1/projects')) {
        listCalls++
        if (listCalls === 1) {
          return new Response(
            JSON.stringify(
              serviceDisabled('firebase.googleapis.com', 'projects/123456'),
            ),
            { status: 403 },
          )
        }
        return new Response(
          JSON.stringify({ results: [{ projectId: 'p1', state: 'ACTIVE' }] }),
          { status: 200 },
        )
      }
      throw new Error(`unexpected fetch ${method} ${url}`)
    }) as typeof fetch

    const projects = await listFirebaseProjects('tok')
    expect(enableCalled).toBe(true)
    expect(listCalls).toBe(2)
    expect(projects).toEqual([{ id: 'p1', displayName: 'p1' }])
  })
})

describe('serviceAccountKeyId', () => {
  const key = {
    type: 'service_account',
    private_key_id: 'abc123',
    project_id: 'p',
  }

  test('reads private_key_id from a JSON string', () => {
    expect(serviceAccountKeyId(JSON.stringify(key))).toBe('abc123')
  })

  test('is stable across reformatting (whitespace, key order)', () => {
    const pretty = JSON.stringify(key, null, 2)
    const reordered = JSON.stringify({
      project_id: 'p',
      private_key_id: 'abc123',
      type: 'service_account',
    })
    expect(serviceAccountKeyId(pretty)).toBe('abc123')
    expect(serviceAccountKeyId(reordered)).toBe('abc123')
  })

  test('accepts an already-parsed object', () => {
    expect(serviceAccountKeyId(key)).toBe('abc123')
  })

  test('returns "" for invalid or missing input', () => {
    expect(serviceAccountKeyId('not json')).toBe('')
    expect(serviceAccountKeyId('{}')).toBe('')
    expect(serviceAccountKeyId(null)).toBe('')
  })
})

describe('FCM credential settlement by key identity', () => {
  const minted = {
    type: 'service_account',
    private_key_id: 'key-1',
    client_email: 'sa@p.iam',
  }

  test('reformatted JSON still settles, so the account is not revoked', async () => {
    const revoked: string[] = []
    const tracker = createMintedCredentialTracker<{
      id: string
      secret: string
    }>(async (id) => {
      revoked.push(id)
    })
    // Tracked by the key identity; the account email is the revocation id.
    tracker.track({ id: 'sa@p.iam', secret: serviceAccountKeyId(minted) })

    // The user reformats the JSON before creating the provider; it is the same
    // key, so settling by identity matches and the save keeps it.
    const reformatted = JSON.stringify(minted, null, 4)
    tracker.settle(serviceAccountKeyId(reformatted), Promise.resolve('ok'))
    await Promise.resolve()
    tracker.release()
    await Promise.resolve()

    expect(revoked).toEqual([])
  })

  test('an abandoned credential (never settled) is revoked on release', async () => {
    const revoked: string[] = []
    const tracker = createMintedCredentialTracker<{
      id: string
      secret: string
    }>(async (id) => {
      revoked.push(id)
    })
    tracker.track({ id: 'sa@p.iam', secret: serviceAccountKeyId(minted) })
    tracker.release()
    await Promise.resolve()

    expect(revoked).toEqual(['sa@p.iam'])
  })
})

describe('planProvisionCompletion', () => {
  test('cleans up when the panel unmounted mid-provision (late completion)', () => {
    expect(
      planProvisionCompletion({
        mounted: false,
        currentName: '',
        defaultName: 'One-click FCM sender',
      }),
    ).toEqual({ kind: 'cleanup' })
  })

  test('fills with the default name when the field is still empty', () => {
    expect(
      planProvisionCompletion({
        mounted: true,
        currentName: '   ',
        defaultName: 'One-click FCM sender',
      }),
    ).toEqual({ kind: 'fill', name: 'One-click FCM sender' })
  })

  test('keeps a name typed while setup ran (no default override)', () => {
    expect(
      planProvisionCompletion({
        mounted: true,
        currentName: 'My sender',
        defaultName: 'One-click FCM sender',
      }),
    ).toEqual({ kind: 'fill', name: undefined })
  })
})
