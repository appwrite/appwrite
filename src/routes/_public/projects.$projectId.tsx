import {
  createFileRoute,
  Outlet,
  redirect,
  isRedirect,
  useLocation,
} from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import { useState, useEffect, useRef, useMemo, useLayoutEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CloudStatusBanner } from '@/components/global/layout/CloudStatusBanner'
import { BlockedProjectCurtain } from '@/components/global/layout/BlockedProjectCurtain'
import { EducationPlanCurtain } from '@/components/global/layout/EducationPlanCurtain'
import { BudgetLimitProjectCurtain } from '@/components/global/layout/BudgetLimitProjectCurtain'
import { PlanUsageLimitProjectCurtain } from '@/components/global/layout/PlanUsageLimitProjectCurtain'
import { PausedProjectCurtain } from '@/components/global/layout/PausedProjectCurtain'
import { hasActiveProjectBlock } from '@/lib/project-blocks'
import { RealtimeProvider } from '@/components/global/providers/RealtimeProvider'
import { BuildNotificationsProvider } from '@/components/global/providers/BuildNotificationsProvider'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { SessionMigrationsProvider } from '@/components/global/providers/SessionMigrationsContext'
import { OrganizationFailedInvoiceHeaderBanner } from '@/components/global/shared/OrganizationFailedInvoiceHeaderBanner'
import { OrganizationInvoiceAuthorizeHeaderBanner } from '@/components/global/shared/OrganizationInvoiceAuthorizeHeaderBanner'
import { OrganizationPlanLimitHeaderBanner } from '@/components/global/shared/OrganizationPlanLimitHeaderBanner'
import {
  fetchProject,
  fetchOrganizationById,
  organizationPlanQueryOptions,
  organizationQueryOptions,
  organizationScopesQueryOptions,
  organizationsQueryOptions,
  prefetchOrganizationInvoiceDataIfAllowed,
  consoleAccountQueryOptions,
  useProject,
  useOrganizationBillingInvoicePresence,
  isOrganizationBillingReadonlyStatus,
  isBudgetLimitReached,
  isPlanUsageLimitReached,
  isProjectLockedByPlanUsage,
  resolveProjectTeamIdFromConsole,
  useOrganizationById,
} from '@/lib/react-query/hooks'
import { apiExplorerSpecQueryOptions } from '@/lib/react-query/hooks/api-explorer'
import {
  getActiveProfileFeatures,
  getActiveProfileId,
} from '@/lib/console-profiles'
import { consoleVariablesQueryOptions } from '@/lib/react-query/hooks/console-variables'
import { ErrorComponent } from '@/components/error/Component'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { PostgresPromoBanner } from '@/components/global/shared/PostgresPromoBanner'
import { reportConsoleAccess } from '@/lib/appwrite/console-access'
import {
  ensureProjectRegion,
  registerProjectRegionFromProject,
} from '@/lib/project-region'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { ProjectCliShellLayout } from '@/components/global/cli-shell/ProjectCliShellLayout'
import {
  isHttpNotFoundError,
  isHttpPaymentRequiredError,
  isHttpProjectAccessError,
  getAppwriteErrorInfo,
} from '@/lib/utils/error-formatting'
import {
  INITIAL_LOADER_SHELL_GATE,
  setInitialLoaderShellGate,
} from '@/lib/initial-loader/shell-gates'
/** Tab segment for routes under `.../tables/:tableId/<tab>` or `.../collections/:id/<tab>` */
const DATABASE_TABLE_VIEW_TABS = new Set([
  'rows',
  'documents',
  'columns',
  'indexes',
  'security',
  'settings',
  'visualizer',
  'monitor',
  'backups',
  'export-import',
  'db-security',
  'db-settings',
  'json',
])

/**
 * Resolves the `<tab>` segment for database table routes.
 * - Current: /projects/:p/databases/:dbKind/:databaseId/tables/:tableId/:tab
 * - Legacy: /projects/:p/databases/:databaseId/tables/:tableId/:tab
 */
function getDatabaseTablesRouteTab(pathParts: string[]): string | undefined {
  if (pathParts[3] !== 'databases') return undefined
  if (pathParts.length >= 8 && pathParts[5] === 'tables') {
    const tab = pathParts[7]
    if (tab && DATABASE_TABLE_VIEW_TABS.has(tab)) return tab
  }
  if (pathParts.length >= 9 && pathParts[6] === 'tables') {
    const tab = pathParts[8]
    if (tab && DATABASE_TABLE_VIEW_TABS.has(tab)) return tab
  }
  if (pathParts.length >= 9 && pathParts[6] === 'collections') {
    const tab = pathParts[8]
    if (tab && DATABASE_TABLE_VIEW_TABS.has(tab)) return tab
  }
  return undefined
}

/** Routes like /databases/:dbKind/:databaseId/visualizer (not under tables/collections). */
const DATABASE_LEVEL_LAYOUT_SEGMENTS = new Set([
  'visualizer',
  'monitor',
  'backups',
  'export-import',
  'db-security',
  'browser',
  'security',
  'settings',
  'connections',
  'connect',
])

function getDatabaseLevelLayoutSegment(
  pathParts: string[],
): string | undefined {
  if (pathParts[3] !== 'databases') return undefined
  if (pathParts.length >= 7 && pathParts[6]) {
    const seg = pathParts[6]
    if (DATABASE_LEVEL_LAYOUT_SEGMENTS.has(seg)) return seg
  }
  return undefined
}

/** Loader return: project data for first paint (avoids layout shift for paused/budget curtains). */
export type ProjectLayoutLoaderData =
  | {
      project: { $id: string; teamId: string; status?: string }
      budgetLimitReached?: boolean
      planUsageLimitReached?: boolean
      projectBlocked?: boolean
      paymentRequiredMessage?: string
      paymentRequiredType?: string
    }
  | undefined

export type ProjectLayoutRouteContext = {
  budgetLimitReached: boolean
  planUsageLimitReached: boolean
  projectBlocked: boolean
  budgetLimitTeamId: string | null
  paymentRequiredMessage: string | null
  paymentRequiredType: string | null
}

const EMPTY_PROJECT_LAYOUT_CONTEXT: ProjectLayoutRouteContext = {
  budgetLimitReached: false,
  planUsageLimitReached: false,
  projectBlocked: false,
  budgetLimitTeamId: null,
  paymentRequiredMessage: null,
  paymentRequiredType: null,
}

function ProjectAccessErrorView({
  error,
  reset,
}: {
  error: Error
  reset: () => void
}) {
  // Route errorComponent mounts without ProjectSelector; release the shell gate
  // so the fullscreen loader does not cover this screen on hard reload.
  useLayoutEffect(() => {
    setInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector, true)
  }, [])

  return (
    <div className="org-layout-container flex h-full flex-col bg-background">
      <div className="sticky top-0 z-[110] flex shrink-0 flex-col bg-background">
        <CloudStatusBanner />
        <ConsoleImpersonationBanner />
      </div>
      <main className="min-h-0 flex-1 overflow-y-auto">
        <ErrorComponent error={error} info={undefined} reset={reset} />
      </main>
    </div>
  )
}

function ProjectRouteErrorComponent({
  error,
  reset,
}: {
  error: unknown
  reset: () => void
}) {
  return <ProjectAccessErrorView error={error instanceof Error ? error : new Error(String(error))} reset={reset} />
}

export const Route = createFileRoute('/_public/projects/$projectId')({
  // Parent ProjectCliShellLayout already provides ConsoleLayout; avoid a nested shell.
  notFoundComponent: NotFoundView,
  errorComponent: ProjectRouteErrorComponent,
  // Region + budget/plan-limit check must run before child loaders (loaders execute in parallel).
  // When the org is blocked, nested project routes are redirected to the project root
  // so heavy service loaders cannot hang the navigation.
  // Project access failures (401/403/404) are thrown so child beforeLoad/loaders never run.
  beforeLoad: async ({
    params,
    context,
    location,
  }): Promise<ProjectLayoutRouteContext> => {
    if (typeof window === 'undefined') {
      return EMPTY_PROJECT_LAYOUT_CONTEXT
    }
    const { projectId } = params
    if (!projectId) {
      return EMPTY_PROJECT_LAYOUT_CONTEXT
    }

    // Region lookup may fail with 402 when budget-locked; ignore and continue.
    await ensureProjectRegion(context.queryClient, projectId).catch(() => {})

    const features = getActiveProfileFeatures()

    const redirectIfNested = () => {
      const pathParts = location.pathname.split('/').filter(Boolean)
      if (pathParts.length > 2) {
        throw redirect({
          to: '/projects/$projectId',
          params: { projectId },
          replace: true,
        })
      }
    }

    // Always resolve the project first. Throwing on access errors stops the
    // rest of the match (child beforeLoad + loaders) so nested pages cannot
    // re-fetch project.get and hang the fullscreen loader.
    let projectData: Awaited<ReturnType<typeof fetchProject>>
    try {
      projectData = await context.queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000,
        retry: false,
      })
      registerProjectRegionFromProject(projectData)

      // Self-hosted usage availability is a backend capability. Resolve the
      // public Console variable before child route guards and navigation render.
      if (getActiveProfileId() === 'self-hosted') {
        await context.queryClient
          .ensureQueryData(consoleVariablesQueryOptions(projectData?.region))
          .catch(() => {})
      }
    } catch (error) {
      if (isRedirect(error)) throw error

      // Budget cap and unpaid invoices both block project-scoped APIs with HTTP 402.
      // Resolve teamId via the console projects API so we can still show the lock curtain.
      if (isHttpPaymentRequiredError(error)) {
        if (features.billing) {
          const teamId = await resolveProjectTeamIdFromConsole(projectId)
          const paymentRequired = getAppwriteErrorInfo(error)
          redirectIfNested()
          return {
            budgetLimitReached: true,
            planUsageLimitReached: false,
            budgetLimitTeamId: teamId,
            paymentRequiredMessage: paymentRequired.message,
            paymentRequiredType: paymentRequired.type,
          }
        }
        console.warn('Failed to resolve budget limit in beforeLoad:', error)
        return EMPTY_PROJECT_LAYOUT_CONTEXT
      }

      // 401/403/404: stop child beforeLoad/loaders so they cannot re-fetch and hang.
      if (isHttpProjectAccessError(error)) {
        throw error
      }

      console.warn('Failed to resolve project in beforeLoad:', error)
      return EMPTY_PROJECT_LAYOUT_CONTEXT
    }

    const projectBlocked = hasActiveProjectBlock(projectData, projectId)
    if (projectBlocked) {
      redirectIfNested()
      return {
        ...EMPTY_PROJECT_LAYOUT_CONTEXT,
        budgetLimitTeamId: projectData?.teamId ?? null,
        projectBlocked: true,
      }
    }

    if (!features.billing) {
      return EMPTY_PROJECT_LAYOUT_CONTEXT
    }

    const teamId = projectData?.teamId
    if (!teamId) {
      return EMPTY_PROJECT_LAYOUT_CONTEXT
    }

    try {
      // Fresh fetch so we do not reuse a cached org payload that omitted billingLimits
      const organization = await context.queryClient
        .fetchQuery({
          queryKey: ['organization', teamId],
          queryFn: () => fetchOrganizationById(teamId),
          staleTime: 30 * 1000,
        })
        .catch(() => null)

      const budgetLimitReached =
        isBudgetLimitReached(projectData) || isBudgetLimitReached(organization)
      // Curtain only for cycle-consumed metrics. Storage/users overage must
      // leave the project open so customers can delete resources.
      const planUsageLimitReached =
        !budgetLimitReached &&
        (isProjectLockedByPlanUsage(projectData) ||
          isProjectLockedByPlanUsage(organization))

      if (budgetLimitReached || planUsageLimitReached) {
        redirectIfNested()
      }

      return {
        budgetLimitReached,
        planUsageLimitReached,
        budgetLimitTeamId: teamId,
        paymentRequiredMessage: null,
        paymentRequiredType: null,
      }
    } catch (error) {
      if (isRedirect(error)) throw error
      console.warn('Failed to resolve budget limit in beforeLoad:', error)
      return EMPTY_PROJECT_LAYOUT_CONTEXT
    }
  },
  loader: async ({ params, context }): Promise<ProjectLayoutLoaderData> => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return undefined
    }

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return undefined

    const budgetLimitReached = context.budgetLimitReached === true
    const planUsageLimitReached = context.planUsageLimitReached === true
    const projectBlocked = context.projectBlocked === true
    let budgetLimitTeamId = context.budgetLimitTeamId

    // When budget-locked, project.get returns 402 - do not fetch the project.
    if (budgetLimitReached) {
      if (!budgetLimitTeamId) {
        budgetLimitTeamId = await resolveProjectTeamIdFromConsole(projectId)
      }
      return {
        project: {
          $id: projectId,
          teamId: budgetLimitTeamId ?? '',
        },
        budgetLimitReached: true,
        planUsageLimitReached: false,
        projectBlocked: false,
        paymentRequiredMessage: context.paymentRequiredMessage ?? undefined,
        paymentRequiredType: context.paymentRequiredType ?? undefined,
      }
    }

    if (projectBlocked) {
      const blockedFeatures = getActiveProfileFeatures()
      const projectData = await queryClient
        .ensureQueryData({
          queryKey: ['project', projectId],
          queryFn: () => fetchProject(projectId),
          staleTime: 5 * 60 * 1000,
          retry: false,
        })
        .catch(() => null)

      registerProjectRegionFromProject(projectData)

      if (projectData?.teamId && blockedFeatures.billing) {
        await queryClient
          .ensureQueryData(organizationPlanQueryOptions(projectData.teamId))
          .catch(() => {})
      }

      return projectData
        ? {
            project: {
              $id: projectData.$id,
              teamId: projectData.teamId,
              status: (projectData as { status?: string }).status,
            },
            budgetLimitReached: false,
            planUsageLimitReached: false,
            projectBlocked: true,
          }
        : {
            project: { $id: projectId, teamId: budgetLimitTeamId ?? '' },
            projectBlocked: true,
          }
    }

    // Fetch project data (needed for header/sidebar and curtains) - CRITICAL
    try {
      const features = getActiveProfileFeatures()
      const [projectData] = await Promise.all([
        queryClient.ensureQueryData({
          queryKey: ['project', projectId],
          queryFn: () => fetchProject(projectId),
          staleTime: 5 * 60 * 1000, // 5 minutes
          retry: false,
        }),
        // Header ProjectSelector uses useOrganizations; prefetch so navigation does not flash skeleton
        queryClient
          .ensureQueryData(organizationsQueryOptions())
          .catch(() => {}),
        queryClient
          .ensureQueryData(consoleAccountQueryOptions())
          .catch(() => {}),
      ])

      registerProjectRegionFromProject(projectData)

      // Fetch organization plan if we have a teamId (critical for header/limit checking)
      if (projectData?.teamId) {
        if (features.billing) {
          await queryClient
            .ensureQueryData(organizationPlanQueryOptions(projectData.teamId))
            .catch(() => {})
          // Keep org billingLimits warm for plan-usage curtain first paint
          await queryClient
            .ensureQueryData(organizationQueryOptions(projectData.teamId))
            .catch(() => {})
          await prefetchOrganizationInvoiceDataIfAllowed(
            queryClient,
            projectData.teamId,
          )
        }
        if (features.orgRoles) {
          await queryClient
            .ensureQueryData(
              organizationScopesQueryOptions(projectData.teamId, projectId),
            )
            .catch(() => {})
        }
      }

      // Prefetch console variables (CNAME, A, AAAA, nameservers, CAA) for domain verification.
      // Skip heavy background work when the project is plan-locked.
      if (!planUsageLimitReached) {
        await queryClient
          .ensureQueryData(consoleVariablesQueryOptions(projectData?.region))
          .catch(() => {})

        // Warm API explorer specs in the background so Explorer opens without a loading state.
        void queryClient
          .prefetchQuery(apiExplorerSpecQueryOptions('server'))
          .catch(() => {})
        void queryClient
          .prefetchQuery(apiExplorerSpecQueryOptions('client'))
          .catch(() => {})
      }

      // Return project for first paint so paused/plan curtains can show immediately
      return projectData
        ? {
            project: {
              $id: projectData.$id,
              teamId: projectData.teamId,
              status: (projectData as { status?: string }).status,
            },
            budgetLimitReached: false,
            planUsageLimitReached,
            projectBlocked: hasActiveProjectBlock(projectData, projectId),
          }
        : undefined
    } catch (error) {
      // Project-scoped get can still 402 here if beforeLoad missed it.
      if (isHttpPaymentRequiredError(error)) {
        const teamId =
          budgetLimitTeamId ??
          (await resolveProjectTeamIdFromConsole(projectId))
        const paymentRequired = getAppwriteErrorInfo(error)
        return {
          project: {
            $id: projectId,
            teamId: teamId ?? '',
          },
          budgetLimitReached: true,
          planUsageLimitReached: false,
          paymentRequiredMessage: paymentRequired.message ?? undefined,
          paymentRequiredType: paymentRequired.type ?? undefined,
        }
      }
      // Fail closed: surface via route errorComponent instead of painting the shell.
      if (isHttpProjectAccessError(error)) {
        throw error
      }
      console.warn('Failed to fetch project in loader:', error)
      return undefined
    }
  },
  component: ProjectLayout,
})

function ProjectLayout() {
  const { projectId } = Route.useParams()
  const location = useLocation()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [hidePausedCurtain, setHidePausedCurtain] = useState(false)
  const loaderData = Route.useLoaderData() as ProjectLayoutLoaderData
  const routeContext = Route.useRouteContext()
  const {
    project,
    projectData,
    isLoading: isProjectLoading,
    error: projectError,
  } = useProject(projectId)
  const { features } = useConsoleProfile()

  const projectPaymentRequired = isHttpPaymentRequiredError(projectError)
  const paymentRequired = getAppwriteErrorInfo(projectError)

  const [consoleTeamId, setConsoleTeamId] = useState<string | null>(null)

  // When project.get returns 402, resolve teamId via console API for the curtain CTA.
  useEffect(() => {
    if (!features.billing || !projectId) return
    if (
      !projectPaymentRequired &&
      routeContext.budgetLimitReached !== true &&
      loaderData?.budgetLimitReached !== true
    ) {
      return
    }
    if (
      project?.teamId ||
      loaderData?.project?.teamId ||
      routeContext.budgetLimitTeamId ||
      consoleTeamId
    ) {
      return
    }
    let cancelled = false
    void resolveProjectTeamIdFromConsole(projectId).then((teamId) => {
      if (!cancelled && teamId) setConsoleTeamId(teamId)
    })
    return () => {
      cancelled = true
    }
  }, [
    features.billing,
    projectId,
    projectPaymentRequired,
    project?.teamId,
    loaderData?.project?.teamId,
    loaderData?.budgetLimitReached,
    routeContext.budgetLimitReached,
    routeContext.budgetLimitTeamId,
    consoleTeamId,
  ])

  const teamIdForBilling =
    project?.teamId ||
    (loaderData?.project?.teamId && loaderData.project.teamId.length > 0
      ? loaderData.project.teamId
      : undefined) ||
    routeContext.budgetLimitTeamId ||
    consoleTeamId ||
    undefined

  // Reactive check: project + org billingLimits, or HTTP 402 from project.get
  const { organization: billingOrganization } = useOrganizationById(
    features.billing ? teamIdForBilling : undefined,
  )
  const budgetLimitReached = Boolean(
    features.billing &&
      (routeContext.budgetLimitReached === true ||
        loaderData?.budgetLimitReached === true ||
        projectPaymentRequired ||
        isBudgetLimitReached(project) ||
        isBudgetLimitReached(billingOrganization)),
  )

  // Free/starter plan overage (e.g. GBHours). Prefer org limits; fall back to project
  // for usagePerProject plans. Budget curtain takes precedence when both apply.
  // Storage/users overage is not a lock: keep the project open so they can delete.
  const planUsageLimitReached = Boolean(
    features.billing &&
      !budgetLimitReached &&
      (routeContext.planUsageLimitReached === true ||
        loaderData?.planUsageLimitReached === true ||
        isProjectLockedByPlanUsage(billingOrganization) ||
        isProjectLockedByPlanUsage(project)),
  )

  const projectBlocked = Boolean(
    !budgetLimitReached &&
      !planUsageLimitReached &&
      (routeContext.projectBlocked === true ||
        loaderData?.projectBlocked === true ||
        hasActiveProjectBlock(projectData, projectId)),
  )
  const planUsageBillingLimits =
    billingOrganization?.billingLimits &&
    isPlanUsageLimitReached(billingOrganization)
      ? billingOrganization.billingLimits
      : project?.billingLimits
  const showPlanUsageLimitBanner =
    !budgetLimitReached &&
    !planUsageLimitReached &&
    features.billing &&
    (isPlanUsageLimitReached(billingOrganization) ||
      isPlanUsageLimitReached(project))

  const skipInvoiceBanners =
    budgetLimitReached || planUsageLimitReached || projectBlocked
  const { data: invoicePresence } = useOrganizationBillingInvoicePresence(
    skipInvoiceBanners ? undefined : teamIdForBilling,
  )
  const showFailedInvoiceBanner =
    !skipInvoiceBanners &&
    features.billing &&
    invoicePresence?.hasFailedInvoice === true
  const showInvoiceAuthorizeBanner =
    !skipInvoiceBanners &&
    !showFailedInvoiceBanner &&
    features.billing &&
    invoicePresence?.hasInvoiceRequiringAuthentication === true

  const { data: organizationsListData } = useQuery({
    ...organizationsQueryOptions(),
    enabled:
      !budgetLimitReached && !planUsageLimitReached && features.multiTenancy,
  })
  const orgBillingReadonlyForFailedInvoice = useMemo(() => {
    if (
      !showFailedInvoiceBanner ||
      !teamIdForBilling ||
      !organizationsListData?.teams
    )
      return false
    const row = organizationsListData.teams.find(
      (t: { $id: string }) => t.$id === teamIdForBilling,
    ) as { status?: string } | undefined
    return isOrganizationBillingReadonlyStatus(row?.status)
  }, [showFailedInvoiceBanner, organizationsListData, teamIdForBilling])

  // Prefer live project data after resume; loader data is only for first paint.
  const projectForPaused = project
    ? { $id: project.$id, teamId: project.teamId, status: project.status }
    : (loaderData?.project ?? null)
  const isPausedFromProject = project?.status === 'paused'
  const isPausedFromLoader =
    !project && loaderData?.project?.status === 'paused'
  // Prefer budget / plan-usage curtains when they apply (root cause of the block).
  const isPaused =
    !budgetLimitReached &&
    !planUsageLimitReached &&
    !projectBlocked &&
    (isPausedFromProject || isPausedFromLoader) &&
    !hidePausedCurtain

  const budgetCurtainTeamId =
    (teamIdForBilling && teamIdForBilling.length > 0
      ? teamIdForBilling
      : null) ||
    (projectForPaused?.teamId && projectForPaused.teamId.length > 0
      ? projectForPaused.teamId
      : null) ||
    (routeContext.budgetLimitTeamId && routeContext.budgetLimitTeamId.length > 0
      ? routeContext.budgetLimitTeamId
      : null)

  // Classify project fetch failures before effects that depend on them.
  const errorMessage = projectError?.message || ''
  const isNotFound = Boolean(projectError && isHttpNotFoundError(projectError))
  const isAccessDenied = Boolean(
    projectError && isHttpProjectAccessError(projectError) && !isNotFound,
  )

  // Hard reload waits on the project-selector shell gate; release it when the layout
  // will not mount ProjectSelector (budget lock, not found, or access denied) so the
  // fullscreen loader does not hang over the error/curtain UI.
  useLayoutEffect(() => {
    const shouldReleaseShellGate =
      budgetLimitReached ||
      (!isProjectLoading && (isNotFound || isAccessDenied))
    if (!shouldReleaseShellGate) return
    setInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector, true)
  }, [
    budgetLimitReached,
    isProjectLoading,
    isNotFound,
    isAccessDenied,
    location.pathname,
  ])

  // Extract active section from pathname (leading empty segment from split)
  const pathParts = location.pathname.split('/')
  const activeSection = pathParts[3] || 'overview'

  const databaseTablesRouteTab = getDatabaseTablesRouteTab(pathParts)
  const databaseLevelLayoutSegment = getDatabaseLevelLayoutSegment(pathParts)

  const isDatabaseSpreadsheetView =
    activeSection === 'databases' &&
    (databaseTablesRouteTab != null || databaseLevelLayoutSegment != null)

  const isDatabaseVisualizerView =
    databaseTablesRouteTab === 'visualizer' ||
    databaseLevelLayoutSegment === 'visualizer'

  // Functions local code editor (Monaco)
  const isFunctionsEditorView =
    activeSection === 'functions' && pathParts[4] === 'editor'

  // Check if we're on the function executions tab
  // Pattern: /projects/:projectId/functions/:functionId/executions
  const isFunctionExecutionsTab =
    activeSection === 'functions' &&
    pathParts.length >= 6 &&
    pathParts[5] === 'executions'

  // Check if we're on the site logs tab
  // Pattern: /projects/:projectId/sites/:siteId/logs
  const isSiteLogsTab =
    activeSection === 'sites' &&
    pathParts.length >= 6 &&
    pathParts[5] === 'logs'

  // Views that need overflow-hidden on main (they manage their own scrolling)
  const isFixedLayoutView =
    isDatabaseSpreadsheetView ||
    isDatabaseVisualizerView ||
    activeSection === 'usage' ||
    activeSection === 'activity' ||
    activeSection === 'realtime' ||
    activeSection === 'storage' ||
    activeSection === 'explorer' ||
    activeSection === 'agents' ||
    isFunctionsEditorView ||
    isFunctionExecutionsTab ||
    isSiteLogsTab

  // Close sidebar on route change
  useEffect(() => {
    setSidebarOpen(false)
  }, [location.pathname])

  // If project state is paused again, allow the curtain to show.
  useEffect(() => {
    if (project?.status === 'paused') {
      setHidePausedCurtain(false)
    }
  }, [project?.status])

  // Keep project active: report console access when layout loads (cloud, non-paused). Fire-and-forget; backend has 6-day cooldown.
  // Dedupe: only one call per projectId per mount (avoids double call from Strict Mode or dependency updates).
  const reportedConsoleAccessForRef = useRef<string | null>(null)
  useEffect(() => {
    if (
      typeof window === 'undefined' ||
      !projectId ||
      isPaused ||
      budgetLimitReached ||
      planUsageLimitReached ||
      projectBlocked ||
      !features.billing
    )
      return
    if (reportedConsoleAccessForRef.current === projectId) return
    reportedConsoleAccessForRef.current = projectId
    reportConsoleAccess(projectId)
  }, [
    projectId,
    isPaused,
    budgetLimitReached,
    planUsageLimitReached,
    projectBlocked,
    features.billing,
  ])

  // Show error without painting the project shell (no flash of sidebar/header).
  // Skip when budget-locked (402) - the curtain handles that state.
  if (
    !budgetLimitReached &&
    !planUsageLimitReached &&
    !projectBlocked &&
    projectError &&
    (isNotFound || isAccessDenied)
  ) {
    const errorObj =
      projectError instanceof Error
        ? projectError
        : new Error(errorMessage || 'Project error occurred')

    return (
      <ProjectAccessErrorView
        error={errorObj}
        reset={() => {
          window.location.reload()
        }}
      />
    )
  }

  // While the project is still resolving, paint nothing. Hard reloads stay on the
  // fullscreen loader; soft navigations keep the previous page until beforeLoad settles.
  if (
    !budgetLimitReached &&
    !planUsageLimitReached &&
    !projectBlocked &&
    isProjectLoading &&
    !project &&
    !loaderData?.project
  ) {
    return null
  }

  return (
    <RequireAuth>
      {budgetLimitReached ? (
        <BudgetLimitProjectCurtain
          teamId={budgetCurtainTeamId}
          message={
            paymentRequired.message ||
            loaderData?.paymentRequiredMessage ||
            routeContext.paymentRequiredMessage
          }
          errorType={
            paymentRequired.type ||
            loaderData?.paymentRequiredType ||
            routeContext.paymentRequiredType
          }
        />
      ) : planUsageLimitReached ? (
        <PlanUsageLimitProjectCurtain
          teamId={budgetCurtainTeamId}
          billingLimits={planUsageBillingLimits}
        />
      ) : projectBlocked && projectForPaused?.teamId ? (
        <BlockedProjectCurtain teamId={projectForPaused.teamId} />
      ) : !isPaused &&
        billingOrganization?.plan === 'education' &&
        teamIdForBilling ? (
        <EducationPlanCurtain orgId={teamIdForBilling} />
      ) : null}
      {isPaused && projectForPaused && (
        <PausedProjectCurtain
          projectId={projectForPaused.$id}
          teamId={projectForPaused.teamId}
          onRestoreSuccess={() => setHidePausedCurtain(true)}
        />
      )}
      <SessionMigrationsProvider>
        <RealtimeProvider projectId={projectId}>
          {features.browserAlerts ? (
            <BuildNotificationsProvider projectId={projectId} />
          ) : null}
          <ProjectCliShellLayout
            projectId={projectId}
            sidebar={{
              projectId,
              activeSection,
              mobileOpen: sidebarOpen,
              onMobileClose: () => setSidebarOpen(false),
              onMenuClick: () => setSidebarOpen(true),
            }}
            headerBanner={
              <>
                <PostgresPromoBanner />
                <OrganizationFailedInvoiceHeaderBanner
                  organizationId={teamIdForBilling}
                  show={showFailedInvoiceBanner}
                  orgBillingReadonly={orgBillingReadonlyForFailedInvoice}
                />
                <OrganizationInvoiceAuthorizeHeaderBanner
                  organizationId={teamIdForBilling}
                  show={showInvoiceAuthorizeBanner}
                />
                <OrganizationPlanLimitHeaderBanner
                  organizationId={teamIdForBilling}
                  show={showPlanUsageLimitBanner}
                  billingLimits={planUsageBillingLimits}
                />
              </>
            }
            fixedLayout={isFixedLayoutView}
          >
            {/* Keep outlet mounted under the curtain (same as paused) so layout stays stable */}
            <Outlet />
          </ProjectCliShellLayout>
        </RealtimeProvider>
      </SessionMigrationsProvider>
    </RequireAuth>
  )
}
