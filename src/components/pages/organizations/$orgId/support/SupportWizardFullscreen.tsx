import { useState, useEffect, useRef } from 'react'
import { useParams } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  useOrganizationPlan,
  useOrganizationProjects,
} from '@/lib/react-query/hooks'
import { useSmartNavigation } from '@/lib/hooks/useSmartNavigation'
import {
  submitSupportTicket,
  getSupportHoursInLocalTime,
  getSupportAnalyticsEvent,
} from '@/lib/support'
import { toast } from 'sonner'
import {
  Activity,
  AlertTriangle,
  BookOpen,
  ExternalLink,
  CheckCircle2,
  Upload,
  X,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { APPWRITE_SUPPORT_EMAIL } from '@/lib/utils/error-formatting'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

const SUBJECT_MAX = 128

const SUBJECT_PLACEHOLDER = 'Brief summary of your issue'
const MESSAGE_PLACEHOLDER =
  'Describe your issue or question in detail. Include any relevant context (e.g. project, SDK version, error messages) so we can help faster.'
const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL || CONTACT_ENTERPRISE_URL
const SUPPORT_DISCORD_URL = '/discord'
const SUPPORT_GITHUB_ISSUES_URL =
  'https://github.com/appwrite/appwrite/issues/new/choose'
const MESSAGE_MAX = 4096
const ATTACHMENT_MAX_MB = 5
const ATTACHMENT_MAX_BYTES = ATTACHMENT_MAX_MB * 1024 * 1024
/** Sentinel for "no project" - Radix Select does not allow value="" */
const NO_PROJECT_VALUE = '__none__'

type SupportSubmitError =
  | { kind: 'portal' }
  | { kind: 'attachment'; message: string }

function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes'
  const k = 1000
  const sizes = ['Bytes', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

export function SupportWizardFullscreen() {
  const t = useT()
  const params = useParams({ strict: false })
  const orgId = params.orgId as string | undefined
  // Same as change plan wizard: no fallbackPath - use internal console history so we return to the page user was on (billing, members, etc.)
  const handleCancel = useSmartNavigation()

  const { account } = useAuth()
  const { plan } = useOrganizationPlan(orgId)
  const { projects } = useOrganizationProjects(orgId)

  const [supportHours, setSupportHours] = useState(() =>
    getSupportHoursInLocalTime(),
  )
  const [projectId, setProjectId] = useState<string>(NO_PROJECT_VALUE)
  const [subject, setSubject] = useState('')
  const [message, setMessage] = useState('')
  const [attachment, setAttachment] = useState<File | null>(null)
  const [isDraggingAttachment, setIsDraggingAttachment] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [submitError, setSubmitError] = useState<SupportSubmitError | null>(
    null,
  )
  const attachmentInputRef = useRef<HTMLInputElement>(null)
  const submitErrorRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const interval = setInterval(() => {
      setSupportHours(getSupportHoursInLocalTime())
    }, 60000)
    return () => clearInterval(interval)
  }, [])

  useEffect(() => {
    if (!submitError) return
    submitErrorRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [submitError])

  const projectList = projects ?? []

  const email = account?.email ?? ''
  const firstName = account?.name ?? 'Unknown'
  const billingPlanId = plan?.$id ?? ''

  const canSubmit =
    !!orgId &&
    !!email &&
    subject.trim().length > 0 &&
    subject.length <= SUBJECT_MAX &&
    message.trim().length > 0 &&
    message.length <= MESSAGE_MAX &&
    !isSubmitting

  const handleAttachmentSelect = (file: File) => {
    if (file.size > ATTACHMENT_MAX_BYTES) {
      toast.error(`${t('File must be')} ${ATTACHMENT_MAX_MB} ${t('MB or less')}`)
      return
    }
    setSubmitError((e) => (e?.kind === 'attachment' ? null : e))
    setAttachment(file)
  }

  const handleAttachmentInputChange = (
    e: React.ChangeEvent<HTMLInputElement>,
  ) => {
    const file = e.target.files?.[0]
    if (file) handleAttachmentSelect(file)
  }

  const handleAttachmentDragOver = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDraggingAttachment(true)
  }

  const handleAttachmentDragLeave = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDraggingAttachment(false)
  }

  const handleAttachmentDrop = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDraggingAttachment(false)
    const file = e.dataTransfer.files?.[0]
    if (file) handleAttachmentSelect(file)
  }

  const handleSubmit = async () => {
    if (!canSubmit || !orgId) return
    setSubmitError(null)
    setIsSubmitting(true)
    try {
      await submitSupportTicket({
        email,
        firstName,
        subject: subject.trim(),
        message: message.trim(),
        organizationId: orgId,
        projectId:
          projectId && projectId !== NO_PROJECT_VALUE ? projectId : undefined,
        billingPlanId: billingPlanId || undefined,
        attachment: attachment ?? undefined,
      })
      const eventName = getSupportAnalyticsEvent()
      if (typeof window !== 'undefined' && (window as unknown).track) {
        ;(window as unknown).track(eventName, {})
      }
      setSubmitted(true)
    } catch (err) {
      const eventName = getSupportAnalyticsEvent()
      if (typeof window !== 'undefined' && (window as unknown).track) {
        ;(window as unknown).track(eventName, { error: String(err) })
      }
      const errMessage = err instanceof Error ? err.message : String(err)
      if (errMessage.includes('Attachment must be')) {
        setSubmitError({ kind: 'attachment', message: errMessage })
      } else {
        setSubmitError({ kind: 'portal' })
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  const sidebar = (
    <div className="space-y-6">
      {/* Support availability */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between gap-3">
            <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
              {t('Support hours')}
            </h3>
            <span
              className={`shrink-0 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-medium uppercase tracking-wider ${
                supportHours.isOpen
                  ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                  : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
              }`}
            >
              <span
                className={`h-1 w-1 rounded-full ${
                  supportHours.isOpen ? 'bg-emerald-500' : 'bg-amber-500'
                }`}
              />
              {supportHours.isOpen ? t('Online') : t('Offline')}
            </span>
          </div>
          <p className="text-[13px] text-muted-foreground tabular-nums whitespace-nowrap mt-2">
            {t('Mon–Fri')} {supportHours.startLocal} – {supportHours.endLocal}
          </p>
          <p className="text-[12px] text-muted-foreground/70 mt-1 font-mono tracking-tight">
            {supportHours.timezone}
          </p>
        </div>
        <div className="border-t border-border/80" />
        <div className="px-6 py-3 bg-muted/30">
          <p className="text-[12px] text-muted-foreground/90 leading-relaxed">
            {t('Tickets can be submitted anytime; we reply during support hours.')}
          </p>
        </div>
      </div>

      {/* Enterprise / 24/7 CTA */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
            {t('Need 24/7 or enterprise support?')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2 leading-relaxed">
            {t('Get dedicated support and SLAs for your organization.')}
          </p>
          <Button variant="outline" size="sm" className="mt-4 w-full" asChild>
            <a
              href={CONTACT_SALES_URL}
              target="_blank"
              rel="noopener noreferrer"
            >
              {t('Contact sales')}
            </a>
          </Button>
        </div>
      </div>

      {/* What happens next */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
            {t('What happens next')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2 leading-relaxed">
            {t(
              'Confirmation email with ticket ID. Typically within 24h during support hours.',
            )}
          </p>
        </div>
      </div>

      {/* Resources */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
            {t('Resources')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2 leading-relaxed">
            {t('Docs, status, and community')}
          </p>
        </div>
        <div className="border-t border-border/80" />
        <div className="px-6 py-4">
          <div className="grid grid-cols-2 gap-2">
            <a
              href="https://status.appwrite.online"
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[12px] font-medium text-foreground hover:bg-muted/50 hover:border-border transition-colors"
            >
              <Activity className="h-4 w-4 shrink-0 text-muted-foreground" />
              <span>{t('Status')}</span>
              <ExternalLink className="h-3 w-3 ms-auto shrink-0 text-muted-foreground" />
            </a>
            <DocsRouteLink className="flex items-center gap-1.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[12px] font-medium text-foreground hover:bg-muted/50 hover:border-border transition-colors" href="/docs">
              <BookOpen className="h-4 w-4 shrink-0 text-muted-foreground" />
              <span>{t('Docs')}</span>
              <ExternalLink className="h-3 w-3 ms-auto shrink-0 text-muted-foreground" />
            </DocsRouteLink>
            <a
              href={SUPPORT_DISCORD_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[12px] font-medium text-foreground hover:bg-muted/50 hover:border-border transition-colors"
            >
              <svg
                className="h-4 w-4 shrink-0 text-muted-foreground"
                viewBox="0 0 24 24"
                fill="currentColor"
                aria-hidden
              >
                <path d="M19.27 5.33C17.94 4.71 16.5 4.26 15 4C14.82 4.33 14.61 4.77 14.46 5.11C12.88 4.87 11.31 4.87 9.76 5.11C9.61 4.77 9.39 4.33 9.21 4C7.71 4.26 6.26 4.71 4.94 5.34C2.24 9.42 1.52 13.39 1.88 17.31C3.65 18.61 5.37 19.43 7.06 19.97C7.49 19.39 7.88 18.77 8.21 18.11C7.59 17.88 6.99 17.59 6.43 17.25C6.58 17.14 6.73 17.02 6.87 16.9C10.19 18.43 13.84 18.43 17.12 16.9C17.27 17.02 17.41 17.14 17.56 17.25C17 17.59 16.4 17.88 15.78 18.11C16.11 18.77 16.5 19.39 16.93 19.97C18.62 19.43 20.34 18.61 22.11 17.31C22.54 12.75 21.34 8.81 19.27 5.33ZM8.52 14.88C7.49 14.88 6.63 13.91 6.63 12.72C6.63 11.53 7.47 10.56 8.52 10.56C9.57 10.56 10.43 11.53 10.41 12.72C10.41 13.91 9.56 14.88 8.52 14.88ZM15.49 14.88C14.46 14.88 13.6 13.91 13.6 12.72C13.6 11.53 14.44 10.56 15.49 10.56C16.54 10.56 17.4 11.53 17.38 12.72C17.38 13.91 16.54 14.88 15.49 14.88Z" />
              </svg>
              <span>Discord</span>
              <ExternalLink className="h-3 w-3 ms-auto shrink-0 text-muted-foreground" />
            </a>
            <a
              href={SUPPORT_GITHUB_ISSUES_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[12px] font-medium text-foreground hover:bg-muted/50 hover:border-border transition-colors"
            >
              <svg
                className="h-4 w-4 shrink-0 text-muted-foreground"
                viewBox="0 0 24 24"
                fill="currentColor"
              >
                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
              </svg>
              <span>GitHub</span>
              <ExternalLink className="h-3 w-3 ms-auto shrink-0 text-muted-foreground" />
            </a>
          </div>
        </div>
      </div>
    </div>
  )

  if (submitted) {
    return (
      <WizardLayout
        title={t('Ticket submitted')}
        fullscreen
        useSidebar={false}
        footerAlign="right"
        footer={<Button onClick={handleCancel}>{t('Done')}</Button>}
      >
        <div className="flex min-h-[70dvh] flex-col items-center justify-center py-12 px-4">
          <div className="w-full max-w-xl flex flex-col items-center text-center">
            <div className="mb-6 flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-emerald-500/10">
              <CheckCircle2
                className="h-8 w-8 text-emerald-600 dark:text-emerald-400"
                aria-hidden
              />
            </div>
            <h2 className="text-[18px] font-semibold text-foreground tracking-tight">
              {t('Your support ticket has been submitted')}
            </h2>
            <p className="mt-3 max-w-md text-[13px] text-muted-foreground leading-relaxed">
              {t(
                "We've received your request and will get back to you as soon as we can.",
              )}
            </p>
          </div>
          <div className="mt-10 w-full max-w-xl">
            <div className="rounded-xl border border-border bg-card overflow-hidden">
              <div className="px-6 py-5">
                <h3 className="text-[15px] font-semibold text-foreground tracking-tight">
                  {t('What happens next')}
                </h3>
                <ul className="mt-4 space-y-3 text-[13px] text-muted-foreground leading-relaxed">
                  <li className="flex gap-3 text-start">
                    <span className="text-muted-foreground/60 shrink-0">•</span>
                    <span>
                      {t('Check')}{' '}
                      <strong className="text-foreground">{email}</strong>{' '}
                      {t(
                        'for a confirmation email with your ticket reference.',
                      )}
                    </span>
                  </li>
                  <li className="flex gap-3 text-start">
                    <span className="text-muted-foreground/60 shrink-0">•</span>
                    <span>
                      {t(
                        'We typically respond within 24 hours during support hours (Mon–Fri).',
                      )}
                    </span>
                  </li>
                  <li className="flex gap-3 text-start">
                    <span className="text-muted-foreground/60 shrink-0">•</span>
                    <span>
                      {t(
                        'Reply to the confirmation email to add more context or attachments.',
                      )}
                    </span>
                  </li>
                </ul>
              </div>
              <div className="border-t border-border px-6 py-4 bg-muted/30">
                <p className="text-[13px] text-muted-foreground">
                  {t('Need 24/7 or enterprise support?')}{' '}
                  <a
                    href={CONTACT_SALES_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium text-foreground underline hover:no-underline"
                  >
                    {t('Contact sales')}
                  </a>
                </p>
              </div>
            </div>
          </div>
        </div>
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title={t('Support')}
      fullscreen
      useSidebar={true}
      sidebar={sidebar}
      footerAlign="right"
      footer={
        <>
          <Button
            variant="outline"
            onClick={handleCancel}
            disabled={isSubmitting}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleSubmit} disabled={!canSubmit || isSubmitting}>
            {t('Submit ticket')}
          </Button>
        </>
      }
    >
      <div className="space-y-6">
        {submitError && (
          <div ref={submitErrorRef}>
            {submitError.kind === 'portal' && (
              <Alert variant="destructive">
                <AlertTriangle aria-hidden />
                <AlertTitle>
                  {t("We're sorry - we couldn't submit your support request")}
                </AlertTitle>
                <AlertDescription className="text-[13px] prose-links-neutral">
                  <p>
                    {t(
                      "We're having a temporary issue with the support portal, and our engineering team are aware. In the meantime, please reach out at",
                    )}{' '}
                    <a href={`mailto:${APPWRITE_SUPPORT_EMAIL}`}>
                      {APPWRITE_SUPPORT_EMAIL}
                    </a>
                    {t(', on')}{' '}
                    <a
                      href={SUPPORT_DISCORD_URL}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      Discord
                    </a>
                    {t(', or on')}{' '}
                    <a
                      href={SUPPORT_GITHUB_ISSUES_URL}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      GitHub
                    </a>
                    .
                  </p>
                </AlertDescription>
              </Alert>
            )}
            {submitError.kind === 'attachment' && (
              <Alert variant="destructive">
                <AlertTriangle aria-hidden />
                <AlertTitle>{t("Couldn't use this attachment")}</AlertTitle>
                <AlertDescription className="text-[13px]">
                  {submitError.message}
                </AlertDescription>
              </Alert>
            )}
          </div>
        )}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Details')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Subject and message are required.')}
            </p>
          </div>
          <div className="border-t border-border px-6 py-4 space-y-4">
            <div>
              <Label
                htmlFor="support-subject"
                className="text-[13px] font-medium"
              >
                {t('Subject')}
              </Label>
              <Input
                id="support-subject"
                value={subject}
                onChange={(e) =>
                  setSubject(e.target.value.slice(0, SUBJECT_MAX))
                }
                placeholder={t(SUBJECT_PLACEHOLDER)}
                className="mt-2 h-9"
                maxLength={SUBJECT_MAX}
              />
              <p className="text-[11px] text-muted-foreground mt-1">
                {subject.length}/{SUBJECT_MAX} {t('characters')}
              </p>
            </div>
            <div>
              <Label
                htmlFor="support-project"
                className="text-[13px] font-medium"
              >
                {t('Project (optional)')}
              </Label>
              <Select value={projectId} onValueChange={setProjectId}>
                <SelectTrigger id="support-project" className="mt-2 h-9 w-full">
                  <SelectValue placeholder={t('None')} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_PROJECT_VALUE}>{t('None')}</SelectItem>
                  {projectList.map((p: { $id: string; name: string }) => (
                    <SelectItem key={p.$id} value={p.$id}>
                      {p.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label
                htmlFor="support-message"
                className="text-[13px] font-medium"
              >
                {t('Message')}
              </Label>
              <Textarea
                id="support-message"
                value={message}
                onChange={(e) =>
                  setMessage(e.target.value.slice(0, MESSAGE_MAX))
                }
                placeholder={t(MESSAGE_PLACEHOLDER)}
                className="mt-2 min-h-[140px] resize-y"
                maxLength={MESSAGE_MAX}
              />
              <p className="text-[11px] text-muted-foreground mt-1">
                {message.length}/{MESSAGE_MAX} {t('characters')}
              </p>
            </div>
            <div className="space-y-2">
              <Label
                htmlFor="support-attachment"
                className="text-[13px] font-medium"
              >
                {t('Attachment (optional)')}
              </Label>
              <div
                onDragOver={handleAttachmentDragOver}
                onDragLeave={handleAttachmentDragLeave}
                onDrop={handleAttachmentDrop}
                className={cn(
                  'border-2 border-dashed rounded-lg p-6 text-center transition-colors',
                  isDraggingAttachment
                    ? 'border-primary bg-primary/5'
                    : 'border-border bg-muted/30',
                )}
              >
                <input
                  ref={attachmentInputRef}
                  id="support-attachment"
                  type="file"
                  accept="*/*"
                  onChange={handleAttachmentInputChange}
                  className="hidden"
                />
                <label
                  htmlFor="support-attachment"
                  className="cursor-pointer flex flex-col items-center gap-2"
                >
                  <Upload className="h-8 w-8 text-muted-foreground" />
                  <span className="text-[13px] text-foreground">
                    {attachment
                      ? attachment.name
                      : t('Click to upload or drag and drop')}
                  </span>
                  <span className="text-[12px] text-muted-foreground">
                    {t('Max size:')} {ATTACHMENT_MAX_MB} MB
                  </span>
                </label>
              </div>
              {attachment && (
                <div className="flex items-center gap-2 rounded-md border border-border bg-muted/30 p-2">
                  <span className="flex-1 truncate text-[12px] text-foreground">
                    {attachment.name} ({formatFileSize(attachment.size)})
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-6 w-6 p-0 shrink-0"
                    onClick={() => {
                      setAttachment(null)
                      setSubmitError((e) =>
                        e?.kind === 'attachment' ? null : e,
                      )
                      if (attachmentInputRef.current) {
                        attachmentInputRef.current.value = ''
                      }
                    }}
                  >
                    <X className="h-3.5 w-3.5" />
                  </Button>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </WizardLayout>
  )
}
