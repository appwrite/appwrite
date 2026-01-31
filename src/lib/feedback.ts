/**
 * Feedback submission to the Growth server.
 * Does not use the Appwrite SDK; uses plain fetch to PUBLIC_GROWTH_ENDPOINT.
 * If VITE_GROWTH_ENDPOINT is not set, submission is skipped (no request is sent).
 */

/** Set VITE_GROWTH_ENDPOINT in .env to enable feedback submission (e.g. https://growth.example.com) */
const GROWTH_ENDPOINT = import.meta.env.VITE_GROWTH_ENDPOINT as
  | string
  | undefined

/** Custom field IDs used by the Growth feedback API */
export const FEEDBACK_CUSTOM_FIELDS = {
  /** Current page (full URL) - always sent */
  PAGE_URL: '47364',
  /** NPS score (0-10) - only when subject is feedback-nps */
  NPS_SCORE: '40655',
  /** Billing plan ID - only when organization has a billing plan */
  BILLING_PLAN: '56109',
} as const

export interface FeedbackMetaFields {
  /** Where the feedback form was opened (e.g. navbar, sidebar). Use "n/a" when not from a specific place. */
  source: string
  /** Current organization ID (if any) */
  orgId: string
  /** Current project ID (if any) */
  projectId: string
  /** Current user ID (if any) */
  userId: string
}

export interface FeedbackCustomField {
  id: string
  value: string | number
}

export interface SubmitFeedbackParams {
  /** Feedback type: feedback-general or feedback-nps */
  subject: string
  /** User's free-text message */
  message: string
  /** User's email (optional) */
  email?: string
  /** User's name, or "Unknown"; truncated to 40 characters */
  firstname: string
  /** Context: page URL, NPS score, billing plan ID, etc. */
  customFields: FeedbackCustomField[]
  /** Context: source, orgId, projectId, userId */
  metaFields: FeedbackMetaFields
}

const FIRSTNAME_MAX_LENGTH = 40

/**
 * Submits feedback to the Growth server.
 * If VITE_GROWTH_ENDPOINT is not set, returns false (no request is sent).
 * On response status >= 400, throws with message "Failed to submit feedback".
 * @returns true if the request was sent and succeeded, false if skipped (endpoint not configured)
 */
export async function submitFeedback(
  params: SubmitFeedbackParams,
): Promise<boolean> {
  if (!GROWTH_ENDPOINT?.trim()) {
    return false
  }

  const firstname =
    params.firstname.slice(0, FIRSTNAME_MAX_LENGTH) || 'Unknown'

  const body = {
    subject: params.subject,
    message: params.message,
    email: params.email ?? '',
    firstname,
    customFields: params.customFields,
    metaFields: params.metaFields,
  }

  const response = await fetch(`${GROWTH_ENDPOINT.replace(/\/$/, '')}/feedback`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  })

  if (response.status >= 400) {
    throw new Error('Failed to submit feedback')
  }

  return true
}
