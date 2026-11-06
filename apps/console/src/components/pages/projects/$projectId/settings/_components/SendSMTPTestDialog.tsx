import { useEffect, useState } from 'react'
import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { InputTags } from '@/components/ui/input-tags'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useTestSMTP, type SmtpUpdateData } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

const MAX_TEST_EMAILS = 10

type DialogPhase = 'form' | 'sending' | 'success' | 'error'

interface SendSMTPTestDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  smtp: SmtpUpdateData
  /** Prefills the first recipient when opening (e.g. signed-in account email). */
  defaultRecipientEmail?: string
  onSent?: () => void
}

export function SendSMTPTestDialog({
  open,
  onOpenChange,
  projectId,
  smtp,
  defaultRecipientEmail,
  onSent,
}: SendSMTPTestDialogProps) {
  const t = useT()
  const testSMTPMutation = useTestSMTP(projectId)
  const [phase, setPhase] = useState<DialogPhase>('form')
  const [emails, setEmails] = useState<string[]>([])
  const [errorMessage, setErrorMessage] = useState('')

  useEffect(() => {
    if (!open) return
    setPhase('form')
    setErrorMessage('')
    setEmails(
      defaultRecipientEmail?.trim()
        ? [defaultRecipientEmail.trim()]
        : [],
    )
  }, [open, defaultRecipientEmail])

  const handleOpenChange = (next: boolean) => {
    if (testSMTPMutation.isPending) return
    onOpenChange(next)
  }

  const handleEmailsChange = (next: string[]) => {
    setEmails(next.slice(0, MAX_TEST_EMAILS))
  }

  const handleSend = async () => {
    if (emails.length === 0) return
    setPhase('sending')
    setErrorMessage('')
    try {
      await testSMTPMutation.mutateAsync({ emails, smtp })
      setPhase('success')
      onSent?.()
    } catch (error: unknown) {
      setErrorMessage(
        error instanceof Error ? error.message : t('Failed to send test email'),
      )
      setPhase('error')
    }
  }

  const isSending = phase === 'sending' || testSMTPMutation.isPending

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className="sm:max-w-md p-0"
>
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Send test email')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Verify your SMTP configuration by sending a test email to one or more recipients.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          {phase === 'form' ? (
            <div className="space-y-2">
              <Label htmlFor="smtp-test-recipients">{t('Recipients')}</Label>
              <InputTags
                value={emails}
                onChange={handleEmailsChange}
                validateEmail
                placeholder="email@example.com"
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Press Enter, Space, or comma to add each address. You can paste multiple addresses separated by commas or spaces. Up to')}{' '}
                {MAX_TEST_EMAILS} {t('recipients.')}
                {emails.length >= MAX_TEST_EMAILS
                  ? ` ${t('Maximum recipients reached.')}`
                  : null}
              </p>
            </div>
          ) : null}

          {phase === 'sending' ? (
            <div className="flex flex-col items-center justify-center gap-3 py-4">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="text-[13px] text-muted-foreground text-center">
                {t('Sending test email…')}
              </p>
            </div>
          ) : null}

          {phase === 'success' ? (
            <div className="flex flex-col items-center gap-3 text-center">
              <CheckCircle2 className="h-10 w-10 text-green-600" />
              <p className="text-[13px] text-foreground">
                {t('Test email sent to')} {emails.length}{' '}
                {emails.length === 1 ? t('recipient') : t('recipients')}.
              </p>
              <ul className="w-full text-start text-[12px] text-muted-foreground space-y-1">
                {emails.map((email) => (
                  <li key={email} className="truncate">
                    {email}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {phase === 'error' ? (
            <Alert variant="destructive" className="border-destructive/30">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {errorMessage}
              </AlertDescription>
            </Alert>
          ) : null}
        </div>

        {phase !== 'sending' ? (
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {phase === 'form' ? (
            <>
              <Button
                type="button"
                size="sm"
                className="h-9 text-[13px]"
                disabled={emails.length === 0 || isSending}
                onClick={handleSend}
>
                {t('Send')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => handleOpenChange(false)}
                disabled={isSending}
>
                {t('Cancel')}
              </Button>
            </>
          ) : null}

          {phase === 'success' ? (
            <Button
              type="button"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => handleOpenChange(false)}
>
              {t('Close')}
            </Button>
          ) : null}

          {phase === 'error' ? (
            <>
              <Button
                type="button"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setPhase('form')}
>
                {t('Try again')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => handleOpenChange(false)}
>
                {t('Close')}
              </Button>
            </>
          ) : null}
          </div>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
