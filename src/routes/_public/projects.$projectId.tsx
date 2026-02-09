import { createFileRoute, Outlet, useLocation } from '@tanstack/react-router'
import { useState, useEffect } from 'react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { KeyboardShortcutsProvider } from '@/components/global/providers/KeyboardShortcuts'
import { RealtimeProvider } from '@/components/global/providers/RealtimeProvider'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import {
  fetchProject,
  organizationPlanQueryOptions,
  useProject,
} from '@/lib/react-query/hooks'
import { ErrorComponent } from '@/components/error/Component'

export const Route = createFileRoute('/_public/projects/$projectId')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Fetch project data (needed for header/sidebar) - CRITICAL: blocks navigation until ready
      // Use ensureQueryData to avoid duplicate calls and handle auth errors gracefully
      try {
        const projectData = await queryClient.ensureQueryData({
          queryKey: ['project', projectId],
          queryFn: () => fetchProject(projectId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        })

        // Fetch organization plan if we have a teamId (critical for header/limit checking)
        // Use ensureQueryData to avoid duplicate calls if already fetching
        if (projectData?.teamId) {
          await queryClient
            .ensureQueryData(organizationPlanQueryOptions(projectData.teamId))
            .catch(() => {
              // Ignore errors for optional prefetch - plan might not be available
            })
        }
      } catch (error) {
        // Don't throw - let the component handle the error to avoid blocking navigation
        // The component will check the error and display appropriate message
        console.warn('Failed to fetch project in loader:', error)
      }
    }
  },
  component: ProjectLayout,
})

function ProjectLayout() {
  const { projectId } = Route.useParams()
  const location = useLocation()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const { isLoading: isProjectLoading, error: projectError } =
    useProject(projectId)

  // Extract active section from pathname
  const pathParts = location.pathname.split('/')
  const activeSection = pathParts[3] || 'overview'

  // Check if we're in a database table spreadsheet view (rows, columns, indexes, security, settings)
  // Pattern: /projects/:projectId/databases/:databaseId/tables/:tableId/(rows|columns|indexes|security|settings)
  const isDatabaseSpreadsheetView =
    activeSection === 'databases' &&
    pathParts.length >= 8 &&
    pathParts[5] === 'tables' &&
    ['rows', 'columns', 'indexes', 'security', 'settings'].includes(
      pathParts[7],
    )

  // Check if we're in the database visualizer view
  // Pattern: /projects/:projectId/databases/:databaseId/visualizer
  const isDatabaseVisualizerView =
    activeSection === 'databases' &&
    pathParts.length >= 6 &&
    pathParts[5] === 'visualizer'

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

  // Check if this is a project not found or access denied error
  // Do this AFTER all hooks are called to avoid hooks order violation
  const errorMessage = projectError?.message || ''
  const lowerMessage = errorMessage.toLowerCase()
  const errorCode = (projectError as any)?.code
  const errorName =
    (projectError as any)?.name ||
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
      <ErrorComponent
        error={errorObj}
        info={undefined}
        reset={() => {
          // Refetch project on reset
          window.location.reload()
        }}
      />
    )
  }

  return (
    <RequireAuth>
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
        </KeyboardShortcutsProvider>
      </RealtimeProvider>
    </RequireAuth>
  )
}
