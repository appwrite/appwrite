import { createFileRoute, Outlet, useLocation } from '@tanstack/react-router'
import { useState, useEffect } from 'react'
import { ConsoleSidebar } from '@/components/global/layout/Sidebar'
import { ConsoleHeader } from '@/components/global/layout/Header'
import { KeyboardShortcutsProvider } from '@/components/global/providers/KeyboardShortcuts'
import { PaymentAlert } from '@/components/pages/projects/$projectId/shared/PaymentAlert'
import { ConsoleFooter } from '@/components/global/layout/Footer'
import { cn } from '@/lib/utils'

export const Route = createFileRoute('/_public/projects/$projectId')({
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
    ['rows', 'columns', 'indexes', 'security', 'settings'].includes(pathParts[7])

  // Check if we're in the database visualizer view
  // Pattern: /projects/:projectId/databases/:databaseId/visualizer
  const isDatabaseVisualizerView =
    activeSection === 'databases' &&
    pathParts.length >= 6 &&
    pathParts[5] === 'visualizer'

  // Views that need overflow-hidden on main (they manage their own scrolling)
  const isFixedLayoutView =
    isDatabaseSpreadsheetView || isDatabaseVisualizerView || activeSection === 'usage'
  
  // Check if we're on a detail route (user detail, team detail, etc.)
  const isDetailRoute = pathParts.some((part, idx) => 
    (part === 'users' || part === 'teams') && 
    idx > 0 && 
    pathParts[idx - 1] === 'auth' &&
    idx + 1 < pathParts.length
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
    <KeyboardShortcutsProvider projectId={projectId}>
      <div className="flex h-full flex-col bg-background">
        {/* Sticky header section - takes space in flex layout */}
        <div className="flex-shrink-0">
          <div className="sticky top-0 z-40">
            <PaymentAlert />
          </div>
          <div className="sticky top-0 z-30 bg-background">
            <ConsoleHeader
              onMenuClick={() => setSidebarOpen(true)}
              projectId={projectId}
            />
          </div>
        </div>

        {/* Mobile sidebar overlay */}
        {sidebarOpen && (
          <div
            className="fixed inset-0 z-40 bg-black/60"
            onClick={() => setSidebarOpen(false)}
          />
        )}

        {/* Sidebar + Content below header */}
        <div className="flex flex-1 min-h-0 overflow-hidden">
          {/* Sidebar */}
          <ConsoleSidebar
            projectId={projectId}
            activeSection={activeSection}
            mobileOpen={sidebarOpen}
            onMobileClose={() => setSidebarOpen(false)}
          />

          {/* Main content area */}
          <main
            className={cn(
              'flex-1 bg-background flex flex-col min-h-0',
              isFixedLayoutView ? 'overflow-hidden' : 'overflow-y-auto',
            )}
          >
            <div className={cn('flex-1', (isFixedLayoutView || isDetailRoute) && 'min-h-0')}>
                <div className={cn('h-full', (isFixedLayoutView || isDetailRoute) && 'min-h-0')}>
                  <Outlet />
                </div>
            </div>
            {!hideFooter && <ConsoleFooter />}
          </main>
        </div>
      </div>
    </KeyboardShortcutsProvider>
  )
}
