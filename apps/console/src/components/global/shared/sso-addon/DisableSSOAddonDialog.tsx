'use client'

import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { refreshAddonQueries } from '@/lib/billing/enable-addon'
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
import {
  ssoAddonKey,
  toAddonTarget,
  type SSOAddonTarget,
} from './use-sso-addon'

type DisableSSOAddonDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  target: SSOAddonTarget
  addonId: string
}

export function DisableSSOAddonDialog({
  open,
  onOpenChange,
  target,
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
      if (target.scope === 'project') {
        await sdk.forConsole.projects.deleteAddon({
          projectId: target.projectId,
          addonId,
        })
      } else {
        await sdk.forConsole.organizations.deleteAddon({
          organizationId: target.organizationId,
          addonId,
        })
      }
      await refreshAddonQueries(
        queryClient,
        toAddonTarget(target),
        ssoAddonKey(target),
        target.scope === 'organization' ? target.organizationId : undefined,
      )
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
            {target.scope === 'project'
              ? t(
                  "Are you sure you want to disable the Single sign-on add-on? It stays active until the end of your current billing cycle and is not renewed. Then nobody signs in to your app through the identity providers of this project's teams, and the teams can no longer verify domains, connect identity providers or turn on Require SSO.",
                )
              : t(
                  'Are you sure you want to disable the Single sign-on add-on? It stays active until the end of your current billing cycle and is not renewed. Then single sign-on for the organization stops, and people who can only sign in through your identity providers can no longer sign in.',
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
