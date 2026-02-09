import { useState, useEffect, useMemo } from 'react'
import {
  useProject,
  useUpdateSMTP,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
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
import { Loader2 } from 'lucide-react'
import { UpgradeCurtain } from '@/components/ui/upgrade-curtain'

interface SMTPProps {
  projectId: string
}

export function SMTP({ projectId }: SMTPProps) {
  const { project: projectData, isLoading } = useProject(projectId)
  const updateSMTPMutation = useUpdateSMTP(projectId)

  // Get raw project data for SMTP fields
  const rawProject = useMemo(() => {
    // We need to get the raw project data to access SMTP fields
    // This would ideally come from a hook that returns the full Models.Project
    return projectData as unknown
  }, [projectData])

  // Get project to access teamId (organization ID)
  const orgId = projectData?.teamId

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
    if (rawProject) {
      setEnabled(rawProject.smtpEnabled || false)
      setSenderName(rawProject.smtpSenderName || '')
      setSenderEmail(rawProject.smtpSenderEmail || '')
      setReplyTo(rawProject.smtpReplyTo || '')
      setHost(rawProject.smtpHost || '')
      setPort(rawProject.smtpPort || 587)
      setUsername(rawProject.smtpUsername || '')
      setPassword('') // Never show password
      setSecure(
        rawProject.smtpSecure === 'tls'
          ? 'tls'
          : rawProject.smtpSecure === 'ssl'
            ? 'ssl'
            : 'none',
      )
    }
  }, [rawProject])

  const hasChanges = useMemo(() => {
    if (!rawProject) return false
    return (
      enabled !== (rawProject.smtpEnabled || false) ||
      senderName !== (rawProject.smtpSenderName || '') ||
      senderEmail !== (rawProject.smtpSenderEmail || '') ||
      replyTo !== (rawProject.smtpReplyTo || '') ||
      host !== (rawProject.smtpHost || '') ||
      port !== (rawProject.smtpPort || 587) ||
      username !== (rawProject.smtpUsername || '') ||
      secure !==
        (rawProject.smtpSecure === 'tls'
          ? 'tls'
          : rawProject.smtpSecure === 'ssl'
            ? 'ssl'
            : 'none')
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
    rawProject,
  ])

  const handleUpdate = async () => {
    try {
      await updateSMTPMutation.mutateAsync({
        enabled,
        senderName: enabled ? senderName : undefined,
        senderEmail: enabled ? senderEmail : undefined,
        replyTo: enabled ? replyTo : undefined,
        host: enabled ? host : undefined,
        port: enabled ? port : undefined,
        username: enabled ? username : undefined,
        password: enabled && password ? password : undefined,
        secure: enabled ? (secure === 'none' ? '' : secure) : undefined,
      })
      toast.success(`SMTP server has been ${enabled ? 'enabled' : 'disabled'}.`)
    } catch (error: unknown) {
      toast.error(error.message || 'Failed to update SMTP settings')
    }
  }

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
            Custom SMTP server
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Configure a custom SMTP server to send emails from your own domain.
            This allows you to customize email templates and prevents emails
            from being labeled as spam.
          </p>
        </div>

        {/* Separator */}
        <div className="border-t border-border" />

        {/* Content with Upgrade Curtain */}
        <UpgradeCurtain
          isLocked={!supportsCustomSmtp}
          orgId={orgId}
          message="Custom SMTP is available on Appwrite Cloud Pro and higher plans."
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
                    Enable custom SMTP server
                  </Label>
                  <p className="text-[12px] text-muted-foreground mt-0.5">
                    When enabled, all emails will be sent through your
                    configured SMTP server.
                  </p>
                </div>
                <Switch
                  id="smtp-enabled"
                  checked={enabled}
                  onCheckedChange={setEnabled}
                  disabled={!supportsCustomSmtp || updateSMTPMutation.isPending}
                />
              </div>

              {/* Configuration Fields */}
              {enabled && (
                <div className="space-y-6">
                  {/* Sender Information Section */}
                  <div className="space-y-4">
                    <div>
                      <h4 className="text-[13px] font-medium text-foreground mb-3">
                        Sender information
                      </h4>
                      <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                          <Label
                            htmlFor="sender-name"
                            className="text-[12px] font-medium"
                          >
                            Sender name{' '}
                            <span className="text-destructive">*</span>
                          </Label>
                          <Input
                            id="sender-name"
                            placeholder="John Doe"
                            value={senderName}
                            onChange={(e) => setSenderName(e.target.value)}
                            disabled={updateSMTPMutation.isPending}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2">
                          <Label
                            htmlFor="sender-email"
                            className="text-[12px] font-medium"
                          >
                            Sender email{' '}
                            <span className="text-destructive">*</span>
                          </Label>
                          <Input
                            id="sender-email"
                            type="email"
                            placeholder="noreply@example.com"
                            value={senderEmail}
                            onChange={(e) => setSenderEmail(e.target.value)}
                            disabled={updateSMTPMutation.isPending}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2 sm:col-span-2">
                          <Label
                            htmlFor="reply-to"
                            className="text-[12px] font-medium"
                          >
                            Reply to
                          </Label>
                          <Input
                            id="reply-to"
                            type="email"
                            placeholder="support@example.com"
                            value={replyTo}
                            onChange={(e) => setReplyTo(e.target.value)}
                            disabled={updateSMTPMutation.isPending}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                          <p className="text-[11px] text-muted-foreground">
                            Optional. Email address where replies will be sent.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Server Configuration Section */}
                  <div className="space-y-4">
                    <div>
                      <h4 className="text-[13px] font-medium text-foreground mb-3">
                        Server configuration
                      </h4>
                      <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2 sm:col-span-2">
                          <Label
                            htmlFor="host"
                            className="text-[12px] font-medium"
                          >
                            Server host{' '}
                            <span className="text-destructive">*</span>
                          </Label>
                          <Input
                            id="host"
                            placeholder="smtp.example.com"
                            value={host}
                            onChange={(e) => setHost(e.target.value)}
                            disabled={updateSMTPMutation.isPending}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2">
                          <Label
                            htmlFor="port"
                            className="text-[12px] font-medium"
                          >
                            Server port{' '}
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
                            disabled={updateSMTPMutation.isPending}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2">
                          <Label
                            htmlFor="secure"
                            className="text-[12px] font-medium"
                          >
                            Secure protocol
                          </Label>
                          <Select
                            value={secure}
                            onValueChange={(value) =>
                              setSecure(value as 'tls' | 'ssl' | 'none')
                            }
                            disabled={updateSMTPMutation.isPending}
                          >
                            <SelectTrigger
                              id="secure"
                              className="h-9 text-[13px]"
                            >
                              <SelectValue placeholder="Select protocol" />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="tls">TLS</SelectItem>
                              <SelectItem value="ssl">SSL</SelectItem>
                              <SelectItem value="none">None</SelectItem>
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
                        Authentication
                      </h4>
                      <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                          <Label
                            htmlFor="username"
                            className="text-[12px] font-medium"
                          >
                            Username
                          </Label>
                          <Input
                            id="username"
                            placeholder="smtp@example.com"
                            value={username}
                            onChange={(e) => setUsername(e.target.value)}
                            disabled={updateSMTPMutation.isPending}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                        </div>

                        <div className="space-y-2">
                          <Label
                            htmlFor="password"
                            className="text-[12px] font-medium"
                          >
                            Password
                          </Label>
                          <Input
                            id="password"
                            type="password"
                            placeholder="Enter password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            disabled={updateSMTPMutation.isPending}
                            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                          />
                          <p className="text-[11px] text-muted-foreground">
                            Leave blank to keep current password unchanged.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Footer with Update Button */}
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={handleUpdate}
                disabled={
                  !hasChanges ||
                  !supportsCustomSmtp ||
                  updateSMTPMutation.isPending
                }
              >
                Update
              </Button>
            </div>
          </div>
        </UpgradeCurtain>
      </div>
    </div>
  )
}
