import {
  CreditCard,
  Plus,
  MoreHorizontal,
  Info,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { formatCardExpiry, maskCardNumber } from './utils'
import { cn } from '@/lib/utils'
import {
  useOrganizationById,
  usePaymentMethods,
  usePaymentMethod,
  useUpdateOrganizationPaymentMethod,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

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
 * - orgId?: string - Organization ID
 *
 * Edge cases:
 * - No payment methods: Shows add payment method prompt
 * - Expired cards: Shows warning styling
 * - Failed payment methods: Shows error status
 */

interface PaymentMethodsProps {
  onAddPaymentMethod?: (isBackup?: boolean) => void
  orgId?: string
}

export function PaymentMethods({
  onAddPaymentMethod,
  orgId,
}: PaymentMethodsProps) {
  const { organization } = useOrganizationById(orgId)
  const { paymentMethods: allPaymentMethods, isLoading: methodsLoading } =
    usePaymentMethods()
  const primaryPaymentMethod = usePaymentMethod(organization?.paymentMethodId)
  const backupPaymentMethod = usePaymentMethod(
    organization?.backupPaymentMethodId,
  )
  const updatePaymentMethodMutation = useUpdateOrganizationPaymentMethod()

  // Filter payment methods to only show completed cards (with last4)
  const completedPaymentMethods = allPaymentMethods.filter(
    (pm: Models.PaymentMethod) => pm.last4,
  )

  // Get primary and backup methods
  const primaryMethod = primaryPaymentMethod.paymentMethod
  const backupMethod = backupPaymentMethod.paymentMethod

  // Get available payment methods (not assigned to org)
  const availableMethods = completedPaymentMethods.filter(
    (pm: Models.PaymentMethod) =>
      pm.$id !== organization?.paymentMethodId &&
      pm.$id !== organization?.backupPaymentMethodId,
  )

  const handleSetPrimary = async (paymentMethodId: string) => {
    if (!orgId) return

    try {
      await updatePaymentMethodMutation.mutateAsync({
        organizationId: orgId,
        paymentMethodId,
      })
      toast.success('Primary payment method updated')
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : 'Failed to update payment method',
      )
    }
  }

  const handleSetBackup = async (paymentMethodId: string) => {
    if (!orgId) return

    try {
      await updatePaymentMethodMutation.mutateAsync({
        organizationId: orgId,
        backupPaymentMethodId: paymentMethodId,
      })
      toast.success('Backup payment method updated')
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : 'Failed to update backup payment method',
      )
    }
  }

  const handleRemove = async (isPrimary: boolean) => {
    if (!orgId) return

    // Can't remove if it's the only method and not on free plan
    if (
      isPrimary &&
      !organization?.backupPaymentMethodId &&
      organization?.billingPlan !== 'tier0'
    ) {
      toast.error('Cannot remove the only payment method on a paid plan')
      return
    }

    try {
      if (isPrimary) {
        // Remove primary, promote backup if exists
        await updatePaymentMethodMutation.mutateAsync({
          organizationId: orgId,
          paymentMethodId: organization?.backupPaymentMethodId || undefined,
          backupPaymentMethodId: undefined,
        })
      } else {
        // Remove backup
        await updatePaymentMethodMutation.mutateAsync({
          organizationId: orgId,
          backupPaymentMethodId: undefined,
        })
      }
      toast.success('Payment method removed')
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : 'Failed to remove payment method',
      )
    }
  }

  // Check if any payment method has errors
  const hasErrors =
    primaryMethod?.failed ||
    primaryMethod?.expired ||
    backupMethod?.failed ||
    backupMethod?.expired

  if (methodsLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Payment Methods
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            Loading payment methods...
          </p>
        </div>
      </div>
    )
  }

  if (!primaryMethod && completedPaymentMethods.length === 0) {
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
            onClick={() => onAddPaymentMethod?.()}
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
          <PaymentMethodCard
            method={primaryMethod}
            isPrimary
            orgId={orgId}
            onReplacePrimary={handleSetPrimary}
            onRemove={() => handleRemove(true)}
            availableMethods={availableMethods}
            onAddPaymentMethod={onAddPaymentMethod}
          />
        </div>
      )}

      {/* Backup Payment Method */}
      {backupMethod && (
        <div className="border-t border-border">
          <div className="px-6 py-2 bg-muted/30">
            <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              Backup methods
            </p>
          </div>
          <PaymentMethodCard
            method={backupMethod}
            isPrimary={false}
            orgId={orgId}
            onSetPrimary={handleSetPrimary}
            onReplaceBackup={handleSetBackup}
            onRemove={() => handleRemove(false)}
            availableMethods={availableMethods}
            onAddPaymentMethod={onAddPaymentMethod}
          />
        </div>
      )}

      {/* No Backup - Add Backup Section */}
      {primaryMethod && !backupMethod && (
        <div className="border-t border-border px-6 py-4 bg-muted/30">
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-2">
              <p className="text-[12px] text-muted-foreground">
                No backup payment method
              </p>
              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Info className="h-4 w-4 text-muted-foreground shrink-0" />
                  </TooltipTrigger>
                  <TooltipContent>
                    <p className="text-[12px]">
                      A backup payment method ensures uninterrupted service if
                      your primary method fails.
                    </p>
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
            </div>
            {availableMethods.length > 0 ? (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 gap-2 text-[13px]"
                  >
                    <Plus className="h-4 w-4" />
                    Add backup
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                  <div className="px-2 py-1.5">
                    <p className="text-[11px] font-medium text-muted-foreground mb-1">
                      Choose existing card
                    </p>
                  </div>
                  {availableMethods.map((availableMethod) => (
                    <DropdownMenuItem
                      key={availableMethod.$id}
                      className="text-[13px]"
                      onClick={() => handleSetBackup(availableMethod.$id)}
                    >
                      <CreditCard className="h-4 w-4 mr-2 text-muted-foreground" />
                      <span className="whitespace-nowrap">
                        {availableMethod.brand} ••••{availableMethod.last4}
                      </span>
                    </DropdownMenuItem>
                  ))}
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    className="text-[13px]"
                    onClick={() => onAddPaymentMethod?.(true)}
                  >
                    <Plus className="h-4 w-4 mr-2" />
                    Add new card
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            ) : (
              <Button
                variant="outline"
                size="sm"
                className="h-9 gap-2 text-[13px]"
                onClick={() => onAddPaymentMethod?.(true)}
              >
                <Plus className="h-4 w-4" />
                Add backup
              </Button>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

interface PaymentMethodCardProps {
  method: Models.PaymentMethod
  isPrimary: boolean
  orgId?: string
  onSetPrimary?: (paymentMethodId: string) => void
  onReplacePrimary?: (paymentMethodId: string) => void
  onReplaceBackup?: (paymentMethodId: string) => void
  onRemove: () => void
  availableMethods: Models.PaymentMethod[]
  onAddPaymentMethod?: (isBackup?: boolean) => void
}

function PaymentMethodCard({
  method,
  isPrimary,
  orgId,
  onSetPrimary,
  onReplacePrimary,
  onReplaceBackup,
  onRemove,
  availableMethods,
  onAddPaymentMethod,
}: PaymentMethodCardProps) {
  const isExpiringSoon =
    method.expiryMonth && method.expiryYear
      ? isCardExpiringSoon(method.expiryMonth, method.expiryYear)
      : false

  const hasError = method.failed || method.expired
  const errorMessage =
    method.lastError ||
    (method.expired ? 'Card expired' : method.failed ? 'Payment failed' : null)

  return (
    <div className="flex items-center justify-between px-6 py-4 hover:bg-accent/50 transition-colors">
      <div className="flex items-center gap-4">
        <div
          className={cn(
            'flex h-10 w-10 items-center justify-center rounded-lg',
            isPrimary ? 'bg-primary/10' : 'bg-muted',
          )}
        >
          <CreditCard
            className={cn(
              'h-5 w-5',
              isPrimary ? 'text-primary' : 'text-muted-foreground',
            )}
          />
        </div>
        <div>
          <div className="flex items-center gap-2">
            <p className="text-[13px] font-medium text-foreground">
              {method.brand} {maskCardNumber(method.last4 || '')}
            </p>
            {isPrimary && (
              <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                Primary
              </span>
            )}
            {isExpiringSoon && (
              <span className="rounded-full bg-yellow-500/10 px-2 py-0.5 text-[10px] font-medium text-yellow-600 dark:text-yellow-400">
                Expiring soon
              </span>
            )}
            {hasError && (
              <span className="rounded-full bg-red-500/10 px-2 py-0.5 text-[10px] font-medium text-red-600 dark:text-red-400">
                {method.expired ? 'Expired' : 'Failed'}
              </span>
            )}
          </div>
          {method.expiryMonth && method.expiryYear && (
            <p className="text-[12px] text-muted-foreground">
              Expires {formatCardExpiry(method.expiryMonth, method.expiryYear)}
            </p>
          )}
          {errorMessage && (
            <p className="text-[11px] text-red-600 dark:text-red-400 mt-0.5">
              {errorMessage}
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
        <DropdownMenuContent align="end" className="w-52">
          {!isPrimary && onSetPrimary && (
            <DropdownMenuItem
              className="text-[13px]"
              onClick={() => onSetPrimary(method.$id)}
            >
              Set as primary
            </DropdownMenuItem>
          )}
          {((isPrimary && onReplacePrimary) ||
            (!isPrimary && onReplaceBackup)) && (
            <>
              {!isPrimary && onSetPrimary && <DropdownMenuSeparator />}
              <DropdownMenuSub>
                <DropdownMenuSubTrigger className="text-[13px]">
                  Replace
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent className="w-52">
                  {availableMethods.length > 0 && (
                    <>
                      <div className="px-2 py-1.5">
                        <p className="text-[11px] font-medium text-muted-foreground">
                          Choose existing card
                        </p>
                      </div>
                      {availableMethods.map((availableMethod) => (
                        <DropdownMenuItem
                          key={availableMethod.$id}
                          className="text-[13px]"
                          onClick={() => {
                            if (isPrimary && onReplacePrimary) {
                              onReplacePrimary(availableMethod.$id)
                            } else if (!isPrimary && onReplaceBackup) {
                              onReplaceBackup(availableMethod.$id)
                            }
                          }}
                        >
                          <CreditCard className="h-4 w-4 mr-2 text-muted-foreground shrink-0" />
                          <span className="whitespace-nowrap">
                            {availableMethod.brand} ••••{availableMethod.last4}
                          </span>
                        </DropdownMenuItem>
                      ))}
                      <DropdownMenuSeparator />
                    </>
                  )}
                  <DropdownMenuItem
                    className="text-[13px]"
                    onClick={() => onAddPaymentMethod?.(!isPrimary)}
                  >
                    <Plus className="h-4 w-4 mr-2 shrink-0" />
                    Add new card
                  </DropdownMenuItem>
                </DropdownMenuSubContent>
              </DropdownMenuSub>
            </>
          )}
          <DropdownMenuSeparator />
          <DropdownMenuItem
            className="text-[13px] text-red-600 dark:text-red-400"
            onClick={onRemove}
          >
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
