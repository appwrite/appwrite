/**
 * Validation for function, site and project environment variables.
 *
 * Variable keys become environment variable names at build and runtime, so the
 * API only accepts C-style identifiers. Keys stored before that rule existed
 * can still fail it, which is why only create paths check the format: an update
 * sends the stored key back unchanged and has to keep working.
 */

import { translate } from '@/lib/i18n/translate'

export const VARIABLE_KEY_MAX_LENGTH = 255
export const VARIABLE_VALUE_MAX_LENGTH = 8192

const VARIABLE_KEY_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*$/

export function getVariableKeyError(key: string): string | null {
  if (!key?.trim()) {
    return translate('Variable key is required')
  }

  if (key.length > VARIABLE_KEY_MAX_LENGTH) {
    return `${translate('Variable key')} ${key} ${translate('is longer than 255 allowed characters')}`
  }

  if (!VARIABLE_KEY_PATTERN.test(key)) {
    return `${translate('Variable key')} ${key} ${translate('can only contain letters, digits and underscores, and cannot start with a digit')}`
  }

  return null
}

export function getVariableValueError(
  key: string,
  value: unknown,
): string | null {
  if (String(value ?? '').length > VARIABLE_VALUE_MAX_LENGTH) {
    return `${translate('Variable')} ${key} ${translate('is longer than 8192 allowed characters')}`
  }

  return null
}

/**
 * Returns the first problem found, or null when every variable is accepted by
 * the API. Call before submitting so a rejected key is reported against the
 * row or file it came from instead of as a bare server error -- and, in the
 * create wizards, before the site or function itself is created, so a rejected
 * variable cannot leave a half-configured resource behind.
 *
 * Pass only the variables that will actually be written: the wizards drop
 * valueless or keyless rows, and rejecting one of those blocks a deploy over a
 * variable that was never going to be sent.
 */
export function validateVariables(
  variables: Array<{ key?: string; value?: unknown }>,
): string | null {
  for (const { key, value } of variables) {
    const keyError = getVariableKeyError(key ?? '')
    if (keyError) {
      return keyError
    }

    const valueError = getVariableValueError(key ?? '', value)
    if (valueError) {
      return valueError
    }
  }

  return null
}
