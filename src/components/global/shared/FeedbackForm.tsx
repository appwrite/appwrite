import { useState } from 'react'
import { Check } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { submitFeedback, FEEDBACK_CUSTOM_FIELDS } from '@/lib/feedback'
import { useT } from '@/lib/i18n/translate'

export interface FeedbackFormContext {
  /** Where the form was opened (e.g. navbar, command-center). Default "n/a". */
  source?: string
  orgId?: string
  projectId?: string
  billingPlanId?: string
}

type FeedbackFormProps = FeedbackFormContext & {
  onSubmitted?: () => void
}

export function FeedbackForm({
  source = 'n/a',
  orgId = '',
  projectId = '',
  billingPlanId,
  onSubmitted,
}: FeedbackFormProps) {
  const t = useT()
  const { account } = useAuth()
  const [message, setMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isSubmitted, setIsSubmitted] = useState(false)

  const handleSubmit = async () => {
    if (!message.trim()) return

    setIsSubmitting(true)

    try {
      const firstname =
        (account?.name || account?.email || 'Unknown').slice(0, 40) || 'Unknown'

      const customFields = [
        { id: FEEDBACK_CUSTOM_FIELDS.PAGE_URL, value: window.location.href },
        ...(billingPlanId
          ? [{ id: FEEDBACK_CUSTOM_FIELDS.BILLING_PLAN, value: billingPlanId }]
          : []),
      ]

      const sent = await submitFeedback({
        subject: 'feedback-general',
        message: message.trim(),
        email: account?.email,
        firstname,
        customFields,
        metaFields: {
          source,
          orgId,
          projectId,
          userId: account?.$id ?? '',
        },
      })

      setIsSubmitting(false)

      if (!sent) {
        toast.error(
          t(
            'Feedback is not configured. Set VITE_GROWTH_ENDPOINT in .env to enable submission.',
          ),
        )
        return
      }

      setIsSubmitted(true)
      onSubmitted?.()
    } catch {
      setIsSubmitting(false)
      toast.error(t('Failed to submit feedback'))
    }
  }

  if (isSubmitted) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 p-8">
        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-green-500/10">
          <Check className="h-6 w-6 text-green-500" />
        </div>
        <div className="text-center">
          <p className="font-medium">{t('Thank you!')}</p>
          <p className="text-sm text-muted-foreground">
            {t('Your feedback helps us improve.')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="p-4">
      <div className="mb-3">
        <h4 className="font-medium">{t('Send feedback')}</h4>
        <p className="text-sm text-muted-foreground">
          {t('Help us improve your experience')}
        </p>
      </div>
      <Textarea
        placeholder={t('Share your feedback...')}
        value={message}
        onChange={(e) => setMessage(e.target.value)}
        className="mb-3 min-h-[100px] resize-none"
        autoFocus
      />
      <div className="flex items-center justify-between">
        <p className="text-xs text-muted-foreground">{message.length}/500</p>
        <Button
          size="sm"
          onClick={handleSubmit}
          disabled={!message.trim() || message.length > 500 || isSubmitting}
        >
          {t('Submit')}
        </Button>
      </div>
    </div>
  )
}
