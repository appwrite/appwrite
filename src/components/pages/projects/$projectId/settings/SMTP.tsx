import { useState, useEffect, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  projectQueryOptions,
  useUpdateSMTP,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import { SendSMTPTestDialog } from './_components/SendSMTPTestDialog'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { Loader2 } from 'lucide-react'
import { UpgradeCurtain } from '@/components/ui/upgrade-curtain'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

interface SMTPProps {
  projectId: string
}

function secureFromProject(smtpSecure?: string): 'tls' | 'ssl' | 'none' {
  if (smtpSecure === 'tls') return 'tls'
  if (smtpSecure === 'ssl') return 'ssl'
  return 'none'
}

export function SMTP({ projectId }: SMTPProps) {
  const t = useT()
  const { account } = useAuth()
  const { data: project, isLoading } = useQuery(projectQueryOptions(projectId))
  const updateSMTPMutation = useUpdateSMTP(projectId)
  const [testDialogOpen, setTestDialogOpen] = useState(false)

  const orgId = project?.teamId

  // Get organization plan to check if custom SMTP is supported
  const { plan: organizationPlan } = useOrganizationPlan(orgId)
  const supportsCustomSmtp = organizationPlan?.customSmtp ?? false

  const [enabled, setEnabled] = useState(false)
  const [senderName, setSenderName] = useState('')
  const [senderEmail, setSenderEmail] = useState('')
  const [replyTo, setReplyTo] = useState('')
  const [host, setHost] = useState('')
  const [port, setPort] = useState<number>(587)
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [secure, setSecure] = useState<'tls' | 'ssl' | 'none'>('tls')

  // Initialize form from project data
  useEffect(() => {
    if (project) {
      setEnabled(project.smtpEnabled || false)
      setSenderName(project.smtpSenderName || '')
      setSenderEmail(project.smtpSenderEmail || '')
      setReplyTo(project.smtpReplyToEmail || '')
      setHost(project.smtpHost || '')
      setPort(project.smtpPort || 587)
      setUsername(project.smtpUsername || '')
      setPassword('') // Never show password
      setSecure(secureFromProject(project.smtpSecure))
    }
  }, [project])

  const hasChanges = useMemo(() => {
    if (!project) return false
    return (
      enabled !== (project.smtpEnabled || false) ||
      senderName !== (project.smtpSenderName || '') ||
      senderEmail !== (project.smtpSenderEmail || '') ||
      replyTo !== (project.smtpReplyToEmail || '') ||
      host !== (project.smtpHost || '') ||
      port !== (project.smtpPort || 587) ||
      username !== (project.smtpUsername || '') ||
      secure !== secureFromProject(project.smtpSecure)
    )
  }, [
    enabled,
    senderName,
    senderEmail,
    replyTo,
    host,
    port,
    username,
    secure,
    project,
  ])

  const smtpFormPayload = useMemo(
    () => ({
      enabled,
      senderName: enabled ? senderName : undefined,
      senderEmail: enabled ? senderEmail : undefined,
      replyTo: enabled ? replyTo : undefined,
      host: enabled ? host : undefined,
      port: enabled ? port : undefined,
      username: enabled ? username : undefined,
      password: enabled && password ? password : undefined,
      secure: enabled ? (secure === 'none' ? '' : secure) : undefined,
    }),
    [
      enabled,
      senderName,
      senderEmail,
      replyTo,
      host,
      port,
      username,
      password,
      secure,
    ],
  )

  const handleUpdate = async () => {
    try {
      await updateSMTPMutation.mutateAsync(smtpFormPayload)
      setPassword('')
      toast.success(enabled ? t('SMTP server has been enabled.') : t('SMTP server has been disabled.'))
    } catch (error: unknown) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to update SMTP settings'),
      )
    }
  }

  const hasSavedSmtpPassword = Boolean(project?.smtpHost)
  const isFormReadyForTest =
    enabled &&
    senderName.trim() !== '' &&
    senderEmail.trim() !== '' &&
    host.trim() !== '' &&
    port > 0 &&
    (password.trim() !== '' || hasSavedSmtpPassword)

  const testDisabledReason = !supportsCustomSmtp
    ? t('Custom SMTP is available on Appwrite Cloud Pro and higher plans.') // pragma: allowlist secret
    : !enabled
      ? t('Enable custom SMTP to send a test email.')
      : !isFormReadyForTest
        ? t('Fill in sender name, sender email, server host, port, and password (for new setups) before sending a test email.')
        : undefined
  const canSendTest = supportsCustomSmtp && isFormReadyForTest

  const isSmtpBusy = updateSMTPMutation.isPending

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-4 sm:px-6">
      {/* SMTP Configuration Card */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        {/* Header */}
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Custom SMTP server')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Configure a custom SMTP server to send emails from your own domain. This allows you to customize email templates and prevents emails from being labeled as spam.')}
          </p>
        </div>

        {/* Separator */}
        <div className="border-t border-border" />

        {/* Content with Upgrade Curtain */}
        <UpgradeCurtain
          isLocked={!supportsCustomSmtp}
          orgId={orgId}
          message={t('Custom SMTP is available on Appwrite Cloud Pro and higher plans.') /* pragma: allowlist secret */}
        >
          <div>
            <div className="px-6 py-4">
              {/* Enable/Disable Toggle */}
              <div className="flex items-center justify-between mb-6">
                <div className="flex-1">
                  <Label
                    htmlFor="smtp-enabled"
                    className="text-[13px] font-medium text-foreground"
                  >
                    {t('Enable custom SMTP server')}
                  </Label>
                  <p className="text-[12px] text-muted-foreground mt-0.5">
                    {t('When enabled, all emails will be sent through your configured SMTP server.')}
                  </p>
                </div>
                <Switch
                  id="smtp-enabled"
                  checked={enabled}
                  onCheckedChange={setEnabled}
                  disabled={!supportsCustomSmtp || isSmtpBusy}
                />
              </div>

              {/* Configuration Fields */}
              {enabled && (
                <div className="space-y-6">
                  {/* Sender Information Section */}
                  <div className="space-y-4">
                    <div>
                      <h4 className="text-[13px] font-medium text-foreground mb-3">
                        {t('Sender information')}
                      </h4>
                      <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                          <Label
                            htmlFor="sender-name"
                            className="text-[12px] font-medium"
                          >
                            {t('Sender name')}{' '}
                            <span className="text-destructive">*</span>
                          </Label>
                          <Input
                            id="sender-name"
                            placeholder="John Doe"
                            value={senderName}
                            onChange={(e) => setSenderName(e.target.value)}
                            disabled={isSmtpBusy}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2">
                          <Label
                            htmlFor="sender-email"
                            className="text-[12px] font-medium"
                          >
                            {t('Sender email')}{' '}
                            <span className="text-destructive">*</span>
                          </Label>
                          <Input
                            id="sender-email"
                            type="email"
                            placeholder="noreply@example.com"
                            value={senderEmail}
                            onChange={(e) => setSenderEmail(e.target.value)}
                            disabled={isSmtpBusy}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2 sm:col-span-2">
                          <Label
                            htmlFor="reply-to"
                            className="text-[12px] font-medium"
                          >
                            {t('Reply to')}
                          </Label>
                          <Input
                            id="reply-to"
                            type="email"
                            placeholder="support@example.com"
                            value={replyTo}
                            onChange={(e) => setReplyTo(e.target.value)}
                            disabled={isSmtpBusy}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                          <p className="text-[11px] text-muted-foreground">
                            {t('Optional. Email address where replies will be sent.')}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Server Configuration Section */}
                  <div className="space-y-4">
                    <div>
                      <h4 className="text-[13px] font-medium text-foreground mb-3">
                        {t('Server configuration')}
                      </h4>
                      <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2 sm:col-span-2">
                          <Label
                            htmlFor="host"
                            className="text-[12px] font-medium"
                          >
                            {t('Server host')}{' '}
                            <span className="text-destructive">*</span>
                          </Label>
                          <Input
                            id="host"
                            placeholder="smtp.example.com"
                            value={host}
                            onChange={(e) => setHost(e.target.value)}
                            disabled={isSmtpBusy}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2">
                          <Label
                            htmlFor="port"
                            className="text-[12px] font-medium"
                          >
                            {t('Server port')}{' '}
                            <span className="text-destructive">*</span>
                          </Label>
                          <Input
                            id="port"
                            type="number"
                            placeholder="587"
                            value={port || ''}
                            onChange={(e) =>
                              setPort(parseInt(e.target.value) || 587)
                            }
                            disabled={isSmtpBusy}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2">
                          <Label
                            htmlFor="secure"
                            className="text-[12px] font-medium"
                          >
                            {t('Secure protocol')}
                          </Label>
                          <Select
                            value={secure}
                            onValueChange={(value) =>
                              setSecure(value as 'tls' | 'ssl' | 'none')
                            }
                            disabled={isSmtpBusy}
                          >
                            <SelectTrigger
                              id="secure"
                              className="h-9 text-[13px]"
                            >
                              <SelectValue placeholder={t('Select protocol')} />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="tls">TLS</SelectItem>
                              <SelectItem value="ssl">SSL</SelectItem>
                              <SelectItem value="none">{t('None')}</SelectItem>
                            </SelectContent>
                          </Select>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Authentication Section */}
                  <div className="space-y-4">
                    <div>
                      <h4 className="text-[13px] font-medium text-foreground mb-3">
                        {t('Authentication')}
                      </h4>
                      <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                          <Label
                            htmlFor="username"
                            className="text-[12px] font-medium"
                          >
                            {t('Username')}
                          </Label>
                          <Input
                            id="username"
                            placeholder="smtp@example.com"
                            value={username}
                            onChange={(e) => setUsername(e.target.value)}
                            disabled={isSmtpBusy}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2">
                          <Label
                            htmlFor="password"
                            className="text-[12px] font-medium"
                          >
                            {t('Password')}
                          </Label>
                          <Input
                            id="password"
                            type="password"
                            placeholder={t('Enter password')}
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            disabled={isSmtpBusy}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                          <p className="text-[11px] text-muted-foreground">
                            {t('Leave blank to keep current password unchanged.')}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Footer */}
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <div className="flex flex-wrap gap-2">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleUpdate}
                  disabled={
                    !hasChanges || !supportsCustomSmtp || isSmtpBusy
                  }
                >
                  {t('Update')}
                </Button>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={() => setTestDialogOpen(true)}
                        disabled={!canSendTest || isSmtpBusy}
                      >
                        {t('Send test email')}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  {testDisabledReason ? (
                    <TooltipContent className="max-w-xs text-[13px]">
                      {testDisabledReason}
                    </TooltipContent>
                  ) : null}
                </Tooltip>
              </div>
            </div>
          </div>
        </UpgradeCurtain>
      </div>

      <SendSMTPTestDialog
        open={testDialogOpen}
        onOpenChange={setTestDialogOpen}
        projectId={projectId}
        smtp={smtpFormPayload}
        defaultRecipientEmail={account?.email}
        onSent={() => setPassword('')}
      />
    </div>
  )
}
