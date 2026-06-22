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
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'

interface PostgresColumnContextMenuProps {
  column: PostgresTableColumnRow
  isPrimary: boolean
  canWrite?: boolean
  onUpdate: (column: PostgresTableColumnRow) => void
  onDelete?: (columnName: string) => void
  children: React.ReactNode
}

export function PostgresColumnContextMenu({
  column,
  isPrimary,
  canWrite = true,
  onUpdate,
  onDelete,
  children,
}: PostgresColumnContextMenuProps) {
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {canWrite ? (
          <>
            <ContextMenuItem onSelect={() => onUpdate(column)}>
              <ContextMenuIcon icon={Pencil} />
              Update
            </ContextMenuItem>
            <ContextMenuSeparator />
          </>
        ) : null}
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            Copy
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard('Name', column.column_name)
              }
            >
              <ContextMenuIcon icon={Copy} />
              Copy name
            </ContextMenuItem>
            {column.column_default ? (
              <ContextMenuItem
                onSelect={() =>
                  copyToClipboard('Value', String(column.column_default))
                }
              >
                <ContextMenuIcon icon={Copy} />
                Copy value
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => column, { fallback: column })
              }
            >
              <ContextMenuIcon icon={FileJson} />
              Copy as JSON
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        {!isPrimary && onDelete && canWrite ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem
              onSelect={() => onDelete(column.column_name)}
            >
              <ContextMenuIcon icon={Trash2} />
              Delete
            </ContextMenuItem>
          </>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}
