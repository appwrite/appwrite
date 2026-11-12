/**
 * Validation for function, site and project environment variables.
 *
 * Variable keys become environment variable names at build and runtime, so the
 * API only accepts C-style identifiers. Keys stored before that rule existed
 * can still fail it, which is why only create paths check the format: an update
 * sends the stored key back unchanged and has to keep working.
 */

import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { translate } from '@/lib/i18n/translate'

/** Appwrite list endpoints default to 25 and cap a page at 100. */
const VARIABLE_LIST_PAGE_SIZE = 100
const VARIABLE_LIST_MAX_PAGES = 50

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

function sortVariablesByCreatedAtDesc(variables: Models.Variable[]) {
  return [...variables].sort((a, b) => {
    const aTime = new Date(a.$createdAt || 0).getTime()
    const bTime = new Date(b.$createdAt || 0).getTime()
    return bTime - aTime
  })
}

/**
 * Page through every variable for a resource. `listVariables` defaults to 25
 * and the settings UI paginates client-side from this full list.
 */
export async function fetchAllVariables(
  list: (queries: string[]) => Promise<Models.VariableList>,
): Promise<{ variables: Models.Variable[]; total: number }> {
  const variables: Models.Variable[] = []
  let total = 0

  for (let page = 0; page < VARIABLE_LIST_MAX_PAGES; page++) {
    const response = await list([
      Query.limit(VARIABLE_LIST_PAGE_SIZE),
      Query.offset(variables.length),
      Query.orderDesc('$createdAt'),
    ])
    const chunk = response.variables || []
    if (chunk.length === 0) {
      break
    }

    variables.push(...chunk)
    total = response.total || variables.length

    if (variables.length >= total) {
      break
    }
  }

  return {
    variables: sortVariablesByCreatedAtDesc(variables),
    total: Math.max(total, variables.length),
  }
}
