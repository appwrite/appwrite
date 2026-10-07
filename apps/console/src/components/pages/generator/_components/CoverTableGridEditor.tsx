import { useMemo } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  getCoverTableCellKey,
  getCoverTableHeaderKey,
  getCoverTableMatrix,
  normalizeCoverTableData,
} from '@/lib/cover-generator/table/constants'
import type { CoverRenderData } from '@/lib/cover-generator/types'

type CoverTableGridEditorProps = {
  data: Extract<CoverRenderData, { template: 'table' }>
  onChange: (next: CoverRenderData) => void
}

function setTableField(
  data: Extract<CoverRenderData, { template: 'table' }>,
  key: string,
  value: string,
): CoverRenderData {
  return { ...data, [key]: value } as CoverRenderData
}

export function CoverTableGridEditor({ data, onChange }: CoverTableGridEditorProps) {
  const normalized = useMemo(() => normalizeCoverTableData(data), [data])
  const matrix = useMemo(() => getCoverTableMatrix(normalized), [normalized])

  return (
    <div className="space-y-2">
      <div>
        <Label className="text-[13px]">Table data</Label>
        <p className="mt-1 text-[12px] text-muted-foreground">
          Edit headers and cell values. The layout matches the cover table.
        </p>
      </div>

      <div className="overflow-hidden rounded-lg border border-border">
        <Table>
          {normalized.showHeader ? (
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                {matrix.headers.map((header, col) => (
                  <TableHead
                    key={getCoverTableHeaderKey(col)}
                    className="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground"
                  >
                    <Input
                      value={header}
                      onChange={(event) => {
                        onChange(
                          setTableField(
                            data,
                            getCoverTableHeaderKey(col),
                            event.target.value,
                          ),
                        )
                      }}
                      placeholder={`Column ${col + 1}`}
                      className="h-8 border-0 bg-transparent px-0 text-[11px] font-semibold uppercase tracking-wider shadow-none focus-visible:ring-0"
                    />
                  </TableHead>
                ))}
              </TableRow>
            </TableHeader>
          ) : null}
          <TableBody>
            {matrix.rows.map((row, rowIndex) => (
              <TableRow
                key={`row-${rowIndex}`}
                className="hover:bg-transparent border-b border-border last:border-b-0"
              >
                {row.map((cell, colIndex) => (
                  <TableCell key={getCoverTableCellKey(rowIndex, colIndex)} className="px-3 py-2">
                    <Input
                      value={cell}
                      onChange={(event) => {
                        onChange(
                          setTableField(
                            data,
                            getCoverTableCellKey(rowIndex, colIndex),
                            event.target.value,
                          ),
                        )
                      }}
                      placeholder={colIndex === 0 ? `Row ${rowIndex + 1}` : ''}
                      className="h-8 text-[13px]"
                    />
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
