import { cn } from '@/lib/utils'

export function splitMetricAmountAndUnit(value: string): {
  amount: string
  unit: string | null
} {
  const trimmed = value.trim()
  if (!trimmed || trimmed === 'N/A') {
    return { amount: trimmed, unit: null }
  }

  const match = trimmed.match(/^([\d,]+(?:\.\d+)?(?:k)?)([A-Za-z]+)$/)
  if (!match) {
    return { amount: trimmed, unit: null }
  }

  return { amount: match[1], unit: match[2] }
}

interface MetricValueWithUnitProps {
  value: string
  className?: string
  amountClassName?: string
  unitClassName?: string
}

export function MetricValueWithUnit({
  value,
  className,
  amountClassName,
  unitClassName,
}: MetricValueWithUnitProps) {
  const { amount, unit } = splitMetricAmountAndUnit(value)

  return (
    <span className={cn('inline-flex items-baseline gap-1', className)}>
      <span className={amountClassName}>{amount}</span>
      {unit ? (
        <span
          className={cn(
            'text-[0.62em] font-medium leading-none tracking-normal text-muted-foreground',
            unitClassName,
          )}
        >
          {unit}
        </span>
      ) : null}
    </span>
  )
}
