/**
 * Formats errors consistently across the application.
 * Handles various error types including API errors, code errors, not found errors, etc.
 */

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
      title: 'Error',
      message: fallbackMessage,
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
        title: 'Not Found',
        message:
          'The requested resource could not be found. It may have been deleted or you may not have permission to access it.',
        isUserFriendly: true,
      }
    }

    // Check for permission/authorization errors (401)
    // For auth failures (e.g. login with wrong credentials), the API often returns a specific message
    // like "Invalid credentials" — use it when present and user-friendly; otherwise use generic message.
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
        : 'You do not have permission to perform this action. Please contact your administrator if you believe this is an error.'
      return {
        title: 'Access Denied',
        message: messageToShow,
        isUserFriendly: true,
      }
    }

    // Check for forbidden errors
    if (code === 403 || lowerMessage.includes('forbidden')) {
      return {
        title: 'Forbidden',
        message: 'You do not have permission to access this resource.',
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
          : 'The request is invalid. Please check your input and try again.'

      return {
        title: 'Invalid Request',
        message: specificMessage,
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
        title: 'Server Error',
        message:
          'An error occurred on the server. Please try again in a few moments. If the problem persists, contact support.',
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
        title: 'Connection Error',
        message:
          'Unable to connect to the server. Please check your internet connection and try again.',
        isUserFriendly: true,
      }
    }

    // Check for timeout errors
    if (error.name === 'TimeoutError' || lowerMessage.includes('timeout')) {
      return {
        title: 'Request Timeout',
        message: 'The request took too long to complete. Please try again.',
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
        title: 'Error',
        message: fallbackMessage,
        isUserFriendly: true,
      }
    }

    // Message seems user-friendly, use it
    return {
      title: 'Error',
      message: message || fallbackMessage,
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
    title: 'Error',
    message: fallbackMessage,
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
  return formatted.title || fallbackTitle
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
