'use client'

import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { ADDON_KEY_BAA } from '@/lib/billing/addons'
import {
  organizationAddonPriceQueryOptions,
  organizationAddonsQueryOptions,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useT } from '@/lib/i18n/translate'

type DisableBaaDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  addonId: string
}

export function DisableBaaDialog({
  open,
  onOpenChange,
  organizationId,
  addonId,
}: DisableBaaDialogProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleDisable = async () => {
    setSubmitting(true)
    setError(null)
    try {
      await sdk.forConsole.organizations.deleteAddon({
        organizationId,
        addonId,
      })
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: organizationAddonsQueryOptions(organizationId).queryKey,
        }),
        queryClient.refetchQueries({
          queryKey: organizationAddonPriceQueryOptions(
            organizationId,
            ADDON_KEY_BAA,
          ).queryKey,
        }),
      ])
      toast.success(
        t(
          'BAA addon will be removed at the end of your current billing cycle',
        ),
      )
      onOpenChange(false)
    } catch (disableError) {
      setError(getErrorMessage(disableError))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Disable BAA')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Are you sure you want to disable the BAA addon? The addon will remain active until the end of your current billing cycle and will not be renewed.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        {error ? (
          <div className="px-6 py-4">
            <p className="text-[13px] text-destructive">{error}</p>
          </div>
        ) : null}
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            disabled={submitting}
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
          <Button disabled={submitting} onClick={() => void handleDisable()}>
            {t('Disable BAA')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
