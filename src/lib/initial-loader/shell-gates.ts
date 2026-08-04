/** Gate keys the fullscreen loader may wait on before hiding. */
export const INITIAL_LOADER_SHELL_GATE = {
  projectSelector: 'project-selector',
} as const

type ShellGateKey =
  (typeof INITIAL_LOADER_SHELL_GATE)[keyof typeof INITIAL_LOADER_SHELL_GATE]

const gateReady = new Map<ShellGateKey, boolean>()
const listeners = new Set<() => void>()

function notifyShellGateListeners() {
  listeners.forEach((listener) => listener())
}

export function setInitialLoaderShellGate(key: ShellGateKey, ready: boolean) {
  if (gateReady.get(key) === ready) return
  gateReady.set(key, ready)
  notifyShellGateListeners()
}

export function resetInitialLoaderShellGate(key: ShellGateKey) {
  if (!gateReady.has(key)) return
  gateReady.delete(key)
  notifyShellGateListeners()
}

export function subscribeInitialLoaderShellGates(listener: () => void) {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/** Project detail routes show the header project selector on first paint. */
export function projectRouteRequiresProjectSelectorGate(pathname: string): boolean {
  if (!pathname.startsWith('/projects/')) return false
  const segments = pathname.split('/').filter(Boolean)
  // /projects/:projectId/...
  return segments.length >= 2 && Boolean(segments[1])
}

/** Extract `/projects/:projectId` from a console pathname. */
export function getProjectIdFromPathname(pathname: string): string | null {
  if (!pathname.startsWith('/projects/')) return null
  const segments = pathname.split('/').filter(Boolean)
  return segments.length >= 2 ? segments[1]! : null
}

export function areInitialLoaderShellGatesReady(pathname: string): boolean {
  if (!projectRouteRequiresProjectSelectorGate(pathname)) return true
  return gateReady.get(INITIAL_LOADER_SHELL_GATE.projectSelector) === true
}
