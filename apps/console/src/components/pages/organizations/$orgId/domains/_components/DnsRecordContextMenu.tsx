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
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

interface DnsRecordContextMenuProps {
  orgId: string
  domainId: string
  record: Models.DnsRecord
  nameValue: string
  value: string
  locked?: boolean
  onUpdate: (record: Models.DnsRecord) => void
  onDelete: (record: Models.DnsRecord) => void
  children: React.ReactNode
}

export function DnsRecordContextMenu({
  orgId,
  domainId,
  record,
  nameValue,
  value,
  locked = false,
  onUpdate,
  onDelete,
  children,
}: DnsRecordContextMenuProps) {
  const t = useT()
  const recordHref = buildConsoleUrl(
    `/organizations/${orgId}/domains/${domainId}`,
  )

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {!locked ? (
          <>
            <ContextMenuItem
              onSelect={() =>
                openDialogAfterOverlayCloses(() => onUpdate(record))
              }
            >
              <ContextMenuIcon icon={Pencil} />
              {t('Update')}
            </ContextMenuItem>
            <ContextMenuSeparator />
          </>
        ) : null}
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', record.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Name', nameValue)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            {value ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Value', value)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy value')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', recordHref)}
            >
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => record, { fallback: record })
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(recordHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(recordHref)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        {!locked ? (
          <ContextMenuItem
            onSelect={() =>
              openDialogAfterOverlayCloses(() => onDelete(record))
            }
          >
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}
