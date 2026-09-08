import { useCallback, useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { AlertCircle, CheckCircle2, ExternalLink, Loader2 } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { projectQueryOptions, useUpdateSMTP } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  RESEND_DOMAINS_URL,
  RESEND_SMTP_HOST,
  RESEND_SMTP_PORT,
  RESEND_SMTP_SECURE,
  RESEND_SMTP_USERNAME,
  buildResendApiKeyName,
  defaultResendSenderEmail,
  emailBelongsToDomain,
  isVerifiedResendDomain,
  pickDefaultResendDomain,
  sortResendDomains,
  type ResendDomain,
} from '@/lib/smtp/resend'
import {
  createResendApiKey,
  deleteResendApiKey,
  listResendDomains,
  type CreatedResendApiKey,
} from '@/lib/smtp/resend-api'
import { ResendReauthorizeRequiredError } from '@/lib/smtp/resend-oauth'

/**
 * Runs a Resend API call with a valid access token, refreshing it through the
 * console session when needed. Throws {@link ResendReauthorizeRequiredError}
 * when only a new OAuth2 round trip can help.
 */
export type ResendApiCall = <T>(
  run: (accessToken: string) => Promise<T>,
) => Promise<T>

type DialogPhase =
  | 'loading'
  | 'no-domains'
  | 'form'
  | 'submitting'
  | 'success'
  | 'reauthorize'
  | 'error'

interface SetupResendSMTPProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  project: Models.Project | undefined
  callResend: ResendApiCall
  /** Start a fresh `createOAuth2Token` round trip (token refresh is no longer possible). */
  onReauthorize: () => void
  /** Called from the success step when the user wants to send a test email right away. */
  onSendTestEmail?: () => void
}

const SERVER_SETTINGS: ReadonlyArray<{ label: string; value: string }> = [
  { label: 'Server host', value: RESEND_SMTP_HOST },
  { label: 'Server port', value: String(RESEND_SMTP_PORT) },
  { label: 'Username', value: RESEND_SMTP_USERNAME },
  { label: 'Secure protocol', value: RESEND_SMTP_SECURE.toUpperCase() },
]

export function SetupResendSMTP({
  open,
  onOpenChange,
  projectId,
  project,
  callResend,
  onReauthorize,
  onSendTestEmail,
}: SetupResendSMTPProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const updateSMTPMutation = useUpdateSMTP(projectId)

  const [phase, setPhase] = useState<DialogPhase>('loading')
  const [domains, setDomains] = useState<ResendDomain[]>([])
  const [domainId, setDomainId] = useState('')
  const [senderName, setSenderName] = useState('')
  const [senderEmail, setSenderEmail] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [validationMessage, setValidationMessage] = useState('')

  const selectedDomain = domains.find((domain) => domain.id === domainId)

  const failWith = useCallback((error: unknown, fallback: string) => {
    if (error instanceof ResendReauthorizeRequiredError) {
      setPhase('reauthorize')
      return
    }
    setErrorMessage(getErrorMessage(error, fallback))
    setPhase('error')
  }, [])

  const loadDomains = useCallback(async () => {
    setPhase('loading')
    setErrorMessage('')
    setValidationMessage('')
    try {
      const list = sortResendDomains(await callResend(listResendDomains))
      setDomains(list)

      const initial = pickDefaultResendDomain(list, project?.smtpSenderEmail)
      if (!initial) {
        setPhase('no-domains')
        return
      }

      setDomainId(initial.id)
      setSenderName(project?.smtpSenderName || project?.name || '')
      setSenderEmail(
        project?.smtpSenderEmail &&
          emailBelongsToDomain(project.smtpSenderEmail, initial.name)
          ? project.smtpSenderEmail
          : defaultResendSenderEmail(initial.name),
      )
      setPhase('form')
    } catch (error) {
      failWith(error, 'Failed to load domains from Resend')
    }
  }, [callResend, failWith, project])

  useEffect(() => {
    if (!open) return
    void loadDomains()
    // Reload only when the dialog opens; edits in between must not reset the form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const handleOpenChange = (next: boolean) => {
    if (phase === 'submitting') return
    onOpenChange(next)
  }

  const handleDomainChange = (nextId: string) => {
    setDomainId(nextId)
    setValidationMessage('')
    const domain = domains.find((item) => item.id === nextId)
    if (domain && !emailBelongsToDomain(senderEmail, domain.name)) {
      setSenderEmail(defaultResendSenderEmail(domain.name))
    }
  }

  const handleSubmit = async () => {
    if (!selectedDomain) return

    const name = senderName.trim()
    const email = senderEmail.trim()
    if (!name) {
      setValidationMessage(t('Enter a sender name.'))
      return
    }
    if (!emailBelongsToDomain(email, selectedDomain.name)) {
      setValidationMessage(t('Enter a sender email on the selected domain.'))
      return
    }

    setValidationMessage('')
    setErrorMessage('')
    setPhase('submitting')

    let created: CreatedResendApiKey | null = null
    try {
      created = await callResend((accessToken) =>
        createResendApiKey(accessToken, {
          name: buildResendApiKeyName(project?.name ?? ''),
          domainId: selectedDomain.id,
        }),
      )

      await updateSMTPMutation.mutateAsync({
        enabled: true,
        senderName: name,
        senderEmail: email,
        replyTo: project?.smtpReplyToEmail || '',
        host: RESEND_SMTP_HOST,
        port: RESEND_SMTP_PORT,
        username: RESEND_SMTP_USERNAME,
        password: created.token,
        secure: RESEND_SMTP_SECURE,
      })

      // Make sure the SMTP form behind the dialog already shows the saved
      // values before the user can trigger a test email from here.
      await queryClient.refetchQueries({
        queryKey: projectQueryOptions(projectId).queryKey,
      })
      setPhase('success')
    } catch (error) {
      if (created) {
        // The key exists in Resend but the project never stored it; drop it
        // so retries do not pile up unused credentials.
        const orphanId = created.id
        void callResend((accessToken) =>
          deleteResendApiKey(accessToken, orphanId),
        ).catch(() => {})
      }
      failWith(error, 'Failed to set up SMTP with Resend')
    }
  }

  const isBusy = phase === 'loading' || phase === 'submitting'

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Set up SMTP with Resend')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Choose the verified domain to send from. Appwrite creates a sending-only Resend API key and saves it as your SMTP password.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 py-4">
          {phase === 'loading' ? (
            <div className="flex flex-col items-center justify-center gap-3 py-4">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="text-[13px] text-muted-foreground text-center">
                {t('Loading domains…')}
              </p>
            </div>
          ) : null}

          {phase === 'no-domains' ? (
            <div className="flex flex-col items-center gap-2 py-2 text-center">
              <p className="text-[13px] font-medium text-foreground">
                {t('No verified domains')}
              </p>
              <p className="text-[13px] text-muted-foreground">
                {t('Add and verify a domain in Resend, then check again.')}
              </p>
            </div>
          ) : null}

          {phase === 'form' ? (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label
                  htmlFor="resend-domain"
                  className="text-[12px] font-medium"
                >
                  {t('Domain')} <span className="text-destructive">*</span>
                </Label>
                <Select value={domainId} onValueChange={handleDomainChange}>
                  <SelectTrigger id="resend-domain" className="h-9 text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {domains.map((domain) => {
                      const verified = isVerifiedResendDomain(domain)
                      return (
                        <SelectItem
                          key={domain.id}
                          value={domain.id}
                          disabled={!verified}
                        >
                          {domain.name}
                          {!verified ? (
                            <span className="text-muted-foreground">
                              {' '}
                              ({t('unverified')})
                            </span>
                          ) : null}
                        </SelectItem>
                      )
                    })}
                  </SelectContent>
                </Select>
                <p className="text-[11px] text-muted-foreground">
                  {t(
                    'A sending-only API key restricted to this domain will be created in your Resend account.',
                  )}
                </p>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label
                    htmlFor="resend-sender-name"
                    className="text-[12px] font-medium"
                  >
                    {t('Sender name')}{' '}
                    <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="resend-sender-name"
                    value={senderName}
                    onChange={(event) => {
                      setSenderName(event.target.value)
                      setValidationMessage('')
                    }}
                    placeholder="John Doe"
                    className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                </div>
                <div className="space-y-2">
                  <Label
                    htmlFor="resend-sender-email"
                    className="text-[12px] font-medium"
                  >
                    {t('Sender email')}{' '}
                    <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="resend-sender-email"
                    type="email"
                    value={senderEmail}
                    onChange={(event) => {
                      setSenderEmail(event.target.value)
                      setValidationMessage('')
                    }}
                    placeholder={
                      selectedDomain
                        ? defaultResendSenderEmail(selectedDomain.name)
                        : 'noreply@example.com'
                    }
                    className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                  <p className="text-[11px] text-muted-foreground">
                    {t('Must use the selected domain.')}
                  </p>
                </div>
              </div>

              <div className="rounded-lg border border-border bg-background px-4 py-3">
                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-[12px]">
                  {SERVER_SETTINGS.map((setting) => (
                    <div key={setting.label} className="contents">
                      <dt className="text-muted-foreground">
                        {t(setting.label)}
                      </dt>
                      <dd className="text-end font-mono text-foreground">
                        {setting.value}
                      </dd>
                    </div>
                  ))}
                </dl>
              </div>

              {validationMessage ? (
                <p className="text-[12px] text-destructive">
                  {validationMessage}
                </p>
              ) : null}
            </div>
          ) : null}

          {phase === 'submitting' ? (
            <div className="flex flex-col items-center justify-center gap-3 py-4">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="text-[13px] text-muted-foreground text-center">
                {t('Creating API key and saving SMTP settings…')}
              </p>
            </div>
          ) : null}

          {phase === 'success' ? (
            <div className="flex flex-col items-center gap-3 text-center">
              <CheckCircle2 className="h-10 w-10 text-green-600" />
              <p className="text-[13px] text-foreground">
                {t(
                  'Custom SMTP is enabled and your project now sends emails through Resend.',
                )}
              </p>
              <p className="text-[12px] text-muted-foreground truncate max-w-full">
                {senderName.trim()} &lt;{senderEmail.trim()}&gt;
              </p>
            </div>
          ) : null}

          {phase === 'reauthorize' ? (
            <div className="flex flex-col items-center gap-2 py-2 text-center">
              <p className="text-[13px] font-medium text-foreground">
                {t('Resend authorization expired')}
              </p>
              <p className="text-[13px] text-muted-foreground">
                {t('Reconnect your Resend account to continue.')}
              </p>
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

        {!isBusy ? (
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            {phase === 'form' ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => handleOpenChange(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={!selectedDomain}
                  onClick={handleSubmit}
                >
                  {t('Set up')}
                </Button>
              </>
            ) : null}

            {phase === 'no-domains' ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => handleOpenChange(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  asChild
                >
                  <a href={RESEND_DOMAINS_URL} target="_blank" rel="noreferrer">
                    {t('Open Resend')}
                    <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                  </a>
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => void loadDomains()}
                >
                  {t('Check again')}
                </Button>
              </>
            ) : null}

            {phase === 'success' ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => handleOpenChange(false)}
                >
                  {t('Close')}
                </Button>
                {onSendTestEmail ? (
                  <Button
                    type="button"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => {
                      handleOpenChange(false)
                      onSendTestEmail()
                    }}
                  >
                    {t('Send test email')}
                  </Button>
                ) : null}
              </>
            ) : null}

            {phase === 'reauthorize' ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => handleOpenChange(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={onReauthorize}
                >
                  {t('Reconnect Resend')}
                </Button>
              </>
            ) : null}

            {phase === 'error' ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => handleOpenChange(false)}
                >
                  {t('Close')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => void loadDomains()}
                >
                  {t('Try again')}
                </Button>
              </>
            ) : null}
          </div>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
