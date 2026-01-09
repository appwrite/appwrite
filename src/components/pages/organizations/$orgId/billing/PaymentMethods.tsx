import { CreditCard, Plus, MoreHorizontal, Building2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { paymentMethods, type PaymentMethod } from '@/lib/utils/mock-data'
import { formatCardExpiry, maskCardNumber } from './utils'
import { cn } from '@/lib/utils'

/**
 * PaymentMethods Component
 *
 * Displays and manages payment methods:
 * - Primary payment method highlighted
 * - Secondary/backup methods listed
 * - Add new payment method action
 * - Update/remove actions per method
 *
 * Props:
 * - onAddPaymentMethod?: () => void - Callback to add new method
 *
 * Edge cases:
 * - No payment methods: Shows add payment method prompt
 * - Expired cards: Shows warning styling
 */

interface PaymentMethodsProps {
  onAddPaymentMethod?: () => void
}

export function PaymentMethods({ onAddPaymentMethod }: PaymentMethodsProps) {
  const primaryMethod = paymentMethods.find((pm) => pm.isPrimary)
  const secondaryMethods = paymentMethods.filter((pm) => !pm.isPrimary)

  if (paymentMethods.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Payment Methods
          </h3>
        </div>
        <div className="border-t border-border px-6 py-8 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <CreditCard className="h-6 w-6 text-muted-foreground" />
          </div>
          <p className="text-[13px] text-muted-foreground mb-4">
            No payment method on file
          </p>
          <Button
            size="sm"
            className="h-9 gap-2 text-[13px]"
            onClick={onAddPaymentMethod}
          >
            <Plus className="h-4 w-4" />
            Add payment method
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Payment Methods
        </h3>
      </div>

      {/* Primary Payment Method */}
      {primaryMethod && (
        <div className="border-t border-border">
          <PaymentMethodCard method={primaryMethod} />
        </div>
      )}

      {/* Secondary Payment Methods */}
      {secondaryMethods.length > 0 && (
        <div className="border-t border-border">
          <div className="px-6 py-2 bg-muted/30">
            <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              Backup methods
            </p>
          </div>
          <div className="divide-y divide-border">
            {secondaryMethods.map((method) => (
              <PaymentMethodCard key={method.$id} method={method} />
            ))}
          </div>
        </div>
      )}

      {/* Footer */}
      <div className="border-t border-border px-6 py-4 bg-muted/30">
        <Button
          variant="outline"
          size="sm"
          className="h-9 gap-2 text-[13px]"
          onClick={onAddPaymentMethod}
        >
          <Plus className="h-4 w-4" />
          Add payment method
        </Button>
      </div>
    </div>
  )
}

function PaymentMethodCard({ method }: { method: PaymentMethod }) {
  const isExpiringSoon =
    method.expiryMonth && method.expiryYear
      ? isCardExpiringSoon(method.expiryMonth, method.expiryYear)
      : false

  return (
    <div className="flex items-center justify-between px-6 py-4 hover:bg-accent/50 transition-colors">
      <div className="flex items-center gap-4">
        <div
          className={cn(
            'flex h-10 w-10 items-center justify-center rounded-lg',
            method.isPrimary ? 'bg-primary/10' : 'bg-muted',
          )}
        >
          {method.type === 'card' ? (
            <CreditCard
              className={cn(
                'h-5 w-5',
                method.isPrimary ? 'text-primary' : 'text-muted-foreground',
              )}
            />
          ) : method.type === 'bank' ? (
            <Building2
              className={cn(
                'h-5 w-5',
                method.isPrimary ? 'text-primary' : 'text-muted-foreground',
              )}
            />
          ) : (
            <CreditCard
              className={cn(
                'h-5 w-5',
                method.isPrimary ? 'text-primary' : 'text-muted-foreground',
              )}
            />
          )}
        </div>
        <div>
          <div className="flex items-center gap-2">
            <p className="text-[13px] font-medium text-foreground">
              {method.type === 'card' && method.brand}{' '}
              {method.type === 'card' && maskCardNumber(method.last4 || '')}
              {method.type === 'bank' && method.bankName}
              {method.type === 'paypal' && method.email}
            </p>
            {method.isPrimary && (
              <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                Primary
              </span>
            )}
            {isExpiringSoon && (
              <span className="rounded-full bg-yellow-500/10 px-2 py-0.5 text-[10px] font-medium text-yellow-600 dark:text-yellow-400">
                Expiring soon
              </span>
            )}
          </div>
          {method.type === 'card' &&
            method.expiryMonth &&
            method.expiryYear && (
              <p className="text-[12px] text-muted-foreground">
                Expires{' '}
                {formatCardExpiry(method.expiryMonth, method.expiryYear)}
              </p>
            )}
        </div>
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
          >
            <MoreHorizontal className="h-4 w-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-40">
          {!method.isPrimary && (
            <DropdownMenuItem className="text-[13px]">
              Set as primary
            </DropdownMenuItem>
          )}
          <DropdownMenuItem className="text-[13px]">Update</DropdownMenuItem>
          <DropdownMenuItem className="text-[13px] text-red-600 dark:text-red-400">
            Remove
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}

function isCardExpiringSoon(month: number, year: number): boolean {
  const now = new Date()
  const expiryDate = new Date(year, month - 1)
  const threeMonthsFromNow = new Date()
  threeMonthsFromNow.setMonth(threeMonthsFromNow.getMonth() + 3)
  return expiryDate <= threeMonthsFromNow && expiryDate >= now
}
