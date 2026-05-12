import { Outlet } from '@tanstack/react-router'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { TableViewResizableLayout } from '../../databases/_components/TableViewResizableLayout'
import { BucketsSidebar } from './BucketsSidebar'
import { MobileBucketSelector } from './MobileBucketSelector'

/**
 * Database-style workspace: resizable bucket list + main outlet (files, settings, …).
 */
export function WorkspaceLayout() {
  const showDesktopSidebar = useMediaMinWidth(1024)

  return (
    <div className="@container flex h-full min-h-0 min-w-0">
      {showDesktopSidebar ? (
        <TableViewResizableLayout sidebar={<BucketsSidebar />}>
          <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden bg-background">
            <Outlet />
          </div>
        </TableViewResizableLayout>
      ) : (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-background">
          <MobileBucketSelector />
          <div className="min-h-0 flex-1 overflow-hidden">
            <Outlet />
          </div>
        </div>
      )}
    </div>
  )
}
