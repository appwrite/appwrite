import type { ReactNode } from 'react'
import { Copy, Expand } from 'lucide-react'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  copySpreadsheetCellValue,
  isSpreadsheetCellValueTrimmed,
} from '@/lib/spreadsheet-cell-formatting'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'

type SpreadsheetCellContextMenuProps = {
  value: unknown
  full: string
  display: string
  isNull: boolean
  onViewFullValue: () => void
  children: ReactNode
}

export function SpreadsheetCellContextMenu({
  value,
  full,
  display,
  isNull,
  onViewFullValue,
  children,
}: SpreadsheetCellContextMenuProps) {
  const t = useT()
  const showViewFullValue =
    !isNull && (isSpreadsheetCellValueTrimmed(full, display) || full.length > 50)

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-52">
        <ContextMenuItem
          onSelect={() =>
            void copyToClipboard('Value', copySpreadsheetCellValue(value))
          }
        >
          <ContextMenuIcon icon={Copy} />
          {t('Copy value')}
        </ContextMenuItem>
        {showViewFullValue ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem onSelect={onViewFullValue}>
              <ContextMenuIcon icon={Expand} />
              {t('View full value')}
            </ContextMenuItem>
          </>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}

export type SpreadsheetCellValueDialogState = {
  columnLabel: string
  rowNumber: number
  value: unknown
  full: string
}
