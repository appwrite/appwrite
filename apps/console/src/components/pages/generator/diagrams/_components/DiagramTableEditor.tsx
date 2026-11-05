import { Minus, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  DIAGRAM_TABLE_MAX_COLUMNS,
  DIAGRAM_TABLE_MAX_ROWS,
  DIAGRAM_TABLE_MIN_COLUMNS,
  DIAGRAM_TABLE_MIN_ROWS,
  resizeDiagramTableColumns,
  resizeDiagramTableRows,
  updateDiagramTableCell,
} from '@/lib/diagram-generator/diagram-table'
import type { DiagramNode } from '@/lib/diagram-generator/types'

type DiagramTableEditorProps = {
  headers: string[]
  rows: string[][]
  onChange: (patch: Pick<DiagramNode, 'tableHeaders' | 'tableRows'>) => void
}

export function DiagramTableEditor({
  headers,
  rows,
  onChange,
}: DiagramTableEditorProps) {
  const columnCount = headers.length
  const rowCount = rows.length

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <Label className="text-[12px]">Columns</Label>
        <div className="flex items-center gap-1.5">
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="size-7"
            disabled={columnCount <= DIAGRAM_TABLE_MIN_COLUMNS}
            aria-label="Remove column"
            onClick={() =>
              onChange(resizeDiagramTableColumns(headers, rows, columnCount - 1))
            }
          >
            <Minus className="size-3.5" />
          </Button>
          <span className="w-6 text-center text-[12px] tabular-nums text-muted-foreground">
            {columnCount}
          </span>
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="size-7"
            disabled={columnCount >= DIAGRAM_TABLE_MAX_COLUMNS}
            aria-label="Add column"
            onClick={() =>
              onChange(resizeDiagramTableColumns(headers, rows, columnCount + 1))
            }
          >
            <Plus className="size-3.5" />
          </Button>
        </div>
      </div>

      <div className="flex items-center justify-between gap-2">
        <Label className="text-[12px]">Rows</Label>
        <div className="flex items-center gap-1.5">
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="size-7"
            disabled={rowCount <= DIAGRAM_TABLE_MIN_ROWS}
            aria-label="Remove row"
            onClick={() =>
              onChange(resizeDiagramTableRows(headers, rows, rowCount - 1))
            }
          >
            <Minus className="size-3.5" />
          </Button>
          <span className="w-6 text-center text-[12px] tabular-nums text-muted-foreground">
            {rowCount}
          </span>
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="size-7"
            disabled={rowCount >= DIAGRAM_TABLE_MAX_ROWS}
            aria-label="Add row"
            onClick={() =>
              onChange(resizeDiagramTableRows(headers, rows, rowCount + 1))
            }
          >
            <Plus className="size-3.5" />
          </Button>
        </div>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table
          className="w-max min-w-full border-collapse text-[12px]"
          style={{ minWidth: columnCount * 120 }}
        >
          <thead>
            <tr className="border-b border-border bg-muted/30">
              {headers.map((header, columnIndex) => (
                <th
                  key={columnIndex}
                  className="min-w-[120px] px-2 py-1.5 text-start font-medium"
                >
                  <Input
                    value={header}
                    maxLength={48}
                    className="h-7 min-w-[104px] border-0 bg-transparent px-1 text-[11px] shadow-none focus-visible:ring-1"
                    onChange={(event) =>
                      onChange(
                        updateDiagramTableCell(
                          headers,
                          rows,
                          -1,
                          columnIndex,
                          event.target.value,
                        ),
                      )
                    }
                  />
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, rowIndex) => (
              <tr key={rowIndex} className="border-b border-border last:border-b-0">
                {row.map((cell, columnIndex) => (
                  <td key={columnIndex} className="min-w-[120px] px-2 py-1">
                    <Input
                      value={cell}
                      maxLength={48}
                      className="h-7 min-w-[104px] border-0 bg-transparent px-1 text-[11px] shadow-none focus-visible:ring-1"
                      onChange={(event) =>
                        onChange(
                          updateDiagramTableCell(
                            headers,
                            rows,
                            rowIndex,
                            columnIndex,
                            event.target.value,
                          ),
                        )
                      }
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
