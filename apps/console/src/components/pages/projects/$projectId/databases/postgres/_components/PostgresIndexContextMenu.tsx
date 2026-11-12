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
import { Copy, FileJson, Trash2 } from 'lucide-react'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import type { PostgresTableIndexRow } from '@/lib/postgres-sql'
import { useT } from '@/lib/i18n/translate'

interface PostgresIndexContextMenuProps {
  index: PostgresTableIndexRow
  canWrite?: boolean
  onDelete: (indexName: string) => void
  children: React.ReactNode
}

export function PostgresIndexContextMenu({
  index,
  canWrite = true,
  onDelete,
  children,
}: PostgresIndexContextMenuProps) {
  const t = useT()
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Name', index.index_name)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => index, { fallback: index })
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        {canWrite ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem
              onSelect={() =>
                openDialogAfterOverlayCloses(() => onDelete(index.index_name))
              }
            >
              <ContextMenuIcon icon={Trash2} />
              {t('Delete')}
            </ContextMenuItem>
          </>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}
