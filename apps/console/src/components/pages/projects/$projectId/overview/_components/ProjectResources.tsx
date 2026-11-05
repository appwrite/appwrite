import { useMemo, type ComponentType, type ReactNode } from 'react'
import type { LinkProps } from '@tanstack/react-router'
import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { useTheme } from 'next-themes'
import type { Models } from '@appwrite.io/console'
import {
  Database,
  Folder,
  Globe,
  MessageSquare,
  Users,
  Zap,
} from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { DatabaseTypeIcon } from '../../databases/_components/DatabaseTypeIcon'
import {
  OverviewListCard,
  OverviewListCardSkeleton,
  OverviewListFiller,
  OverviewListFooter,
  OverviewListRow,
  OverviewListRowIcon,
  overviewListBodyHeight,
} from './OverviewListCard'
import { ResourceQuickStart } from './ResourceQuickStart'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { AnalyticsActionId } from '@/lib/analytics-actions'
import { getSiteScreenshotFilePreviewUrl } from '@/lib/appwrite/sdk'
import {
  canCreateBucket,
  canCreateDatabase,
  canCreateFunction,
  canCreateSite,
  canCreateUser,
  canSeeProjectNavItem,
  canShowAuthSecuritySettings,
  canWriteProviders,
  canWriteTopics,
} from '@/lib/console-access-checks'
import {
  dedicatedDatabaseHomeLink,
  productDatabaseListLink,
} from '@/lib/database-routes'
import { buildProductDedicatedCardSource } from '@/lib/databases/database-compute'
import {
  coerceDatabaseType,
  type DatabaseType,
} from '@/lib/databases/database-type'
import { getDatabaseTypeDisplayLabel } from '@/lib/databases/database-type-display'
import { useT } from '@/lib/i18n/translate'
import {
  OVERVIEW_PRIMARY_RESOURCES,
  OVERVIEW_RESOURCE_CARD_LIMIT,
  OVERVIEW_RESOURCE_ITEM_LIMIT,
  OVERVIEW_SECONDARY_RESOURCES,
  pickOverviewResources,
  type OverviewResourceId,
} from '@/lib/overview-resources'
import {
  overviewBucketsQueryOptions,
  overviewDatabasesQueryOptions,
  overviewFunctionsQueryOptions,
  overviewSitesQueryOptions,
  overviewTopicsQueryOptions,
  overviewUsersQueryOptions,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import {
  SITE_SCREENSHOT_THUMB_HEIGHT,
  SITE_SCREENSHOT_THUMB_WIDTH,
  SITE_SCREENSHOTS_BUCKET_ID,
} from '@/lib/sites/screenshot-preview-sizes'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import { getDeploymentStatusBadge } from '@/lib/utils/deployment-status'
import { cn } from '@/lib/utils'

const BODY_HEIGHT = overviewListBodyHeight(OVERVIEW_RESOURCE_ITEM_LIMIT)

const ALL_RESOURCES: OverviewResourceId[] = [
  ...OVERVIEW_PRIMARY_RESOURCES,
  ...OVERVIEW_SECONDARY_RESOURCES,
]

type ResourceConfig = {
  /** Sidebar item id, for `canSeeProjectNavItem`. */
  navItem: string
  icon: ComponentType<{ className?: string }>
  title: string
  viewAll: string
  create: string
  /** Shown in the create filler when it spans two rows. */
  fillerHint: string
  loadError: string
  analytics: AnalyticsActionId
  /** First step of an empty card when it is not `create`. */
  start?: { label: string; analytics: AnalyticsActionId }
}

// English source copy; translated at render.
const RESOURCES: Record<OverviewResourceId, ResourceConfig> = {
  databases: {
    navItem: 'databases',
    icon: Database,
    title: 'Databases',
    viewAll: 'View all databases',
    create: 'Create database',
    fillerHint: 'Keep data apart by environment, customer, or workload.',
    loadError: 'Failed to load databases',
    analytics: 'create-database',
  },
  sites: {
    navItem: 'sites',
    icon: Globe,
    title: 'Sites',
    viewAll: 'View all sites',
    create: 'Create site',
    fillerHint: 'Deploy another app, a landing page, or your docs.',
    loadError: 'Failed to load sites',
    analytics: 'create-site',
  },
  functions: {
    navItem: 'functions',
    icon: Zap,
    title: 'Functions',
    viewAll: 'View all functions',
    create: 'Create function',
    fillerHint: 'Add a webhook, a scheduled job, or an event handler.',
    loadError: 'Failed to load functions',
    analytics: 'create-function',
  },
  storage: {
    navItem: 'storage',
    icon: Folder,
    title: 'Storage',
    viewAll: 'View all buckets',
    create: 'Create bucket',
    fillerHint: 'Group files with their own permissions and limits.',
    loadError: 'Failed to load buckets',
    analytics: 'create-bucket',
  },
  auth: {
    navItem: 'auth',
    icon: Users,
    title: 'Auth',
    viewAll: 'View all users',
    create: 'Create user',
    fillerHint: 'Add a user by hand, or let people sign up from your app.',
    loadError: 'Failed to load users',
    analytics: 'create-user',
  },
  messaging: {
    navItem: 'messaging',
    icon: MessageSquare,
    title: 'Messaging',
    viewAll: 'View all topics',
    create: 'Create topic',
    fillerHint: 'Group subscribers to message them all at once.',
    loadError: 'Failed to load topics',
    analytics: 'create-topic',
    // Nothing reaches a topic until a provider can deliver it.
    start: { label: 'Add provider', analytics: 'create-provider' },
  },
}

type ResourceItem = {
  id: string
  title: string
  icon: ReactNode
  meta?: ReactNode
  date?: string
  link: LinkProps
}

type ResourceState = {
  total: number | null | undefined
  items: ResourceItem[]
  settled: boolean
  refetch: () => void
}

/** Total for slot picking: `null` when the list failed, `undefined` while it loads. */
function resourceState<T>(
  query: UseQueryResult<T>,
  read: (data: T) => { total: number; items: ResourceItem[] },
  refetch: () => void,
): ResourceState {
  if (query.isError) {
    return { total: null, items: [], settled: true, refetch }
  }
  if (!query.data) {
    return { total: undefined, items: [], settled: false, refetch }
  }
  return { ...read(query.data), settled: true, refetch }
}

/**
 * "Your resources" row on the project dashboard: one card per main service,
 * listing its latest items, or a get-started card when it has none.
 */
export function ProjectResources({
  projectId,
  className,
}: {
  projectId: string
  className?: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access, isLoading: scopesLoading } = useOrganizationScopes(
    project?.teamId,
  )
  const { resolvedTheme } = useTheme()
  const isDark = resolvedTheme !== 'light'
  const scopesReady = !features.orgRoles || (!!project && !scopesLoading)

  const visible = useMemo(
    () =>
      ALL_RESOURCES.filter((id) =>
        canSeeProjectNavItem(access, features, RESOURCES[id].navItem),
      ),
    [access, features],
  )
  const enabled = (id: OverviewResourceId) =>
    scopesReady && visible.includes(id)

  const canCreate: Record<OverviewResourceId, boolean> = {
    databases: canCreateDatabase(access, features),
    sites: canCreateSite(access, features),
    functions: canCreateFunction(access, features),
    storage: canCreateBucket(access, features),
    auth: canCreateUser(access, features),
    messaging: canWriteTopics(access, features),
  }
  // An empty card's quick starts go one step further than `create` for Auth
  // (sign-in settings) and Messaging (providers).
  const canStart: Record<OverviewResourceId, boolean> = {
    ...canCreate,
    auth: canShowAuthSecuritySettings(access, features),
    messaging: canWriteProviders(access, features),
  }

  const databasesQuery = useQuery({
    ...overviewDatabasesQueryOptions(projectId),
    enabled: enabled('databases'),
  })
  const sitesQuery = useQuery({
    ...overviewSitesQueryOptions(projectId),
    enabled: enabled('sites'),
  })
  const functionsQuery = useQuery({
    ...overviewFunctionsQueryOptions(projectId),
    enabled: enabled('functions'),
  })

  const states: Partial<Record<OverviewResourceId, ResourceState>> = {
    databases: resourceState(
      databasesQuery,
      (data) => ({
        total: data.total,
        items: data.databases.map((db) => databaseItem(projectId, db)),
      }),
      () => void databasesQuery.refetch(),
    ),
    sites: resourceState(
      sitesQuery,
      (data) => ({
        total: data.total,
        items: data.sites.map((site) => siteItem(projectId, site, isDark)),
      }),
      () => void sitesQuery.refetch(),
    ),
    functions: resourceState(
      functionsQuery,
      (data) => ({
        total: data.total,
        items: data.functions.map((fn) => functionItem(projectId, fn)),
      }),
      () => void functionsQuery.refetch(),
    ),
  }

  const bucketsQuery = useQuery({
    ...overviewBucketsQueryOptions(projectId),
    enabled: enabled('storage'),
  })
  const usersQuery = useQuery({
    ...overviewUsersQueryOptions(projectId),
    enabled: enabled('auth'),
  })
  const topicsQuery = useQuery({
    ...overviewTopicsQueryOptions(projectId),
    enabled: enabled('messaging'),
  })

  states.storage = resourceState(
    bucketsQuery,
    (data) => ({
      total: data.total,
      items: data.buckets.map((bucket) => bucketItem(projectId, bucket)),
    }),
    () => void bucketsQuery.refetch(),
  )
  states.auth = resourceState(
    usersQuery,
    (data) => ({
      total: data.total,
      items: data.users.map((user) => userItem(projectId, user, t)),
    }),
    () => void usersQuery.refetch(),
  )
  states.messaging = resourceState(
    topicsQuery,
    (data) => ({
      total: data.total,
      items: data.topics.map((topic) => topicItem(projectId, topic, t)),
    }),
    () => void topicsQuery.refetch(),
  )

  const isLoading =
    !scopesReady ||
    !ALL_RESOURCES.every((id) => !visible.includes(id) || states[id]?.settled)

  const slots = isLoading
    ? []
    : pickOverviewResources(
        // A list that was never loaded counts as empty; a failed one stays null.
        Object.fromEntries(
          ALL_RESOURCES.map((id) => [
            id,
            states[id]?.total === undefined ? 0 : states[id]!.total,
          ]),
        ) as Record<OverviewResourceId, number | null>,
        visible,
      )

  if (!isLoading && slots.length === 0) return null

  const cards = isLoading
    ? Math.min(OVERVIEW_RESOURCE_CARD_LIMIT, Math.max(visible.length, 1))
    : slots.length

  return (
    <section aria-labelledby="overview-resources-title" className={className}>
      <h2
        id="overview-resources-title"
        className="mb-4 text-[15px] font-semibold text-foreground"
      >
        {t('Your resources')}
      </h2>
      <div
        className={cn('grid min-w-0 grid-cols-1 gap-3 [&>*]:min-w-0', {
          '@[640px]:grid-cols-2': cards === 2 || cards === 4 || cards > 4,
          '@[880px]:grid-cols-3': cards === 3,
          '@[960px]:grid-cols-3': cards > 4,
        })}
      >
        {isLoading
          ? Array.from({ length: cards }, (_, index) => (
              <OverviewListCardSkeleton key={index} bodyHeight={BODY_HEIGHT} />
            ))
          : slots.map((id) => (
              <ResourceCard
                key={id}
                id={id}
                projectId={projectId}
                state={states[id]!}
                canCreate={canCreate[id]}
                canStart={canStart[id]}
              />
            ))}
      </div>
    </section>
  )
}

function ResourceCard({
  id,
  projectId,
  state,
  canCreate,
  canStart,
}: {
  id: OverviewResourceId
  projectId: string
  state: ResourceState
  canCreate: boolean
  canStart: boolean
}) {
  const t = useT()
  const config = RESOURCES[id]
  const createLink = resourceCreateLink(id, projectId)
  const noPermission = t("You don't have permission to perform this action.")

  if (state.total === 0) {
    return (
      <OverviewListCard
        icon={config.icon}
        title={t(config.title)}
        bodyHeight={BODY_HEIGHT}
        footer={
          config.start ? (
            <OverviewListFooter
              variant="action"
              label={t(config.start.label)}
              link={resourceStartLink(id, projectId) ?? createLink}
              disabled={!canStart}
              disabledTooltip={noPermission}
              analytics={config.start.analytics}
            />
          ) : (
            <OverviewListFooter
              variant="action"
              label={t(config.create)}
              link={createLink}
              disabled={!canCreate}
              disabledTooltip={noPermission}
              analytics={config.analytics}
            />
          )
        }
      >
        <ResourceQuickStart id={id} projectId={projectId} canStart={canStart} />
      </OverviewListCard>
    )
  }

  const items = state.items.slice(0, OVERVIEW_RESOURCE_ITEM_LIMIT)
  const fillerRows = OVERVIEW_RESOURCE_ITEM_LIMIT - items.length

  return (
    <OverviewListCard
      icon={config.icon}
      title={t(config.title)}
      count={state.total ?? undefined}
      bodyHeight={BODY_HEIGHT}
      action={{
        label: t(config.create),
        link: createLink,
        disabled: !canCreate,
        disabledTooltip: noPermission,
        analytics: config.analytics,
      }}
      footer={
        <OverviewListFooter
          label={t(config.viewAll)}
          link={resourceListLink(id, projectId)}
          analytics="overview-resource-view-all"
        />
      }
    >
      {state.total === null ? (
        <ResourceLoadError
          message={t(config.loadError)}
          onRetry={state.refetch}
        />
      ) : (
        <>
          {items.map((item) => (
            <OverviewListRow
              key={item.id}
              icon={item.icon}
              title={item.title}
              meta={item.meta}
              trailing={
                item.date ? (
                  <DateTooltip
                    date={item.date}
                    className="text-[12px] text-muted-foreground"
                  />
                ) : null
              }
              link={item.link}
              analytics="overview-resource-open"
            />
          ))}
          {fillerRows > 0 ? (
            <OverviewListFiller
              label={t(config.create)}
              description={fillerRows > 1 ? t(config.fillerHint) : undefined}
              link={createLink}
              disabled={!canCreate}
              analytics={config.analytics}
            />
          ) : null}
        </>
      )}
    </OverviewListCard>
  )
}

function ResourceLoadError({
  message,
  onRetry,
}: {
  message: string
  onRetry: () => void
}) {
  const t = useT()
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-2 px-4 text-center">
      <p className="text-[12px] text-muted-foreground">{message}</p>
      <button
        type="button"
        onClick={onRetry}
        className="text-[12px] font-medium text-foreground underline-offset-4 hover:underline"
      >
        {t('Retry')}
      </button>
    </div>
  )
}

/** Where an empty card's `start` action goes. */
function resourceStartLink(
  id: OverviewResourceId,
  projectId: string,
): LinkProps | null {
  if (id === 'messaging') {
    return {
      to: '/projects/$projectId/messaging/providers/create',
      params: { projectId },
    }
  }
  return null
}

function resourceListLink(
  id: OverviewResourceId,
  projectId: string,
): LinkProps {
  const params = { projectId }
  switch (id) {
    case 'databases':
      return { to: '/projects/$projectId/databases', params }
    case 'sites':
      return { to: '/projects/$projectId/sites', params }
    case 'functions':
      return { to: '/projects/$projectId/functions', params }
    case 'storage':
      return { to: '/projects/$projectId/storage', params }
    case 'auth':
      return { to: '/projects/$projectId/auth', params }
    case 'messaging':
      return { to: '/projects/$projectId/messaging/topics', params }
  }
}

/** Same targets as the header create menu. */
function resourceCreateLink(
  id: OverviewResourceId,
  projectId: string,
): LinkProps {
  const params = { projectId }
  switch (id) {
    case 'databases':
      return {
        to: '/projects/$projectId/databases',
        params,
        search: { create: 'database' },
      }
    case 'sites':
      return { to: '/projects/$projectId/sites/create', params }
    case 'functions':
      return { to: '/projects/$projectId/functions/create', params }
    case 'storage':
      return {
        to: '/projects/$projectId/storage',
        params,
        search: { create: 'bucket' },
      }
    case 'auth':
      return {
        to: '/projects/$projectId/auth',
        params,
        search: { create: 'user' },
      }
    case 'messaging':
      return {
        to: '/projects/$projectId/messaging',
        params,
        search: { create: 'topic' },
      }
  }
}

function databaseItem(projectId: string, db: Models.Database): ResourceItem {
  const apiType = db.type as string | undefined
  const raw = db as Models.Database & {
    status?: string | null
    replicas?: number | null
    specification?: string | null
  }
  const dedicated = buildProductDedicatedCardSource({
    $id: db.$id,
    name: db.name,
    databaseType: apiType,
    status: raw.status,
    replicas: raw.replicas,
    specification: raw.specification,
  })
  const link =
    (dedicated ? dedicatedDatabaseHomeLink(projectId, dedicated) : null) ??
    productDatabaseListLink(
      projectId,
      db.$id,
      coerceDatabaseType(apiType) as DatabaseType,
    )
  return {
    id: db.$id,
    title: db.name,
    icon: (
      <OverviewListRowIcon>
        <DatabaseTypeIcon apiType={apiType} />
      </OverviewListRowIcon>
    ),
    meta: (
      <span className="truncate">{getDatabaseTypeDisplayLabel(apiType)}</span>
    ),
    date: db.$createdAt,
    link: link as LinkProps,
  }
}

function siteItem(
  projectId: string,
  site: Models.Site,
  isDark: boolean,
): ResourceItem {
  const screenshotId = isDark
    ? site.deploymentScreenshotDark
    : site.deploymentScreenshotLight
  const screenshotUrl = screenshotId
    ? getSiteScreenshotFilePreviewUrl(projectId, {
        bucketId: SITE_SCREENSHOTS_BUCKET_ID,
        fileId: screenshotId,
        width: SITE_SCREENSHOT_THUMB_WIDTH,
        height: SITE_SCREENSHOT_THUMB_HEIGHT,
      })
    : null
  return {
    id: site.$id,
    title: site.name,
    icon: (
      <OverviewListRowIcon className="w-12">
        {screenshotUrl ? (
          <img
            src={screenshotUrl}
            alt=""
            loading="lazy"
            className="h-full w-full object-cover object-top"
          />
        ) : (
          <FrameworkIcon framework={site.framework} size="sm" />
        )}
      </OverviewListRowIcon>
    ),
    meta: <DeploymentStatus resource={site} />,
    date:
      site.latestDeploymentCreatedAt ||
      site.deploymentCreatedAt ||
      site.$updatedAt,
    link: {
      to: '/projects/$projectId/sites/$siteId',
      params: { projectId, siteId: site.$id },
    },
  }
}

const RUNTIME_NAMES: Record<string, string> = {
  node: 'Node.js',
  python: 'Python',
  'python-ml': 'Python ML',
  php: 'PHP',
  ruby: 'Ruby',
  go: 'Go',
  deno: 'Deno',
  bun: 'Bun',
  dart: 'Dart',
  flutter: 'Flutter',
  swift: 'Swift',
  kotlin: 'Kotlin',
  java: 'Java',
  dotnet: '.NET',
  cpp: 'C++',
  rust: 'Rust',
}

/** `node-22` → `Node.js 22`; unknown runtimes stay as they are. */
function formatRuntimeName(runtime: string): string {
  const separator = runtime.lastIndexOf('-')
  if (separator <= 0) return RUNTIME_NAMES[runtime] ?? runtime
  const base = runtime.slice(0, separator)
  const version = runtime.slice(separator + 1)
  const name = RUNTIME_NAMES[base]
  return name ? `${name} ${version}` : runtime
}

function functionItem(projectId: string, fn: Models.Function): ResourceItem {
  return {
    id: fn.$id,
    title: fn.name,
    icon: (
      <OverviewListRowIcon>
        <RuntimeIcon runtime={fn.runtime} size="sm" />
      </OverviewListRowIcon>
    ),
    meta: (
      <>
        <DeploymentStatus resource={fn} />
        {fn.runtime ? (
          <>
            <span aria-hidden className="text-muted-foreground/50">
              ·
            </span>
            <span className="truncate">{formatRuntimeName(fn.runtime)}</span>
          </>
        ) : null}
      </>
    ),
    date:
      fn.latestDeploymentCreatedAt || fn.deploymentCreatedAt || fn.$updatedAt,
    link: {
      to: '/projects/$projectId/functions/$functionId',
      params: { projectId, functionId: fn.$id },
    },
  }
}

const STATUS_DOT_CLASSNAME: Record<string, string> = {
  deploymentReady: 'bg-emerald-500',
  deploymentBuilding: 'bg-sky-500 animate-pulse',
  failed: 'bg-red-500',
  pending: 'bg-amber-500',
}

function DeploymentStatus({
  resource,
}: {
  resource: Pick<
    Models.Site,
    'enabled' | 'latestDeploymentStatus' | 'latestDeploymentCreatedAt'
  >
}) {
  const t = useT()
  let label: string
  let dot: string
  if (resource.enabled === false) {
    label = t('Disabled')
    dot = 'bg-muted-foreground/40'
  } else if (!resource.latestDeploymentStatus) {
    label = t('No deployments yet')
    dot = 'border border-muted-foreground/50'
  } else {
    const badge = getDeploymentStatusBadge(
      resource.latestDeploymentStatus,
      resource.latestDeploymentCreatedAt,
    )
    label = t(badge.label)
    dot = STATUS_DOT_CLASSNAME[badge.badgeVariant] ?? 'bg-muted-foreground/40'
  }
  return (
    <span className="flex shrink-0 items-center gap-1.5">
      <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', dot)} />
      {label}
    </span>
  )
}

function bucketItem(projectId: string, bucket: Models.Bucket): ResourceItem {
  return {
    id: bucket.$id,
    title: bucket.name,
    icon: (
      <OverviewListRowIcon>
        <Folder className="h-4 w-4" />
      </OverviewListRowIcon>
    ),
    meta: (
      <span className="truncate tabular-nums">
        {formatDecimalBytes(bucket.totalSize ?? 0)}
      </span>
    ),
    date: bucket.$createdAt,
    link: {
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId, bucketId: bucket.$id },
    },
  }
}

function userItem(
  projectId: string,
  user: Models.User<Models.Preferences>,
  t: (text: string) => string,
): ResourceItem {
  const contact = user.email || user.phone
  return {
    id: user.$id,
    title: user.name || contact || t('Anonymous'),
    icon: <InitialsAvatar name={user.name || user.email} size="md" />,
    meta: contact ? <span className="truncate">{contact}</span> : undefined,
    date: user.$createdAt,
    link: {
      to: '/projects/$projectId/auth/users/$userId',
      params: { projectId, userId: user.$id },
    },
  }
}

function topicItem(
  projectId: string,
  topic: Models.Topic,
  t: (text: string) => string,
): ResourceItem {
  const subscribers =
    (topic.emailTotal ?? 0) + (topic.smsTotal ?? 0) + (topic.pushTotal ?? 0)
  return {
    id: topic.$id,
    title: topic.name,
    icon: (
      <OverviewListRowIcon>
        <MessageSquare className="h-4 w-4" />
      </OverviewListRowIcon>
    ),
    meta: (
      <span className="truncate tabular-nums">
        {subscribers.toLocaleString()}{' '}
        {subscribers === 1 ? t('subscriber') : t('subscribers')}
      </span>
    ),
    date: topic.$createdAt,
    link: {
      to: '/projects/$projectId/messaging/topics/$topicId',
      params: { projectId, topicId: topic.$id },
    },
  }
}
