'use client'

import { useEffect, useState } from 'react'
import { useLocation } from '@tanstack/react-router'
import { CheckCircle2, ThumbsDown, ThumbsUp } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { submitDocsFeedback, type DocsFeedbackType } from '@/lib/feedback'
import { cn } from '@/lib/utils'

export function DocsFeedback() {
  const { account } = useAuth()
  const pathname = useLocation().pathname
  const [showForm, setShowForm] = useState(false)
  const [feedbackType, setFeedbackType] = useState<DocsFeedbackType | null>(null)
  const [email, setEmail] = useState('')
  const [comment, setComment] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (account?.email) {
      setEmail(account.email)
    }
  }, [account?.email])

  const resetForm = () => {
    setComment('')
    setFeedbackType(null)
    setSubmitted(false)
    setError(null)
    if (!account?.email) {
      setEmail('')
    }
  }

  const handleOpenChange = (open: boolean) => {
    setShowForm(open)
    if (!open) {
      resetForm()
    }
  }

  const handleThumbClick = (type: DocsFeedbackType) => {
    if (showForm && feedbackType === type) {
      handleOpenChange(false)
      return
    }

    setFeedbackType(type)
    setShowForm(true)
    setSubmitted(false)
    setError(null)
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!feedbackType || !email.trim() || !comment.trim()) return

    setIsSubmitting(true)
    setError(null)

    try {
      const sent = await submitDocsFeedback({
        type: feedbackType,
        route: pathname,
        comment: comment.trim(),
        email: email.trim(),
        userId: account?.$id,
      })

      if (!sent) {
        toast.error(
          'Feedback is not configured. Set VITE_GROWTH_ENDPOINT in .env to enable submission.',
        )
        return
      }

      setSubmitted(true)
      setTimeout(() => handleOpenChange(false), 500)
    } catch {
      setError('There was an error submitting your feedback. Please try again later.')
    } finally {
      setIsSubmitting(false)
    }
  }

  const thumbButtonClass = (type: DocsFeedbackType) =>
    cn(
      'h-9 gap-1.5 px-3 text-[13px] font-medium',
      feedbackType === type &&
        showForm &&
        'border-foreground/20 bg-muted text-foreground',
    )

  return (
    <section className="mb-8 mt-14 border-t border-border pt-10">
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div className="min-w-0">
            <p className="text-[14px] font-semibold text-foreground">
              Was this page helpful?
            </p>
            <p className="mt-1 text-[13px] text-muted-foreground">
              Your feedback helps us improve the documentation.
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className={thumbButtonClass('positive')}
              aria-pressed={feedbackType === 'positive' && showForm}
              onClick={() => handleThumbClick('positive')}
            >
              <ThumbsUp className="size-3.5" />
              Yes
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className={thumbButtonClass('negative')}
              aria-pressed={feedbackType === 'negative' && showForm}
              onClick={() => handleThumbClick('negative')}
            >
              <ThumbsDown className="size-3.5" />
              No
            </Button>
          </div>
        </div>

        {showForm && feedbackType ? (
          <form onSubmit={handleSubmit}>
            <div className="border-t border-border" />
            <div className="space-y-4 px-5 py-5 sm:px-6">
              <div className="space-y-2">
                <label
                  htmlFor="docs-feedback-message"
                  className="text-[13px] font-medium text-foreground"
                >
                  {feedbackType === 'negative'
                    ? 'What could we improve?'
                    : 'What did you find most helpful?'}
                </label>
                <Textarea
                  id="docs-feedback-message"
                  placeholder="Share your thoughts"
                  value={comment}
                  onChange={(event) => setComment(event.target.value)}
                  required
                  className="min-h-[100px] resize-none text-[13px]"
                />
              </div>
              <div className="space-y-2">
                <label
                  htmlFor="docs-feedback-email"
                  className="text-[13px] font-medium text-foreground"
                >
                  Email
                </label>
                <Input
                  id="docs-feedback-email"
                  type="email"
                  placeholder="you@example.com"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  required
                  className="h-9 text-[13px]"
                />
                <p className="text-[12px] text-muted-foreground">
                  We may follow up if we need more details.
                </p>
              </div>

              {submitted ? (
                <div className="flex items-center gap-2 text-[13px] text-foreground">
                  <CheckCircle2 className="size-4 shrink-0 text-green-600" />
                  <span>Thank you. Your feedback has been submitted.</span>
                </div>
              ) : null}

              {error ? (
                <p className="text-[13px] text-destructive">{error}</p>
              ) : null}
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => handleOpenChange(false)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                size="sm"
                className="h-9 text-[13px]"
                disabled={isSubmitting || !email.trim() || !comment.trim()}
              >
                Submit
              </Button>
            </div>
          </form>
        ) : null}
      </div>
    </section>
  )
}
