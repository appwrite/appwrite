import type { CliShellContainer } from './types'

export type ProjectBootstrapState = {
  promise: Promise<CliShellContainer> | null
  container: CliShellContainer | null
}

const projectBootstrapState = new Map<string, ProjectBootstrapState>()

export function getProjectBootstrapState(
  projectId: string,
): ProjectBootstrapState {
  const existing = projectBootstrapState.get(projectId)
  if (existing) return existing
  const created: ProjectBootstrapState = { promise: null, container: null }
  projectBootstrapState.set(projectId, created)
  return created
}

export function resetProjectBootstrapState(projectId: string): void {
  const state = projectBootstrapState.get(projectId)
  if (!state) return
  state.container = null
  state.promise = null
}

export function resetAllCliShellBootstraps(): void {
  for (const state of projectBootstrapState.values()) {
    state.container = null
    state.promise = null
  }
}

export function deleteProjectBootstrapState(projectId: string): void {
  projectBootstrapState.delete(projectId)
}
