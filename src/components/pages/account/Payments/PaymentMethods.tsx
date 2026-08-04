/**
 * AccountPaymentMethods Component
 *
 * Displays and manages payment methods at the account level.
 * Shows all payment methods with their linked organizations.
 */

import { useMemo, useState } from 'react'
import {
  CreditCard,
  Link as LinkIcon,
  Pencil,
  Trash2,
  Plus} from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { PaymentMethodBrandAvatar } from '@/components/global/shared/PaymentMethodBrandAvatar'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Popover,
  PopoverContent,
  PopoverTrigger} from '@/components/ui/popover'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow} from '@/components/ui/table'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { formatCardExpiry } from '../../organizations/$orgId/billing/utils'
import { cn } from '@/lib/utils'
import {
  useUpdatePaymentMethod,
  useDeletePaymentMethod,
  fetchPaymentMethods,
  organizationsFullQueryOptions,
  paymentMethodsQueryOptions,
} from '@/lib/react-query/hooks'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { EditPaymentMethodModal } from './EditPaymentMethod'
import { DeletePaymentMethodModal } from './DeletePaymentMethod'
import { PaymentMethodContextMenu } from './PaymentMethodContextMenu'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

interface AccountPaymentMethodsProps {
  onAddPaymentMethod?: () => void
  initialData?: {
    paymentMethods?: Awaited<ReturnType<typeof fetchPaymentMethods>>
    organizations?: Models.Organization[]
  }
}

export function AccountPaymentMethods({
  onAddPaymentMethod,
  initialData,
}: AccountPaymentMethodsProps) {
  const t = useT()
  const { data: paymentMethodsData, isFetched: methodsFetched } = useQuery(
    paymentMethodsQueryOptions(),
  )
  useUpdatePaymentMethod()
  useDeletePaymentMethod()

  const paymentMethodsList =
    paymentMethodsData?.paymentMethods ??
    initialData?.paymentMethods?.paymentMethods ??
    []

  const { data: organizationsData } = useQuery(organizationsFullQueryOptions())

  const organizations = organizationsData ?? initialData?.organizations ?? []
  const hasResolvedData =
    methodsFetched || initialData?.paymentMethods !== undefined

  const [editModalOpen, setEditModalOpen] = useState(false)
  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [selectedPaymentMethod, setSelectedPaymentMethod] =
    useState<Models.PaymentMethod | null>(null)

  // Filter to only show completed cards (with last4)
  const completedPaymentMethods = useMemo(() => {
    return paymentMethodsList.filter((pm: Models.PaymentMethod) => pm.last4)
  }, [paymentMethodsList])

  // Get linked payment method IDs from organizations
  const linkedMethodIds = useMemo(() => {
    const linked = new Set<string>()
    organizations.forEach((org) => {
      if (org.paymentMethodId) linked.add(org.paymentMethodId)
      if (org.backupPaymentMethodId) linked.add(org.backupPaymentMethodId)
    })
    return linked
  }, [organizations])

  // Get organizations linked to each payment method
  const getLinkedOrganizations = (paymentMethodId: string) => {
    return organizations.filter(
      (org) =>
        org.paymentMethodId === paymentMethodId ||
        org.backupPaymentMethodId === paymentMethodId,
    )
  }

  // Check if any payment method has errors
  const hasPaymentError = useMemo(() => {
    return completedPaymentMethods.some(
      (method: Models.PaymentMethod) =>
        method.lastError || method.expired || method.failed,
    )
  }, [completedPaymentMethods])

  const handleEdit = (method: Models.PaymentMethod) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedPaymentMethod(method)
      setEditModalOpen(true)
    })
  }

  const handleDelete = (method: Models.PaymentMethod) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedPaymentMethod(method)
      setDeleteModalOpen(true)
    })
  }

  const handleEditSuccess = () => {
    setEditModalOpen(false)
    setSelectedPaymentMethod(null)
  }

  const handleDeleteSuccess = () => {
    setDeleteModalOpen(false)
    setSelectedPaymentMethod(null)
  }

  if (!hasResolvedData) {
    return null
  }

  if (completedPaymentMethods.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Payment methods')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                {t('Manage your payment methods and billing information.')}
              </p>
            </div>
          </div>
        </div>
        <div className="border-t border-border -mx-6" />
        <div className="px-6 py-8 text-center">
          <EmptyState
            icon={CreditCard}
            title={t('No payment methods')}
            description={t('Add a payment method to get started')}
            isEmpty={true}
            hasFilters={false}
            variant="default"
          >
            <div className="mt-4">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={onAddPaymentMethod}
              >
                <Plus className="me-1.5 h-4 w-4" />
                {t('Add payment method')}
              </Button>
            </div>
          </EmptyState>
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Payment methods')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                {t('Manage your payment methods and billing information.')}
              </p>
            </div>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={onAddPaymentMethod}
            >
              <Plus className="me-1.5 h-4 w-4" />
              {t('Add payment method')}
            </Button>
          </div>
        </div>
        <div className="border-t border-border -mx-6" />
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[220px]">
                {t('Card')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                {t('Cardholder')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[140px]">
                {t('Expires')}
              </TableHead>
              {hasPaymentError && (
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                  {t('Status')}
                </TableHead>
              )}
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Linked To')}
              </TableHead>
              <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[60px]" />
            </TableRow>
          </TableHeader>
              <TableBody>
                {completedPaymentMethods.map((method: Models.PaymentMethod) => {
                  const isExpiringSoon =
                    method.expiryMonth && method.expiryYear
                      ? isCardExpiringSoon(
                          method.expiryMonth,
                          method.expiryYear,
                        )
                      : false

                  const hasError = method.failed || method.expired
                  const linkedOrgs = getLinkedOrganizations(method.$id)
                  const isLinked = linkedMethodIds.has(method.$id)

                  return (
                    <PaymentMethodContextMenu
                      key={method.$id}
                      paymentMethod={method}
                      onUpdate={handleEdit}
                      onDelete={handleDelete}
                    >
                    <TableRow
                      className={cn(
                        'transition-colors',
                        hasError && 'bg-red-50/50 dark:bg-red-950/10',
                      )}
                    >
                      <TableCell className="px-6 py-3">
                        <div className="flex items-center gap-3">
                          <PaymentMethodBrandAvatar brand={method.brand} />
                          <div className="min-w-0">
                            <p className="text-[13px] font-medium text-foreground">
                              {method.brand} ••••{method.last4}
                            </p>
                            {method.expiryMonth && method.expiryYear && (
                              <p className="text-[11px] text-muted-foreground mt-0.5">
                                {t('Expires')}{' '}
                                {formatCardExpiry(
                                  method.expiryMonth,
                                  method.expiryYear,
                                )}
                              </p>
                            )}
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <p className="text-[13px] text-foreground">
                          {method.name || (
                            <span className="text-muted-foreground"> - </span>
                          )}
                        </p>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {method.expiryMonth && method.expiryYear ? (
                          <p className="text-[13px] text-foreground">
                            {formatCardExpiry(
                              method.expiryMonth,
                              method.expiryYear,
                            )}
                          </p>
                        ) : (
                          <span className="text-[13px] text-muted-foreground">
                             - 
                          </span>
                        )}
                      </TableCell>
                      {hasPaymentError && (
                        <TableCell className="px-4 py-3">
                          {hasError ? (
                            <Badge
                              variant="failed"
                              className="text-[10px] shrink-0"
                            >
                              {method.expired ? t('Expired') : t('Failed')}
                            </Badge>
                          ) : isExpiringSoon ? (
                            <Badge variant="warning" className="text-[10px] shrink-0">
                              {t('Expiring soon')}
                            </Badge>
                          ) : (
                            <Badge variant="active" className="text-[10px] shrink-0">
                              {t('Active')}
                            </Badge>
                          )}
                        </TableCell>
                      )}
                      <TableCell className="px-4 py-3">
                        {isLinked ? (
                          <Popover>
                            <PopoverTrigger asChild>
                              <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 text-[12px] text-muted-foreground hover:text-foreground -ms-2"
                              >
                                <LinkIcon className="me-1.5 h-3.5 w-3.5" />
                                {linkedOrgs.length}{' '}
                                {linkedOrgs.length > 1
                                  ? t('organizations')
                                  : t('organization')}
                              </Button>
                            </PopoverTrigger>
                            <PopoverContent className="w-64" align="start">
                              <div className="space-y-2">
                                <p className="text-[12px] font-medium text-foreground mb-2">
                                  {t('Linked Organizations')}
                                </p>
                                {linkedOrgs.map((org) => (
                                  <Link
                                    key={org.$id}
                                    to="/organizations/$orgId/settings/billing"
                                    params={{ orgId: org.$id }}
                                    className="block rounded-md px-2 py-1.5 text-[12px] text-foreground hover:bg-muted transition-colors"
                                  >
                                    {org.name}
                                  </Link>
                                ))}
                              </div>
                            </PopoverContent>
                          </Popover>
                        ) : (
                          <Badge variant="inactive" className="text-[10px] shrink-0">
                            {t('Not linked')}
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="px-6 py-3 text-end">
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-40">
                            <DropdownMenuItem
                              className="text-[13px]"
                              onClick={() => handleEdit(method)}
                            >
                              <MenuItemContent icon={Pencil}>
                                {t('Update')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              className="text-[13px]"
                              onClick={() => handleDelete(method)}
                            >
                              <MenuItemContent icon={Trash2}>
                                {t('Delete')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                    </PaymentMethodContextMenu>
                  )
                })}
              </TableBody>
            </Table>
      </div>

      {/* Edit Modal */}
      {selectedPaymentMethod && (
        <EditPaymentMethodModal
          open={editModalOpen}
          onOpenChange={setEditModalOpen}
          paymentMethod={selectedPaymentMethod}
          onSuccess={handleEditSuccess}
        />
      )}

      {/* Delete Modal */}
      {selectedPaymentMethod && (
        <DeletePaymentMethodModal
          open={deleteModalOpen}
          onOpenChange={setDeleteModalOpen}
          paymentMethod={selectedPaymentMethod}
          linkedOrganizations={getLinkedOrganizations(
            selectedPaymentMethod.$id,
          )}
          onSuccess={handleDeleteSuccess}
        />
      )}
    </>
  )
}

function isCardExpiringSoon(month: number, year: number): boolean {
  const now = new Date()
  const expiryDate = new Date(year, month - 1)
  const threeMonthsFromNow = new Date()
  threeMonthsFromNow.setMonth(threeMonthsFromNow.getMonth() + 3)
  return expiryDate <= threeMonthsFromNow && expiryDate >= now
}
