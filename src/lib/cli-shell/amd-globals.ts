type AmdGlobalWindow = Window & {
  define?: AMDModuleLoader
}

type AMDModuleLoader = ((...args: unknown[]) => unknown) & {
  amd?: unknown
}

type AmdGlobalsSnapshot = {
  defineAmd: unknown
  hadDefineAmd: boolean
}

function getAmdWindow(): AmdGlobalWindow | null {
  if (typeof window === 'undefined') return null
  return window as AmdGlobalWindow
}

function neutralizeAmdGlobals(): AmdGlobalsSnapshot | null {
  const amdWindow = getAmdWindow()
  const define = amdWindow?.define
  if (!define || typeof define !== 'function') return null

  const hadDefineAmd = Object.prototype.hasOwnProperty.call(define, 'amd')
  const snapshot: AmdGlobalsSnapshot = {
    defineAmd: define.amd,
    hadDefineAmd,
  }

  try {
    delete define.amd
  } catch {
    define.amd = undefined
  }

  return snapshot
}

function restoreAmdGlobals(snapshot: AmdGlobalsSnapshot | null): void {
  const amdWindow = getAmdWindow()
  const define = amdWindow?.define
  if (!snapshot || !define || typeof define !== 'function') return

  if (snapshot.hadDefineAmd) {
    define.amd = snapshot.defineAmd
    return
  }

  try {
    delete define.amd
  } catch {
    define.amd = undefined
  }
}

/**
 * Monaco's CDN AMD loader sets `window.define.amd`. UMD deps in the browser CLI
 * (e.g. bignumber.js via json-bigint) detect that flag and register with Monaco's
 * loader, which fails. Clear `define.amd` for the duration of almostnode execution so
 * modules fall back to CommonJS `module.exports`.
 */
export async function withIsolatedAmdGlobals<T>(
  fn: () => Promise<T>,
): Promise<T> {
  const snapshot = neutralizeAmdGlobals()
  try {
    return await fn()
  } finally {
    restoreAmdGlobals(snapshot)
  }
}
