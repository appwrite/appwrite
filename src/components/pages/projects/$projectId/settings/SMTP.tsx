import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { useProject, useUpdateSMTP } from '@/lib/react-query/hooks'
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { toast } from 'sonner'
import { Loader2, AlertCircle } from 'lucide-react'
import { Link } from '@tanstack/react-router'

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
    return projectData as any
  }, [projectData])

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
    } catch (error: any) {
      toast.error(error.message || 'Failed to update SMTP settings')
    }
  }

  // Check if plan is free (this would need to come from organization data)
  const isFreePlan = false // TODO: Get from organization plan

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
      <div className="flex flex-col gap-6">

        {/* Free Plan Alert */}
        {isFreePlan && (
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertTitle>Custom SMTP is a Pro plan feature</AlertTitle>
            <AlertDescription>
              Upgrade to enable custom SMTP server.
              <Button variant="outline" size="sm" className="ml-2" asChild>
                <Link to="/organizations/$orgId/billing" params={{ orgId: rawProject?.teamId }}>
                  Upgrade plan
                </Link>
              </Button>
            </AlertDescription>
          </Alert>
        )}

        {/* SMTP Configuration */}
        <div className="rounded-lg border border-border bg-card p-6 space-y-6">
          {/* Enable/Disable Switch */}
          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <Label htmlFor="smtp-enabled" className="text-[14px] font-medium">
                Custom SMTP server
              </Label>
              <p className="text-[13px] text-muted-foreground">
                Enabling this option allows customizing email templates and prevents emails from
                being labeled as spam.
              </p>
            </div>
            <Switch
              id="smtp-enabled"
              checked={enabled}
              onCheckedChange={setEnabled}
              disabled={isFreePlan || updateSMTPMutation.isPending}
            />
          </div>

          {enabled && !isFreePlan && (
            <>
              <div className="border-t border-border" />

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="sender-name" className="text-[12px] font-medium">
                    Sender name <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="sender-name"
                    placeholder="Enter sender name"
                    value={senderName}
                    onChange={(e) => setSenderName(e.target.value)}
                    disabled={updateSMTPMutation.isPending}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="sender-email" className="text-[12px] font-medium">
                    Sender email <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="sender-email"
                    type="email"
                    placeholder="user@example.io"
                    value={senderEmail}
                    onChange={(e) => setSenderEmail(e.target.value)}
                    disabled={updateSMTPMutation.isPending}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="reply-to" className="text-[12px] font-medium">
                    Reply to
                  </Label>
                  <Input
                    id="reply-to"
                    type="email"
                    placeholder="user@example.io"
                    value={replyTo}
                    onChange={(e) => setReplyTo(e.target.value)}
                    disabled={updateSMTPMutation.isPending}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="host" className="text-[12px] font-medium">
                    Server host <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="host"
                    placeholder="smtp.server.com"
                    value={host}
                    onChange={(e) => setHost(e.target.value)}
                    disabled={updateSMTPMutation.isPending}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="port" className="text-[12px] font-medium">
                    Server port <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="port"
                    type="number"
                    placeholder="587"
                    value={port || ''}
                    onChange={(e) => setPort(parseInt(e.target.value) || 587)}
                    disabled={updateSMTPMutation.isPending}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="secure" className="text-[12px] font-medium">
                    Secure protocol
                  </Label>
                  <Select
                    value={secure}
                    onValueChange={(value) => setSecure(value as 'tls' | 'ssl' | 'none')}
                    disabled={updateSMTPMutation.isPending}
                  >
                    <SelectTrigger id="secure">
                      <SelectValue placeholder="Select protocol" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="tls">TLS</SelectItem>
                      <SelectItem value="ssl">SSL</SelectItem>
                      <SelectItem value="none">None</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="username" className="text-[12px] font-medium">
                    Username
                  </Label>
                  <Input
                    id="username"
                    placeholder="Enter username"
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    disabled={updateSMTPMutation.isPending}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="password" className="text-[12px] font-medium">
                    Password
                  </Label>
                  <Input
                    id="password"
                    type="password"
                    placeholder="Enter password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    disabled={updateSMTPMutation.isPending}
                  />
                </div>
              </div>
            </>
          )}
        </div>

        {/* Update Button */}
        <div className="flex justify-end">
          <Button
            onClick={handleUpdate}
            disabled={!hasChanges || isFreePlan || updateSMTPMutation.isPending}
          >
            Update
          </Button>
        </div>
      </div>
    </div>
  )
}

