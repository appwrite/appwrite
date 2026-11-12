import { ID } from '@appwrite.io/console'

export const DEDICATED_DATABASE_ID_MAX_LENGTH = 36

const DEDICATED_DATABASE_ID_REGEX = /^[A-Za-z0-9]+$/

export function isValidDedicatedDatabaseId(id: string): boolean {
  const trimmed = id.trim()
  if (!trimmed || trimmed.length > DEDICATED_DATABASE_ID_MAX_LENGTH) {
    return false
  }
  return DEDICATED_DATABASE_ID_REGEX.test(trimmed)
}

export function getDedicatedDatabaseIdError(id: string): string | null {
  const trimmed = id.trim()
  if (!trimmed) return null
  if (trimmed.length > DEDICATED_DATABASE_ID_MAX_LENGTH) {
    return `Database ID must be ${DEDICATED_DATABASE_ID_MAX_LENGTH} characters or less.`
  }
  if (!DEDICATED_DATABASE_ID_REGEX.test(trimmed)) {
    return 'Database ID must contain only letters and numbers (no hyphens, underscores, or periods).'
  }
  return null
}

export function resolveDedicatedDatabaseId(customId?: string | null): string {
  const trimmed = customId?.trim()
  return trimmed && trimmed !== '' ? trimmed : ID.unique()
}

export function formatDedicatedDatabaseCreateError(
  error: unknown,
  fallbackMessage: string,
): string {
  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : fallbackMessage

  if (!message.includes('dedicated database IDs must match')) {
    return message || fallbackMessage
  }

  return 'Database ID must contain only letters and numbers. Leave the ID blank to auto-generate one, or choose a custom ID without hyphens, underscores, or periods.'
}
