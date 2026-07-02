import { useMemo, useState } from 'react'
import { CreditCard, Plus, Info } from '@/lib/icons'
import { PaymentMethodBrandAvatar } from '@/components/global/shared/PaymentMethodBrandAvatar'
import {
  warningAlertContainerClassName,
  warningAlertTextClassName} from '@/components/global/shared/WarningAlert'
import { Trash2, Star, ArrowLeftRight } from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger} from '@/components/ui/tooltip'
import { formatCardExpiry, maskCardNumber } from './utils'
import { cn } from '@/lib/utils'
import {
  useOrganizationById,
  useOrganizationPaymentMethod,
  useBillingPlans,
  useOrganizationPlan,
  usePaymentMethods,
  useUpdateOrganizationPaymentMethod} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { CannotRemovePrimaryPaymentMethodModal } from './CannotRemovePrimaryPaymentMethodModal'
import { OrgPaymentMethodContextMenu } from './OrgPaymentMethodContextMenu'
import { useT } from '@/lib/i18n/translate'

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
  orgId}: PaymentMethodsProps) {
  const t = useT()
  const { organization } = useOrganizationById(orgId)
  const { plan } = useOrganizationPlan(orgId)
  const { plans: billingPlans } = useBillingPlans()
  const { paymentMethods: allPaymentMethods, isLoading: methodsLoading } =
    usePaymentMethods()
  const [cannotRemovePrimaryOpen, setCannotRemovePrimaryOpen] = useState(false)

  const isPaidPlan = useMemo(() => {
    if (plan) return (plan.price ?? 0) > 0
    const tier = organization?.billingPlan
    if (tier && billingPlans[tier]) {
      return (billingPlans[tier].price ?? 0) > 0
    }
    return false
  }, [plan, organization?.billingPlan, billingPlans])
  const primaryPaymentMethod = useOrganizationPaymentMethod(
    orgId,
    organization?.paymentMethodId,
  )
  const backupPaymentMethod = useOrganizationPaymentMethod(
    orgId,
    organization?.backupPaymentMethodId,
  )
  const updatePaymentMethodMutation = useUpdateOrganizationPaymentMethod()

  // Filter payment methods to only show completed cards (with last4)
  const completedPaymentMethods = allPaymentMethods.filter(
    (pm: Models.PaymentMethod) => pm.last4,
  )

  const primaryMethodId = organization?.paymentMethodId
  const backupMethodId = organization?.backupPaymentMethodId

  // Resolve from org hook first; fall back to account list for instant UI after replace
  const primaryMethod = useMemo(() => {
    if (primaryPaymentMethod.paymentMethod) {
      return primaryPaymentMethod.paymentMethod
    }
    if (!primaryMethodId) return undefined
    return completedPaymentMethods.find((pm) => pm.$id === primaryMethodId)
  }, [
    primaryPaymentMethod.paymentMethod,
    primaryMethodId,
    completedPaymentMethods,
  ])

  const backupMethod = useMemo(() => {
    if (backupPaymentMethod.paymentMethod) {
      return backupPaymentMethod.paymentMethod
    }
    if (!backupMethodId) return undefined
    return completedPaymentMethods.find((pm) => pm.$id === backupMethodId)
  }, [
    backupPaymentMethod.paymentMethod,
    backupMethodId,
    completedPaymentMethods,
  ])

  const hasPrimaryAssigned = !!primaryMethodId
  const isPrimaryResolving =
    hasPrimaryAssigned &&
    !primaryMethod &&
    primaryPaymentMethod.isLoading

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
        paymentMethodId})
      toast.success(t('Primary payment method updated'))
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to update payment method'),
      )
    }
  }

  const handleSetBackup = async (paymentMethodId: string) => {
    if (!orgId) return

    try {
      await updatePaymentMethodMutation.mutateAsync({
        organizationId: orgId,
        backupPaymentMethodId: paymentMethodId})
      toast.success(t('Backup payment method updated'))
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to update backup payment method'),
      )
    }
  }

  const handleRemove = async (isPrimary: boolean) => {
    if (!orgId) return

    if (
      isPrimary &&
      !organization?.backupPaymentMethodId &&
      isPaidPlan
    ) {
      setCannotRemovePrimaryOpen(true)
      return
    }

    try {
      if (isPrimary) {
        const backupId = organization?.backupPaymentMethodId
        if (backupId) {
          // Promote backup to primary and clear the backup slot
          await updatePaymentMethodMutation.mutateAsync({
            organizationId: orgId,
            paymentMethodId: backupId,
            backupPaymentMethodId: null})
        } else {
          // Remove primary (allowed on free plan only; guarded above)
          await updatePaymentMethodMutation.mutateAsync({
            organizationId: orgId,
            paymentMethodId: null})
        }
      } else {
        await updatePaymentMethodMutation.mutateAsync({
          organizationId: orgId,
          backupPaymentMethodId: null})
      }
      toast.success(t('Payment method removed'))
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to remove payment method'),
      )
    }
  }

  if (methodsLoading && completedPaymentMethods.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Payment methods')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading payment methods...')}
          </p>
        </div>
      </div>
    )
  }

  if (!hasPrimaryAssigned && completedPaymentMethods.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4 flex items-center justify-between gap-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Payment methods')}
          </h3>
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-2 text-[13px] shrink-0"
            onClick={() => onAddPaymentMethod?.()}
>
            <Plus className="h-4 w-4" />
            {t('Add payment method')}
          </Button>
        </div>
        <div className="border-t border-border px-6 py-8">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <CreditCard className="h-6 w-6 text-muted-foreground" />
          </div>
          <p className="text-[13px] text-muted-foreground text-center mb-1">
            {t('No payment method on file')}
          </p>
          <p className="text-[13px] text-muted-foreground text-center mb-4">
            {t('Add a new credit card to pay for your organization.')}
          </p>
          <div className="flex justify-center">
            <Button
              variant="outline"
              size="sm"
              className="h-9 gap-2 text-[13px]"
              onClick={() => onAddPaymentMethod?.()}
>
              <Plus className="h-4 w-4" />
              {t('Add payment method')}
            </Button>
          </div>
        </div>
      </div>
    )
  }

  return (
    <>
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4 flex items-center justify-between gap-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Payment methods')}
        </h3>
        {!hasPrimaryAssigned && (
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-2 text-[13px] shrink-0"
            onClick={() => onAddPaymentMethod?.()}
>
            <Plus className="h-4 w-4" />
            {t('Add payment method')}
          </Button>
        )}
      </div>

      {/* No primary – use existing or add new */}
      {!hasPrimaryAssigned && (
        <div className="border-t border-border px-6 py-8">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <CreditCard className="h-6 w-6 text-muted-foreground" />
          </div>
          <p className="text-[13px] text-muted-foreground text-center mb-1">
            {t('No payment method on file')}
          </p>
          <p className="text-[13px] text-muted-foreground text-center mb-4">
            {t('Use an existing card or add a new one for this organization.')}
          </p>
          {availableMethods.length > 0 ? (
            <div className="space-y-3 max-w-md mx-auto">
              <p className="text-[12px] font-medium text-foreground">
                {t('Use existing card')}
              </p>
              <div className="flex flex-col gap-2">
                {availableMethods.map((pm: Models.PaymentMethod) => (
                  <div
                    key={pm.$id}
                    className="flex items-center justify-between rounded-lg border border-border bg-muted/20 px-4 py-3"
>
                    <div className="flex items-center gap-3 min-w-0">
                      <PaymentMethodBrandAvatar brand={pm.brand} />
                      <div className="min-w-0">
                        <p className="text-[13px] font-medium text-foreground truncate">
                          {pm.brand} {maskCardNumber(pm.last4 || '')}
                        </p>
                        {pm.expiryMonth && pm.expiryYear && (
                          <p className="text-[12px] text-muted-foreground truncate">
                            {t('Expires')}{' '}
                            {formatCardExpiry(pm.expiryMonth, pm.expiryYear)}
                          </p>
                        )}
                      </div>
                    </div>
                    <Button
                      size="sm"
                      className="h-8 text-[12px] shrink-0"
                      onClick={() => handleSetPrimary(pm.$id)}
                      disabled={updatePaymentMethodMutation.isPending}
>
                      {t('Use as primary')}
                    </Button>
                  </div>
                ))}
              </div>
              <p className="text-[12px] font-medium text-foreground pt-1">
                {t('Or add a new card')}
              </p>
              <Button
                variant="outline"
                size="sm"
                className="h-9 gap-2 text-[13px] w-full"
                onClick={() => onAddPaymentMethod?.()}
>
                <Plus className="h-4 w-4" />
                {t('Add new card')}
              </Button>
            </div>
          ) : (
            <div className="flex justify-center">
              <Button
                variant="outline"
                size="sm"
                className="h-9 gap-2 text-[13px]"
                onClick={() => onAddPaymentMethod?.()}
>
                <Plus className="h-4 w-4" />
                {t('Add payment method')}
              </Button>
            </div>
          )}
        </div>
      )}

      {/* Primary Payment Method */}
      {(primaryMethod || isPrimaryResolving) && (
        <div className="border-t border-border">
          {primaryMethod ? (
            <PaymentMethodCard
              method={primaryMethod}
              isPrimary
              orgId={orgId}
              onReplacePrimary={handleSetPrimary}
              onRemove={() => handleRemove(true)}
              availableMethods={availableMethods}
              onAddPaymentMethod={onAddPaymentMethod}
            />
          ) : (
            <div className="flex items-center gap-4 px-6 py-4">
              <div className="h-10 w-10 shrink-0 animate-pulse rounded-lg bg-muted" />
              <div className="min-w-0 flex-1 space-y-2">
                <div className="h-4 w-40 animate-pulse rounded bg-muted" />
                <div className="h-3 w-24 animate-pulse rounded bg-muted" />
              </div>
            </div>
          )}
        </div>
      )}

      {/* Backup Payment Method */}
      {backupMethod && (
        <div className="border-t border-border">
          <div className="px-6 py-2 bg-muted/30">
            <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('Backup methods')}
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
                {t('No backup payment method')}
              </p>
              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Info className="h-4 w-4 text-muted-foreground shrink-0" />
                  </TooltipTrigger>
                  <TooltipContent>
                    <p className="text-[12px]">
                      {t('A backup payment method ensures uninterrupted service if your primary method fails.')}
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
                    {t('Add backup')}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                  align="end"
                  className="w-52"
>
                  <div className="px-2 py-1.5">
                    <p className="text-[11px] font-medium text-muted-foreground mb-1">
                      {t('Choose existing card')}
                    </p>
                  </div>
                  {availableMethods.map((availableMethod) => (
                    <DropdownMenuItem
                      key={availableMethod.$id}
                      className="text-[13px]"
                      onClick={() => handleSetBackup(availableMethod.$id)}
>
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
                    {t('Add')}
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
                {t('Add backup')}
              </Button>
            )}
          </div>
        </div>
      )}
    </div>

    <CannotRemovePrimaryPaymentMethodModal
      open={cannotRemovePrimaryOpen}
      onOpenChange={setCannotRemovePrimaryOpen}
      organizationName={organization?.name}
      availableMethods={availableMethods}
      onReplacePrimary={handleSetPrimary}
      onAddNew={onAddPaymentMethod ? () => onAddPaymentMethod() : undefined}
    />
    </>
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
  onSetPrimary,
  onReplacePrimary,
  onReplaceBackup,
  onRemove,
  availableMethods,
  onAddPaymentMethod}: PaymentMethodCardProps) {
  const t = useT()
  const isExpiringSoon =
    method.expiryMonth && method.expiryYear
      ? isCardExpiringSoon(method.expiryMonth, method.expiryYear)
      : false

  const hasError = method.failed || method.expired
  const errorMessage =
    method.lastError ||
    (method.expired ? t('Card expired') : method.failed ? t('Payment failed') : null)

  return (
    <OrgPaymentMethodContextMenu
      method={method}
      isPrimary={isPrimary}
      availableMethods={availableMethods}
      onSetPrimary={onSetPrimary}
      onReplacePrimary={onReplacePrimary}
      onReplaceBackup={onReplaceBackup}
      onRemove={onRemove}
      onAddPaymentMethod={onAddPaymentMethod}
    >
    <div className="flex items-center justify-between px-6 py-4 hover:bg-accent/50 transition-colors">
      <div className="flex items-center gap-2">
        <PaymentMethodBrandAvatar brand={method.brand} />
        <div>
          <div className="flex items-center gap-2">
            <p className="text-[13px] font-medium text-foreground">
              {method.brand} {maskCardNumber(method.last4 || '')}
            </p>
            {isPrimary && (
              <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                {t('Primary')}
              </span>
            )}
            {isExpiringSoon && (
              <span className="rounded-full bg-yellow-500/10 px-2 py-0.5 text-[10px] font-medium text-yellow-600 dark:text-yellow-400">
                {t('Expiring soon')}
              </span>
            )}
            {hasError && (
              <span className="rounded-full bg-red-500/10 px-2 py-0.5 text-[10px] font-medium text-red-600 dark:text-red-400">
                {method.expired ? t('Expired') : t('Failed')}
              </span>
            )}
          </div>
          {method.expiryMonth && method.expiryYear && (
            <p className="text-[12px] text-muted-foreground">
              {t('Expires')} {formatCardExpiry(method.expiryMonth, method.expiryYear)}
            </p>
          )}
          {errorMessage && (
            <div
              className={cn(
                'mt-1.5 rounded-md border px-2 py-1',
                warningAlertContainerClassName,
              )}
>
              <p className={cn('text-[11px]', warningAlertTextClassName)}>
                {errorMessage}
              </p>
            </div>
          )}
        </div>
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <RowActionsMenuTrigger />
        </DropdownMenuTrigger>
        <DropdownMenuContent
          align="end"
          className="w-52"
>
          {!isPrimary && onSetPrimary && (
            <DropdownMenuItem
              className="text-[13px]"
              onClick={() => onSetPrimary(method.$id)}
            >
              <MenuItemContent icon={Star}>{t('Primary')}</MenuItemContent>
            </DropdownMenuItem>
          )}
          {((isPrimary && onReplacePrimary) ||
            (!isPrimary && onReplaceBackup)) && (
            <>
              {!isPrimary && onSetPrimary && <DropdownMenuSeparator />}
              <DropdownMenuSub>
                <DropdownMenuSubTrigger className="text-[13px]">
                  <MenuItemIcon icon={ArrowLeftRight} />
                  {t('Replace')}
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent
                  className="w-52"
>
                  {availableMethods.length > 0 && (
                    <>
                      <div className="px-2 py-1.5">
                        <p className="text-[11px] font-medium text-muted-foreground">
                          {t('Choose existing card')}
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
                          <MenuItemContent icon={CreditCard}>
                            <span className="whitespace-nowrap">
                              {availableMethod.brand} ••••{availableMethod.last4}
                            </span>
                          </MenuItemContent>
                        </DropdownMenuItem>
                      ))}
                      <DropdownMenuSeparator />
                    </>
                  )}
                  <DropdownMenuItem
                    className="text-[13px]"
                    onClick={() => onAddPaymentMethod?.(!isPrimary)}
                  >
                    <MenuItemContent icon={Plus}>{t('Add')}</MenuItemContent>
                  </DropdownMenuItem>
                </DropdownMenuSubContent>
              </DropdownMenuSub>
            </>
          )}
          <DropdownMenuSeparator />
          <DropdownMenuItem
            className="text-[13px]"
            onClick={onRemove}
          >
            <MenuItemContent icon={Trash2}>{t('Remove')}</MenuItemContent>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
    </OrgPaymentMethodContextMenu>
  )
}

function isCardExpiringSoon(month: number, year: number): boolean {
  const now = new Date()
  const expiryDate = new Date(year, month - 1)
  const threeMonthsFromNow = new Date()
  threeMonthsFromNow.setMonth(threeMonthsFromNow.getMonth() + 3)
  return expiryDate <= threeMonthsFromNow && expiryDate >= now
}
