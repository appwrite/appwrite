import { useState, useEffect, useMemo } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  ProjectEmailTemplateId,
  ProjectEmailTemplateLocale,
  type Models,
} from '@appwrite.io/console'
import {
  useEmailTemplate,
  useUpdateEmailTemplate,
  useDeleteEmailTemplate,
  useLocaleCodes,
  fetchEmailTemplate,
  useProjectSmtpEnabled,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Loader2,
  Copy,
  Check,
  Mail,
  ChevronDown,
  ExternalLink,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

// GitHub Circle Icon Component
function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

interface TemplatesProps {
  projectId: string
}

// Email template type configuration
const EMAIL_TEMPLATE_TYPES = [
  {
    type: ProjectEmailTemplateId.Verification,
    label: 'Verification',
    description:
      'Send a verification email to users that sign in with their email and password.',
    variables: [
      { variable: '{{user}}', description: 'User name' },
      { variable: '{{project}}', description: 'Project name' },
      { variable: '{{redirect}}', description: 'Redirect URL' },
    ],
  },
  {
    type: ProjectEmailTemplateId.MagicSession,
    label: 'Magic URL',
    description: 'Send an email to users that sign in with a magic URL.',
    variables: [
      { variable: '{{user}}', description: 'User name' },
      { variable: '{{project}}', description: 'Project name' },
      { variable: '{{redirect}}', description: 'Redirect URL' },
      { variable: '{{agentClient}}', description: 'Client name' },
      { variable: '{{agentDevice}}', description: 'Device name' },
      { variable: '{{agentOs}}', description: 'Operating system name' },
    ],
  },
  {
    type: ProjectEmailTemplateId.OtpSession,
    label: 'OTP Session',
    description: 'Send an email to users that sign in with an email OTP.',
    variables: [
      { variable: '{{user}}', description: 'User name' },
      { variable: '{{project}}', description: 'Project name' },
      { variable: '{{otp}}', description: 'One-time password code' },
      { variable: '{{agentClient}}', description: 'Client name' },
      { variable: '{{agentDevice}}', description: 'Device name' },
      { variable: '{{agentOs}}', description: 'Operating system name' },
    ],
  },
  {
    type: ProjectEmailTemplateId.Recovery,
    label: 'Reset Password',
    description: 'Send a recovery email to users that forget their password.',
    variables: [
      { variable: '{{user}}', description: 'User name' },
      { variable: '{{project}}', description: 'Project name' },
      { variable: '{{redirect}}', description: 'Redirect URL' },
    ],
  },
  {
    type: ProjectEmailTemplateId.Invitation,
    label: 'Invite User',
    description: 'Send an invitation email to become a member of your project.',
    variables: [
      { variable: '{{team}}', description: 'Team name' },
      { variable: '{{user}}', description: 'User name' },
      { variable: '{{project}}', description: 'Project name' },
      { variable: '{{redirect}}', description: 'Redirect URL' },
    ],
  },
  {
    type: ProjectEmailTemplateId.MfaChallenge,
    label: '2FA Verification',
    description: 'Send a two-factor authentication email to a user.',
    variables: [
      { variable: '{{user}}', description: 'User name' },
      { variable: '{{project}}', description: 'Project name' },
      { variable: '{{otp}}', description: 'One-time password code' },
      { variable: '{{agentClient}}', description: 'Client name' },
      { variable: '{{agentDevice}}', description: 'Device name' },
      { variable: '{{agentOs}}', description: 'Operating system name' },
    ],
  },
  {
    type: ProjectEmailTemplateId.SessionAlert,
    label: 'Session Alert',
    description: 'Send an email to users when a new session is created.',
    variables: [
      { variable: '{{user}}', description: 'User name' },
      { variable: '{{project}}', description: 'Project name' },
      { variable: '{{device}}', description: 'Device name' },
      { variable: '{{ipAddress}}', description: 'IP address' },
      { variable: '{{country}}', description: 'Country name' },
    ],
  },
] as const

export function Templates({ projectId }: TemplatesProps) {
  const t = useT()
  const { isSmtpEnabled } = useProjectSmtpEnabled(projectId)
  const { data: localeData } = useLocaleCodes()
  const queryClient = useQueryClient()

  const [selectedType, setSelectedType] = useState<string>(
    ProjectEmailTemplateId.Verification,
  )
  const [selectedLocale, setSelectedLocale] = useState<string>(
    ProjectEmailTemplateLocale.En,
  )
  const [resetDialogOpen, setResetDialogOpen] = useState(false)
  const [resetType, setResetType] = useState<string | null>(null)
  const [resetLocale, setResetLocale] = useState<string | null>(null)
  const [copiedVariable, setCopiedVariable] = useState<string | null>(null)

  // Prefetch all English templates when component mounts
  useEffect(() => {
    if (!projectId) return

    // Prefetch all English templates in parallel (don't await - let them fetch in background)
    // This ensures all templates are in cache when user switches between them
    const prefetchPromises = EMAIL_TEMPLATE_TYPES.map((templateConfig) =>
      queryClient.prefetchQuery({
        queryKey: [
          'emailTemplate',
          projectId,
          templateConfig.type,
          ProjectEmailTemplateLocale.En,
        ],
        queryFn: () =>
          fetchEmailTemplate(
            projectId,
            templateConfig.type,
            ProjectEmailTemplateLocale.En,
          ),
        staleTime: 30 * 1000, // 30 seconds
      }),
    )

    // Start all prefetches but don't block rendering
    Promise.all(prefetchPromises).catch((error) => {
      console.error('Failed to prefetch some templates:', error)
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]) // queryClient is stable, no need to include in deps

  // Fetch template when type or locale changes
  const { data: template, isLoading: isTemplateLoading } = useEmailTemplate(
    projectId,
    selectedType,
    selectedLocale,
  )

  // Keep previous template data visible when switching languages
  // This prevents the form from clearing while new data loads
  const [displayTemplate, setDisplayTemplate] =
    useState<Models.EmailTemplate | null>(null)

  // Update display template when new data arrives, but keep previous data while loading
  useEffect(() => {
    if (template) {
      setDisplayTemplate(template)
    }
    // Don't clear displayTemplate when template is undefined/null - keep previous data visible
    // This allows smooth transitions when switching languages
  }, [template])

  // Check if we have cached data for the current template
  const hasCachedData = useMemo(() => {
    if (!projectId || !selectedType || !selectedLocale) return false
    return !!queryClient.getQueryData([
      'emailTemplate',
      projectId,
      selectedType,
      selectedLocale,
    ])
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, selectedType, selectedLocale]) // queryClient is stable, no need to include in deps

  // Only show loading if we don't have cached data AND we don't have previous data to display
  const showLoading = isTemplateLoading && !hasCachedData && !displayTemplate

  const updateMutation = useUpdateEmailTemplate(projectId)
  const resetMutation = useDeleteEmailTemplate(projectId)

  // Local state for form fields
  const [formData, setFormData] = useState<{
    senderName: string
    senderEmail: string
    replyToEmail: string
    subject: string
    message: string
  }>({
    senderName: '',
    senderEmail: '',
    replyToEmail: '',
    subject: '',
    message: '',
  })

  // Update form data when display template changes (keeps previous data visible)
  useEffect(() => {
    if (displayTemplate) {
      setFormData({
        senderName: displayTemplate.senderName || '',
        senderEmail: displayTemplate.senderEmail || '',
        replyToEmail: displayTemplate.replyToEmail || '',
        subject: displayTemplate.subject || '',
        message: displayTemplate.message || '',
      })
    }
  }, [displayTemplate])

  // Store base template for change detection
  const [baseTemplate, setBaseTemplate] = useState<Models.EmailTemplate | null>(
    null,
  )

  useEffect(() => {
    if (displayTemplate) {
      setBaseTemplate(displayTemplate)
    }
  }, [displayTemplate])

  // Check if form has changes
  const hasChanges = useMemo(() => {
    if (!baseTemplate || !displayTemplate) return false

    return (
      formData.senderName !== (baseTemplate.senderName || '') ||
      formData.senderEmail !== (baseTemplate.senderEmail || '') ||
      formData.replyToEmail !== (baseTemplate.replyToEmail || '') ||
      formData.subject !== (baseTemplate.subject || '') ||
      formData.message !== (baseTemplate.message || '')
    )
  }, [formData, baseTemplate, displayTemplate])

  // Handle locale change
  const handleLocaleChange = (locale: string) => {
    setSelectedLocale(locale)
  }

  // Handle form field changes
  const handleFieldChange = (field: string, value: string) => {
    if (field in formData) {
      setFormData((prev) => ({ ...prev, [field]: value }))
    }
  }

  // Handle update
  const handleUpdate = async () => {
    if (!selectedLocale) {
      toast.error(t('Please select a locale'))
      return
    }

    try {
      await updateMutation.mutateAsync({
        type: selectedType,
        locale: selectedLocale,
        subject: formData.subject || '',
        message: formData.message || '',
        senderName: formData.senderName || undefined,
        senderEmail: formData.senderEmail || undefined,
        replyToEmail: formData.replyToEmail || undefined,
      })

      const templateTypeLabel =
        EMAIL_TEMPLATE_TYPES.find((t) => t.type === selectedType)?.label ||
        selectedType

      toast.success(
        `${t('Email template updated:')} ${t(templateTypeLabel)} (${selectedLocale})`,
      )

      // Update base template after successful update
      if (displayTemplate) {
        setBaseTemplate({
          ...displayTemplate,
          senderName: formData.senderName,
          senderEmail: formData.senderEmail,
          replyToEmail: formData.replyToEmail,
          subject: formData.subject,
          message: formData.message,
        })
      }
    } catch (error: unknown) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to update template'),
      )
    }
  }

  // Handle reset
  const handleResetClick = (type: string, locale: string) => {
    setResetType(type)
    setResetLocale(locale)
    setResetDialogOpen(true)
  }

  const handleResetConfirm = async () => {
    if (!resetType || !resetLocale) return

    try {
      await resetMutation.mutateAsync({
        type: resetType,
        locale: resetLocale,
      })

      toast.success(t('Email template has been reset'))

      // Reload template after reset
      if (resetType === selectedType && resetLocale === selectedLocale) {
        // Template will be refetched automatically via query invalidation
      }

      setResetDialogOpen(false)
      setResetType(null)
      setResetLocale(null)
    } catch (error: unknown) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to reset template'),
      )
    }
  }

  // Copy variable to clipboard
  const handleCopyVariable = async (variable: string) => {
    try {
      await navigator.clipboard.writeText(variable)
      setCopiedVariable(variable)
      setTimeout(() => setCopiedVariable(null), 2000)
    } catch {
      toast.error(t('Failed to copy variable'))
    }
  }

  const currentTemplateConfig = EMAIL_TEMPLATE_TYPES.find(
    (t) => t.type === selectedType,
  )

  return (
    <div className="@container w-full">
      <div className="flex h-full flex-col gap-4 @[640px]:flex-row @[640px]:gap-6">
        {/* Mobile - template picker */}
        <div className="@[640px]:hidden">
          <TemplateTypeSelector
            selectedType={selectedType}
            onTypeChange={setSelectedType}
          />
        </div>

        {/* Sidebar - Template List (desktop) */}
        <div className="hidden w-full shrink-0 @[640px]:block @[640px]:w-64">
          <div className="overflow-hidden rounded-lg border border-border bg-card">
            <div className="border-b border-border px-4 py-3">
              <h2 className="text-sm font-semibold text-foreground">
                {t('Email templates')}
              </h2>
              <p className="mt-1 text-xs text-muted-foreground">
                {EMAIL_TEMPLATE_TYPES.length} {t('templates')}
              </p>
            </div>
            <div className="divide-y divide-border">
              {EMAIL_TEMPLATE_TYPES.map((templateConfig) => {
                const isSelected = selectedType === templateConfig.type
                return (
                  <button
                    key={templateConfig.type}
                    onClick={() => setSelectedType(templateConfig.type)}
                    className={cn(
                      'w-full cursor-pointer px-4 py-3 text-start transition-colors hover:bg-muted/50',
                      isSelected && 'bg-muted',
                    )}
                  >
                    <div className="flex items-start gap-3">
                      <div className="mt-0.5 shrink-0">
                        <Mail className="h-4 w-4 text-muted-foreground" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div
                          className={cn(
                            'text-sm font-medium',
                            isSelected
                              ? 'text-foreground'
                              : 'text-muted-foreground',
                          )}
                        >
                          {t(templateConfig.label)}
                        </div>
                        <div className="mt-0.5 text-xs text-muted-foreground line-clamp-2">
                          {t(templateConfig.description)}
                        </div>
                      </div>
                    </div>
                  </button>
                )
              })}
            </div>
          </div>
        </div>

        {/* Main Editor Area */}
        <div className="min-w-0 flex-1">
          {currentTemplateConfig && (
            <TemplateEditor
              projectId={projectId}
              templateType={currentTemplateConfig.type}
              templateLabel={currentTemplateConfig.label}
              templateDescription={currentTemplateConfig.description}
              locale={selectedLocale}
              onLocaleChange={handleLocaleChange}
              localeCodes={localeData?.localeCodes || []}
              template={displayTemplate || undefined}
              isLoading={showLoading}
              formData={formData}
              onFieldChange={handleFieldChange}
              hasChanges={hasChanges}
              isSmtpEnabled={isSmtpEnabled}
              onUpdate={handleUpdate}
              onReset={() => handleResetClick(selectedType, selectedLocale)}
              isUpdating={updateMutation.isPending}
              isResetting={resetMutation.isPending}
              onCopyVariable={handleCopyVariable}
              copiedVariable={copiedVariable}
              variables={[...currentTemplateConfig.variables]}
            />
          )}
        </div>
      </div>

      {/* Reset Confirmation Dialog */}
      <Dialog open={resetDialogOpen} onOpenChange={setResetDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Reset email template?')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to reset the email template?')}{' '}
              <strong>{t('Default values will be set in all inputs.')}</strong>
            </DialogDescription>
          </DialogHeader>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setResetDialogOpen(false)}
              disabled={resetMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleResetConfirm}
              disabled={resetMutation.isPending}
            >
              {t('Reset')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

interface TemplateEditorProps {
  projectId: string
  templateType: string
  templateLabel: string
  templateDescription: string
  locale: string
  onLocaleChange: (locale: string) => void
  localeCodes: Array<{ code: string; name: string }>
  template?: Models.EmailTemplate
  isLoading: boolean
  formData: {
    senderName: string
    senderEmail: string
    replyToEmail: string
    subject: string
    message: string
  }
  onFieldChange: (field: string, value: string) => void
  hasChanges: boolean
  isSmtpEnabled: boolean
  onUpdate: () => void
  onReset: () => void
  isUpdating: boolean
  isResetting: boolean
  onCopyVariable: (variable: string) => void
  copiedVariable: string | null
  variables?: Array<string | { variable: string; description: string }>
}

// Helper function to detect RTL languages based on locale code
function isRTLLocale(locale: string): boolean {
  // RTL language codes: Arabic, Hebrew, Urdu, Persian/Farsi, Yiddish, etc.
  const rtlLanguageCodes = ['ar', 'he', 'iw', 'ur', 'fa', 'yi']
  // Extract the base language code (e.g., 'ar' from 'ar-SA')
  const baseCode = locale.split('-')[0].toLowerCase()
  return rtlLanguageCodes.includes(baseCode)
}

function TemplateEditor({
  templateLabel,
  templateDescription,
  locale,
  onLocaleChange,
  localeCodes,
  isLoading,
  formData,
  onFieldChange,
  hasChanges,
  isSmtpEnabled,
  onUpdate,
  onReset,
  isUpdating,
  isResetting,
  onCopyVariable,
  copiedVariable,
  variables = [],
}: TemplateEditorProps) {
  const t = useT()
  const [localFormData, setLocalFormData] = useState(formData)
  const isRTL = isRTLLocale(locale)

  useEffect(() => {
    setLocalFormData(formData)
  }, [formData])

  const handleLocalFieldChange = (field: string, value: string) => {
    setLocalFormData((prev) => ({ ...prev, [field]: value }))
    onFieldChange(field, value)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center rounded-lg border border-border bg-card py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="@container rounded-lg border border-border bg-card">
      {/* Header */}
      <div className="border-b border-border px-4 py-3 @[500px]:px-6 @[500px]:py-4">
        <div className="flex flex-col gap-3 @[500px]:flex-row @[500px]:items-start @[500px]:justify-between">
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-semibold text-foreground">
              {t(templateLabel)}
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">
              {t(templateDescription)}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-3 w-full @[500px]:w-auto">
            <LocaleSelector
              locale={locale}
              localeCodes={localeCodes}
              onLocaleChange={onLocaleChange}
            />
          </div>
        </div>
      </div>

      {/* Translation CTA & Documentation */}
      <div className="space-y-3 border-b border-border bg-muted/30 px-4 py-3 @[500px]:px-6">
        <div className="flex flex-col gap-2 @[600px]:flex-row @[600px]:items-center @[600px]:justify-between">
          <div className="flex items-center gap-2 min-w-0">
            <GitHubIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
            <p className="text-xs text-muted-foreground">
              {t(
                'Found a translation issue or want to contribute a new language?',
              )}
            </p>
          </div>
          <Button
            variant="outline"
            size="sm"
            className="h-8 gap-2 text-xs shrink-0 w-full @[600px]:w-auto"
            asChild
          >
            <a
              href="https://github.com/appwrite/appwrite/pulls"
              target="_blank"
              rel="noopener noreferrer"
            >
              <GitHubIcon className="h-3.5 w-3.5" />
              {t('Submit a PR')}
              <ExternalLink className="h-3 w-3" />
            </a>
          </Button>
        </div>
        <div className="flex items-start gap-2 rounded-md border border-border bg-card/50 px-3 py-2">
          <div className="mt-0.5 shrink-0">
            <ExternalLink className="h-3.5 w-3.5 text-muted-foreground" />
          </div>
          <div className="min-w-0 flex-1 space-y-1">
            <p className="text-xs font-medium text-foreground">
              {t('Using templates in your app')}
            </p>
            <p className="text-xs text-muted-foreground">
              {t('Set the locale using')}{' '}
              <code className="rounded bg-muted px-1 py-0.5 text-[11px] font-mono">
                client.setLocale()
              </code>{' '}
              {t('in SDKs or the')}{' '}
              <code className="rounded bg-muted px-1 py-0.5 text-[11px] font-mono">
                X-Appwrite-Locale
              </code>{' '}
              {t(
                "HTTP header. Templates are automatically selected based on the user's locale.",
              )}
            </p>
            <DocsRouteLink className="link-neutral inline-flex items-center gap-1 text-xs" href="/docs/advanced/platform/message-templates">
              {t('Learn more about message templates')}
              <ExternalLink className="h-3 w-3" />
            </DocsRouteLink>
          </div>
        </div>
      </div>

      {/* Form Content */}
      <div className="px-4 py-4 @[500px]:px-6 @[500px]:py-6">
        <div className="space-y-6">
          {/* Sender Name */}
          <div className="space-y-2">
            <Label htmlFor="sender-name">{t('Sender name')}</Label>
            <Input
              id="sender-name"
              placeholder={t('Enter sender name')}
              value={localFormData.senderName}
              onChange={(e) =>
                handleLocalFieldChange('senderName', e.target.value)
              }
              disabled={!isSmtpEnabled}
              dir={isRTL ? 'rtl' : 'ltr'}
            />
          </div>

          {/* Sender Email */}
          <div className="space-y-2">
            <Label htmlFor="sender-email">{t('Sender email')}</Label>
            <Input
              id="sender-email"
              type="email"
              placeholder={t('Enter sender email')}
              value={localFormData.senderEmail}
              onChange={(e) =>
                handleLocalFieldChange('senderEmail', e.target.value)
              }
              disabled={!isSmtpEnabled}
              dir={isRTL ? 'rtl' : 'ltr'}
            />
          </div>

          {/* Reply To */}
          <div className="space-y-2">
            <Label htmlFor="reply-to">{t('Reply to')}</Label>
            <Input
              id="reply-to"
              type="email"
              placeholder="noreply@appwrite.io"
              value={localFormData.replyToEmail}
              onChange={(e) =>
                handleLocalFieldChange('replyToEmail', e.target.value)
              }
              dir={isRTL ? 'rtl' : 'ltr'}
            />
          </div>

          {/* Subject */}
          <div className="space-y-2">
            <Label htmlFor="subject">{t('Subject')}</Label>
            <Input
              id="subject"
              placeholder={t('Enter subject')}
              value={localFormData.subject}
              onChange={(e) =>
                handleLocalFieldChange('subject', e.target.value)
              }
              dir={isRTL ? 'rtl' : 'ltr'}
            />
          </div>

          {/* Message */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="message">{t('Message')}</Label>
              {!isSmtpEnabled && (
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span className="text-xs text-muted-foreground">
                        {t('SMTP required')}
                      </span>
                    </TooltipTrigger>
                    <TooltipContent>
                      <p>{t('Set up an SMTP server to edit the message body')}</p>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              )}
            </div>

            {/* Template Variables */}
            {variables && variables.length > 0 && (
              <div className="space-y-2 rounded-lg border border-border bg-muted/30 p-3 @[500px]:p-4">
                <div className="space-y-1">
                  <Label className="text-sm font-medium">
                    {t('Available Variables')}
                  </Label>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'Use these variables in your message to insert dynamic content. Click a variable to copy it.',
                    )}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {variables.map((variableItem) => {
                    const variable =
                      typeof variableItem === 'string'
                        ? variableItem
                        : variableItem.variable
                    const description =
                      typeof variableItem === 'string'
                        ? undefined
                        : variableItem.description

                    return (
                      <TooltipProvider key={variable}>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              variant="outline"
                              size="sm"
                              className="h-8 text-xs font-mono"
                              onClick={() => onCopyVariable(variable)}
                            >
                              {copiedVariable === variable ? (
                                <>
                                  <Check className="me-1.5 h-3.5 w-3.5" />
                                  {t('Copied')}
                                </>
                              ) : (
                                <>
                                  <Copy className="me-1.5 h-3.5 w-3.5" />
                                  {variable}
                                </>
                              )}
                            </Button>
                          </TooltipTrigger>
                          {description && (
                            <TooltipContent>
                              <p className="text-xs">{t(description)}</p>
                            </TooltipContent>
                          )}
                        </Tooltip>
                      </TooltipProvider>
                    )
                  })}
                </div>
              </div>
            )}

            <Textarea
              id="message"
              placeholder={t('Enter your message')}
              rows={12}
              value={localFormData.message}
              onChange={(e) =>
                handleLocalFieldChange('message', e.target.value)
              }
              readOnly={!isSmtpEnabled}
              className={cn(!isSmtpEnabled && 'cursor-not-allowed opacity-60')}
              dir="ltr"
            />
          </div>
        </div>
      </div>

      {/* Footer Actions */}
      <div className="border-t border-border bg-muted/30 px-4 py-3 @[500px]:px-6 @[500px]:py-4">
        <div className="flex flex-col-reverse gap-2 @[500px]:flex-row @[500px]:items-center @[500px]:justify-between">
          {!isSmtpEnabled ? (
            <TooltipProvider>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="w-full @[500px]:w-auto">
                    <Button
                      variant="outline"
                      disabled
                      className="w-full pointer-events-none"
                    >
                      {t('Reset to default')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent>
                  <p>{t('Set up SMTP to reset email templates')}</p>
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            <Button
              variant="outline"
              onClick={onReset}
              disabled={isResetting || isUpdating}
              className="w-full @[500px]:w-auto"
            >
              {t('Reset to default')}
            </Button>
          )}
          <Button
            onClick={onUpdate}
            disabled={!hasChanges || isUpdating || isResetting}
            className="w-full @[500px]:w-auto"
          >
            {t('Update template')}
          </Button>
        </div>
      </div>
    </div>
  )
}

interface TemplateTypeSelectorProps {
  selectedType: string
  onTypeChange: (type: string) => void
}

function TemplateTypeSelector({
  selectedType,
  onTypeChange,
}: TemplateTypeSelectorProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const selectedConfig = EMAIL_TEMPLATE_TYPES.find(
    (t) => t.type === selectedType,
  )

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="h-auto w-full justify-between gap-2 py-3 text-start"
        >
          <div className="flex min-w-0 flex-1 items-start gap-3">
            <Mail className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium">
                {selectedConfig?.label
                  ? t(selectedConfig.label)
                  : t('Select template...')}
              </span>
              {selectedConfig?.description && (
                <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                  {t(selectedConfig.description)}
                </span>
              )}
            </div>
          </div>
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
        <Command>
          <CommandInput placeholder={t('Search templates...')} />
          <CommandList>
            <CommandEmpty>{t('No template found.')}</CommandEmpty>
            <CommandGroup>
              {EMAIL_TEMPLATE_TYPES.map((templateConfig) => (
                <CommandItem
                  key={templateConfig.type}
                  value={`${templateConfig.label} ${templateConfig.description}`}
                  onSelect={() => {
                    onTypeChange(templateConfig.type)
                    setOpen(false)
                  }}
                  className="items-start py-2.5"
                >
                  <Check
                    className={cn(
                      'me-2 mt-0.5 h-4 w-4 shrink-0',
                      selectedType === templateConfig.type
                        ? 'opacity-100'
                        : 'opacity-0',
                    )}
                  />
                  <div className="min-w-0 flex-1">
                    <span className="text-sm font-medium">
                      {t(templateConfig.label)}
                    </span>
                    <p className="mt-0.5 text-xs text-muted-foreground line-clamp-2">
                      {t(templateConfig.description)}
                    </p>
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}

interface LocaleSelectorProps {
  locale: string
  localeCodes: Array<{ code: string; name: string }>
  onLocaleChange: (locale: string) => void
}

function LocaleSelector({
  locale,
  localeCodes,
  onLocaleChange,
}: LocaleSelectorProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const selectedLocale = localeCodes.find((l) => l.code === locale)

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="w-full min-w-[200px] @[400px]:w-[240px] justify-between"
        >
          <span className="truncate">
            {selectedLocale ? selectedLocale.name : t('Select language...')}
          </span>
          <ChevronDown className="ms-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[200px] @[400px]:w-[240px] p-0" align="end">
        <Command>
          <CommandInput placeholder={t('Search languages...')} />
          <CommandList>
            <CommandEmpty>{t('No language found.')}</CommandEmpty>
            <CommandGroup>
              {localeCodes.map((localeOption) => (
                <CommandItem
                  key={localeOption.code}
                  value={localeOption.name}
                  onSelect={() => {
                    onLocaleChange(localeOption.code)
                    setOpen(false)
                  }}
                >
                  <Check
                    className={cn(
                      'me-2 h-4 w-4',
                      locale === localeOption.code
                        ? 'opacity-100'
                        : 'opacity-0',
                    )}
                  />
                  {localeOption.name}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
