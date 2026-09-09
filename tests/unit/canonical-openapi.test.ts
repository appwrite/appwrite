import { describe, expect, test } from 'bun:test'
import { loadParsedApiSpec, loadRawApiSpec } from '@/lib/api-explorer/load-spec'
import { parseOpenApiSpec } from '@/lib/api-explorer/parse-spec'
import {
  methodRequiresApiKey,
  methodSupportsServerApiKey,
} from '@/lib/api-explorer/auth'
import { getMethodAuthDescription } from '@/lib/api-explorer/format-method-auth'
import {
  getAcceptedSecuritySchemeNames,
  getRequiredSecuritySchemeNames,
} from '@/lib/api-explorer/security'
import { parameterToFormField } from '@/lib/api-explorer/request-form'
import { getSpecFilename } from '@/lib/docs/references/constants'
import { loadApiReferenceService } from '@/server/api-reference/load-service'
import type {
  ApiSpecPlatform,
  OpenApiOperation,
  OpenApiSpec,
} from '@/lib/api-explorer/types'

function fixture(operation: OpenApiOperation): OpenApiSpec {
  return {
    paths: {
      '/widgets': {
        get: {
          operationId: 'widgetsList',
          tags: ['widgets'],
          ...operation,
        },
      },
    },
  }
}

function methods(spec: OpenApiSpec, platform: ApiSpecPlatform = 'server') {
  return parseOpenApiSpec(spec, platform).services.flatMap(
    (service) => service.methods,
  )
}

describe('canonical OpenAPI metadata', () => {
  test('derives SDK names and selects platform auth without changing the raw document', () => {
    const spec = fixture({
      'x-appwrite': {
        platforms: ['client', 'server'],
        auth: { client: { Project: [] }, server: { Project: [], Key: [] } },
      },
    })
    const before = JSON.stringify(spec)
    expect(methods(spec)[0]?.id).toBe('list')
    expect(methods(spec)[0]?.authLabel).toBe('Project, Key')
    expect(methods(spec, 'client')[0]?.xAppwrite?.auth).toEqual({ Project: [] })
    expect(methods(spec, 'console')).toEqual([])
    expect(JSON.stringify(spec)).toBe(before)
  })

  test('preserves legacy flat auth and explicit method names', () => {
    const method = methods(
      fixture({
        'x-appwrite': {
          method: 'listLegacy',
          auth: { Project: [], Key: [] },
        },
      }),
    )[0]!
    expect(method.id).toBe('listLegacy')
    expect(method.authLabel).toBe('Project, Key')
  })

  test('selects alias platforms, auth, required properties, responses and IDs', () => {
    const spec = fixture({
      requestBody: {
        content: {
          'application/json': {
            schema: {
              type: 'object',
              properties: {
                name: { type: 'string' },
                ignored: { type: 'string' },
              },
              required: ['ignored'],
            },
          },
        },
      },
      'x-appwrite': {
        methods: [
          { name: 'listClient', platforms: ['client'] },
          {
            name: 'listServer',
            platforms: ['server'],
            summary: 'Server alias',
            auth: { server: { Key: [] } },
            parameters: ['name'],
            required: ['name'],
            responses: [{ code: 201, model: '#/components/schemas/widget' }],
            deprecated: { since: '2.0.0' },
            demo: 'widgets/list-server.md',
          },
          { name: 'hidden', public: false },
        ],
      },
      responses: { '200': {} },
    })
    const result = methods(spec)
    expect(result).toHaveLength(1)
    expect(result[0]).toMatchObject({
      id: 'listServer',
      operationId: 'widgetsListServer',
      summary: 'Server alias',
      deprecated: true,
      xAppwrite: { auth: { Key: [] }, demo: 'widgets/list-server.md' },
    })
    expect(
      result[0]?.requestBody?.content?.['application/json']?.schema,
    ).toMatchObject({
      properties: { name: { type: 'string' } },
      required: ['name'],
    })
    expect(Object.keys(result[0]?.rawResponses ?? {})).toEqual(['201'])
    expect(methods(spec, 'client').map((method) => method.id)).toEqual([
      'listClient',
    ])
  })

  test('retains security alternatives and finds schemes outside the first entry', () => {
    const security: NonNullable<OpenApiOperation['security']> = [
      { Project: [], Session: [] },
      { Project: [], Key: [], ImpersonateUserId: [] },
    ]
    const method = methods(
      fixture({
        security,
        'x-appwrite': { auth: { server: { Project: [] } } },
      }),
    )[0]!
    expect(method.security).toEqual(security)
    expect(getAcceptedSecuritySchemeNames(method)).toEqual([
      'Project',
      'Session',
      'Key',
      'ImpersonateUserId',
    ])
    expect(getRequiredSecuritySchemeNames(method)).toEqual(['Project'])
    const withoutExampleAuth = { ...method, xAppwrite: undefined }
    expect(methodRequiresApiKey(withoutExampleAuth, 'server')).toBe(false)
    expect(methodSupportsServerApiKey(withoutExampleAuth, 'server')).toBe(true)
    expect(methodSupportsServerApiKey(method, 'server')).toBe(true)
    expect(
      getMethodAuthDescription(method, 'server-nodejs').impersonation,
    ).toBe(true)
  })

  test('inherits root security, honors anonymous overrides and scheme platforms', () => {
    const spec = fixture({})
    spec.security = [{ Project: [], Key: [] }]
    spec.components = {
      securitySchemes: { Key: { 'x-appwrite': { platforms: ['server'] } } },
    }
    expect(methods(spec, 'client')[0]?.security).toEqual([{ Project: [] }])
    spec.paths!['/widgets']!.get!.security = []
    expect(methods(spec)[0]?.security).toEqual([])
    expect(
      getRequiredSecuritySchemeNames(
        methods(fixture({ security: [{ Key: [] }, {}] }))[0]!,
      ),
    ).toEqual([])
  })

  test('does not merge OAuth scopes across security alternatives', () => {
    const security = [{ OAuth: ['read'] }, { OAuth: ['write'] }]
    const method = methods(fixture({ security }))[0]!
    expect(getAcceptedSecuritySchemeNames(method)).toEqual(['OAuth'])
    expect(getRequiredSecuritySchemeNames(method)).toEqual(['OAuth'])
    expect(method.security).toEqual(security)
  })

  test('uses standard nullable while retaining legacy fallback', () => {
    for (const schema of [
      { type: 'string', nullable: true },
      { type: 'string', 'x-nullable': true },
    ]) {
      expect(
        parameterToFormField({ name: 'value', in: 'query', schema }).nullable,
      ).toBe(true)
    }
    expect(
      parameterToFormField({
        name: 'value',
        in: 'query',
        schema: {
          type: 'string',
          nullable: false,
          'x-nullable': true,
        },
      }).nullable,
    ).toBe(false)
  })
})

describe('pinned appwrite/specs integration', () => {
  test('loads a shared canonical document and parses every platform with usable method IDs', async () => {
    expect(await loadRawApiSpec('client')).toBe(await loadRawApiSpec('server'))
    for (const platform of ['client', 'server', 'console'] as const) {
      const parsed = await loadParsedApiSpec(platform)
      const all = parsed.services.flatMap((service) => service.methods)
      expect(all.length).toBeGreaterThan(200)
      for (const method of all) {
        expect(method.id).toBeTruthy()
        expect(method.authLabel).not.toMatch(/client|server|console/)
      }
      const account = parsed.services.find(
        (service) => service.id === 'account',
      )!
      expect(account.methods.some((method) => method.id === 'get')).toBe(true)
      expect(account.methods.some((method) => method.id === 'delete')).toBe(
        platform === 'console',
      )
    }
  })

  test('resolves canonical and legacy reference files and loads real examples', async () => {
    expect(getSpecFilename('latest', 'client')).toBe('open-api3-latest.json')
    expect(getSpecFilename('2.0.x', 'server')).toBe('open-api3-2.0.x.json')
    expect(getSpecFilename('1.9.x', 'client')).toBe(
      'open-api3-1.9.x-client.json',
    )
    for (const version of ['cloud', '2.0.x', '1.9.x']) {
      const service = await loadApiReferenceService(
        version,
        'client-web',
        'account',
      )
      const get = service?.methods.find((method) => method.id === 'get')
      expect(get?.demo).toContain('account.get(')
      expect(
        get?.responses.some(
          (response) => response.code === 200 && response.models.length > 0,
        ),
      ).toBe(true)
    }
  })
})
