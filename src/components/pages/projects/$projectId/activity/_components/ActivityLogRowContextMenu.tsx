import {
  Copy,
  ExternalLink,
  FileJson,
  LayoutList,
  Link2,
  Square,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { formatActivityEventJson } from '@/components/pages/projects/$projectId/activity/activity-utils'

interface ActivityLogRowContextMenuProps {
  projectId: string
  event: Models.ActivityEvent
  /** Opens the activity detail drawer (same as row activate). */
  onOpenDetails: () => void
  children: React.ReactNode
}

function activityPermalink(projectId: string, eventId: string) {
  const path = `/projects/${projectId}/activity?event=${encodeURIComponent(eventId)}`
  return buildConsoleUrl(path)
}

export function ActivityLogRowContextMenu({
  projectId,
  event,
  onOpenDetails,
  children,
}: ActivityLogRowContextMenuProps) {
  if (!event?.$id) {
    return <>{children}</>
  }

  const eventHref = activityPermalink(projectId, event.$id)
  const hasName = !!event.actorName?.trim()

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem onSelect={() => onOpenDetails()}>
          <ContextMenuIcon icon={LayoutList} />
          Overview
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            Copy
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem onSelect={() => copyToClipboard('ID', event.$id)}>
              <ContextMenuIcon icon={Copy} />
              Copy ID
            </ContextMenuItem>
            {hasName && (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', event.actorName)}
              >
                <ContextMenuIcon icon={Copy} />
                Copy name
              </ContextMenuItem>
            )}
            <ContextMenuItem onSelect={() => copyToClipboard('Link', eventHref)}>
              <ContextMenuIcon icon={Link2} />
              Copy link
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard('JSON', formatActivityEventJson(event))
              }
            >
              <ContextMenuIcon icon={FileJson} />
              Copy as JSON
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(eventHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          Open in new tab
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(eventHref)}>
          <ContextMenuIcon icon={Square} />
          Open in new window
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
