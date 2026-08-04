import { ReactNode, useState, useEffect } from 'react'
import { ConsoleHeader } from './Header'
import { ConsoleSidebar } from './Sidebar'
import { ConsoleFooter } from './Footer'
import { CloudStatusBanner } from './CloudStatusBanner'
import { ConsoleImpersonationBanner } from '@/components/global/shared/ConsoleImpersonationBanner'
import { NetworkOfflineCurtain } from '@/components/global/shared/NetworkOfflineCurtain'
import { SkipToContent } from './SkipToContent'
import { NativeAppBar } from './NativeAppBar'
import { cn } from '@/lib/utils'
import {
  loadDebugOverrides,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'

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

  /** Optional left sidebar before main content (e.g. init online participants panel) */
  leftSidebar?: {
    mobileOpen: boolean
    onMobileClose: () => void
    onMenuClick: () => void
    content: ReactNode
  }

  /** ConsoleHeader props */
  header?: {
    projectId?: string
    onCommandCenterOpen?: () => void
    onCreateOrganization?: () => void
    marketingNav?: boolean
    headerTitleSuffix?: string
    centerSearch?: boolean
    centerSearchPlaceholder?: string
  }

  /** Optional strip below impersonation / cloud status, above the main header bar (e.g. {@link HeaderAlertBar}) */
  headerBanner?: ReactNode

  /** Whether to show the footer */
  showFooter?: boolean

  /** Optional footer configuration */
  footer?: {
    expanded?: boolean
  }

  /** Whether the main content should have overflow-hidden (for views that manage their own scrolling) */
  fixedLayout?: boolean

  /** Hides the global app header bar (logo, nav, account). Full-screen tool views. */
  hideHeader?: boolean

  /** Custom container class name */
  containerClassName?: string

  /** Optional panel docked to the bottom of the main column (pushes content up) */
  bottomPanel?: ReactNode
}

/**
 * ConsoleLayout - Reusable layout component for all console pages
 *
 * Provides consistent layout structure with:
 * - Sticky header (ConsoleHeader)
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
  leftSidebar,
  header,
  headerBanner,
  showFooter = true,
  footer,
  fixedLayout = false,
  hideHeader = false,
  containerClassName,
  bottomPanel,
}: ConsoleLayoutProps) {
  const hasSidebar = !!sidebar
  const hasLeftSidebar = !!leftSidebar
  const usesSplitMain = fixedLayout || !!bottomPanel
  const layoutContainerClass =
    containerClassName ||
    (hasSidebar || hasLeftSidebar
      ? 'project-layout-container'
      : 'org-layout-container')

  const [overrides, setOverrides] = useState(loadDebugOverrides)
  useEffect(() => {
    return subscribeToDebugOverrides(setOverrides)
  }, [])

  const showNativeAppBar = overrides.showNativeAppBar
  const showAppHeader = !hideHeader

  return (
    <div
      className={cn('flex h-full flex-col bg-background', layoutContainerClass)}
    >
      {/* Sticky header section - takes space in flex layout */}
      {/* z-[110]: above PausedProjectCurtain (z-100) so alerts / exit impersonation stay reachable */}
      {(showNativeAppBar ||
        showAppHeader ||
        headerBanner) && (
        <div className="sticky top-0 z-[110] flex shrink-0 flex-col overflow-visible bg-background">
          {showNativeAppBar && <NativeAppBar />}
          <CloudStatusBanner />
          <ConsoleImpersonationBanner />
          {headerBanner}
          {showAppHeader ? (
            <ConsoleHeader
              onMenuClick={sidebar?.onMenuClick ?? leftSidebar?.onMenuClick}
              projectId={header?.projectId}
              onCommandCenterOpen={header?.onCommandCenterOpen}
              onCreateOrganization={header?.onCreateOrganization}
              marketingNav={header?.marketingNav}
              headerTitleSuffix={header?.headerTitleSuffix}
              centerSearch={header?.centerSearch}
              centerSearchPlaceholder={header?.centerSearchPlaceholder}
              hideSearch={showNativeAppBar}
            />
          ) : null}
        </div>
      )}
      {/* After sticky header in DOM: same z-[110] stacks the skip link above the bar; Radix dialogs portaled after #root still cover it at z-[110]. */}
      <SkipToContent />

      {/* Mobile sidebar overlay */}
      {(sidebar?.mobileOpen || leftSidebar?.mobileOpen) && (
        <div
          className="fixed inset-0 z-[120] h-[100dvh] max-h-[100dvh] w-full bg-black/60"
          onClick={() => {
            if (sidebar?.mobileOpen) sidebar.onMobileClose()
            else leftSidebar?.onMobileClose()
          }}
        />
      )}

      {/* Sidebar + content below header */}
      <div className="@container flex flex-1 min-h-0 overflow-x-visible overflow-y-hidden">
        {sidebar && (
          <ConsoleSidebar
            projectId={sidebar.projectId}
            activeSection={sidebar.activeSection}
            mobileOpen={sidebar.mobileOpen}
            onMobileClose={sidebar.onMobileClose}
          />
        )}

        {leftSidebar?.content}

        <main
          id="main-content"
          tabIndex={-1}
          className={cn(
            // Content-width container for resource grids / toolbars. Nearest
            // `@container` for main descendants so queries ignore sidebar width
            // (unlike the layout-row `@container` on the flex parent above).
            '@container flex min-h-0 min-w-0 flex-1 flex-col bg-background outline-none',
            usesSplitMain ? 'overflow-hidden' : 'overflow-x-hidden overflow-y-auto',
          )}
        >
          <div
            className={cn(
              'flex-1',
              usesSplitMain && 'min-h-0',
              usesSplitMain &&
                (fixedLayout ? 'overflow-hidden' : 'overflow-y-auto'),
              fixedLayout && 'flex flex-col',
            )}
          >
            <div
              className={cn(
                usesSplitMain && 'h-full',
                fixedLayout && 'min-h-0 flex flex-col',
              )}
            >
              {children}
            </div>
          </div>
          {bottomPanel}
          {showFooter && <ConsoleFooter expanded={footer?.expanded} />}
        </main>
      </div>

      <NetworkOfflineCurtain />
    </div>
  )
}
