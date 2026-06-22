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
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Pencil,
  Square,
  Trash2,
} from 'lucide-react'
import { fetchProjectWebhook } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

interface WebhookContextMenuProps {
  projectId: string
  webhook: Models.Webhook
  onUpdate: (webhook: Models.Webhook) => void
  onDelete: (webhook: Models.Webhook) => void
  children: React.ReactNode
}

export function WebhookContextMenu({
  projectId,
  webhook,
  onUpdate,
  onDelete,
  children,
}: WebhookContextMenuProps) {
  const webhookHref = buildConsoleUrl(
    `/projects/${projectId}/settings/webhooks?webhookId=${webhook.$id}`,
  )

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem onSelect={() => onUpdate(webhook)}>
          <ContextMenuIcon icon={Pencil} />
          Update
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            Copy
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', webhook.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              Copy ID
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Name', webhook.name)}
            >
              <ContextMenuIcon icon={Copy} />
              Copy name
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', webhookHref)}
            >
              <ContextMenuIcon icon={Link2} />
              Copy link
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchProjectWebhook(projectId, webhook.$id),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              Copy as JSON
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(webhookHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          Open in new tab
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(webhookHref)}>
          <ContextMenuIcon icon={Square} />
          Open in new window
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => onDelete(webhook)}>
          <ContextMenuIcon icon={Trash2} />
          Delete
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
