'use client'

import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { ADDON_KEY_SSO } from '@/lib/billing/addons'
import {
  projectAddonPriceQueryOptions,
  projectAddonsQueryOptions,
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

type DisableSSOAddonDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  addonId: string
}

export function DisableSSOAddonDialog({
  open,
  onOpenChange,
  projectId,
  addonId,
}: DisableSSOAddonDialogProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleDisable = async () => {
    setSubmitting(true)
    setError(null)
    try {
      await sdk.forConsole.projects.deleteAddon({ projectId, addonId })
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: projectAddonsQueryOptions(projectId).queryKey,
        }),
        queryClient.refetchQueries({
          queryKey: projectAddonPriceQueryOptions(projectId, ADDON_KEY_SSO)
            .queryKey,
        }),
      ])
      toast.success(
        t(
          'Single sign-on add-on will be removed at the end of your current billing cycle',
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
          <DialogTitle>{t('Disable the Single sign-on add-on')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Are you sure you want to disable the Single sign-on add-on? It stays active until the end of your current billing cycle and is not renewed. Then the teams in this project can no longer verify domains, connect identity providers or turn on Require SSO through the Teams API.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        {error ? (
          <div className="px-6 py-4">
            <p className="text-[13px] text-destructive [.dark_&]:text-destructive-foreground">
              {error}
            </p>
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
            {t('Disable add-on')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
