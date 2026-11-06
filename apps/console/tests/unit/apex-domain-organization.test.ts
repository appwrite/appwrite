import { afterAll, describe, expect, mock, test } from 'bun:test'
import * as actualSdk from '@/lib/appwrite/sdk'
import { queryClient } from '@/lib/react-query/query-client'
import { ensureApexDomainInOrganization } from '@/lib/utils/proxy-domains'

describe('ensureApexDomainInOrganization', () => {
  // Copy the real module now: the namespace import is live and points at the mock once it is set.
  const realSdk = { ...actualSdk }

  afterAll(() => {
    queryClient.removeQueries({ queryKey: ['console-variables'] })
    mock.module('@/lib/appwrite/sdk', () => realSdk)
  })

  function mockCloud(
    variables?: () => Promise<object>,
    regional?: Record<string, () => Promise<object>>,
  ) {
    queryClient.removeQueries({ queryKey: ['console-variables'] })
    const created: string[] = []
    const domains = {
      list: async () => ({ domains: [] }),
      create: async ({ domain }: { domain: string }) => {
        created.push(domain)
      },
    }
    const console = {
      console: {
        variables:
          variables ??
          (async () => ({
            _APP_DOMAIN_SITES: 'appwrite.network',
            _APP_DOMAIN_FUNCTIONS: 'fra.appwrite.run',
          })),
      },
      domains,
    }
    mock.module('@/lib/appwrite/sdk', () => ({
      ...actualSdk,
      getBaseEndpoint: () => 'https://cloud.appwrite.io/v1',
      sdk: {
        forConsole: console,
        forConsoleIn: (region: string) =>
          regional?.[region]
            ? { console: { variables: regional[region] }, domains }
            : console,
      },
    }))
    return created
  }

  test('does not register a zone Appwrite serves', async () => {
    const created = mockCloud()
    await ensureApexDomainInOrganization('team1', 'myapp.appwrite.network', {
      skipForSites: true,
    })
    await ensureApexDomainInOrganization('team1', 'fn.fra.appwrite.run')
    expect(created).toEqual([])
  })

  test("reads the zones of the project's region", async () => {
    const created = mockCloud(undefined, {
      sfo: async () => ({
        _APP_DOMAIN_SITES: 'appwrite.network',
        _APP_DOMAIN_FUNCTIONS: 'sfo.appwrite.run',
      }),
    })
    await ensureApexDomainInOrganization('team1', 'fn.sfo.appwrite.run', {
      region: 'sfo',
    })
    expect(created).toEqual([])
  })

  test('does not register appwrite.site, a later sites zone', async () => {
    const created = mockCloud()
    await ensureApexDomainInOrganization('team1', 'myapp.appwrite.site')
    expect(created).toEqual([])
  })

  test('registers a domain that only ends like a zone Appwrite serves', async () => {
    const created = mockCloud()
    await ensureApexDomainInOrganization('team1', 'app.evil-appwrite.network')
    expect(created).toEqual(['evil-appwrite.network'])
  })

  test('still registers a custom domain when the variables fail to load', async () => {
    const created = mockCloud(async () => {
      throw new Error('Network request failed')
    })
    await ensureApexDomainInOrganization('team1', 'app.example.com')
    expect(created).toEqual(['example.com'])
  })
})
