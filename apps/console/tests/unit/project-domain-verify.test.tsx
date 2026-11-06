import { afterAll, beforeAll, describe, expect, mock, test } from 'bun:test'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import { renderToString } from 'react-dom/server'
import * as actualSdk from '@/lib/appwrite/sdk'
import * as actualConsoleProfiles from '@/lib/console-profiles'

// Copy the real modules now: the namespace imports are live and point at the mocks once set.
const realSdk = { ...actualSdk }
const realConsoleProfiles = { ...actualConsoleProfiles }

let checkFails = false

describe('useVerifyDomain', () => {
  beforeAll(() => {
    mock.module('@/lib/console-profiles', () => ({
      ...realConsoleProfiles,
      getActiveProfileId: () => 'cloud',
    }))
    mock.module('@/lib/appwrite/sdk', () => ({
      ...realSdk,
      sdk: {
        // The nameserver lookup never answers, as with a slow external NS.
        forConsole: {
          domains: { verifyNameservers: () => new Promise(() => {}) },
        },
        forProject: () => ({
          proxy: {
            updateRuleStatus: async ({ ruleId }: { ruleId: string }) => {
              if (checkFails) throw new Error('DNS verification failed')
              return { $id: ruleId, status: 'verified' }
            },
          },
        }),
      },
    }))
  })

  afterAll(() => {
    mock.module('@/lib/appwrite/sdk', () => realSdk)
    mock.module('@/lib/console-profiles', () => realConsoleProfiles)
  })

  async function mountVerify(client = new QueryClient()) {
    const { useVerifyDomain } = await import(
      '@/lib/react-query/hooks/project-domains'
    )
    let verify:
      | ((input: {
          ruleId: string
          organizationDomainId?: string
        }) => Promise<{ status: string }>)
      | undefined
    function Verify() {
      verify = useVerifyDomain('project1', 'fra').mutateAsync
      return null
    }
    // Rendered on the server: the hook needs no DOM, and React DOM is not
    // loaded without one for the other test files in the run.
    renderToString(
      createElement(QueryClientProvider, { client }, createElement(Verify)),
    )
    return verify!
  }

  test('checks the rule without waiting on the nameserver check', async () => {
    const verify = await mountVerify()

    const rule = await verify({ ruleId: 'rule1', organizationDomainId: 'd1' })

    expect(rule.status).toBe('verified')
  }, 2000)

  test('refreshes the domains list when the check fails, since it saves logs', async () => {
    const client = new QueryClient()
    let listFetches = 0
    await client.prefetchQuery({
      queryKey: ['proxy-rules', 'project', 'project1'],
      queryFn: async () => ++listFetches,
    })
    const verify = await mountVerify(client)
    checkFails = true

    try {
      await expect(verify({ ruleId: 'rule1' })).rejects.toThrow()
    } finally {
      checkFails = false
    }

    expect(listFetches).toBe(2)
  })
})
