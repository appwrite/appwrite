/**
 * Client-side helpers for Appwrite MCP try-it prompts in Connect flows.
 */

/** Templates use `{projectName}` so try-it prompts target the open project. */
export const MCP_TRY_IT_PROMPT_TEMPLATES = [
  'Use Appwrite MCP to list the databases in project {projectName}',
  'Use Appwrite MCP to list the storage buckets in project {projectName}',
  'Use Appwrite MCP to list the users in project {projectName}',
] as const

export function getMcpTryItPrompts(projectName: string): string[] {
  return MCP_TRY_IT_PROMPT_TEMPLATES.map((template) =>
    template.replaceAll('{projectName}', projectName),
  )
}
