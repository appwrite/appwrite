import { useState } from 'react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { MessageSquarePlus, Send, Check } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  submitFeedback,
  FEEDBACK_CUSTOM_FIELDS,
} from '@/lib/feedback'

export interface FeedbackPopoverContext {
  /** Where the form was opened (e.g. navbar, sidebar). Default "n/a". */
  source?: string
  /** Current organization ID when available */
  orgId?: string
  /** Current project ID when available */
  projectId?: string
  /** Organization billing plan ID when available (sent in custom field 56109) */
  billingPlanId?: string
}

export function FeedbackPopover({
  source = 'n/a',
  orgId = '',
  projectId = '',
  billingPlanId,
}: FeedbackPopoverContext = {}) {
  const { account } = useAuth()
  const [isOpen, setIsOpen] = useState(false)
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
          'Feedback is not configured. Set VITE_GROWTH_ENDPOINT in .env to enable submission.',
        )
        return
      }

      setIsSubmitted(true)

      setTimeout(() => {
        setIsOpen(false)
        setMessage('')
        setIsSubmitted(false)
      }, 1500)
    } catch {
      setIsSubmitting(false)
      toast.error('Failed to submit feedback')
    }
  }

  const handleOpenChange = (open: boolean) => {
    setIsOpen(open)
    if (!open) {
      setTimeout(() => {
        setMessage('')
        setIsSubmitted(false)
      }, 200)
    }
  }

  return (
    <Popover open={isOpen} onOpenChange={handleOpenChange}>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-muted-foreground hover:bg-accent hover:text-foreground"
            >
              <MessageSquarePlus className="h-4 w-4" />
              <span className="sr-only">Feedback</span>
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>
          <p>Feedback</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent align="end" className="w-80 p-0">
        {isSubmitted ? (
          <div className="flex flex-col items-center justify-center gap-3 p-8">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-green-500/10">
              <Check className="h-6 w-6 text-green-500" />
            </div>
            <div className="text-center">
              <p className="font-medium">Thank you!</p>
              <p className="text-sm text-muted-foreground">
                Your feedback helps us improve.
              </p>
            </div>
          </div>
        ) : (
          <div className="p-4">
            <div className="mb-3">
              <h4 className="font-medium">Send feedback</h4>
              <p className="text-sm text-muted-foreground">
                Help us improve your experience
              </p>
            </div>
            <Textarea
              placeholder="Share your feedback..."
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              className="mb-3 min-h-[100px] resize-none"
              autoFocus
            />
            <div className="flex items-center justify-between">
              <p className="text-xs text-muted-foreground">
                {message.length}/500
              </p>
              <Button
                size="sm"
                onClick={handleSubmit}
                disabled={
                  !message.trim() || message.length > 500 || isSubmitting
                }
                className="gap-2"
              >
                <Send className="h-3.5 w-3.5" />
                Send Feedback
              </Button>
            </div>
          </div>
        )}
      </PopoverContent>
    </Popover>
  )
}
