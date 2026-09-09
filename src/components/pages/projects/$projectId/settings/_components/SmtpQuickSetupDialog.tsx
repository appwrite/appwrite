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
import { useT } from '@/lib/i18n/translate'
import { defaultSenderEmail } from '@/lib/smtp/quick-setup'
import type { AvailableSmtpQuickSetupProvider } from '@/lib/smtp/providers'
import { useSmtpQuickSetup, type ProviderApiCall } from './use-smtp-quick-setup'

export type { ProviderApiCall }

interface SmtpQuickSetupDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  project: Models.Project | undefined
  provider: AvailableSmtpQuickSetupProvider
  callProvider: ProviderApiCall
  /** Start a fresh `createOAuth2Token` round trip (token refresh is no longer possible). */
  onReauthorize: () => void
}

/** Default presentation of the quick setup flow: a single modal step. */
export function SmtpQuickSetupDialog({
  open,
  onOpenChange,
  projectId,
  project,
  provider,
  callProvider,
  onReauthorize,
}: SmtpQuickSetupDialogProps) {
  const t = useT()
  const setup = useSmtpQuickSetup({
    active: open,
    projectId,
    project,
    provider,
    callProvider,
  })
  const { phase, selectedDomain } = setup

  const handleOpenChange = (next: boolean) => {
    if (phase === 'submitting') return
    onOpenChange(next)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t(provider.setupTitle)}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(provider.setupDescription)}
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
                {t('Add and verify a sending domain, then check again.')}
              </p>
            </div>
          ) : null}

          {phase === 'form' ? (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label
                  htmlFor="quick-setup-domain"
                  className="text-[12px] font-medium"
                >
                  {t('Domain')} <span className="text-destructive">*</span>
                </Label>
                <Select
                  value={setup.domainId}
                  onValueChange={setup.changeDomain}
                >
                  <SelectTrigger
                    id="quick-setup-domain"
                    className="h-9 text-[13px]"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {setup.domains.map((domain) => (
                      <SelectItem
                        key={domain.id}
                        value={domain.id}
                        disabled={!domain.verified}
                      >
                        {domain.name}
                        {!domain.verified ? (
                          <span className="text-muted-foreground">
                            {' '}
                            ({t('unverified')})
                          </span>
                        ) : null}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-[11px] text-muted-foreground">
                  {t(
                    'The credential is restricted to this domain and can only send email.',
                  )}
                </p>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label
                    htmlFor="quick-setup-sender-name"
                    className="text-[12px] font-medium"
                  >
                    {t('Sender name')}{' '}
                    <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="quick-setup-sender-name"
                    value={setup.senderName}
                    onChange={(event) =>
                      setup.changeSenderName(event.target.value)
                    }
                    placeholder="John Doe"
                    className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                </div>
                <div className="space-y-2">
                  <Label
                    htmlFor="quick-setup-sender-email"
                    className="text-[12px] font-medium"
                  >
                    {t('Sender email')}{' '}
                    <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="quick-setup-sender-email"
                    type="email"
                    value={setup.senderEmail}
                    onChange={(event) =>
                      setup.changeSenderEmail(event.target.value)
                    }
                    placeholder={
                      selectedDomain
                        ? defaultSenderEmail(selectedDomain.name)
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
                  {setup.serverSettings.map((setting) => (
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

              {setup.validationMessage ? (
                <p className="text-[12px] text-destructive">
                  {setup.validationMessage}
                </p>
              ) : null}
            </div>
          ) : null}

          {phase === 'submitting' ? (
            <div className="flex flex-col items-center justify-center gap-3 py-4">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="text-[13px] text-muted-foreground text-center">
                {t('Creating credential and saving SMTP settings…')}
              </p>
            </div>
          ) : null}

          {phase === 'success' ? (
            <div className="flex flex-col items-center gap-3 text-center">
              <CheckCircle2 className="h-10 w-10 text-green-600" />
              <p className="text-[13px] text-foreground">
                {t(
                  'Custom SMTP is enabled and your project now sends emails through this provider.',
                )}
              </p>
              <p className="text-[12px] text-muted-foreground truncate max-w-full">
                {setup.senderName.trim()} &lt;{setup.senderEmail.trim()}&gt;
              </p>
            </div>
          ) : null}

          {phase === 'reauthorize' ? (
            <div className="flex flex-col items-center gap-2 py-2 text-center">
              <p className="text-[13px] font-medium text-foreground">
                {t('Authorization expired')}
              </p>
              <p className="text-[13px] text-muted-foreground">
                {t('Reconnect your provider account to continue.')}
              </p>
            </div>
          ) : null}

          {phase === 'error' ? (
            <Alert variant="destructive" className="border-destructive/30">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {setup.errorMessage}
              </AlertDescription>
            </Alert>
          ) : null}
        </div>

        {!setup.isBusy ? (
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
                  onClick={() => void setup.submit()}
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
                  <a
                    href={provider.domainsUrl}
                    target="_blank"
                    rel="noreferrer"
                  >
                    {t('Manage domains')}
                    <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                  </a>
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => void setup.reload()}
                >
                  {t('Check again')}
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
                  {t('Reconnect')}
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
                  onClick={() => void setup.reload()}
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
