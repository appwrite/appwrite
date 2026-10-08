/**
 * Template placeholder resolution for sites and functions templates.
 *
 * Replaces exact placeholder strings ({apiEndpoint}, {projectId}, {projectName})
 * with actual runtime values so templates work without manual copy-paste.
 * See docs/SITES_TEMPLATE_VARIABLES.md for the single source of truth.
 */

export const TEMPLATE_PLACEHOLDERS = {
  API_ENDPOINT: '{apiEndpoint}',
  PROJECT_ID: '{projectId}',
  PROJECT_NAME: '{projectName}',
} as const

export type TemplatePlaceholderContext = {
  apiEndpoint: string
  projectId: string
  projectName: string
}

/**
 * Resolves a single variable value if it is exactly one of the supported placeholders.
 * Matching is case-sensitive. Returns the original value if not a placeholder.
 */
export function resolveTemplatePlaceholder(
  value: string,
  context: TemplatePlaceholderContext,
): string {
  switch (value) {
    case TEMPLATE_PLACEHOLDERS.API_ENDPOINT:
      return context.apiEndpoint
    case TEMPLATE_PLACEHOLDERS.PROJECT_ID:
      return context.projectId
    case TEMPLATE_PLACEHOLDERS.PROJECT_NAME:
      return context.projectName
    default:
      return value
  }
}

type VariableWithValue = { value?: string; placeholder?: string }

/**
 * Resolves placeholder values in an array of template variables.
 * For each variable, if value is exactly a placeholder, sets value and placeholder
 * to the resolved value. Otherwise leaves them unchanged.
 */
export function resolveTemplateVariables<T extends VariableWithValue>(
  variables: T[],
  context: TemplatePlaceholderContext,
): T[] {
  return variables.map((v) => {
    const resolvedValue = resolveTemplatePlaceholder(v.value ?? '', context)
    const wasPlaceholder =
      v.value === TEMPLATE_PLACEHOLDERS.API_ENDPOINT ||
      v.value === TEMPLATE_PLACEHOLDERS.PROJECT_ID ||
      v.value === TEMPLATE_PLACEHOLDERS.PROJECT_NAME

    return {
      ...v,
      value: resolvedValue,
      ...(wasPlaceholder && { placeholder: resolvedValue }),
    } as T
  })
}
