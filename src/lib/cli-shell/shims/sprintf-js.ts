/**
 * ESM re-export for sprintf-js (CJS-only). just-bash imports `{ sprintf }`
 * from "sprintf-js", which fails under Vite without this shim.
 */
import sprintfRoot from 'sprintf-js/src/sprintf.js'

type SprintfExports = {
  sprintf: (...args: unknown[]) => string
  vsprintf: (format: string, argv: unknown[]) => string
}

function resolveSprintfExports(mod: unknown): SprintfExports {
  if (mod && typeof mod === 'object') {
    const record = mod as Record<string, unknown>
    if (typeof record.sprintf === 'function') {
      return record as SprintfExports
    }
    const nested = record.default
    if (
      nested &&
      typeof nested === 'object' &&
      typeof (nested as SprintfExports).sprintf === 'function'
    ) {
      return nested as SprintfExports
    }
  }
  throw new Error('Failed to load sprintf-js in the browser runtime.')
}

const { sprintf, vsprintf } = resolveSprintfExports(sprintfRoot)

export { sprintf, vsprintf }
export default { sprintf, vsprintf }
