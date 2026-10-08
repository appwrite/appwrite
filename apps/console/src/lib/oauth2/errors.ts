/**
 * Exception `type` values returned by the OAuth2 endpoints. The SDK does not
 * export these, so they are mirrored here.
 */
export const OAuth2ErrorType = {
  /**
   * Covers every dead request_uri handle case (expired, consumed, malformed).
   */
  INVALID_REQUEST: 'oauth2_invalid_request',
} as const

export const OAuth2ErrorMessage = {
  AUTHORIZE_FAILED: 'Could not start authorization.',
  GRANT_INVALID: 'This authorization request is invalid or has expired.',
  HANDLE_EXPIRED:
    'This sign-in request has expired. Return to the application and try connecting again.',
  MISSING_REQUEST:
    'Missing authorization request. Open this page from an application sign-in.',
} as const
