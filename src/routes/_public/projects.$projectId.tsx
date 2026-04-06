import { createFileRoute, Outlet, useLocation } from '@tanstack/react-router'
import { useState, useEffect, useRef } from 'react'
import { CloudStatusBanner } from '@/components/global/layout/CloudStatusBanner'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { PausedProjectCurtain } from '@/components/global/layout/PausedProjectCurtain'
import { KeyboardShortcutsProvider } from '@/components/global/providers/KeyboardShortcuts'
import { RealtimeProvider } from '@/components/global/providers/RealtimeProvider'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { CsvExportBox, CsvImportBox } from '@/components/global/csv-migrations'
import { SessionMigrationsProvider } from '@/components/global/providers/SessionMigrationsContext'
import { GlobalUploadProgress } from '@/components/global/shared/GlobalUploadProgress'
import {
  fetchProject,
  organizationPlanQueryOptions,
  organizationScopesQueryOptions,
  organizationsQueryOptions,
  useProject,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleVariablesQueryOptions } from '@/lib/react-query/hooks/console-variables'
import { ErrorComponent } from '@/components/error/Component'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { reportConsoleAccess } from '@/lib/appwrite/console-access'
import { useConsoleProfile } from '@/hooks/use-console-profile'

/** Tab segment for routes under `.../tables/:tableId/<tab>` or `.../collections/:id/<tab>` */
const DATABASE_TABLE_VIEW_TABS = new Set([
  'rows',
  'documents',
  'columns',
  'indexes',
  'security',
  'settings',
  'visualizer',
  'insights',
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
  'backups',
  'export-import',
  'insights',
  'db-security',
  'browser',
  'security',
  'settings',
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

/** Loader return: project data for first paint (avoids layout shift for paused curtain). */
export type ProjectLayoutLoaderData =
  | {
      project: { $id: string; teamId: string; status?: string }
    }
  | undefined

export const Route = createFileRoute('/_public/projects/$projectId')({
  loader: async ({ params, context }): Promise<ProjectLayoutLoaderData> => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return undefined
    }

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return undefined

    // Fetch project data (needed for header/sidebar and paused curtain) - CRITICAL: blocks navigation until ready
    // Use ensureQueryData to avoid duplicate calls and handle auth errors gracefully
    try {
      const [projectData] = await Promise.all([
        queryClient.ensureQueryData({
          queryKey: ['project', projectId],
          queryFn: () => fetchProject(projectId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        }),
        // Header ProjectSelector uses useOrganizations; prefetch so navigation does not flash skeleton
        queryClient
          .ensureQueryData(organizationsQueryOptions())
          .catch(() => {}),
      ])

      // Fetch organization plan if we have a teamId (critical for header/limit checking)
      if (projectData?.teamId) {
        await queryClient
          .ensureQueryData(organizationPlanQueryOptions(projectData.teamId))
          .catch(() => {})
        if (getActiveProfileFeatures().orgRoles) {
          await queryClient
            .ensureQueryData(organizationScopesQueryOptions(projectData.teamId))
            .catch(() => {})
        }
      }

      // Prefetch console variables (CNAME, A, AAAA, nameservers, CAA) for domain verification.
      await queryClient
        .ensureQueryData(consoleVariablesQueryOptions(projectData?.region))
        .catch(() => {})

      // Return project for first paint so paused curtain can show immediately (no layout shift)
      return projectData
        ? {
            project: {
              $id: projectData.$id,
              teamId: projectData.teamId,
              status: (projectData as { status?: string }).status,
            },
          }
        : undefined
    } catch (error) {
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
  const {
    project,
    isLoading: isProjectLoading,
    error: projectError,
  } = useProject(projectId)
  const { features } = useConsoleProfile()

  // Use loader data for first paint so paused curtain shows immediately (no layout shift)
  const projectForPaused =
    loaderData?.project ??
    (project
      ? { $id: project.$id, teamId: project.teamId, status: project.status }
      : null)
  const isPausedFromProject = project?.status === 'paused'
  const isPausedFromLoader =
    !project && loaderData?.project?.status === 'paused'
  const isPaused =
    (isPausedFromProject || isPausedFromLoader) && !hidePausedCurtain

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

  // Views that need overflow-hidden on main (they manage their own scrolling)
  const isFixedLayoutView =
    isDatabaseSpreadsheetView ||
    isDatabaseVisualizerView ||
    activeSection === 'usage' ||
    isFunctionsEditorView

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

  // Hide footer for usage view, database spreadsheet view, visualizer, function executions tab, site logs tab, and functions editor
  const hideFooter =
    isDatabaseSpreadsheetView ||
    isDatabaseVisualizerView ||
    activeSection === 'usage' ||
    isFunctionExecutionsTab ||
    isSiteLogsTab ||
    activeSection === 'activity' ||
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
      !features.billing
    )
      return
    if (reportedConsoleAccessForRef.current === projectId) return
    reportedConsoleAccessForRef.current = projectId
    reportConsoleAccess(projectId)
  }, [projectId, isPaused, features.billing])

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
  if (!isProjectLoading && projectError && (isNotFound || isAccessDenied)) {
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
      {isPaused && projectForPaused && (
        <PausedProjectCurtain
          projectId={projectForPaused.$id}
          teamId={projectForPaused.teamId}
          onRestoreSuccess={() => setHidePausedCurtain(true)}
        />
      )}
      <SessionMigrationsProvider>
        <RealtimeProvider projectId={projectId}>
          <KeyboardShortcutsProvider projectId={projectId}>
            <ConsoleLayout
              sidebar={{
                projectId,
                activeSection,
                mobileOpen: sidebarOpen,
                onMobileClose: () => setSidebarOpen(false),
                onMenuClick: () => setSidebarOpen(true),
              }}
              header={{ projectId }}
              showFooter={!hideFooter}
              fixedLayout={isFixedLayoutView}
            >
              <Outlet />
            </ConsoleLayout>
            {/* Unified progress panel: file uploads + CSV export/import (same style, no collision) */}
            <div className="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full">
              <GlobalUploadProgress embedded />
              <CsvImportBox projectId={projectId} />
              <CsvExportBox projectId={projectId} />
            </div>
          </KeyboardShortcutsProvider>
        </RealtimeProvider>
      </SessionMigrationsProvider>
    </RequireAuth>
  )
}
