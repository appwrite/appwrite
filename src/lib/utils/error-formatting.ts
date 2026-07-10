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

/** True when the API responded with HTTP 403 (e.g. blocked console account). */
export function isHttpForbiddenError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const e = error as { code?: number; status?: number }
  return e.code === 403 || e.status === 403
}

/** True when the API responded with HTTP 404 (resource missing or inaccessible). */
export function isHttpNotFoundError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const e = error as { code?: number; status?: number; name?: string; message?: string }
  if (e.code === 404 || e.status === 404 || e.name === 'NotFoundError') return true
  const message = typeof e.message === 'string' ? e.message.toLowerCase() : ''
  return (
    message.includes('not found') ||
    message.includes('404') ||
    message.includes('does not exist')
  )
}

/** True when the API responded with HTTP 402 (payment / budget limit required). */
export function isHttpPaymentRequiredError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const e = error as { code?: number; status?: number; message?: string }
  if (e.code === 402 || e.status === 402) return true
  const message = typeof e.message === 'string' ? e.message.toLowerCase() : ''
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
  return (
    e.code === 401 ||
    e.status === 401 ||
    e.name === 'UnauthorizedError'
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
        message:
          translate('The requested resource could not be found. It may have been deleted or you may not have permission to access it.'),
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
        : translate('You do not have permission to perform this action. Please contact your administrator if you believe this is an error.')
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
        message: translate('You do not have permission to access this resource.'),
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
          : translate('The request is invalid. Please check your input and try again.')

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
        message:
          translate('An error occurred on the server. Please try again in a few moments. If the problem persists, contact support.'),
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
        message:
          translate('Unable to connect to the server. Please check your internet connection and try again.'),
        isUserFriendly: true,
      }
    }

    // Check for timeout errors
    if (error.name === 'TimeoutError' || lowerMessage.includes('timeout')) {
      return {
        title: translate('Request Timeout'),
        message: translate('The request took too long to complete. Please try again.'),
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
      message: translate(message || fallbackMessage),
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
