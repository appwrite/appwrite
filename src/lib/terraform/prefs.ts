/**
 * Projects where the user chose not to confirm changes to Terraform-managed
 * resources. Value: JSON string of string[] (project IDs).
 */
export const USER_PREFS_KEY_TERRAFORM_SKIP_CONFIRM =
  'console.terraform.skipConfirmProjectIds'

export function parseTerraformSkipConfirmProjectIds(
  prefs: Record<string, unknown> | null | undefined,
): string[] {
  const raw = prefs?.[USER_PREFS_KEY_TERRAFORM_SKIP_CONFIRM]
  if (typeof raw !== 'string') return []
  try {
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed)
      ? parsed.filter((id): id is string => typeof id === 'string')
      : []
  } catch {
    return []
  }
}

export function buildTerraformSkipConfirmPrefs(
  prefs: Record<string, unknown> | null | undefined,
  projectId: string,
): Record<string, unknown> {
  const ids = parseTerraformSkipConfirmProjectIds(prefs)
  if (ids.includes(projectId)) return {}
  return {
    [USER_PREFS_KEY_TERRAFORM_SKIP_CONFIRM]: JSON.stringify([
      ...ids,
      projectId,
    ]),
  }
}
