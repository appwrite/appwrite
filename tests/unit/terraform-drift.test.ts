import { afterAll, beforeEach, describe, expect, mock, test } from 'bun:test'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import { renderToString } from 'react-dom/server'
import type { Models } from '@appwrite.io/console'
import prodEvents from './fixtures/terraform-activity-prod.json'
import stagingEvents from './fixtures/terraform-activity-staging.json'

/** Activity log the mocked API serves, newest first. */
let log: Models.ActivityEvent[] = []
let listEvents: () => Promise<{
  events: Models.ActivityEvent[]
}> = async () => ({
  events: log,
})

mock.module('@/lib/appwrite/sdk', () => ({
  sdk: {
    forProject: () => ({
      activities: { listEvents: () => listEvents() },
    }),
  },
}))

const {
  lookupTerraformResource,
  prefetchTerraformProject,
  recordTerraformDrift,
  terraformProjectQueryOptions,
} = await import('@/lib/react-query/hooks/terraform')
const { TerraformResourceAlert } = await import(
  '@/components/global/shared/TerraformResourceAlert'
)
const {
  guardTerraformChanges,
  setTerraformChangeConfirmer,
  TerraformChangeCancelledError,
} = await import('@/lib/terraform/guard')

const STAGING = stagingEvents as Models.ActivityEvent[]
const PROD = prodEvents as Models.ActivityEvent[]
const RESOURCE = 'function/api'

const adminUpdate = STAGING.find((event) => event.actorType === 'admin')!
const terraformUpdate = STAGING.find(
  (event) => event.event === 'function.update' && event.sdk === 'terraform',
)!
const cliDeployment = PROD.find((event) =>
  event.userAgent.startsWith('AppwriteCLI/'),
)!

/** Staging as it was right after the 11:41:20 apply: `function/api` in sync. */
const inSync = STAGING.filter(
  (event) => event.time <= '2026-09-28T11:41:20.000+00:00',
)

function logged(
  event: Models.ActivityEvent,
  time: string,
): Models.ActivityEvent {
  return {
    ...event,
    $id: `${event.$id}-${time}`,
    resource: RESOURCE,
    resourceType: 'function',
    time,
  }
}

let queryClient: QueryClient
let projectId: string

async function refetch() {
  const project = await queryClient.fetchQuery({
    ...terraformProjectQueryOptions(projectId),
    staleTime: 0,
  })
  return project.resources[RESOURCE]
}

function changeInConsole(browserTime: string) {
  recordTerraformDrift(queryClient, projectId, RESOURCE, {
    time: browserTime,
    event: 'functions.update',
    actorName: 'Console admin',
    actorType: 'admin',
    userAgent: '',
  })
}

beforeEach(() => {
  // Same defaults as the app: unwatched queries are dropped at once.
  queryClient = new QueryClient({ defaultOptions: { queries: { gcTime: 0 } } })
  projectId = `project-${crypto.randomUUID()}`
  log = [...inSync]
  listEvents = async () => ({ events: log })
})

describe('console change on a Terraform-managed resource', () => {
  test('stays flagged across refetches until the activity log records it', async () => {
    expect((await refetch()).drift).toBeNull()
    changeInConsole(new Date().toISOString())

    expect((await refetch()).drift?.event).toBe('functions.update')

    log = [logged(adminUpdate, '2026-09-28T12:02:38.000+00:00'), ...log]
    expect((await refetch()).drift?.event).toBe('function.update')
  })

  test('is not cleared by an unrelated write from another client', async () => {
    await refetch()
    changeInConsole(new Date().toISOString())

    log = [logged(cliDeployment, '2026-09-28T12:02:38.000+00:00'), ...log]
    expect((await refetch()).drift?.event).toBe('functions.update')
  })

  test('adding a variable keeps the function in sync', async () => {
    log = [
      {
        ...logged(adminUpdate, '2026-09-28T12:02:38.000+00:00'),
        event: 'variable.create',
      },
      ...log,
    ]
    expect((await refetch()).drift).toBeNull()
  })

  test('clears after a later apply even when the browser clock runs ahead', async () => {
    await refetch()
    changeInConsole(new Date(Date.now() + 5 * 60 * 1000).toISOString())

    log = [
      logged(terraformUpdate, '2026-09-28T12:03:00.000+00:00'),
      logged(adminUpdate, '2026-09-28T12:02:38.000+00:00'),
      ...log,
    ]
    expect((await refetch()).drift).toBeNull()
  })
})

/** What a resource page renders on its first paint after the project loader ran. */
function firstPaint(): string {
  return renderToString(
    createElement(
      QueryClientProvider,
      { client: queryClient },
      createElement(TerraformResourceAlert, { projectId, resource: RESOURCE }),
    ),
  )
}

describe('cold visit to a managed resource', () => {
  test('shows the banner on first paint when the activity store answers', async () => {
    await prefetchTerraformProject(queryClient, projectId)
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(firstPaint()).toContain('Managed by Terraform')
  })

  test('renders without the banner when the activity store hangs', async () => {
    listEvents = () => new Promise(() => {})
    const started = Date.now()
    await prefetchTerraformProject(queryClient, projectId, 50)
    expect(Date.now() - started).toBeLessThan(1000)
    expect(firstPaint()).not.toContain('Terraform')
  })
})

describe('activity store outage', () => {
  test('a hanging lookup does not hold up a console write', async () => {
    listEvents = () => new Promise(() => {})
    const started = Date.now()
    const resource = await lookupTerraformResource(
      queryClient,
      projectId,
      RESOURCE,
      50,
    )
    expect(resource).toBeNull()
    expect(Date.now() - started).toBeLessThan(1000)
  })

  test('a failing lookup falls back to the last known state', async () => {
    await refetch()
    let calls = 0
    listEvents = async () => {
      calls += 1
      throw new Error('ClickHouse unavailable')
    }
    await queryClient.invalidateQueries({
      queryKey: terraformProjectQueryOptions(projectId).queryKey,
      refetchType: 'none',
    })
    const resource = await lookupTerraformResource(
      queryClient,
      projectId,
      RESOURCE,
    )
    expect(calls).toBe(1)
    expect(resource?.resource).toBe(RESOURCE)
  })
})

describe('console writes to a Terraform-managed function', () => {
  const client = {
    config: { project: 'terraform-demo', endpoint: 'https://example.test/v1' },
    setEndpoint() {
      return this
    },
    setProject() {
      return this
    },
  }
  let sent: string[] = []
  const functions = guardTerraformChanges(
    {
      functions: {
        createVariable: async () => {
          sent.push('createVariable')
          return 'created'
        },
        updateVariable: async () => {
          sent.push('updateVariable')
          return 'updated'
        },
      },
    },
    client as never,
  ).functions as {
    createVariable: (params: object) => Promise<string>
    updateVariable: (params: object) => Promise<string>
  }

  let asked: string[] = []
  beforeEach(() => {
    asked = []
    sent = []
    setTerraformChangeConfirmer({
      confirm: (request) => {
        asked.push(request.resource)
        return false
      },
      changed: () => {},
    })
  })

  test('adding a variable goes through without asking', async () => {
    await expect(
      functions.createVariable({ functionId: 'api', key: 'EXTRA' }),
    ).resolves.toBe('created')
    expect(asked).toEqual([])
    expect(sent).toEqual(['createVariable'])
  })

  test('changing an existing variable asks first, and cancelling sends nothing', async () => {
    await expect(
      functions.updateVariable({ functionId: 'api', variableId: 'x' }),
    ).rejects.toBeInstanceOf(TerraformChangeCancelledError)
    expect(asked).toEqual(['function/api'])
    expect(sent).toEqual([])
  })

  afterAll(() => setTerraformChangeConfirmer(null))
})
