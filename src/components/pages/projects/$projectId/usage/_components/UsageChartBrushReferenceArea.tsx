'use client'

import { ReferenceArea } from 'recharts'

type UsageChartBrushReferenceAreaProps = {
  left: number | null
  right: number | null
}

/** Highlight for an in-progress drag selection on usage time-series charts. */
export function UsageChartBrushReferenceArea({
  left,
  right,
}: UsageChartBrushReferenceAreaProps) {
  if (left == null || right == null || left === right) return null

  return (
    <ReferenceArea
      x1={left}
      x2={right}
      stroke="var(--chart-brand)"
      strokeOpacity={0.35}
      fill="var(--chart-brand)"
      fillOpacity={0.12}
      ifOverflow="visible"
    />
  )
}
