import {
  createFileRoute,
  Outlet,
  redirect,
  isRedirect,
  useLocation,
} from '@tanstack/react-router'
import { useState, useEffect, useRef, useMemo, useLayoutEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CloudStatusBanner } from '@/components/global/layout/CloudStatusBanner'
import { BudgetLimitProjectCurtain } from '@/components/global/layout/BudgetLimitProjectCurtain'
import { PausedProjectCurtain } from '@/components/global/layout/PausedProjectCurtain'
import { RealtimeProvider } from '@/components/global/providers/RealtimeProvider'
import { BuildNotificationsProvider } from '@/components/global/providers/BuildNotificationsProvider'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { SessionMigrationsProvider } from '@/components/global/providers/SessionMigrationsContext'
import { OrganizationFailedInvoiceHeaderBanner } from '@/components/global/shared/OrganizationFailedInvoiceHeaderBanner'
import {
  fetchProject,
  fetchOrganizationById,
  organizationPlanQueryOptions,
  organizationQueryOptions,
  organizationScopesQueryOptions,
  organizationsQueryOptions,
  prefetchOrganizationInvoiceDataIfAllowed,
  useProject,
  useOrganizationFailedInvoicePresence,
  isOrganizationBillingReadonlyStatus,
  isBudgetLimitReached,
  resolveProjectTeamIdFromConsole,
  useOrganizationById,
} from '@/lib/react-query/hooks'
import { apiExplorerSpecQueryOptions } from '@/lib/react-query/hooks/api-explorer'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleVariablesQueryOptions } from '@/lib/react-query/hooks/console-variables'
import { ErrorComponent } from '@/components/error/Component'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { reportConsoleAccess } from '@/lib/appwrite/console-access'
import {
  ensureProjectRegion,
  registerProjectRegionFromProject,
} from '@/lib/project-region'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { ProjectCliShellLayout } from '@/components/global/cli-shell/ProjectCliShellLayout'
import { isHttpPaymentRequiredError } from '@/lib/utils/error-formatting'
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
    }
  | undefined

export type ProjectLayoutRouteContext = {
  budgetLimitReached: boolean
  budgetLimitTeamId: string | null
}

export const Route = createFileRoute('/_public/projects/$projectId')({
  // Region + budget check must run before child loaders (loaders execute in parallel).
  // When the budget cap is hit, nested project routes are redirected to the project root
  // so heavy service loaders cannot hang the navigation.
  beforeLoad: async ({
    params,
    context,
    location,
  }): Promise<ProjectLayoutRouteContext> => {
    if (typeof window === 'undefined') {
      return { budgetLimitReached: false, budgetLimitTeamId: null }
    }
    const { projectId } = params
    if (!projectId) {
      return { budgetLimitReached: false, budgetLimitTeamId: null }
    }

    // Region lookup may fail with 402 when budget-locked; ignore and continue.
    await ensureProjectRegion(context.queryClient, projectId).catch(() => {})

    const features = getActiveProfileFeatures()
    if (!features.billing) {
      return { budgetLimitReached: false, budgetLimitTeamId: null }
    }

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

    try {
      const projectData = await context.queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000,
      })
      registerProjectRegionFromProject(projectData)

      const teamId = projectData?.teamId
      if (!teamId) {
        return { budgetLimitReached: false, budgetLimitTeamId: null }
      }

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

      if (budgetLimitReached) {
        redirectIfNested()
      }

      return {
        budgetLimitReached,
        budgetLimitTeamId: teamId,
      }
    } catch (error) {
      if (isRedirect(error)) throw error

      // Budget cap blocks project-scoped APIs with HTTP 402. Resolve teamId via
      // the console projects API so we can still show the lock curtain.
      if (isHttpPaymentRequiredError(error)) {
        const teamId = await resolveProjectTeamIdFromConsole(projectId)
        let budgetConfirmed = true
        if (teamId) {
          const organization = await context.queryClient
            .fetchQuery({
              queryKey: ['organization', teamId],
              queryFn: () => fetchOrganizationById(teamId),
              staleTime: 30 * 1000,
            })
            .catch(() => null)
          // If org loads and clearly has no budget limit, do not force the curtain.
          if (
            organization &&
            organization.billingLimits &&
            !isBudgetLimitReached(organization)
          ) {
            budgetConfirmed = false
          }
        }
        if (budgetConfirmed) {
          redirectIfNested()
          return {
            budgetLimitReached: true,
            budgetLimitTeamId: teamId,
          }
        }
      }

      console.warn('Failed to resolve budget limit in beforeLoad:', error)
      return { budgetLimitReached: false, budgetLimitTeamId: null }
    }
  },
  loader: async ({
    params,
    context,
  }): Promise<ProjectLayoutLoaderData> => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return undefined
    }

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return undefined

    const budgetLimitReached = context.budgetLimitReached === true
    let budgetLimitTeamId = context.budgetLimitTeamId

    // When budget-locked, project.get returns 402 — do not fetch the project.
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
        }),
        // Header ProjectSelector uses useOrganizations; prefetch so navigation does not flash skeleton
        features.multiTenancy
          ? queryClient
              .ensureQueryData(organizationsQueryOptions())
              .catch(() => {})
          : Promise.resolve(),
      ])

      registerProjectRegionFromProject(projectData)

      // Fetch organization plan if we have a teamId (critical for header/limit checking)
      if (projectData?.teamId) {
        if (features.billing) {
          await queryClient
            .ensureQueryData(organizationPlanQueryOptions(projectData.teamId))
            .catch(() => {})
          await prefetchOrganizationInvoiceDataIfAllowed(
            queryClient,
            projectData.teamId,
          )
        }
        if (features.orgRoles) {
          await queryClient
            .ensureQueryData(organizationScopesQueryOptions(projectData.teamId))
            .catch(() => {})
        }
      }

      // Prefetch console variables (CNAME, A, AAAA, nameservers, CAA) for domain verification.
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

      // Return project for first paint so paused curtain can show immediately (no layout shift)
      return projectData
        ? {
            project: {
              $id: projectData.$id,
              teamId: projectData.teamId,
              status: (projectData as { status?: string }).status,
            },
            budgetLimitReached: false,
          }
        : undefined
    } catch (error) {
      // Project-scoped get can still 402 here if beforeLoad missed it.
      if (isHttpPaymentRequiredError(error)) {
        const teamId =
          budgetLimitTeamId ??
          (await resolveProjectTeamIdFromConsole(projectId))
        return {
          project: {
            $id: projectId,
            teamId: teamId ?? '',
          },
          budgetLimitReached: true,
        }
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
    isLoading: isProjectLoading,
    error: projectError,
  } = useProject(projectId)
  const { features } = useConsoleProfile()

  const projectPaymentRequired = isHttpPaymentRequiredError(projectError)

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

  const { data: failedInvoicePresence } =
    useOrganizationFailedInvoicePresence(
      budgetLimitReached ? undefined : teamIdForBilling,
    )
  const showFailedInvoiceBanner =
    !budgetLimitReached &&
    features.billing &&
    failedInvoicePresence?.hasFailedInvoice === true

  const { data: organizationsListData } = useQuery({
    ...organizationsQueryOptions(),
    enabled: !budgetLimitReached && features.multiTenancy,
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
  }, [
    showFailedInvoiceBanner,
    organizationsListData,
    teamIdForBilling,
  ])

  // Use loader data for first paint so paused curtain shows immediately (no layout shift)
  const projectForPaused =
    loaderData?.project ??
    (project
      ? { $id: project.$id, teamId: project.teamId, status: project.status }
      : null)
  const isPausedFromProject = project?.status === 'paused'
  const isPausedFromLoader =
    !project && loaderData?.project?.status === 'paused'
  // Prefer budget curtain when both apply (budget is the root cause).
  const isPaused =
    !budgetLimitReached &&
    (isPausedFromProject || isPausedFromLoader) &&
    !hidePausedCurtain

  const budgetCurtainTeamId =
    (teamIdForBilling && teamIdForBilling.length > 0
      ? teamIdForBilling
      : null) ||
    (projectForPaused?.teamId && projectForPaused.teamId.length > 0
      ? projectForPaused.teamId
      : null) ||
    (routeContext.budgetLimitTeamId &&
    routeContext.budgetLimitTeamId.length > 0
      ? routeContext.budgetLimitTeamId
      : null)

  // Hard reload waits on the project-selector shell gate; release it when budget-locked
  // so the fullscreen loader does not hang (project.get returns 402, selector never "ready").
  useLayoutEffect(() => {
    if (!budgetLimitReached) return
    setInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector, true)
  }, [budgetLimitReached])

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
    isFunctionsEditorView ||
    isFunctionExecutionsTab ||
    isSiteLogsTab

  // Hide footer for usage view, database spreadsheet / level tabs (incl. monitor, visualizer), function executions tab, site logs tab, functions editor, and storage workspace
  const hideFooter =
    isDatabaseSpreadsheetView ||
    isDatabaseVisualizerView ||
    activeSection === 'usage' ||
    isFunctionExecutionsTab ||
    isSiteLogsTab ||
    activeSection === 'activity' ||
    activeSection === 'realtime' ||
    activeSection === 'storage' ||
    activeSection === 'explorer' ||
    isFunctionsEditorView

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
      !features.billing
    )
      return
    if (reportedConsoleAccessForRef.current === projectId) return
    reportedConsoleAccessForRef.current = projectId
    reportConsoleAccess(projectId)
  }, [projectId, isPaused, budgetLimitReached, features.billing])

  // Check if this is a project not found or access denied error
  // Do this AFTER all hooks are called to avoid hooks order violation
  const errorMessage = projectError?.message || ''
  const lowerMessage = errorMessage.toLowerCase()
  const errorCode = (projectError as unknown)?.code
  const errorName =
    (projectError as unknown)?.name ||
    (projectError instanceof Error ? projectError.name : '')

  const isNotFound =
    projectError &&
    (errorName === 'NotFoundError' ||
      errorCode === 404 ||
      lowerMessage.includes('not found') ||
      lowerMessage.includes('404') ||
      lowerMessage.includes('does not exist'))

  const isAccessDenied =
    projectError &&
    (errorName === 'UnauthorizedError' ||
      errorName === 'ForbiddenError' ||
      errorCode === 401 ||
      errorCode === 403 ||
      lowerMessage.includes('unauthorized') ||
      lowerMessage.includes('forbidden') ||
      lowerMessage.includes('permission denied') ||
      lowerMessage.includes('access denied'))

  // Show error component if project is not found or access denied
  // Only show after loading is complete to avoid flashing
  // Skip when budget-locked (402) — the curtain handles that state.
  if (
    !budgetLimitReached &&
    !isProjectLoading &&
    projectError &&
    (isNotFound || isAccessDenied)
  ) {
    // Ensure we have an Error object for the ErrorComponent
    const errorObj =
      projectError instanceof Error
        ? projectError
        : new Error(errorMessage || 'Project error occurred')

    return (
      <div className="org-layout-container flex h-full flex-col bg-background">
        <div className="sticky top-0 z-[110] flex shrink-0 flex-col bg-background">
          <CloudStatusBanner />
          <ConsoleImpersonationBanner />
        </div>
        <main className="min-h-0 flex-1 overflow-y-auto">
          <ErrorComponent
            error={errorObj}
            info={undefined}
            reset={() => {
              window.location.reload()
            }}
          />
        </main>
      </div>
    )
  }

  return (
    <RequireAuth>
      {budgetLimitReached ? (
        <BudgetLimitProjectCurtain teamId={budgetCurtainTeamId} />
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
          <BuildNotificationsProvider projectId={projectId} />
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
              <OrganizationFailedInvoiceHeaderBanner
                organizationId={teamIdForBilling}
                show={showFailedInvoiceBanner}
                orgBillingReadonly={orgBillingReadonlyForFailedInvoice}
              />
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
