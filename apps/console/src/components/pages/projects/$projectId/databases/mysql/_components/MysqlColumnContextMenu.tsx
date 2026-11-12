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
import { Copy, FileJson, Pencil, Trash2 } from 'lucide-react'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import type { MysqlTableColumnRow } from '@/lib/mysql-sql'
import { useT } from '@/lib/i18n/translate'

interface MysqlColumnContextMenuProps {
  column: MysqlTableColumnRow
  isPrimary: boolean
  canWrite?: boolean
  onUpdate: (column: MysqlTableColumnRow) => void
  onDelete?: (columnName: string) => void
  children: React.ReactNode
}

export function MysqlColumnContextMenu({
  column,
  isPrimary,
  canWrite = true,
  onUpdate,
  onDelete,
  children,
}: MysqlColumnContextMenuProps) {
  const t = useT()
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {canWrite ? (
          <>
            <ContextMenuItem
              onSelect={() =>
                openDialogAfterOverlayCloses(() => onUpdate(column))
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
              onSelect={() =>
                copyToClipboard('Name', column.column_name)
              }
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            {column.column_default ? (
              <ContextMenuItem
                onSelect={() =>
                  copyToClipboard('Value', String(column.column_default))
                }
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy value')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => column, { fallback: column })
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        {!isPrimary && onDelete && canWrite ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem
              onSelect={() =>
                openDialogAfterOverlayCloses(() =>
                  onDelete(column.column_name),
                )
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
