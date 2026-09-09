import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertCircle } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { SmtpQuickSetupProvider } from '@/lib/smtp/providers'

interface DisconnectSmtpProviderProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  provider: SmtpQuickSetupProvider
  /** Identity linking this console account to the provider. */
  identityId: string
  onDisconnected?: () => void
}

/**
 * Deletes the console account's identity for an email provider.
 *
 * Only the link is removed: credentials already saved in the project's SMTP
 * settings keep working, because they live at the provider, not in the identity.
 */
export function DisconnectSmtpProvider({
  open,
  onOpenChange,
  provider,
  identityId,
  onDisconnected,
}: DisconnectSmtpProviderProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleOpenChange = (next: boolean) => {
    if (submitting) return
    setError(null)
    onOpenChange(next)
  }

  const handleDisconnect = async () => {
    setSubmitting(true)
    setError(null)
    try {
      await sdk.forConsole.account.deleteIdentity({ identityId })
      await queryClient.refetchQueries({ queryKey: ['identities', 'account'] })
      onDisconnected?.()
      toast.success(t('Provider disconnected'))
      onOpenChange(false)
    } catch (disconnectError) {
      setError(
        getErrorMessage(
          disconnectError,
          t('Failed to disconnect the provider'),
        ),
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Disconnect provider')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Appwrite will no longer be able to create sending credentials for this provider. Credentials already saved in your SMTP settings keep working.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 py-4">
          <dl className="rounded-lg border border-border bg-background px-4 py-3 text-[12px]">
            <div className="flex items-center justify-between gap-4">
              <dt className="text-muted-foreground">{t('Provider')}</dt>
              <dd className="text-foreground">{provider.name}</dd>
            </div>
          </dl>

          {error ? (
            <Alert variant="destructive" className="mt-4 border-destructive/30">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          ) : null}
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => handleOpenChange(false)}
            disabled={submitting}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleDisconnect}
            disabled={submitting}
          >
            {t('Disconnect')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
