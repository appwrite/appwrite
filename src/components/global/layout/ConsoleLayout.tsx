import { ReactNode } from 'react'
import { ConsoleHeader } from './Header'
import { ConsoleSidebar } from './Sidebar'
import { ConsoleFooter } from './Footer'
import { ConsoleBanner } from './ConsoleBanner'
import { PaymentAlert } from '@/components/pages/projects/$projectId/shared/PaymentAlert'
import { cn } from '@/lib/utils'

interface ConsoleLayoutProps {
  /** Main content to render */
  children: ReactNode

  /** Optional sidebar configuration */
  sidebar?: {
    projectId: string
    activeSection: string
    mobileOpen: boolean
    onMobileClose: () => void
    onMenuClick: () => void
  }

  /** ConsoleHeader props */
  header?: {
    projectId?: string
    onCommandCenterOpen?: () => void
    onCreateOrganization?: () => void
  }

  /** Whether to show the footer */
  showFooter?: boolean

  /** Whether the main content should have overflow-hidden (for views that manage their own scrolling) */
  fixedLayout?: boolean

  /** Custom container class name */
  containerClassName?: string
}

/**
 * ConsoleLayout - Reusable layout component for all console pages
 *
 * Provides consistent layout structure with:
 * - Sticky header (PaymentAlert + ConsoleHeader)
 * - Optional sidebar
 * - Scrollable main content area
 * - Optional footer
 *
 * Usage:
 * ```tsx
 * // With sidebar (project scope)
 * <ConsoleLayout
 *   sidebar={{
 *     projectId: "project-id",
 *     activeSection: "databases",
 *     mobileOpen: sidebarOpen,
 *     onMobileClose: () => setSidebarOpen(false),
 *     onMenuClick: () => setSidebarOpen(true),
 *   }}
 *   header={{ projectId: "project-id" }}
 *   showFooter={!hideFooter}
 * >
 *   <Outlet />
 * </ConsoleLayout>
 *
 * // Without sidebar (org/account scope)
 * <ConsoleLayout
 *   header={{
 *     onCommandCenterOpen: () => setOpen(true),
 *     onCreateOrganization: () => setOpen(true),
 *   }}
 *   showFooter
 * >
 *   {children}
 * </ConsoleLayout>
 * ```
 */
export function ConsoleLayout({
  children,
  sidebar,
  header,
  showFooter = true,
  fixedLayout = false,
  containerClassName,
}: ConsoleLayoutProps) {
  const hasSidebar = !!sidebar
  const layoutContainerClass =
    containerClassName ||
    (hasSidebar ? 'project-layout-container' : 'org-layout-container')

  return (
    <div
      className={cn('flex h-full flex-col bg-background', layoutContainerClass)}
    >
      {/* Sticky header section - takes space in flex layout */}
      <div className="flex-shrink-0">
        <div className="sticky top-0 z-40">
          <PaymentAlert />
        </div>
        <div className="sticky top-0 z-30 bg-background">
          <ConsoleHeader
            onMenuClick={sidebar?.onMenuClick}
            projectId={header?.projectId}
            onCommandCenterOpen={header?.onCommandCenterOpen}
            onCreateOrganization={header?.onCreateOrganization}
          />
        </div>
      </div>

      {/* Mobile sidebar overlay */}
      {sidebar?.mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/60"
          onClick={sidebar.onMobileClose}
        />
      )}

      {/* Sidebar + Content below header */}
      <div className="flex flex-1 min-h-0 overflow-hidden">
        {/* Sidebar - only render if sidebar config provided */}
        {sidebar && (
          <ConsoleSidebar
            projectId={sidebar.projectId}
            activeSection={sidebar.activeSection}
            mobileOpen={sidebar.mobileOpen}
            onMobileClose={sidebar.onMobileClose}
          />
        )}

        {/* Main content area */}
        <main
          className={cn(
            'flex-1 bg-background flex flex-col min-h-0',
            fixedLayout ? 'overflow-hidden' : 'overflow-y-auto',
          )}
        >
          <div className={cn('flex-1', fixedLayout && 'min-h-0')}>
            <div
              className={cn('h-full', fixedLayout && 'min-h-0 flex flex-col')}
            >
              {children}
            </div>
          </div>
          <ConsoleBanner />
          {showFooter && <ConsoleFooter />}
        </main>
      </div>
    </div>
  )
}
