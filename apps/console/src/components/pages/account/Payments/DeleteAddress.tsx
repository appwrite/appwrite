/**
 * DeleteAddressModal Component
 *
 * Modal for deleting a billing address with warnings if linked to organizations.
 */

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { AlertTriangle } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { useDeleteBillingAddress } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface DeleteAddressModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  address: Models.BillingAddress
  linkedOrganizations: Array<{ $id: string; name: string }>
  onSuccess?: () => void
}

export function DeleteAddressModal({
  open,
  onOpenChange,
  address,
  linkedOrganizations,
  onSuccess,
}: DeleteAddressModalProps) {
  const t = useT()
  const deleteAddressMutation = useDeleteBillingAddress()

  const handleDelete = async () => {
    try {
      await deleteAddressMutation.mutateAsync({
        billingAddressId: address.$id,
      })

      toast.success(t('Billing address deleted'))
      onOpenChange(false)
      onSuccess?.()
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to delete billing address'),
      )
    }
  }

  const isLoading = deleteAddressMutation.isPending
  const isLinked = linkedOrganizations.length > 0

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Delete billing address')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {isLinked
              ? `${t('This billing address is linked to')} ${linkedOrganizations.length} ${linkedOrganizations.length > 1 ? t('organizations') : t('organization')}. ${t('Deleting it will remove it from those organizations.')}`
              : t(
                  'Are you sure you want to delete this billing address? This action cannot be undone.',
                )}
          </DialogDescription>
        </DialogHeader>

        {isLinked && (
          <div className="px-6 pb-4 pt-0">
            <Alert variant="destructive">
              <AlertTriangle className="h-4 w-4" />
              <AlertDescription className="text-[12px] mt-2">
                <div className="space-y-1">
                  <p className="font-medium">{t('Linked to:')}</p>
                  <ul className="list-disc list-inside space-y-0.5">
                    {linkedOrganizations.map((org) => (
                      <li key={org.$id}>
                        <Link
                          to="/organizations/$orgId/settings/billing"
                          params={{ orgId: org.$id }}
                          className="underline hover:no-underline"
                          onClick={(e) => e.stopPropagation()}
                        >
                          {org.name}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </div>
              </AlertDescription>
            </Alert>
          </div>
        )}

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleDelete}
            disabled={isLoading}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
