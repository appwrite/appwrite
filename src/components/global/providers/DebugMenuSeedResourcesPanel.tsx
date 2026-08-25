import { useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import {
  Brain,
  Cpu,
  Database,
  FileText,
  FolderKanban,
  Globe,
  HardDrive,
  Loader2,
  Table2,
  User,
  Users,
  UsersRound,
} from 'lucide-react'
import { toast } from 'sonner'
import { ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { createConsoleProject } from '@/lib/appwrite/console-projects'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useProject } from '@/lib/react-query/hooks'
import {
  createProjectDatabase,
  createProjectTable,
} from '@/lib/react-query/hooks/databases'
import {
  createProjectTeam,
  createProjectUser,
} from '@/lib/react-query/hooks/users'
import { Dependencies } from '@/lib/react-query/hooks/dependencies'
import { isStoragePlaceholderBucketId } from '@/lib/storage-routes'
import {
  isDatabaseRouteKind,
  usesCollectionsPath,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'

type ResourceKind =
  | 'projects'
  | 'memberships'
  | 'users'
  | 'teams'
  | 'databases'
  | 'tables'
  | 'buckets'
  | 'files'
  | 'domains'
  | 'models'
  | 'memories'

type SeedProgress = {
  kind: ResourceKind
  created: number
  total: number
  failed: number
}

type SeedCard = {
  kind: ResourceKind
  title: string
  description: string
  icon: ReactNode
  disabled: boolean
  disabledReason?: string
}

const DEFAULT_AMOUNT = 5
const MAX_AMOUNT = 100
const SEED_USER_PASSWORD = 'Password1!'
const MEMORY_CATEGORIES = ['preference', 'instruction', 'fact'] as const
const SEED_FILE_KINDS = [
  {
    ext: 'txt',
    type: 'text/plain',
    body: (seed: string, index: number) =>
      `Debug seed file ${index}\nCreated by the console debug menu (${seed}).\n`,
  },
  {
    ext: 'json',
    type: 'application/json',
    body: (seed: string, index: number) =>
      `${JSON.stringify({ seed, index, source: 'debug-menu' }, null, 2)}\n`,
  },
  {
    ext: 'csv',
    type: 'text/csv',
    body: (seed: string, index: number) =>
      `id,name\n${index},${seed} file ${index}\n`,
  },
] as const
const MOCK_MODEL_PROVIDERS = [
  { provider: 'openai', model: 'gpt-4o' },
  { provider: 'anthropic', model: 'claude-sonnet-4-5' },
  { provider: 'google', model: 'gemini-2.5-flash' },
  { provider: 'openrouter', model: 'openai/gpt-4o-mini' },
  { provider: 'custom', model: 'mock-model' },
] as const
const RESOURCE_LABELS: Record<
  ResourceKind,
  { singular: string; plural: string }
> = {
  projects: { singular: 'project', plural: 'projects' },
  memberships: { singular: 'membership', plural: 'memberships' },
  users: { singular: 'user', plural: 'users' },
  teams: { singular: 'team', plural: 'teams' },
  databases: { singular: 'database', plural: 'databases' },
  tables: { singular: 'table', plural: 'tables' },
  buckets: { singular: 'bucket', plural: 'buckets' },
  files: { singular: 'file', plural: 'files' },
  domains: { singular: 'domain', plural: 'domains' },
  models: { singular: 'model', plural: 'models' },
  memories: { singular: 'memory', plural: 'memories' },
}

function buildSeedLabel(prefix: string) {
  const normalized = prefix
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9-]/g, '-')
  return normalized.replace(/-+/g, '-').replace(/^-|-$/g, '') || 'debug'
}

function buildSeedFile(index: number, seed: string) {
  const kind = SEED_FILE_KINDS[(index - 1) % SEED_FILE_KINDS.length]!
  return new File([kind.body(seed, index)], `${seed}-file-${index}.${kind.ext}`, {
    type: kind.type,
  })
}

function getFailureMessage(results: PromiseSettledResult<unknown>[]) {
  const firstFailure = results.find(
    (result): result is PromiseRejectedResult => result.status === 'rejected',
  )
  if (!firstFailure) return null
  return getErrorMessage(firstFailure.reason, 'Failed to create resources')
}

export function DebugMenuSeedResourcesPanel() {
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const params = useParams({ strict: false }) as {
    orgId?: string
    projectId?: string
    databaseId?: string
    dbKind?: string
    bucketId?: string
  }

  const routeOrgId = typeof params.orgId === 'string' ? params.orgId : undefined
  const projectId =
    typeof params.projectId === 'string' ? params.projectId : undefined
  const databaseId =
    typeof params.databaseId === 'string' ? params.databaseId : undefined
  const routeBucketId =
    typeof params.bucketId === 'string' ? params.bucketId : undefined
  const bucketId = isStoragePlaceholderBucketId(routeBucketId)
    ? undefined
    : routeBucketId
  const dbKind: DatabaseRouteKind =
    typeof params.dbKind === 'string' && isDatabaseRouteKind(params.dbKind)
      ? params.dbKind
      : 'tablesdb'
  const usesCollections = usesCollectionsPath(dbKind)
  const tableLabels = usesCollections
    ? { singular: 'collection', plural: 'collections' }
    : { singular: 'table', plural: 'tables' }
  const { project } = useProject(projectId)
  const organizationId = routeOrgId || project?.teamId || null
  const [amount, setAmount] = useState(String(DEFAULT_AMOUNT))
  const [prefix, setPrefix] = useState('debug')
  const [busyKind, setBusyKind] = useState<ResourceKind | null>(null)
  const [progress, setProgress] = useState<SeedProgress | null>(null)

  const parsedAmount = Number.parseInt(amount, 10)
  const safeAmount = Number.isFinite(parsedAmount)
    ? Math.min(MAX_AMOUNT, Math.max(1, parsedAmount))
    : DEFAULT_AMOUNT

  const contextLabel = useMemo(() => {
    const parts: string[] = []
    if (projectId) parts.push(`Project ${projectId}`)
    if (organizationId) parts.push(`organization ${organizationId}`)
    if (databaseId) parts.push(`database ${databaseId}`)
    if (bucketId) parts.push(`bucket ${bucketId}`)
    if (parts.length > 0) return parts.join(', ')
    return 'Agent models and memories work anywhere. Open a project or organization route for the rest.'
  }, [bucketId, databaseId, organizationId, projectId])

  const runSeed = async (
    kind: ResourceKind,
    createOne: (index: number, seed: string) => Promise<unknown>,
    invalidate: () => Promise<void>,
    labels: { singular: string; plural: string } = RESOURCE_LABELS[kind],
  ) => {
    const total = safeAmount
    const seed = `${buildSeedLabel(prefix)}-${Date.now().toString(36)}`
    setBusyKind(kind)
    setProgress({ kind, created: 0, total, failed: 0 })

    const results: PromiseSettledResult<unknown>[] = []

    try {
      for (let index = 1; index <= total; index += 1) {
        const result = await createOne(index, seed)
          .then((value) => ({ status: 'fulfilled', value }) as const)
          .catch((reason) => ({ status: 'rejected', reason }) as const)
        results.push(result)
        setProgress({
          kind,
          created: results.filter((item) => item.status === 'fulfilled').length,
          failed: results.filter((item) => item.status === 'rejected').length,
          total,
        })
      }

      await invalidate()

      const created = results.filter(
        (item) => item.status === 'fulfilled',
      ).length
      const failed = total - created
      if (created > 0) {
        toast.success(
          `Created ${created} ${created === 1 ? labels.singular : labels.plural}`,
        )
      }
      if (failed > 0) {
        toast.error(
          `Failed to create ${failed} ${
            failed === 1 ? labels.singular : labels.plural
          }: ${getFailureMessage(results)}`,
        )
      }
    } catch (error) {
      toast.error(getErrorMessage(error, `Failed to create ${labels.plural}`))
    } finally {
      setBusyKind(null)
    }
  }

  const seedMemberships = () => {
    if (!organizationId) return
    const role = features.orgRoles ? 'developer' : 'owner'
    void runSeed(
      'memberships',
      (index, seed) =>
        sdk.forConsole.teams.createMembership({
          teamId: organizationId,
          email: `${seed}+member-${index}@appwrite.io`,
          roles: [role],
          url: `${window.location.origin}/join`,
        }),
      async () => {
        await queryClient.invalidateQueries({
          queryKey: ['memberships', 'organization', organizationId],
        })
      },
    )
  }

  const seedUsers = () => {
    if (!projectId) return
    void runSeed(
      'users',
      (index, seed) =>
        createProjectUser(projectId, {
          email: `${seed}+user-${index}@appwrite.io`,
          name: `${seed} user ${index}`,
          password: SEED_USER_PASSWORD,
        }),
      async () => {
        await queryClient.invalidateQueries({
          queryKey: ['users', 'project', projectId],
        })
      },
    )
  }

  const seedTeams = () => {
    if (!projectId) return
    void runSeed(
      'teams',
      (index, seed) =>
        createProjectTeam(projectId, {
          name: `${seed} team ${index}`,
        }),
      async () => {
        await queryClient.invalidateQueries({
          queryKey: ['teams', 'project', projectId],
        })
      },
    )
  }

  const seedProjects = () => {
    if (!organizationId) return
    void runSeed(
      'projects',
      (index, seed) =>
        createConsoleProject({
          projectId: ID.unique(),
          name: `${seed} project ${index}`,
          teamId: organizationId,
        }),
      async () => {
        await queryClient.invalidateQueries({
          queryKey: ['projects', 'team', organizationId],
        })
        await queryClient.invalidateQueries({
          queryKey: ['organization-projects'],
        })
        await queryClient.invalidateQueries({ queryKey: ['projects'] })
      },
    )
  }

  const seedDatabases = () => {
    if (!projectId) return
    void runSeed(
      'databases',
      (index, seed) =>
        createProjectDatabase(projectId, {
          name: `${seed} database ${index}`,
        }),
      async () => {
        await queryClient.invalidateQueries({
          queryKey: ['databases', 'project', projectId],
        })
      },
    )
  }

  const seedTables = () => {
    if (!projectId || !databaseId) return
    try {
      requireOperationalDatabase(queryClient, projectId, databaseId)
    } catch (error) {
      toast.error(
        getErrorMessage(error, `Failed to create ${tableLabels.plural}`),
      )
      return
    }
    void runSeed(
      'tables',
      (index, seed) =>
        createProjectTable(projectId, databaseId, dbKind, {
          name: `${seed} ${tableLabels.singular} ${index}`,
        }),
      async () => {
        await queryClient.refetchQueries({
          queryKey: ['tables', 'project', projectId, databaseId],
        })
      },
      tableLabels,
    )
  }

  const seedBuckets = () => {
    if (!projectId) return
    void runSeed(
      'buckets',
      (index, seed) =>
        sdk.forProject(projectId).storage.createBucket({
          bucketId: ID.unique(),
          name: `${seed} bucket ${index}`,
        }),
      async () => {
        await queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
      },
    )
  }

  const seedFiles = () => {
    if (!projectId || !bucketId) return
    void runSeed(
      'files',
      (index, seed) =>
        sdk.forProject(projectId).storage.createFile({
          bucketId,
          fileId: ID.unique(),
          file: buildSeedFile(index, seed),
        }),
      async () => {
        await queryClient.invalidateQueries({ queryKey: Dependencies.FILES })
        await queryClient.refetchQueries({
          queryKey: ['files', 'project', projectId, 'bucket', bucketId],
        })
      },
    )
  }

  const seedDomains = () => {
    if (!organizationId) return
    void runSeed(
      'domains',
      (index, seed) =>
        sdk.forConsole.domains.create({
          teamId: organizationId,
          domain: `${seed}-${index}.example.com`,
        }),
      async () => {
        await queryClient.invalidateQueries({
          queryKey: ['domains', 'organization', organizationId],
        })
        await queryClient.invalidateQueries({ queryKey: Dependencies.DOMAINS })
      },
    )
  }

  const seedModels = () => {
    void runSeed(
      'models',
      (index, seed) => {
        const provider =
          MOCK_MODEL_PROVIDERS[(index - 1) % MOCK_MODEL_PROVIDERS.length]!
        return sdk.forConsole.agent.createModel({
          modelId: 'unique()',
          name: `${seed} ${provider.provider} ${index}`,
          provider: provider.provider,
          model: provider.model,
          apiKey: `sk-debug-${seed}-${index}`,
          enabled: true,
          status: 'ready',
        })
      },
      async () => {
        await queryClient.refetchQueries({ queryKey: ['agent', 'models'] })
      },
    )
  }

  const seedMemories = () => {
    void runSeed(
      'memories',
      (index, seed) => {
        const category =
          MEMORY_CATEGORIES[(index - 1) % MEMORY_CATEGORIES.length]!
        return sdk.forConsole.agent.createMemory({
          memoryId: 'unique()',
          scope: 'user',
          key: `${seed}.${category}.${index}`,
          content: `Debug ${category} #${index}: prefer concise answers and reuse this mock memory.`,
          category,
          priority: index,
          status: 'active',
          source: 'user',
        })
      },
      async () => {
        await queryClient.refetchQueries({ queryKey: ['agent', 'memories'] })
      },
    )
  }

  const cards: SeedCard[] = [
    {
      kind: 'models',
      title: 'Agent models',
      description: 'Create mock LLM models with fake API keys for the agent.',
      icon: <Cpu className="h-3.5 w-3.5" />,
      disabled: false,
    },
    {
      kind: 'memories',
      title: 'Agent memories',
      description:
        'Create mock preferences, instructions, and facts for the agent.',
      icon: <Brain className="h-3.5 w-3.5" />,
      disabled: false,
    },
    {
      kind: 'projects',
      title: 'Projects',
      description: 'Create empty projects in the current organization.',
      icon: <FolderKanban className="h-3.5 w-3.5" />,
      disabled: !organizationId,
      disabledReason: 'Open an organization or project route first.',
    },
    {
      kind: 'memberships',
      title: 'Memberships',
      description:
        'Invite mock appwrite.io emails to the current organization.',
      icon: <Users className="h-3.5 w-3.5" />,
      disabled: !organizationId,
      disabledReason: 'Open an organization or project route first.',
    },
    {
      kind: 'users',
      title: 'Users',
      description: `Create Auth users with password ${SEED_USER_PASSWORD} in the current project.`,
      icon: <User className="h-3.5 w-3.5" />,
      disabled: !projectId,
      disabledReason: 'Open a project route first.',
    },
    {
      kind: 'teams',
      title: 'Teams',
      description: 'Create empty Auth teams in the current project.',
      icon: <UsersRound className="h-3.5 w-3.5" />,
      disabled: !projectId,
      disabledReason: 'Open a project route first.',
    },
    {
      kind: 'databases',
      title: 'Empty DBs',
      description: 'Create empty TablesDB databases in the current project.',
      icon: <Database className="h-3.5 w-3.5" />,
      disabled: !projectId,
      disabledReason: 'Open a project route first.',
    },
    {
      kind: 'tables',
      title: usesCollections ? 'Collections' : 'Tables',
      description: usesCollections
        ? 'Create empty collections in the current database.'
        : 'Create empty tables in the current database.',
      icon: <Table2 className="h-3.5 w-3.5" />,
      disabled: !projectId || !databaseId,
      disabledReason: 'Open a database route first.',
    },
    {
      kind: 'buckets',
      title: 'Empty buckets',
      description: 'Create empty storage buckets in the current project.',
      icon: <HardDrive className="h-3.5 w-3.5" />,
      disabled: !projectId,
      disabledReason: 'Open a project route first.',
    },
    {
      kind: 'files',
      title: 'Files',
      description:
        'Upload mock text, JSON, and CSV files into the current bucket.',
      icon: <FileText className="h-3.5 w-3.5" />,
      disabled: !projectId || !bucketId,
      disabledReason: 'Open a bucket route first.',
    },
    {
      kind: 'domains',
      title: 'Mock domains',
      description: 'Create unverified example.com domains on the organization.',
      icon: <Globe className="h-3.5 w-3.5" />,
      disabled: !organizationId,
      disabledReason: 'Open an organization or project route first.',
    },
  ]

  const actions: Record<ResourceKind, () => void> = {
    projects: seedProjects,
    memberships: seedMemberships,
    users: seedUsers,
    teams: seedTeams,
    databases: seedDatabases,
    tables: seedTables,
    buckets: seedBuckets,
    files: seedFiles,
    domains: seedDomains,
    models: seedModels,
    memories: seedMemories,
  }

  return (
    <div className="flex flex-col gap-3 px-1" aria-label="Seed resources">
      <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/90">
        Create test resources directly in the current context. Amount is capped
        at {MAX_AMOUNT} per click.
      </p>

      <div className="rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 p-3">
        <p className="mb-2 text-[10px] uppercase tracking-wider text-[var(--network-globe-edge)]/75">
          Context
        </p>
        <p className="break-all text-[11px] text-foreground/90">
          {contextLabel}
        </p>
      </div>

      <div className="grid grid-cols-[1fr_120px] gap-2">
        <label className="space-y-1">
          <span className="text-[11px] font-medium text-foreground">
            Name prefix
          </span>
          <Input
            value={prefix}
            onChange={(event) => setPrefix(event.target.value)}
            disabled={busyKind !== null}
            className="h-8 border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40 text-[12px] text-foreground"
          />
        </label>
        <label className="space-y-1">
          <span className="text-[11px] font-medium text-foreground">Amount</span>
          <Input
            type="number"
            min={1}
            max={MAX_AMOUNT}
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            disabled={busyKind !== null}
            className="h-8 border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40 text-[12px] text-foreground"
          />
        </label>
      </div>

      <div className="grid gap-2 sm:grid-cols-2">
        {cards.map((card) => {
          const isBusy = busyKind === card.kind
          const disabled = busyKind !== null || card.disabled
          const cardProgress =
            progress?.kind === card.kind
              ? Math.round(
                  ((progress.created + progress.failed) / progress.total) * 100,
                )
              : 0

          return (
            <div
              key={card.kind}
              className="rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 p-3"
            >
              <div className="mb-3 flex items-start gap-2">
                <span className="mt-0.5 text-[var(--network-globe-edge)]">{card.icon}</span>
                <div className="min-w-0 flex-1">
                  <h3 className="text-[13px] font-medium text-foreground">
                    {card.title}
                  </h3>
                  <p className="mt-1 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
                    {card.disabled ? card.disabledReason : card.description}
                  </p>
                </div>
              </div>

              {isBusy && progress ? (
                <div className="mb-3 space-y-1.5">
                  <Progress
                    value={cardProgress}
                    className="h-1.5 bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)]"
                  />
                  <p className="text-[10px] text-[var(--network-globe-edge)]/80">
                    {progress.created} created, {progress.failed} failed of{' '}
                    {progress.total}
                  </p>
                </div>
              ) : null}

              <Button
                type="button"
                size="sm"
                variant="secondary"
                className="h-8 w-full text-[11px]"
                disabled={disabled}
                onClick={actions[card.kind]}
              >
                {isBusy && <Loader2 className="h-3 w-3 animate-spin" />}
                Create {safeAmount}
              </Button>
            </div>
          )
        })}
      </div>
    </div>
  )
}
