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
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Pause,
  Pencil,
  Play,
  Square,
  Trash2,
} from 'lucide-react'
import type { ParsedMonitor } from '@/lib/appwrite/monitors'
import { fetchMonitor } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'

interface MonitorContextMenuProps {
  projectId: string
  monitor: ParsedMonitor
  onUpdate: (monitor: ParsedMonitor) => void
  onToggle: (monitor: ParsedMonitor) => void
  onDelete: (monitor: ParsedMonitor) => void
  /** Hides the actions that change the monitor, for members who cannot. */
  readOnly?: boolean
  children: React.ReactNode
}

/** Right-click actions on a monitor row, alongside its row menu. */
export function MonitorContextMenu({
  projectId,
  monitor,
  onUpdate,
  onToggle,
  onDelete,
  readOnly,
  children,
}: MonitorContextMenuProps) {
  const t = useT()
  const href = buildConsoleUrl(`/projects/${projectId}/monitors/${monitor.$id}`)

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          onSelect={() => openDialogAfterOverlayCloses(() => onUpdate(monitor))}
        >
          <ContextMenuIcon icon={Pencil} />
          {readOnly ? t('Open') : t('Update')}
        </ContextMenuItem>
        {!readOnly && (
          <ContextMenuItem onSelect={() => onToggle(monitor)}>
            <ContextMenuIcon icon={monitor.enabled ? Pause : Play} />
            {monitor.enabled ? t('Pause') : t('Resume')}
          </ContextMenuItem>
        )}
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', monitor.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Name', monitor.name)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            <ContextMenuItem onSelect={() => copyToClipboard('Link', href)}>
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchMonitor(projectId, monitor.$id),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(href)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(href)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>
        {!readOnly && (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem
              onSelect={() =>
                openDialogAfterOverlayCloses(() => onDelete(monitor))
              }
            >
              <ContextMenuIcon icon={Trash2} />
              {t('Delete')}
            </ContextMenuItem>
          </>
        )}
      </ContextMenuContent>
    </ContextMenu>
  )
}
