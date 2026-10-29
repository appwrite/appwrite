/**
 * Console and docs feedback, filed as growth conversations.
 */

import { ConversationType, createConversation, GrowthError } from '@/lib/growth'
import { translate } from '@/lib/i18n/translate'

export const MAX_FEEDBACK_LENGTH = 500

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const INVALID_EMAIL_MESSAGE = 'Please enter a valid email address'

function isEmail(value: string): boolean {
  return EMAIL_PATTERN.test(value.trim())
}

export type FeedbackSentiment = 'positive' | 'negative'

export interface FeedbackDraft {
  sentiment: FeedbackSentiment | null
  message: string
  /** The account email, or the one a signed-out visitor typed. */
  email: string
}

/**
 * Whether console feedback can be sent. Negative feedback needs a comment, and
 * a valid email is always needed because signed-out visitors send without a
 * session.
 */
export function isFeedbackReady(draft: FeedbackDraft): boolean {
  return (
    draft.sentiment !== null &&
    isEmail(draft.email) &&
    draft.message.length <= MAX_FEEDBACK_LENGTH &&
    (draft.sentiment !== 'negative' || draft.message.trim().length > 0)
  )
}

export interface SubmitFeedbackParams {
  message: string
  /** Where the feedback form was opened (e.g. navbar, command-center). */
  source: string
  /** Page the feedback was sent from. */
  route: string
  /**
   * The account email, or the one a signed-out visitor typed. The server reads
   * it from the session when there is one.
   */
  email: string
  name?: string
  organizationId?: string
  projectId?: string
}

/**
 * Submits general console feedback.
 *
 * @throws GrowthError when the email is invalid or the server rejects the request.
 */
export async function submitFeedback(
  params: SubmitFeedbackParams,
): Promise<void> {
  if (!isEmail(params.email)) {
    throw new GrowthError(translate(INVALID_EMAIL_MESSAGE), 400)
  }
  await createConversation({
    type: ConversationType.Feedback,
    email: params.email,
    name: params.name,
    message: params.message,
    organizationId: params.organizationId,
    projectId: params.projectId,
    attributes: {
      route: params.route,
      source: params.source,
    },
  })
}

export type DocsFeedbackType = 'positive' | 'negative'

export interface SubmitDocsFeedbackParams {
  type: DocsFeedbackType
  route: string
  comment: string
  email: string
}

/**
 * Submits a docs page rating.
 *
 * @throws GrowthError when the server rejects the request.
 */
export async function submitDocsFeedback(
  params: SubmitDocsFeedbackParams,
): Promise<void> {
  await createConversation({
    type: ConversationType.Docs,
    email: params.email,
    message: params.comment,
    attributes: {
      rating: params.type,
      route: params.route,
    },
  })
}
