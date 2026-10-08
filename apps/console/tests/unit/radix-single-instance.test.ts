/**
 * Radix keeps its overlay bookkeeping in module-level state:
 *
 * - `react-dismissable-layer` tracks which layers disabled outside pointer
 *   events, and uses that to decide whether to put `pointer-events: auto` back
 *   on a layer while `<body>` is locked to `none`.
 * - `react-focus-scope` keeps the stack of focus scopes, so opening a popover
 *   pauses the dialog scope instead of letting it steal focus back.
 * - `react-focus-guards` counts the guards it added around the active overlay.
 *
 * That state lives in a module-scoped context/array, so two copies on disk mean
 * two independent registries. A Popover portalled to `<body>` from inside a
 * modal Dialog then never learns the dialog locked the body, renders without
 * `pointer-events: auto`, and every click inside the dropdown falls through to
 * the dialog overlay behind it — the dropdown looks fine but cannot be clicked,
 * typed into, or navigated with the keyboard.
 *
 * The versions only diverge through the lockfile, so assert the invariant here
 * rather than waiting for it to surface as a dead dropdown.
 */

import { describe, expect, test } from 'bun:test'
import { createRequire } from 'node:module'
import { dirname } from 'node:path'

const require = createRequire(import.meta.url)

/** Radix packages whose module-level state must be shared across the app. */
const SHARED_STATE_PACKAGES = [
  '@radix-ui/react-dismissable-layer',
  '@radix-ui/react-focus-scope',
  '@radix-ui/react-focus-guards',
] as const

/**
 * Every Radix package we render overlays from. A popover opened inside any of
 * these has to agree with it about the body pointer-events lock and the focus
 * scope stack.
 */
const OVERLAY_CONSUMERS = [
  '@radix-ui/react-dialog',
  '@radix-ui/react-alert-dialog',
  '@radix-ui/react-popover',
  '@radix-ui/react-dropdown-menu',
  '@radix-ui/react-context-menu',
  '@radix-ui/react-menubar',
  '@radix-ui/react-select',
  '@radix-ui/react-tooltip',
  '@radix-ui/react-hover-card',
  '@radix-ui/react-navigation-menu',
] as const

/** Directory a consumer resolves its dependencies from. */
function consumerRoot(consumer: string): string {
  return dirname(require.resolve(`${consumer}/package.json`))
}

/**
 * Where `consumer` resolves `pkg`, or null when it does not depend on it
 * (`react-hover-card` has no focus scope of its own, for example).
 */
function resolveFrom(consumer: string, pkg: string): string | null {
  try {
    return require.resolve(`${pkg}/package.json`, {
      paths: [consumerRoot(consumer)],
    })
  } catch {
    return null
  }
}

describe('Radix overlay packages resolve to a single instance', () => {
  for (const pkg of SHARED_STATE_PACKAGES) {
    test(`${pkg} is shared by every overlay consumer`, () => {
      const resolvedBy = new Map<string, string[]>()

      for (const consumer of OVERLAY_CONSUMERS) {
        const resolved = resolveFrom(consumer, pkg)
        if (!resolved) continue
        const consumers = resolvedBy.get(resolved) ?? []
        consumers.push(consumer)
        resolvedBy.set(resolved, consumers)
      }

      // Sanity check: a typo in the package names above would pass vacuously.
      expect(resolvedBy.size).toBeGreaterThan(0)

      // Report the split itself, not just the count — the fix is always to
      // realign whichever consumers pinned the odd version out.
      const copies = [...resolvedBy.entries()].map(
        ([path, consumers]) =>
          `${(require(path) as { version: string }).version} <- ${consumers.join(', ')}`,
      )

      expect(copies.join('\n')).toBe(copies[0])
    })
  }
})
