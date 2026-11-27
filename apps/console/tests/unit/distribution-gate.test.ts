import { afterEach, beforeEach, describe, expect, spyOn, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { QueryClient } from '@tanstack/react-query'
import { isRedirect } from '@tanstack/react-router'
import { sdk } from '@/lib/appwrite/sdk'
import {
  CONSOLE_PROFILES,
  CONSOLE_PROFILE_FEATURE_LABELS,
} from '@/lib/console-profiles'
import type { OrganizationRolesScopes } from '@/lib/console-roles'
import {
  consoleVariablesQueryOptions,
  isDistributionEnabled,
} from '@/lib/react-query/hooks/console-variables'
import { organizationScopesQueryOptions } from '@/lib/react-query/hooks/organizations'
import { projectQueryOptions } from '@/lib/react-query/hooks/projects'
import { Route as DistributionRoute } from '@/routes/_public/projects.$projectId.distribution'

const PROJECT_ID = 'project'
const TEAM_ID = 'team'
const REGION = 'fra'
const DEBUG_PROFILE_KEY = 'debug:consoleProfile'

const ALL_DISTRIBUTION_SCOPES = [
  'projects.read',
  'distribution.read',
  'distribution.write',
  'distribution.credentials.read',
  'distribution.credentials.write',
  'distribution.submissions.read',
  'distribution.submissions.write',
]

function variables(enabled: unknown): Models.ConsoleVariables {
  return {
    _APP_DISTRIBUTION_ENABLED: enabled,
  } as unknown as Models.ConsoleVariables
}

describe('isDistributionEnabled', () => {
  test('is on only when the server reports a literal true', () => {
    expect(isDistributionEnabled(variables(true))).toBe(true)
    expect(isDistributionEnabled(variables(false))).toBe(false)
    expect(isDistributionEnabled(variables('true'))).toBe(false)
    expect(isDistributionEnabled(variables(1))).toBe(false)
    expect(isDistributionEnabled(variables(undefined))).toBe(false)
    expect(isDistributionEnabled({} as Models.ConsoleVariables)).toBe(false)
    expect(isDistributionEnabled(undefined)).toBe(false)
  })
})

describe('distribution profile flag', () => {
  test('is off in every profile', () => {
    for (const profile of Object.values(CONSOLE_PROFILES)) {
      expect(profile.features.distribution).toBe(false)
    }
  })

  test('is labelled for the debug menu', () => {
    expect(CONSOLE_PROFILE_FEATURE_LABELS.distribution).toBe('Distribution')
  })
})

describe('distribution layout gate', () => {
  const globals = globalThis as { window?: unknown; localStorage?: unknown }
  let storage: Map<string, string>

  beforeEach(() => {
    storage = new Map<string, string>()
    const localStorage = {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => void storage.set(key, value),
      removeItem: (key: string) => void storage.delete(key),
    }
    globals.localStorage = localStorage
    globals.window = {
      localStorage,
      location: new URL('https://cloud.appwrite.io/console'),
      dispatchEvent: () => true,
    }
  })

  afterEach(() => {
    delete globals.window
    delete globals.localStorage
  })

  function enableProfileFlag() {
    storage.set(
      DEBUG_PROFILE_KEY,
      JSON.stringify({ id: 'cloud', features: { distribution: true } }),
    )
  }

  function seed({
    consoleVariables = variables(true),
    scopes = ALL_DISTRIBUTION_SCOPES,
  }: {
    consoleVariables?: Models.ConsoleVariables | null
    scopes?: string[]
  } = {}): QueryClient {
    const queryClient = new QueryClient()
    queryClient.setQueryData(projectQueryOptions(PROJECT_ID).queryKey, {
      $id: PROJECT_ID,
      teamId: TEAM_ID,
      region: REGION,
    } as unknown as Models.Project)
    if (consoleVariables) {
      queryClient.setQueryData(
        consoleVariablesQueryOptions(REGION).queryKey,
        consoleVariables,
      )
    }
    queryClient.setQueryData<OrganizationRolesScopes>(
      organizationScopesQueryOptions(TEAM_ID, PROJECT_ID).queryKey,
      { roles: ['developer'], scopes },
    )
    return queryClient
  }

  async function enter(queryClient: QueryClient): Promise<unknown> {
    const beforeLoad = DistributionRoute.options
      .beforeLoad as unknown as (context: {
      params: { projectId: string }
      context: { queryClient: QueryClient }
    }) => Promise<unknown>
    try {
      await beforeLoad({
        params: { projectId: PROJECT_ID },
        context: { queryClient },
      })
      return null
    } catch (thrown) {
      return thrown
    }
  }

  function expectRedirectToProject(thrown: unknown) {
    expect(isRedirect(thrown)).toBe(true)
    expect(
      (thrown as { options: { to: string; params: unknown } }).options,
    ).toMatchObject({
      to: '/projects/$projectId',
      params: { projectId: PROJECT_ID },
      replace: true,
    })
  }

  test('redirects to the project while the profile flag is off', async () => {
    expectRedirectToProject(await enter(seed()))
  })

  test('redirects when the server reports distribution disabled', async () => {
    enableProfileFlag()
    expectRedirectToProject(
      await enter(seed({ consoleVariables: variables(false) })),
    )
  })

  test('redirects when the server predates the variable', async () => {
    enableProfileFlag()
    expectRedirectToProject(
      await enter(seed({ consoleVariables: {} as Models.ConsoleVariables })),
    )
  })

  test('redirects when the console variables request fails', async () => {
    enableProfileFlag()
    const forConsoleIn = spyOn(sdk, 'forConsoleIn').mockReturnValue({
      console: {
        variables: () => Promise.reject(new Error('Network request failed')),
      },
    } as unknown as ReturnType<typeof sdk.forConsoleIn>)
    try {
      expectRedirectToProject(await enter(seed({ consoleVariables: null })))
      expect(forConsoleIn).toHaveBeenCalledWith(REGION)
    } finally {
      forConsoleIn.mockRestore()
    }
  })

  test('redirects when the role lacks distribution.read', async () => {
    enableProfileFlag()
    expectRedirectToProject(
      await enter(seed({ scopes: ['projects.read', 'sites.read'] })),
    )
  })

  test('opens when the flag, the server and the role all allow it', async () => {
    enableProfileFlag()
    expect(await enter(seed())).toBeNull()
  })

  test('opens for a read-only distribution role', async () => {
    enableProfileFlag()
    expect(
      await enter(seed({ scopes: ['projects.read', 'distribution.read'] })),
    ).toBeNull()
  })
})
