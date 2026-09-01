import { beforeEach, describe, expect, mock, test } from 'bun:test'

type Prefs = Record<string, unknown>

const state = {
  teams: [] as Array<{ $id: string; name: string }>,
  projects: 0,
  prefs: {} as Prefs,
  listProjectsError: null as Error | null,
  cached: null as unknown,
}

const createOrganization = mock(async ({ name }: { name: string }) => {
  const team = { $id: `team-${state.teams.length + 1}`, name }
  state.teams.push(team)
  return team
})
const createConsoleProject = mock(async () => {
  state.projects += 1
  return { $id: `project-${state.projects}` }
})
const listConsoleProjects = mock(async () => {
  if (state.listProjectsError) throw state.listProjectsError
  return { projects: [], total: state.projects }
})
const updateAccountPrefs = mock(async (prefs: Prefs) => {
  state.prefs = prefs
  return { $id: 'user-1', prefs }
})
const setConsoleAccountCache = mock((account: unknown) => {
  state.cached = account
})

mock.module('@/lib/react-query/hooks/organizations', () => ({
  createOrganization,
  fetchOrganizationById: async (id: string) =>
    state.teams.find((team) => team.$id === id) ?? null,
  fetchOrganizations: async () => ({
    teams: [...state.teams],
    total: state.teams.length,
  }),
  organizationQueryOptions: (id: string) => ({ queryKey: ['org', id] }),
  organizationsQueryOptions: () => ({ queryKey: ['orgs'] }),
}))
mock.module('@/lib/react-query/hooks/auth', () => ({
  fetchConsoleAccount: async () => ({ $id: 'user-1', prefs: state.prefs }),
  updateAccountPrefs,
}))
mock.module('@/lib/appwrite/console-projects', () => ({
  createConsoleProject,
  listConsoleProjects,
}))
mock.module('@/lib/console-account-cache', () => ({ setConsoleAccountCache }))
mock.module('@/lib/console-impersonation', () => ({
  getConsoleAccountQueryRevision: () => 0,
}))

const { ensurePersonalOrgAndFirstProject } = await import(
  '@/lib/ensure-personal-org'
)

describe('ensurePersonalOrgAndFirstProject', () => {
  beforeEach(() => {
    state.teams = []
    state.projects = 0
    state.prefs = {}
    state.listProjectsError = null
    state.cached = null
    createOrganization.mockClear()
    createConsoleProject.mockClear()
    listConsoleProjects.mockClear()
    updateAccountPrefs.mockClear()
    setConsoleAccountCache.mockClear()
  })

  test('concurrent calls provision one organization and one project', async () => {
    const [a, b, c] = await Promise.all([
      ensurePersonalOrgAndFirstProject(),
      ensurePersonalOrgAndFirstProject(),
      ensurePersonalOrgAndFirstProject(),
    ])

    expect(a).toBe('team-1')
    expect(b).toBe('team-1')
    expect(c).toBe('team-1')
    expect(createOrganization).toHaveBeenCalledTimes(1)
    expect(createConsoleProject).toHaveBeenCalledTimes(1)
  })

  test('writes the organization preference back to the account cache', async () => {
    await ensurePersonalOrgAndFirstProject()

    expect(state.prefs.organization).toBe('team-1')
    expect(setConsoleAccountCache).toHaveBeenCalledTimes(1)
    expect((state.cached as { prefs: Prefs }).prefs.organization).toBe('team-1')
  })

  test('a second run after provisioning creates nothing new', async () => {
    await ensurePersonalOrgAndFirstProject()
    await ensurePersonalOrgAndFirstProject()

    expect(createOrganization).toHaveBeenCalledTimes(1)
    expect(createConsoleProject).toHaveBeenCalledTimes(1)
  })

  test('a failed project listing aborts instead of creating a duplicate', async () => {
    state.teams = [{ $id: 'team-1', name: 'Personal Projects' }]
    state.prefs = { organization: 'team-1' }
    state.projects = 1
    state.listProjectsError = new Error('429 rate limited')

    await expect(ensurePersonalOrgAndFirstProject()).rejects.toThrow(
      '429 rate limited',
    )
    expect(createConsoleProject).not.toHaveBeenCalled()
  })
})
