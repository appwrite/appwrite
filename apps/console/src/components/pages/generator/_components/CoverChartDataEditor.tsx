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
  getCoverChartLabelKey,
  getCoverChartPoints,
  getCoverChartValueKey,
  normalizeCoverBarChartData,
  normalizeCoverLineChartData,
} from '@/lib/cover-generator/chart/constants'
import type { CoverRenderData } from '@/lib/cover-generator/types'

type CoverChartDataEditorProps = {
  data: Extract<CoverRenderData, { template: 'bar-chart' | 'line-chart' }>
  onChange: (next: CoverRenderData) => void
}

function setChartField(
  data: Extract<CoverRenderData, { template: 'bar-chart' | 'line-chart' }>,
  key: string,
  value: string,
): CoverRenderData {
  return { ...data, [key]: value } as CoverRenderData
}

export function CoverChartDataEditor({ data, onChange }: CoverChartDataEditorProps) {
  const normalized = useMemo(() => {
    return data.template === 'bar-chart'
      ? normalizeCoverBarChartData(data)
      : normalizeCoverLineChartData(data)
  }, [data])

  const points = useMemo(() => getCoverChartPoints(normalized), [normalized])

  return (
    <div className="space-y-2">
      <div>
        <Label className="text-[13px]">Chart data</Label>
        <p className="mt-1 text-[12px] text-muted-foreground">
          Edit labels and values for each data point.
        </p>
      </div>

      <div className="overflow-hidden rounded-lg border border-border">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-3 py-2 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                Label
              </TableHead>
              <TableHead className="px-3 py-2 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                Value
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {points.map((point, index) => (
              <TableRow
                key={`chart-point-${index}`}
                className="hover:bg-transparent border-b border-border last:border-b-0"
              >
                <TableCell className="px-3 py-2">
                  <Input
                    value={point.label}
                    onChange={(event) => {
                      onChange(
                        setChartField(
                          data,
                          getCoverChartLabelKey(index),
                          event.target.value,
                        ),
                      )
                    }}
                    placeholder={`Point ${index + 1}`}
                    className="h-8 text-[13px]"
                  />
                </TableCell>
                <TableCell className="px-3 py-2">
                  <Input
                    type="number"
                    min={0}
                    value={point.value}
                    onChange={(event) => {
                      onChange(
                        setChartField(
                          data,
                          getCoverChartValueKey(index),
                          event.target.value,
                        ),
                      )
                    }}
                    placeholder="0"
                    className="h-8 text-[13px]"
                  />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
