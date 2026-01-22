import { createFileRoute, Outlet, useLocation } from '@tanstack/react-router'
import { useState, useEffect } from 'react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { KeyboardShortcutsProvider } from '@/components/global/providers/KeyboardShortcuts'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { fetchProject, fetchOrganizationPlan } from '@/lib/react-query/hooks'

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
          await queryClient.ensureQueryData({
            queryKey: ['organization', 'plan', projectData.teamId],
            queryFn: () => fetchOrganizationPlan(projectData.teamId),
            staleTime: 5 * 60 * 1000, // 5 minutes
          }).catch(() => {
            // Ignore errors for optional prefetch - plan might not be available
          })
        }
      } catch (error) {
        // If authentication is not set up yet, the component will handle it via RequireAuth
        // Don't block navigation - let the component handle the error
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

  // Views that need overflow-hidden on main (they manage their own scrolling)
  const isFixedLayoutView =
    isDatabaseSpreadsheetView ||
    isDatabaseVisualizerView ||
    activeSection === 'usage'

  // Check if we're on a detail route (user detail, team detail, etc.)
  const isDetailRoute = pathParts.some(
    (part, idx) =>
      (part === 'users' || part === 'teams') &&
      idx > 0 &&
      pathParts[idx - 1] === 'auth' &&
      idx + 1 < pathParts.length,
  )

  // Check if we're on the function executions tab
  // Pattern: /projects/:projectId/functions/:functionId/executions
  const isFunctionExecutionsTab =
    activeSection === 'functions' &&
    pathParts.length >= 6 &&
    pathParts[5] === 'executions'

  // Hide footer for usage view, database spreadsheet view, visualizer, detail routes, and function executions tab
  const hideFooter =
    isDatabaseSpreadsheetView ||
    isDatabaseVisualizerView ||
    activeSection === 'usage' ||
    isFunctionExecutionsTab ||
    activeSection === 'activity' ||
    isDetailRoute

  // Close sidebar on route change
  useEffect(() => {
    setSidebarOpen(false)
  }, [location.pathname])

  return (
    <RequireAuth>
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
          isDetailRoute={isDetailRoute}
        >
          <Outlet />
        </ConsoleLayout>
      </KeyboardShortcutsProvider>
    </RequireAuth>
  )
}
