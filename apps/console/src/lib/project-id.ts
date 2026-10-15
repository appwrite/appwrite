/**
 * The `ProjectId` rule, shared by project ids and project environment ids.
 *
 * Both are validated server-side by
 * `Appwrite\Utopia\Database\Validator\ProjectId`: a-z, 0-9 and hyphen, no
 * leading hyphen, at most 36 characters. This module has no dependencies so
 * the shared `IdInput` primitive can read the rule without importing a feature.
 */

export const PROJECT_ID_MAX_LENGTH = 36

const PROJECT_ID_REGEX = /^[a-z0-9][a-z0-9-]*$/

/**
 * One deliberate divergence from the server: an empty id counts as valid,
 * because a blank field means "auto-generate" and the caller sends
 * `ID.unique()` instead of what was typed.
 */
export function isValidProjectId(id: string): boolean {
  if (!id) return true
  if (id.length > PROJECT_ID_MAX_LENGTH) return false
  return PROJECT_ID_REGEX.test(id)
}
