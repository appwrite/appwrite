import { useMemo, useState, type ReactNode } from 'react'
import {
  Check,
  ChevronDown,
  ExternalLink,
  PenLine,
  ThumbsDown,
  ThumbsUp,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  type FeedbackSentiment,
  isFeedbackReady,
  MAX_FEEDBACK_LENGTH,
  submitFeedback,
} from '@/lib/feedback'
import { submitCustomerStoryInterviewRequest } from '@/lib/marketing/customer-story-request'
import {
  useOrganizationById,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import { isPayingBillingPlan } from '@/lib/utils/plan-filter'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs, type AnalyticsActionId } from '@/lib/analytics-actions'
import { trackEvent } from '@/lib/analytics'
import { GrowthError } from '@/lib/growth'

export interface FeedbackFormContext {
  source?: string
  orgId?: string
  projectId?: string
}

type FeedbackFormProps = FeedbackFormContext & {
  onSubmitted?: () => void
}

function splitAccountName(name: string | undefined) {
  const parts = name?.trim().split(/\s+/) ?? []
  return {
    firstName: parts[0] ?? '',
    lastName: parts.slice(1).join(' '),
  }
}

function isFullWebsiteUrl(value: string): boolean {
  try {
    const url = new URL(value.trim())
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}

function FeedbackPanelRow({
  icon,
  title,
  description,
  onClick,
  href,
  analyticsAction,
  trailing,
}: {
  icon: ReactNode
  title: string
  description?: string
  onClick?: () => void
  href?: string
  analyticsAction?: AnalyticsActionId
  trailing?: ReactNode
}) {
  const className = cn(
    'flex w-full items-center gap-3 rounded-lg px-2 py-2.5 text-start transition-colors',
    'hover:bg-muted/50',
  )
  const content = (
    <>
      <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
        {icon}
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        {description ? (
          <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
            {description}
          </p>
        ) : null}
      </div>
      {trailing ?? (
        <ExternalLink className="size-3.5 shrink-0 text-muted-foreground" />
      )}
    </>
  )

  if (href) {
    return (
      <a
        href={href}
        target="_blank"
        rel="noopener noreferrer"
        className={className}
        {...(analyticsAction ? analyticsAttrs(analyticsAction) : {})}
      >
        {content}
      </a>
    )
  }

  return (
    <button type="button" className={className} onClick={onClick}>
      {content}
    </button>
  )
}

export function FeedbackForm({
  source = 'n/a',
  orgId = '',
  projectId = '',
  onSubmitted,
}: FeedbackFormProps) {
  const t = useT()
  const { account } = useAuth()
  const { organization } = useOrganizationById(orgId || undefined)
  const { plan: organizationPlan } = useOrganizationPlan(orgId || undefined)

  const showCustomerStorySection = isPayingBillingPlan(organizationPlan)

  const [sentiment, setSentiment] = useState<FeedbackSentiment | null>(null)
  const [message, setMessage] = useState('')
  const [email, setEmail] = useState('')
  const [isSubmittingFeedback, setIsSubmittingFeedback] = useState(false)
  const [feedbackSubmitted, setFeedbackSubmitted] = useState(false)

  const [storyOpen, setStoryOpen] = useState(false)
  const [storySummary, setStorySummary] = useState('')
  const [companyWebsite, setCompanyWebsite] = useState('')
  const [isSubmittingStory, setIsSubmittingStory] = useState(false)
  const [storySubmitted, setStorySubmitted] = useState(false)

  const nameParts = useMemo(
    () => splitAccountName(account?.name),
    [account?.name],
  )

  // Signed-out visitors have no session, so they type the email themselves.
  const accountEmail = account?.email?.trim() ?? ''
  const feedbackEmail = accountEmail || email.trim()
  const canSubmitFeedback = isFeedbackReady({
    sentiment,
    message,
    email: feedbackEmail,
  })

  const canSubmitStory =
    storySummary.trim().length > 0 &&
    storySummary.length <= MAX_FEEDBACK_LENGTH &&
    isFullWebsiteUrl(companyWebsite) &&
    nameParts.firstName.length > 0 &&
    Boolean(account?.email?.trim()) &&
    Boolean(organization?.name?.trim() || nameParts.firstName) &&
    !isSubmittingStory

  const handleSubmitFeedback = async () => {
    if (!sentiment || !canSubmitFeedback) return

    setIsSubmittingFeedback(true)

    try {
      const trimmed = message.trim()
      const body =
        trimmed ||
        (sentiment === 'positive'
          ? 'Positive console feedback'
          : 'Negative console feedback')

      const labeledMessage = `[${sentiment === 'positive' ? 'Positive' : 'Negative'} feedback]\n\n${body}`

      await submitFeedback({
        message: labeledMessage,
        source,
        route: window.location.pathname,
        email: feedbackEmail,
        name: account?.name,
        organizationId: orgId,
        projectId,
      })

      setFeedbackSubmitted(true)
      onSubmitted?.()
    } catch (error) {
      // API messages are dynamic, so only the rate limit and fallback copy go through t().
      toast.error(
        error instanceof GrowthError
          ? error.isRateLimited
            ? t(error.message)
            : error.message
          : t('Failed to submit feedback'),
      )
    } finally {
      setIsSubmittingFeedback(false)
    }
  }

  const handleSubmitStory = async () => {
    if (!canSubmitStory || !account?.email) return

    setIsSubmittingStory(true)
    try {
      await submitCustomerStoryInterviewRequest({
        firstName: nameParts.firstName,
        lastName: nameParts.lastName || nameParts.firstName,
        email: account.email,
        companyName: organization?.name?.trim() || nameParts.firstName,
        companyWebsite: companyWebsite.trim(),
        storySummary: storySummary.trim(),
        cloudEmail: account.email,
      })

      trackEvent('Form Submitted', { form: 'customer-story' })
      setStorySubmitted(true)
      setStoryOpen(false)
    } catch (error) {
      toast.error(
        error instanceof Error
          ? t(error.message)
          : t('Failed to submit story request'),
      )
    } finally {
      setIsSubmittingStory(false)
    }
  }

  if (feedbackSubmitted) {
    return (
      <div className="flex flex-col items-center gap-2 px-6 py-10 text-center">
        <div className="flex size-10 items-center justify-center rounded-full bg-muted">
          <Check className="size-5 text-foreground" aria-hidden />
        </div>
        <p className="text-[14px] font-medium text-foreground">
          {t('Thank you!')}
        </p>
        <p className="text-[13px] text-muted-foreground">
          {t('Your feedback helps us improve.')}
        </p>
      </div>
    )
  }

  return (
    <>
      <div className="space-y-3 px-4 py-3">
        <div>
          <p className="text-[14px] font-semibold text-foreground">
            {t('Send feedback')}
          </p>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t('How is the console working for you?')}
          </p>
        </div>

        <div
          className="flex gap-1 rounded-lg border border-border bg-muted/30 p-1"
          role="group"
          aria-label={t('Feedback sentiment')}
        >
          {(
            [
              ['positive', ThumbsUp, t('Positive')] as const,
              ['negative', ThumbsDown, t('Negative')] as const,
            ] as const
          ).map(([value, Icon, label]) => {
            const selected = sentiment === value
            return (
              <button
                key={value}
                type="button"
                aria-pressed={selected}
                onClick={() => setSentiment(value)}
                className={cn(
                  'flex flex-1 items-center justify-center gap-1.5 rounded-md py-2 text-[13px] font-medium transition-colors',
                  !selected &&
                    'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                  selected &&
                    value === 'positive' &&
                    'bg-emerald-500/15 text-emerald-700 shadow-sm dark:text-emerald-400',
                  selected &&
                    value === 'negative' &&
                    'bg-red-500/15 text-red-700 shadow-sm dark:text-red-400',
                )}
              >
                <Icon className="size-3.5 shrink-0" aria-hidden />
                {label}
              </button>
            )
          })}
        </div>

        {sentiment ? (
          <div className="space-y-3">
            <Textarea
              id="console-feedback-message"
              aria-label={
                sentiment === 'negative'
                  ? t('What could we improve?')
                  : t('What is working well? (optional)')
              }
              placeholder={
                sentiment === 'negative'
                  ? t('What could we improve?')
                  : t('Add details (optional)')
              }
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              className="field-sizing-fixed min-h-[64px] resize-none text-[13px]"
              maxLength={MAX_FEEDBACK_LENGTH}
              autoFocus
            />
            {accountEmail ? null : (
              <Input
                id="console-feedback-email"
                type="email"
                aria-label={t('Email')}
                placeholder="you@example.com"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
                className="h-9 text-[13px]"
              />
            )}
            <Button
              size="sm"
              className="h-9 w-full text-[13px]"
              onClick={handleSubmitFeedback}
              disabled={!canSubmitFeedback || isSubmittingFeedback}
            >
              {t('Submit feedback')}
            </Button>
          </div>
        ) : null}
      </div>

      {showCustomerStorySection ? (
        <div className="border-t border-border px-2 py-2">
          <div>
            <FeedbackPanelRow
              icon={<PenLine className="size-4" aria-hidden />}
              title={t('Share your customer story')}
              description={
                storySubmitted
                  ? t('Request received. We will be in touch.')
                  : t('Tell us what you build')
              }
              onClick={() => {
                if (!storySubmitted) setStoryOpen((open) => !open)
              }}
              trailing={
                storySubmitted ? (
                  <Check className="size-3.5 shrink-0 text-muted-foreground" />
                ) : (
                  <ChevronDown
                    className={cn(
                      'size-3.5 shrink-0 text-muted-foreground transition-transform',
                      storyOpen && 'rotate-180',
                    )}
                    aria-hidden
                  />
                )
              }
            />

            {storyOpen && !storySubmitted ? (
              <div className="space-y-2 border-t border-border px-2 py-2">
                <Textarea
                  id="feedback-story-summary"
                  aria-label={t('What would you like to share?')}
                  value={storySummary}
                  onChange={(e) => setStorySummary(e.target.value)}
                  placeholder={t(
                    'Product, results, and how Appwrite fits your stack',
                  )}
                  className="field-sizing-fixed min-h-[120px] resize-none text-[13px]"
                  maxLength={MAX_FEEDBACK_LENGTH}
                />
                <Input
                  id="feedback-story-website"
                  type="url"
                  aria-label={t('Company website')}
                  value={companyWebsite}
                  onChange={(e) => setCompanyWebsite(e.target.value)}
                  placeholder="https://yourcompany.com"
                  className="h-9 text-[13px]"
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 w-full text-[13px]"
                  disabled={!canSubmitStory}
                  onClick={handleSubmitStory}
                  {...analyticsAttrs('feedback-customer-story-submit')}
                >
                  {t('Request interview')}
                </Button>
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
    </>
  )
}
