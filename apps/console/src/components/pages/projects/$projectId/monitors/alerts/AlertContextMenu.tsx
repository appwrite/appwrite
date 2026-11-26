import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
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
  Check,
  Copy,
  ExternalLink,
  Eye,
  FileJson,
  Gauge,
  Link2,
  Square,
  Undo2,
} from 'lucide-react'
import type { Alert } from '@/lib/appwrite/monitors'
import { fetchAlert, useUpdateAlert } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'

interface AlertContextMenuProps {
  projectId: string
  alert: Alert
  onOpen: (alert: Alert) => void
  children: React.ReactNode
}

/** Right-click actions on an alert in the feed. */
export function AlertContextMenu({
  projectId,
  alert,
  onOpen,
  children,
}: AlertContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const update = useUpdateAlert(projectId)
  const href = buildConsoleUrl(
    `/projects/${projectId}/monitors/alerts?alert=${alert.$id}`,
  )
  // Servers whose alerts carry no acknowledgement leave the action out, as the drawer does.
  const acknowledgeable = 'acknowledgedAt' in alert && alert.status === 'firing'
  const acknowledged = !!alert.acknowledgedAt

  const toggleAcknowledgement = () =>
    update.mutate(
      { alertId: alert.$id, acknowledged: !acknowledged },
      {
        onError: (error) =>
          toast.error(
            getErrorMessage(
              error,
              acknowledged
                ? t('Failed to undo the acknowledgement')
                : t('Failed to acknowledge the alert'),
            ),
          ),
      },
    )

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          onSelect={() => openDialogAfterOverlayCloses(() => onOpen(alert))}
        >
          <ContextMenuIcon icon={Eye} />
          {t('Open')}
        </ContextMenuItem>
        {acknowledgeable && (
          <ContextMenuItem onSelect={toggleAcknowledgement}>
            <ContextMenuIcon icon={acknowledged ? Undo2 : Check} />
            {acknowledged ? t('Undo acknowledgement') : t('Acknowledge')}
          </ContextMenuItem>
        )}
        <ContextMenuItem
          onSelect={() =>
            void navigate({
              to: '/projects/$projectId/monitors/$monitorId',
              params: { projectId, monitorId: alert.monitorId },
            })
          }
        >
          <ContextMenuIcon icon={Gauge} />
          {t('View monitor')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem onSelect={() => copyToClipboard('ID', alert.$id)}>
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Name', alert.name)}
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
                void copyResourceAsJson(() => fetchAlert(projectId, alert.$id))
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
      </ContextMenuContent>
    </ContextMenu>
  )
}
