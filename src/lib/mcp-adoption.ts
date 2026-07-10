/**
 * Client-side adoption helpers for Appwrite MCP install flows.
 * Completions are local (not backend onboarding stages).
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

const AGENT_ONBOARDING_PREFIX = 'console.mcp.agentOnboardingDone.'

function storageGet(key: string): string | null {
  if (typeof window === 'undefined') return null
  try {
    return window.localStorage.getItem(key)
  } catch {
    return null
  }
}

function storageSet(key: string, value: string): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(key, value)
  } catch {
    // Ignore quota / private mode failures
  }
}

export function onboardingAgentStorageKey(projectId: string): string {
  return `${AGENT_ONBOARDING_PREFIX}${projectId}`
}

export function getOnboardingAgentStepState(
  projectId: string,
): 'pending' | 'completed' | 'skipped' {
  const value = storageGet(onboardingAgentStorageKey(projectId))
  if (value === 'skipped') return 'skipped'
  if (value === 'completed') return 'completed'
  return 'pending'
}

export function isOnboardingAgentStepDone(projectId: string): boolean {
  return getOnboardingAgentStepState(projectId) !== 'pending'
}

export function markOnboardingAgentStepSkipped(projectId: string): void {
  storageSet(onboardingAgentStorageKey(projectId), 'skipped')
}

export function markOnboardingAgentStepDone(projectId: string): void {
  storageSet(onboardingAgentStorageKey(projectId), 'completed')
}
