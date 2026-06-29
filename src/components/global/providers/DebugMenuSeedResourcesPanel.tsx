import { useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import {
  Database,
  FolderKanban,
  Globe,
  HardDrive,
  Loader2,
  Users,
} from 'lucide-react'
import { toast } from 'sonner'
import { ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { createConsoleProject } from '@/lib/appwrite/console-projects'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useProject } from '@/lib/react-query/hooks'
import { createProjectDatabase } from '@/lib/react-query/hooks/databases'
import { Dependencies } from '@/lib/react-query/hooks/dependencies'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'

type ResourceKind =
  | 'projects'
  | 'memberships'
  | 'databases'
  | 'buckets'
  | 'domains'

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
const RESOURCE_LABELS: Record<
  ResourceKind,
  { singular: string; plural: string }
> = {
  projects: { singular: 'project', plural: 'projects' },
  memberships: { singular: 'membership', plural: 'memberships' },
  databases: { singular: 'database', plural: 'databases' },
  buckets: { singular: 'bucket', plural: 'buckets' },
  domains: { singular: 'domain', plural: 'domains' },
}

function buildSeedLabel(prefix: string) {
  const normalized = prefix
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9-]/g, '-')
  return normalized.replace(/-+/g, '-').replace(/^-|-$/g, '') || 'debug'
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
  }

  const routeOrgId = typeof params.orgId === 'string' ? params.orgId : undefined
  const projectId =
    typeof params.projectId === 'string' ? params.projectId : undefined
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
    if (projectId && organizationId) {
      return `Project ${projectId}, organization ${organizationId}`
    }
    if (projectId) return `Project ${projectId}`
    if (organizationId) return `Organization ${organizationId}`
    return 'Open a project or organization route to seed resources.'
  }, [organizationId, projectId])

  const runSeed = async (
    kind: ResourceKind,
    createOne: (index: number, seed: string) => Promise<unknown>,
    invalidate: () => Promise<void>,
  ) => {
    const total = safeAmount
    const seed = `${buildSeedLabel(prefix)}-${Date.now().toString(36)}`
    setBusyKind(kind)
    setProgress({ kind, created: 0, total, failed: 0 })
    const label = RESOURCE_LABELS[kind]

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
          `Created ${created} ${created === 1 ? label.singular : label.plural}`,
        )
      }
      if (failed > 0) {
        toast.error(
          `Failed to create ${failed} ${
            failed === 1 ? label.singular : label.plural
          }: ${getFailureMessage(results)}`,
        )
      }
    } catch (error) {
      toast.error(getErrorMessage(error, `Failed to create ${label.plural}`))
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

  const cards: SeedCard[] = [
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
      kind: 'databases',
      title: 'Empty DBs',
      description: 'Create empty TablesDB databases in the current project.',
      icon: <Database className="h-3.5 w-3.5" />,
      disabled: !projectId,
      disabledReason: 'Open a project route first.',
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
    databases: seedDatabases,
    buckets: seedBuckets,
    domains: seedDomains,
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
