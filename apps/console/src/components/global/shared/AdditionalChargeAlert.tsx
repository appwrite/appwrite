import { Alert, AlertDescription } from '@/components/ui/alert'
import { Info } from 'lucide-react'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'

interface AdditionalChargeAlertProps {
  /** Singular resource label, e.g. "project" or "member" */
  resourceLabel: string
  pricePerMonth: number
  currency?: string
  /** When true, copy refers to each additional unit (e.g. per member). */
  perUnit?: boolean
}

export function AdditionalChargeAlert({
  resourceLabel,
  pricePerMonth,
  currency = 'USD',
  perUnit = false,
}: AdditionalChargeAlertProps) {
  const formattedPrice = formatCurrency(pricePerMonth, currency)

  return (
    <Alert>
      <Info className="h-4 w-4" />
      <AlertDescription className="text-[12px]">
        <p>
          {perUnit ? (
            <>
              Each additional {resourceLabel} will incur a charge of{' '}
              <span className="font-medium text-foreground">
                {formattedPrice} per month
              </span>
              .
            </>
          ) : (
            <>
              This {resourceLabel} will incur an additional charge of{' '}
              <span className="font-medium text-foreground">
                {formattedPrice} per month
              </span>
              .
            </>
          )}
        </p>
      </AlertDescription>
    </Alert>
  )
}
