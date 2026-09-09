import { AlertCircle, CheckCircle2, ExternalLink, Loader2 } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
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
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { defaultSenderEmail } from '@/lib/smtp/quick-setup'
import type { AvailableSmtpQuickSetupProvider } from '@/lib/smtp/providers'
import { useSmtpQuickSetup, type ProviderApiCall } from './use-smtp-quick-setup'

interface SmtpQuickSetupWizardProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  project: Models.Project | undefined
  provider: AvailableSmtpQuickSetupProvider
  callProvider: ProviderApiCall
  /** Start a fresh `createOAuth2Token` round trip (token refresh is no longer possible). */
  onReauthorize: () => void
}

/**
 * Fullscreen presentation of the quick setup flow, behind the
 * `smtpQuickSetupWizard` debug flag. Same steps as
 * {@link SmtpQuickSetupDialog}, laid out as a wizard page with a live summary
 * of what will be saved.
 */
export function SmtpQuickSetupWizard({
  open,
  onOpenChange,
  projectId,
  project,
  provider,
  callProvider,
  onReauthorize,
}: SmtpQuickSetupWizardProps) {
  const t = useT()
  const setup = useSmtpQuickSetup({
    active: open,
    projectId,
    project,
    provider,
    callProvider,
  })
  const { phase, selectedDomain } = setup

  if (!open) return null

  const close = () => {
    if (phase === 'submitting') return
    onOpenChange(false)
  }

  const footer = setup.isBusy ? null : (
    <div className="flex w-full justify-end gap-2">
      {phase === 'form' ? (
        <>
          <Button variant="outline" onClick={close}>
            {t('Cancel')}
          </Button>
          <Button
            disabled={!selectedDomain}
            onClick={() => void setup.submit()}
          >
            {t('Set up')}
          </Button>
        </>
      ) : null}

      {phase === 'no-domains' ? (
        <>
          <Button variant="outline" onClick={close}>
            {t('Cancel')}
          </Button>
          <Button variant="outline" asChild>
            <a href={provider.domainsUrl} target="_blank" rel="noreferrer">
              {t('Manage domains')}
              <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
            </a>
          </Button>
          <Button onClick={() => void setup.reload()}>
            {t('Check again')}
          </Button>
        </>
      ) : null}

      {phase === 'success' ? (
        <Button onClick={close}>{t('Close')}</Button>
      ) : null}

      {phase === 'reauthorize' ? (
        <>
          <Button variant="outline" onClick={close}>
            {t('Cancel')}
          </Button>
          <Button onClick={onReauthorize}>{t('Reconnect')}</Button>
        </>
      ) : null}

      {phase === 'error' ? (
        <>
          <Button variant="outline" onClick={close}>
            {t('Close')}
          </Button>
          <Button onClick={() => void setup.reload()}>{t('Try again')}</Button>
        </>
      ) : null}
    </div>
  )

  const showSummary = phase === 'form'

  return (
    <WizardLayout
      fullscreen
      title={t(provider.setupTitle)}
      description={t(provider.setupDescription)}
      onClose={close}
      useSidebar={showSummary}
      footer={footer}
      footerAlign="right"
      sidebar={
        showSummary ? (
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="flex items-center gap-3 px-4 py-3">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <img
                  src={provider.iconPath}
                  alt=""
                  aria-hidden="true"
                  className={`h-4 w-4 ${PUBLIC_ICON_MUTED_CLASSES}`}
                />
              </div>
              <span className="text-[13px] font-medium text-foreground">
                {provider.name}
              </span>
            </div>
            <div className="border-t border-border" />
            <dl className="px-4 py-3 text-[12px]">
              <SummaryRow
                label={t('Sender')}
                value={
                  setup.senderName.trim()
                    ? `${setup.senderName.trim()} <${setup.senderEmail.trim()}>`
                    : setup.senderEmail.trim()
                }
              />
              <SummaryRow
                label={t('Domain')}
                value={selectedDomain?.name ?? ''}
              />
              {setup.serverSettings.map((setting) => (
                <SummaryRow
                  key={setting.label}
                  label={t(setting.label)}
                  value={setting.value}
                  mono
                />
              ))}
            </dl>
          </div>
        ) : undefined
      }
    >
      {phase === 'loading' || phase === 'submitting' ? (
        <div className="flex flex-col items-center justify-center gap-3 py-16">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          <p className="text-[13px] text-muted-foreground text-center">
            {phase === 'loading'
              ? t('Loading domains…')
              : t('Creating credential and saving SMTP settings…')}
          </p>
        </div>
      ) : null}

      {phase === 'no-domains' ? (
        <div className="flex flex-col items-center gap-2 py-16 text-center">
          <p className="text-[15px] font-semibold text-foreground">
            {t('No verified domains')}
          </p>
          <p className="text-[13px] text-muted-foreground">
            {t('Add and verify a sending domain, then check again.')}
          </p>
        </div>
      ) : null}

      {phase === 'form' ? (
        <div className="max-w-xl space-y-8">
          <section className="space-y-3">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Sending domain')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                {t(
                  'The credential is restricted to this domain and can only send email.',
                )}
              </p>
            </div>
            <div className="space-y-2">
              <Label
                htmlFor="quick-setup-wizard-domain"
                className="text-[12px] font-medium"
              >
                {t('Domain')} <span className="text-destructive">*</span>
              </Label>
              <Select value={setup.domainId} onValueChange={setup.changeDomain}>
                <SelectTrigger
                  id="quick-setup-wizard-domain"
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
            </div>
          </section>

          <section className="space-y-3">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Sender information')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                {t('Must use the selected domain.')}
              </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label
                  htmlFor="quick-setup-wizard-sender-name"
                  className="text-[12px] font-medium"
                >
                  {t('Sender name')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="quick-setup-wizard-sender-name"
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
                  htmlFor="quick-setup-wizard-sender-email"
                  className="text-[12px] font-medium"
                >
                  {t('Sender email')}{' '}
                  <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="quick-setup-wizard-sender-email"
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
              </div>
            </div>
          </section>

          {setup.validationMessage ? (
            <p className="text-[12px] text-destructive">
              {setup.validationMessage}
            </p>
          ) : null}
        </div>
      ) : null}

      {phase === 'success' ? (
        <div className="flex flex-col items-center gap-3 py-16 text-center">
          <CheckCircle2 className="h-10 w-10 text-green-600" />
          <p className="text-[15px] font-semibold text-foreground">
            {t(
              'Custom SMTP is enabled and your project now sends emails through this provider.',
            )}
          </p>
          <p className="text-[13px] text-muted-foreground">
            {setup.senderName.trim()} &lt;{setup.senderEmail.trim()}&gt;
          </p>
        </div>
      ) : null}

      {phase === 'reauthorize' ? (
        <div className="flex flex-col items-center gap-2 py-16 text-center">
          <p className="text-[15px] font-semibold text-foreground">
            {t('Authorization expired')}
          </p>
          <p className="text-[13px] text-muted-foreground">
            {t('Reconnect your provider account to continue.')}
          </p>
        </div>
      ) : null}

      {phase === 'error' ? (
        <div className="max-w-xl py-4">
          <Alert variant="destructive" className="border-destructive/30">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription className="text-[13px]">
              {setup.errorMessage}
            </AlertDescription>
          </Alert>
        </div>
      ) : null}
    </WizardLayout>
  )
}

function SummaryRow({
  label,
  value,
  mono = false,
}: {
  label: string
  value: string
  mono?: boolean
}) {
  return (
    <div className="flex items-start justify-between gap-4 py-1">
      <dt className="text-muted-foreground shrink-0">{label}</dt>
      <dd
        className={`text-end text-foreground truncate ${mono ? 'font-mono' : ''}`}
      >
        {value || '-'}
      </dd>
    </div>
  )
}
