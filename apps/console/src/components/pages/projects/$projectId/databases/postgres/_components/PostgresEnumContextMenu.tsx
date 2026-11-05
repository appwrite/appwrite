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
import type { PostgresSchemaEnumRow } from '@/lib/postgres-sql'
import { useT } from '@/lib/i18n/translate'

interface PostgresEnumContextMenuProps {
  enumRow: PostgresSchemaEnumRow
  canWrite?: boolean
  onUpdate: (enumRow: PostgresSchemaEnumRow) => void
  onDelete: (enumName: string) => void
  children: React.ReactNode
}

export function PostgresEnumContextMenu({
  enumRow,
  canWrite = true,
  onUpdate,
  onDelete,
  children,
}: PostgresEnumContextMenuProps) {
  const t = useT()
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {canWrite ? (
          <ContextMenuItem
            onSelect={() =>
              openDialogAfterOverlayCloses(() => onUpdate(enumRow))
            }
          >
            <ContextMenuIcon icon={Pencil} />
            {t('Update')}
          </ContextMenuItem>
        ) : null}
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Name', enumRow.enum_name)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => enumRow, { fallback: enumRow })
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
                openDialogAfterOverlayCloses(() => onDelete(enumRow.enum_name))
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
