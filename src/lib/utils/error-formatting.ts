/**
 * Formats errors consistently across the application.
 * Handles various error types including API errors, code errors, not found errors, etc.
 */

import { translate } from '@/lib/i18n/translate'

export interface FormattedError {
  title: string
  message: string
  isUserFriendly: boolean
}

/** Error-like value with optional HTTP-style code (e.g. from API/Appwrite) */
export interface ErrorWithCode extends Error {
  code?: number
  status?: number
}

function isErrorWithCode(error: unknown): error is ErrorWithCode {
  return error instanceof Error && ('code' in error || 'status' in error)
}

function getErrorCode(error: ErrorWithCode): number | undefined {
  return error.code ?? (error as ErrorWithCode & { status?: number }).status
}

function isShortActionableErrorMessage(message: string): boolean {
  return (
    message.length > 0 &&
    message.length <= 400 &&
    !message.includes('\n') &&
    !message.includes(' at ') &&
    !message.includes('TypeError') &&
    !message.includes('ReferenceError')
  )
}

/** SQL engine failures often arrive as HTTP 500 with a useful message. */
function looksLikeDatabaseEngineError(message: string): boolean {
  if (!isShortActionableErrorMessage(message)) return false
  const lower = message.toLowerCase()
  if (
    lower.includes('internal server error') ||
    lower.includes('an error occurred on the server')
  ) {
    return false
  }
  return (
    lower.includes('sqlstate') ||
    lower.includes('syntax error') ||
    lower.includes('parse error') ||
    lower.includes('query error') ||
    lower.includes('key specification') ||
    lower.includes('duplicate entry') ||
    lower.includes('unknown column') ||
    lower.includes('check constraint') ||
    lower.includes('invalid default') ||
    lower.includes('duplicate column') ||
    lower.includes('duplicate key') ||
    lower.includes('operator does not exist') ||
    lower.includes('invalid input syntax') ||
    lower.includes('undefined column') ||
    lower.includes('does not exist') ||
    lower.includes('cannot add') ||
    lower.includes('cannot change') ||
    lower.includes('truncated') ||
    lower.includes('violat') ||
    /\ber_\d+\b/i.test(message)
  )
}

function rewriteDatabaseEngineErrorMessage(message: string): string {
  if (/used in key specification without a key length/i.test(message)) {
    return 'MySQL cannot uniquely index TEXT or BLOB columns without a key length. Use VARCHAR with a defined length, or create a prefix index.'
  }
  if (/duplicate entry '' for key/i.test(message)) {
    return 'Existing rows were filled with an empty value, which is not unique. Allow NULL, or add the column first and fill distinct values.'
  }
  return message
}

/** True when the API responded with HTTP 403 (e.g. blocked console account). */
export function isHttpForbiddenError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const e = error as { code?: number; status?: number }
  return e.code === 403 || e.status === 403
}

/**
 * How a failed VCS call should be explained to the user.
 *
 * - `reconnect` - the installation's stored OAuth token is dead and cannot be
 *   refreshed. Only the user can fix it, by re-authorizing the installation.
 * - `locked` - a concurrent token refresh holds the lock. Transient; retrying
 *   works. Never offer a reconnect here, the installation is fine.
 * - `provider` - the provider itself failed (outage, rate limit). Also
 *   transient, but a dead token can land here too (see below), so a reconnect
 *   stays available as a secondary action.
 */
export type VcsInstallationErrorKind = 'reconnect' | 'locked' | 'provider'

/**
 * Classify an error from a VCS endpoint that refreshes the installation token
 * (list/get repositories, branches, namespaces, repository contents).
 *
 * The API reports both a dead installation token and a genuine provider outage
 * as `general_provider_failure`, so the message is the only discriminator: the
 * token failures are the ones that ask the user to reconnect. That copy is
 * authored in the backend's `Appwrite\Vcs\InstallationTokens` (`refresh()` and
 * `exchange()`); if it is ever reworded this falls back to `provider`, which
 * still shows a real error and still offers a reconnect, just with
 * outage-first wording.
 */
export function getVcsInstallationErrorKind(
  error: unknown,
): VcsInstallationErrorKind | null {
  if (!error || typeof error !== 'object') return null
  const e = error as { type?: string; message?: string }
  if (e.type === 'general_resource_locked') return 'locked'
  if (e.type !== 'general_provider_failure') return null
  const message = typeof e.message === 'string' ? e.message.toLowerCase() : ''
  return message.includes('reconnect') ? 'reconnect' : 'provider'
}

/** True when the API responded with HTTP 404 (resource missing or inaccessible). */
export function isHttpNotFoundError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const e = error as {
    code?: number
    status?: number
    name?: string
    message?: string
  }
  if (e.code === 404 || e.status === 404 || e.name === 'NotFoundError')
    return true
  const message = typeof e.message === 'string' ? e.message.toLowerCase() : ''
  return (
    message.includes('not found') ||
    message.includes('404') ||
    message.includes('does not exist')
  )
}

/** True when the API responded with HTTP 408 (request timeout). */
export function isHttpRequestTimeoutError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const e = error as {
    code?: number
    status?: number
    name?: string
    message?: string
    type?: string
  }
  if (
    e.code === 408 ||
    e.status === 408 ||
    e.name === 'TimeoutError' ||
    e.type === 'database_timeout'
  ) {
    return true
  }
  const message = typeof e.message === 'string' ? e.message.toLowerCase() : ''
  return (
    message.includes('request timeout') ||
    message.includes('timed out') ||
    message.includes('database timed out') ||
    /\b408\b/.test(message)
  )
}

export type AppwriteErrorInfo = {
  message: string | null
  type: string | null
  code: number | null
}

function readString(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : null
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

/**
 * Message, type, and code from an AppwriteException or a similar API error object.
 * Falls back to JSON in `response` when the SDK leaves `message` empty.
 */
export function getAppwriteErrorInfo(error: unknown): AppwriteErrorInfo {
  if (!error || typeof error !== 'object') {
    return { message: null, type: null, code: null }
  }
  const e = error as {
    message?: unknown
    type?: unknown
    code?: unknown
    status?: unknown
    response?: unknown
  }
  let message = readString(e.message)
  let type = readString(e.type)
  let code = readNumber(e.code) ?? readNumber(e.status)

  const response = e.response
  let parsed: { message?: unknown; type?: unknown; code?: unknown } | null =
    null
  if (typeof response === 'string') {
    try {
      parsed = JSON.parse(response) as {
        message?: unknown
        type?: unknown
        code?: unknown
      }
    } catch {
      parsed = null
    }
  } else if (response && typeof response === 'object') {
    parsed = response as { message?: unknown; type?: unknown; code?: unknown }
  }
  if (parsed) {
    message = message ?? readString(parsed.message)
    type = type ?? readString(parsed.type)
    code = code ?? readNumber(parsed.code)
  }

  return { message, type, code }
}

/** Static, translatable titles for Appwrite `<resource>_not_found` types. */
const RESOURCE_NOT_FOUND_TITLES: Record<string, string> = {
  user_not_found: 'User not found',
  team_not_found: 'Team not found',
  membership_not_found: 'Membership not found',
  user_target_not_found: 'Target not found',
  function_not_found: 'Function not found',
  deployment_not_found: 'Deployment not found',
  execution_not_found: 'Execution not found',
  site_not_found: 'Site not found',
  storage_bucket_not_found: 'Bucket not found',
  storage_file_not_found: 'File not found',
  database_not_found: 'Database not found',
  collection_not_found: 'Collection not found',
  document_not_found: 'Document not found',
  table_not_found: 'Table not found',
  row_not_found: 'Row not found',
  message_not_found: 'Message not found',
  provider_not_found: 'Provider not found',
  topic_not_found: 'Topic not found',
  subscriber_not_found: 'Subscriber not found',
}

/**
 * A 404 for a resource inside a project (user, team, function, ...), identified
 * by its Appwrite `<resource>_not_found` type. Returns null for the project
 * itself and for generic types, so project routes only blame the project when
 * the project is what is missing. Titles are English keys, translated at render.
 */
export function formatResourceNotFoundError(
  error: unknown,
): FormattedError | null {
  const { type, message } = getAppwriteErrorInfo(error)
  if (
    !type?.endsWith('_not_found') ||
    type === 'project_not_found' ||
    type.startsWith('general_')
  ) {
    return null
  }
  return {
    title: RESOURCE_NOT_FOUND_TITLES[type] ?? 'Not Found',
    // The SDK sends the active locale, so the API message is already localized.
    message:
      message ??
      'The requested resource could not be found. It may have been deleted or you may not have permission to access it.',
    isUserFriendly: true,
  }
}

/** True when the API responded with HTTP 402 (payment / budget limit required). */
export function isHttpPaymentRequiredError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const info = getAppwriteErrorInfo(error)
  if (info.code === 402) return true
  if (info.type === 'outstanding_invoice' || info.type === 'budget_limit') {
    return true
  }
  const message = info.message?.toLowerCase() ?? ''
  return (
    message.includes('payment required') ||
    message.includes('budget limit') ||
    message.includes('budget_limit')
  )
}

/** True when the API responded with HTTP 401 (no active console session). */
export function isHttpUnauthorizedError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const e = error as { code?: number; status?: number; name?: string }
  return e.code === 401 || e.status === 401 || e.name === 'UnauthorizedError'
}

/**
 * True when a project (or similar) fetch failed in a way the console should
 * surface as not-found / access-denied rather than keep waiting on loaders.
 */
export function isHttpProjectAccessError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  if (
    isHttpUnauthorizedError(error) ||
    isHttpForbiddenError(error) ||
    isHttpNotFoundError(error)
  ) {
    return true
  }
  const e = error as { name?: string; message?: string }
  if (e.name === 'ForbiddenError' || e.name === 'UnauthorizedError') return true
  const message = typeof e.message === 'string' ? e.message.toLowerCase() : ''
  return (
    message.includes('unauthorized') ||
    message.includes('forbidden') ||
    message.includes('permission denied') ||
    message.includes('access denied')
  )
}

/** Console / cloud support contact (e.g. blocked account screen). */
export const APPWRITE_SUPPORT_EMAIL = 'support@appwrite.io'

/**
 * User-facing copy when `sdk.forConsole.account.get()` returns HTTP 403
 * (blocked or restricted console access). Matches global error screen patterns.
 */
export const CONSOLE_ACCOUNT_ACCESS_BLOCKED: FormattedError = {
  title: 'Account access blocked',
  message: `This account cannot use the Appwrite Console - access is blocked or restricted, which may include a Terms of Service violation. For questions about this restriction or to request a review of your account, contact ${APPWRITE_SUPPORT_EMAIL}.`,
  isUserFriendly: true,
}

/**
 * Formats an error into a user-friendly message
 * @param error - The error object (Error, ErrorWithCode, or unknown)
 * @param fallbackMessage - Optional fallback message if error cannot be parsed
 * @returns Formatted error with title and message
 */
export function formatError(
  error: unknown,
  fallbackMessage: string = 'Something went wrong. Please try again.',
): FormattedError {
  // Handle null/undefined
  if (!error) {
    return {
      title: translate('Error'),
      message: translate(fallbackMessage),
      isUserFriendly: true,
    }
  }

  // Handle Error objects
  if (error instanceof Error) {
    const message = error.message || ''
    const lowerMessage = message.toLowerCase()
    const code = isErrorWithCode(error) ? getErrorCode(error) : undefined

    if (looksLikeDatabaseEngineError(message)) {
      return {
        title: translate('Invalid Request'),
        message: translate(rewriteDatabaseEngineErrorMessage(message)),
        isUserFriendly: true,
      }
    }

    // Check for 404 / not found errors
    if (
      error.name === 'NotFoundError' ||
      code === 404 ||
      lowerMessage.includes('not found') ||
      lowerMessage.includes('404') ||
      lowerMessage.includes('does not exist')
    ) {
      return {
        title: translate('Not Found'),
        message: translate(
          'The requested resource could not be found. It may have been deleted or you may not have permission to access it.',
        ),
        isUserFriendly: true,
      }
    }

    // Check for permission/authorization errors (401)
    // For auth failures (e.g. login with wrong credentials), the API often returns a specific message
    // like "Invalid credentials" - use it when present and user-friendly; otherwise use generic message.
    if (
      error.name === 'UnauthorizedError' ||
      code === 401 ||
      lowerMessage.includes('unauthorized') ||
      lowerMessage.includes('permission denied') ||
      lowerMessage.includes('access denied')
    ) {
      const isUserFriendlyMessage =
        message.length > 0 &&
        message.length <= 200 &&
        !message.includes(' at ') &&
        !message.includes('Error:') &&
        !message.includes('TypeError')
      const messageToShow = isUserFriendlyMessage
        ? message
        : translate(
            'You do not have permission to perform this action. Please contact your administrator if you believe this is an error.',
          )
      return {
        title: translate('Access Denied'),
        message: translate(messageToShow),
        isUserFriendly: true,
      }
    }

    // Check for forbidden errors
    if (code === 403 || lowerMessage.includes('forbidden')) {
      return {
        title: translate('Forbidden'),
        message: translate(
          'You do not have permission to access this resource.',
        ),
        isUserFriendly: true,
      }
    }

    // Check for validation errors
    if (
      code === 400 ||
      lowerMessage.includes('validation') ||
      lowerMessage.includes('invalid') ||
      lowerMessage.includes('bad request')
    ) {
      // Try to extract a more specific message if available
      const specificMessage =
        message.length > 0 && !message.includes('400')
          ? message
          : translate(
              'The request is invalid. Please check your input and try again.',
            )

      return {
        title: translate('Invalid Request'),
        message: translate(specificMessage),
        isUserFriendly: true,
      }
    }

    // Check for server errors
    if (
      code === 500 ||
      code === 502 ||
      code === 503 ||
      lowerMessage.includes('server error') ||
      lowerMessage.includes('internal error')
    ) {
      return {
        title: translate('Server Error'),
        message: translate(
          'An error occurred on the server. Please try again in a few moments. If the problem persists, contact support.',
        ),
        isUserFriendly: true,
      }
    }

    // Check for network errors
    if (
      error.name === 'NetworkError' ||
      (error.name === 'TypeError' && message.includes('fetch')) ||
      lowerMessage.includes('network') ||
      lowerMessage.includes('failed to fetch') ||
      lowerMessage.includes('connection')
    ) {
      return {
        title: translate('Connection Error'),
        message: translate(
          'Unable to connect to the server. Please check your internet connection and try again.',
        ),
        isUserFriendly: true,
      }
    }

    // Check for timeout errors (HTTP 408 / database_timeout / client TimeoutError).
    // Prefer the API message when it is already actionable (e.g. TablesDB
    // "Database timed out. Try adjusting your queries or adding an index.").
    const errorType =
      'type' in error && typeof (error as { type?: unknown }).type === 'string'
        ? (error as { type: string }).type
        : undefined
    if (
      error.name === 'TimeoutError' ||
      code === 408 ||
      errorType === 'database_timeout' ||
      lowerMessage.includes('timeout') ||
      lowerMessage.includes('timed out')
    ) {
      const apiMessageLooksUseful =
        message.length > 0 &&
        message.length <= 200 &&
        !message.includes('at ') &&
        (lowerMessage.includes('timed out') ||
          lowerMessage.includes('timeout') ||
          lowerMessage.includes('index') ||
          lowerMessage.includes('quer'))
      return {
        title: translate('Request Timeout'),
        message: apiMessageLooksUseful
          ? translate(message)
          : translate(
              'The request took too long to complete. Please try again.',
            ),
        isUserFriendly: true,
      }
    }

    // For other Error objects, check if message is user-friendly
    // If it's a technical error (contains stack trace indicators, etc.), use fallback
    const isTechnicalError =
      message.includes('at ') ||
      message.includes('Error:') ||
      message.includes('TypeError') ||
      message.includes('ReferenceError') ||
      message.length > 200

    if (isTechnicalError) {
      return {
        title: translate('Error'),
        message: translate(fallbackMessage),
        isUserFriendly: true,
      }
    }

    // Message seems user-friendly, use it
    return {
      title: translate('Error'),
      message: translate(
        rewriteDatabaseEngineErrorMessage(message) || fallbackMessage,
      ),
      isUserFriendly: true,
    }
  }

  // Handle objects with message property
  if (typeof error === 'object' && error !== null && 'message' in error) {
    const message = String((error as { message?: unknown }).message || '')
    if (message) {
      return formatError(new Error(message), fallbackMessage)
    }
  }

  // Handle string errors
  if (typeof error === 'string') {
    return formatError(new Error(error), fallbackMessage)
  }

  // Fallback for unknown error types
  return {
    title: translate('Error'),
    message: translate(fallbackMessage),
    isUserFriendly: true,
  }
}

/**
 * Gets a user-friendly error message for toast notifications
 * @param error - The error object
 * @param fallbackMessage - Optional fallback message
 * @returns User-friendly error message string
 */
export function getErrorMessage(
  error: unknown,
  fallbackMessage: string = 'Something went wrong. Please try again.',
): string {
  const formatted = formatError(error, fallbackMessage)
  return formatted.message
}

/**
 * Gets a user-friendly error title
 * @param error - The error object
 * @param fallbackTitle - Optional fallback title
 * @returns User-friendly error title string
 */
export function getErrorTitle(
  error: unknown,
  fallbackTitle: string = 'Error',
): string {
  const formatted = formatError(error, '')
  return formatted.title || translate(fallbackTitle)
}

/**
 * Helper function to show a toast error with consistent formatting
 * This should be used instead of toast.error() directly for better UX
 *
 * Note: This function requires toast to be imported separately to avoid circular dependencies.
 * Use getErrorMessage() with toast.error() directly if you prefer.
 *
 * @example
 * ```ts
 * import { toast } from 'sonner'
 * import { getErrorMessage } from '@/lib/utils/error-formatting'
 *
 * try {
 *   await someOperation()
 * } catch (error) {
 *   toast.error(getErrorMessage(error, 'Failed to perform operation'))
 * }
 * ```
 */
