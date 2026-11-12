import { Link, Outlet, useLocation, useParams } from '@tanstack/react-router'
import { ChevronLeft } from 'lucide-react'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { useT } from '@/lib/i18n/translate'
import { TableViewResizableLayout } from '../../databases/_components/TableViewResizableLayout'
import { VideosSidebar } from './VideosSidebar'
import { VideoInspectorProvider } from './player/VideoInspectorContext'

export const VIDEOS_DESKTOP_MIN_WIDTH_PX = 1024

/**
 * Storage-style workspace: resizable videos list + main outlet (video detail,
 * encoding profiles). On small screens the list is its own page.
 */
export function WorkspaceLayout() {
  return (
    <VideoInspectorProvider>
      <WorkspaceContent />
    </VideoInspectorProvider>
  )
}

function WorkspaceContent() {
  const t = useT()
  const showDesktopSidebar = useMediaMinWidth(VIDEOS_DESKTOP_MIN_WIDTH_PX)
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId?: string
  }
  const location = useLocation()
  const onProfiles = location.pathname.endsWith('/videos/profiles')

  if (showDesktopSidebar) {
    return (
      <div className="@container flex h-full min-h-0 min-w-0 flex-col">
        <TableViewResizableLayout
          sidebar={<VideosSidebar />}
          sidebarWidthScope="videos"
        >
          <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden bg-background">
            <Outlet />
          </div>
        </TableViewResizableLayout>
      </div>
    )
  }

  if (!videoId && !onProfiles) {
    return (
      <div className="@container flex h-full min-h-0 min-w-0 flex-col bg-background">
        <VideosSidebar />
      </div>
    )
  }

  return (
    <div className="@container flex h-full min-h-0 min-w-0 flex-col overflow-hidden bg-background">
      <div className="flex h-11 shrink-0 items-center border-b border-border px-3">
        <Link
          to="/projects/$projectId/videos"
          params={{ projectId }}
          className="flex items-center gap-1 text-[13px] font-medium text-muted-foreground hover:text-foreground"
        >
          <ChevronLeft className="h-4 w-4 rtl:rotate-180" />
          {t('All videos')}
        </Link>
      </div>
      <div className="min-h-0 flex-1 overflow-hidden">
        <Outlet />
      </div>
    </div>
  )
}
