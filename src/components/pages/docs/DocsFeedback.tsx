'use client'

import { useEffect, useRef, useState } from 'react'
import { useLocation } from '@tanstack/react-router'
import { AnimatePresence, motion, useReducedMotion } from 'motion/react'
import { Check, ThumbsDown, ThumbsUp } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { submitDocsFeedback, type DocsFeedbackType } from '@/lib/feedback'
import { cn } from '@/lib/utils'

const FEEDBACK_EASE: [number, number, number, number] = [0.22, 1, 0.36, 1]
const MAX_COMMENT_LENGTH = 500

export function DocsFeedback() {
  const { account } = useAuth()
  const pathname = useLocation().pathname
  const prefersReducedMotion = useReducedMotion()
  const sectionRef = useRef<HTMLElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement>(null)

  const [showForm, setShowForm] = useState(false)
  const [feedbackType, setFeedbackType] = useState<DocsFeedbackType | null>(null)
  const [email, setEmail] = useState('')
  const [comment, setComment] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const accountEmail = account?.email?.trim() ?? ''
  const hasAccountEmail = accountEmail.length > 0
  const resolvedEmail = hasAccountEmail ? accountEmail : email.trim()
  const commentRequired = feedbackType === 'negative'
  const canSubmit =
    Boolean(feedbackType) &&
    resolvedEmail.length > 0 &&
    comment.length <= MAX_COMMENT_LENGTH &&
    (!commentRequired || comment.trim().length > 0)

  const resetForm = () => {
    setComment('')
    setFeedbackType(null)
    setSubmitted(false)
    setError(null)
    if (!hasAccountEmail) {
      setEmail('')
    }
  }

  useEffect(() => {
    if (accountEmail) {
      setEmail(accountEmail)
    }
  }, [accountEmail])

  useEffect(() => {
    setShowForm(false)
    resetForm()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when the docs page changes
  }, [pathname])

  useEffect(() => {
    if (!showForm || !feedbackType || submitted) return

    const frame = requestAnimationFrame(() => {
      sectionRef.current?.scrollIntoView({
        behavior: prefersReducedMotion ? 'auto' : 'smooth',
        block: 'nearest',
      })
      textareaRef.current?.focus({ preventScroll: true })
    })

    return () => cancelAnimationFrame(frame)
  }, [showForm, feedbackType, submitted, prefersReducedMotion])

  const handleClose = () => {
    setShowForm(false)
    resetForm()
  }

  const handleThumbClick = (type: DocsFeedbackType) => {
    setFeedbackType(type)
    setShowForm(true)
    setSubmitted(false)
    setError(null)
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!feedbackType || !canSubmit) return

    setIsSubmitting(true)
    setError(null)

    try {
      const sent = await submitDocsFeedback({
        type: feedbackType,
        route: pathname,
        comment:
          comment.trim() ||
          (feedbackType === 'positive' ? 'Page was helpful' : ''),
        email: resolvedEmail,
        userId: account?.$id,
      })

      if (!sent) {
        toast.error(
          'Feedback is not configured. Set VITE_GROWTH_ENDPOINT in .env to enable submission.',
        )
        return
      }

      setSubmitted(true)
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
        !submitted &&
        'border-foreground/20 bg-muted text-foreground',
    )

  const expandTransition = prefersReducedMotion
    ? { duration: 0 }
    : { duration: 0.28, ease: FEEDBACK_EASE }

  return (
    <section
      ref={sectionRef}
      className="mb-8 mt-14 scroll-mt-24 border-t border-border pt-10"
    >
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <AnimatePresence mode="wait" initial={false}>
          {submitted ? (
            <motion.div
              key="success"
              initial={prefersReducedMotion ? false : { opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              exit={prefersReducedMotion ? undefined : { opacity: 0, y: -4 }}
              transition={expandTransition}
              className="flex flex-col items-center justify-center gap-3 px-6 py-10 text-center"
            >
              <div className="flex size-12 items-center justify-center rounded-full bg-green-500/10">
                <Check className="size-6 text-green-600" />
              </div>
              <div>
                <p className="text-[14px] font-semibold text-foreground">
                  Thank you for your feedback
                </p>
                <p className="mt-1 text-[13px] text-muted-foreground">
                  Once approved, our agents will automatically apply
                  improvements based on your feedback.
                </p>
              </div>
            </motion.div>
          ) : (
            <motion.div
              key="form-shell"
              initial={false}
              animate={{ opacity: 1 }}
              exit={prefersReducedMotion ? undefined : { opacity: 0 }}
              transition={expandTransition}
            >
              <div className="flex flex-col gap-4 px-5 py-5 @[560px]:flex-row @[560px]:items-center @[560px]:justify-between @[560px]:px-6">
                <div className="min-w-0">
                  <p className="text-[14px] font-semibold text-foreground">
                    Was this page helpful?
                  </p>
                  <p className="mt-1 text-[13px] text-muted-foreground">
                    Share what worked or what we should fix. Once approved, our
                    agents automatically apply suggested updates to the docs.
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

              <AnimatePresence initial={false}>
                {showForm && feedbackType ? (
                  <motion.form
                    key="feedback-form"
                    onSubmit={handleSubmit}
                    initial={
                      prefersReducedMotion ? false : { height: 0, opacity: 0 }
                    }
                    animate={{ height: 'auto', opacity: 1 }}
                    exit={
                      prefersReducedMotion ? undefined : { height: 0, opacity: 0 }
                    }
                    transition={expandTransition}
                    className="overflow-hidden"
                  >
                    <div className="border-t border-border" />
                    <div className="space-y-4 px-5 py-5 @[560px]:px-6">
                      <div className="space-y-2">
                        <label
                          htmlFor="docs-feedback-message"
                          className="text-[13px] font-medium text-foreground"
                        >
                          {feedbackType === 'negative'
                            ? 'What could we improve?'
                            : 'What did you find most helpful?'}
                          {feedbackType === 'positive' ? (
                            <span className="font-normal text-muted-foreground">
                              {' '}
                              (optional)
                            </span>
                          ) : null}
                        </label>
                        <Textarea
                          ref={textareaRef}
                          id="docs-feedback-message"
                          placeholder={
                            feedbackType === 'negative'
                              ? 'Tell us what was missing or unclear'
                              : 'Share your thoughts'
                          }
                          value={comment}
                          onChange={(event) => setComment(event.target.value)}
                          required={commentRequired}
                          maxLength={MAX_COMMENT_LENGTH}
                          className="min-h-[100px] resize-none text-[13px]"
                        />
                        <p className="text-[12px] text-muted-foreground">
                          {comment.length}/{MAX_COMMENT_LENGTH}. Approved
                          feedback is applied automatically by our agents.
                        </p>
                      </div>

                      {!hasAccountEmail ? (
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
                      ) : null}

                      {error ? (
                        <p className="text-[13px] text-destructive">{error}</p>
                      ) : null}
                    </div>

                    <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-5 py-4 @[560px]:flex-row @[560px]:justify-end @[560px]:px-6">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={handleClose}
                        disabled={isSubmitting}
                      >
                        Cancel
                      </Button>
                      <Button
                        type="submit"
                        size="sm"
                        className="h-9 text-[13px]"
                        disabled={isSubmitting || !canSubmit}
                      >
                        Submit
                      </Button>
                    </div>
                  </motion.form>
                ) : null}
              </AnimatePresence>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </section>
  )
}
