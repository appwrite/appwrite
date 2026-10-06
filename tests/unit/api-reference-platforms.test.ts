import { describe, expect, test } from 'bun:test'
import { loadParsedApiSpec } from '@/lib/api-explorer/load-spec'
import { loadApiReferenceService } from '@/server/api-reference/load-service'
import { loadReferenceNavServiceCounts } from '@/server/api-reference/reference-nav'

const ENGINES = ['postgresql', 'mysql', 'mongo'] as const

describe('API reference platform filtering', () => {
  for (const version of ['cloud', '1.9.x'] as const) {
    for (const engine of ENGINES) {
      test(`${version} client-web lists no ${engine} methods`, async () => {
        const service = await loadApiReferenceService(
          version,
          'client-web',
          engine,
        )
        expect(service?.methods).toEqual([])
      })

      test(`${version} server-nodejs lists ${engine} methods`, async () => {
        const service = await loadApiReferenceService(
          version,
          'server-nodejs',
          engine,
        )
        expect(service?.methods.length).toBeGreaterThan(0)
      })
    }

    test(`${version} client nav omits native engines`, async () => {
      const counts = await loadReferenceNavServiceCounts(version, 'client')
      for (const engine of ENGINES) expect(counts.has(engine)).toBe(false)
      expect(counts.get('account')).toBeGreaterThan(0)
    })

    test(`${version} server nav keeps native engines`, async () => {
      const counts = await loadReferenceNavServiceCounts(version, 'server')
      for (const engine of ENGINES)
        expect(counts.get(engine)).toBeGreaterThan(0)
    })
  }

  test('client-web postgresql has no createExecution', async () => {
    const service = await loadApiReferenceService(
      'cloud',
      'client-web',
      'postgresql',
    )
    expect(
      service?.methods.some((method) => method.id === 'createExecution'),
    ).toBe(false)
  })

  test('project explorer client spec omits native engines', async () => {
    const client = await loadParsedApiSpec('client')
    const server = await loadParsedApiSpec('server')
    const ids = (services: typeof client.services) =>
      services
        .filter((service) => service.methods.length > 0)
        .map((service) => service.id)

    for (const engine of ENGINES) {
      expect(ids(client.services)).not.toContain(engine)
      expect(ids(server.services)).toContain(engine)
    }
  })
})
