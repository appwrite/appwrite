/**
 * Client-side adoption helpers for Appwrite MCP install flows.
 * Completions are local (not backend onboarding stages).
 */

export const MCP_TRY_IT_PROMPTS = [
  'List my databases',
  'List my storage buckets',
  'Show my project users',
] as const

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
