/**
 * Shared client OS detection for UI that toggles between operating systems
 * (CLI install, docs tabs, keyboard shortcuts, etc.).
 *
 * Debug menu can override the resolved OS via `userOs` in debug-overrides.
 */

export const USER_OS_VALUES = ['macos', 'windows', 'linux'] as const

export type UserOs = (typeof USER_OS_VALUES)[number]

export type UserOsOverride = 'auto' | UserOs

export const USER_OS_LABELS: Record<UserOs, string> = {
  macos: 'macOS',
  windows: 'Windows',
  linux: 'Linux',
}

/**
 * Detect the current client OS from the browser environment.
 * Safe to call during SSR (returns `'macos'` when `navigator` is unavailable).
 */
export function detectUserOs(): UserOs {
  if (typeof navigator === 'undefined') return 'macos'

  const ua = navigator.userAgent.toLowerCase()
  const platform = (
    (
      navigator as Navigator & {
        userAgentData?: { platform?: string }
      }
    ).userAgentData?.platform ??
    navigator.platform ??
    ''
  ).toLowerCase()

  if (
    /win32|win64|wow64|windows/.test(platform) ||
    /windows|win32|wow64/.test(ua)
  ) {
    return 'windows'
  }

  if (
    /mac|darwin|iphone|ipad|ipod/.test(platform) ||
    /macintosh|mac os|iphone|ipad|ipod/.test(ua)
  ) {
    return 'macos'
  }

  return 'linux'
}

/** Apply a debug override on top of device detection. */
export function resolveUserOs(override: UserOsOverride = 'auto'): UserOs {
  if (override === 'auto') return detectUserOs()
  return override
}

export function isMacOs(os: UserOs = detectUserOs()): boolean {
  return os === 'macos'
}

export function getUserOsLabel(os: UserOs): string {
  return USER_OS_LABELS[os]
}

/**
 * Put `preferred` first, then keep the rest in their original relative order.
 */
export function orderByPreferredOs<T>(
  items: readonly T[],
  preferred: UserOs,
  getOs: (item: T) => UserOs | null | undefined,
): T[] {
  const preferredItems: T[] = []
  const rest: T[] = []
  for (const item of items) {
    if (getOs(item) === preferred) preferredItems.push(item)
    else rest.push(item)
  }
  return [...preferredItems, ...rest]
}

/** Convenience for plain OS option lists. */
export function orderOsOptions(
  options: readonly UserOs[],
  preferred: UserOs = detectUserOs(),
): UserOs[] {
  return orderByPreferredOs(options, preferred, (os) => os)
}

/**
 * Map a docs/CLI-style tab id or title to a user OS when it represents one.
 * Returns null for non-OS tabs (e.g. npm). Combined Unix tabs resolve to
 * `'macos'` for ordering helpers; use `tabMatchesUserOs` for matching.
 */
export function matchTabToUserOs(
  idOrTitle: string | undefined | null,
): UserOs | null {
  if (!idOrTitle) return null
  const key = idOrTitle.trim().toLowerCase().replace(/\s+/g, '')

  if (
    key === 'macos' ||
    key === 'mac' ||
    key === 'osx' ||
    key === 'darwin'
  ) {
    return 'macos'
  }
  if (
    key === 'windows' ||
    key === 'win' ||
    key === 'cmd' ||
    key === 'powershell'
  ) {
    return 'windows'
  }
  if (key === 'linux') {
    return 'linux'
  }
  // Combined Unix-style tabs used in self-hosting docs.
  if (
    key === 'unix' ||
    key === 'macosandlinux' ||
    key === 'macandlinux' ||
    key === 'linuxandmacos'
  ) {
    return 'macos'
  }
  return null
}

/** Whether a tab is appropriate for the given OS (including combined Unix tabs). */
export function tabMatchesUserOs(
  idOrTitle: string | undefined | null,
  os: UserOs,
): boolean {
  if (!idOrTitle) return false
  const key = idOrTitle.trim().toLowerCase().replace(/\s+/g, '')
  const matched = matchTabToUserOs(idOrTitle)
  if (matched === os) return true
  if (
    (os === 'macos' || os === 'linux') &&
    (key === 'unix' ||
      key === 'macosandlinux' ||
      key === 'macandlinux' ||
      key === 'linuxandmacos')
  ) {
    return true
  }
  return false
}

/**
 * Among registered tabs, pick the best default for the resolved OS.
 * Prefers an exact OS match; falls back to combined Unix tabs for mac/linux.
 */
export function resolvePreferredOsTabId(
  tabs: ReadonlyArray<{ id: string; title?: string }>,
  os: UserOs = detectUserOs(),
): string | null {
  if (tabs.length === 0) return null

  const exact = tabs.find((tab) => {
    const matched =
      matchTabToUserOs(tab.id) ?? matchTabToUserOs(tab.title ?? null)
    return matched === os
  })
  if (exact) return exact.id

  const soft = tabs.find(
    (tab) =>
      tabMatchesUserOs(tab.id, os) || tabMatchesUserOs(tab.title ?? null, os),
  )
  return soft?.id ?? null
}

/**
 * Reorder tabs so the preferred OS option comes first among OS tabs.
 * Pure OS groups are fully reordered; mixed groups (e.g. npm + OSes) keep
 * non-OS tabs in place and only reorder the OS subset in their slots.
 */
export function orderTabsByPreferredOs<T extends { id: string; title?: string }>(
  tabs: readonly T[],
  preferred: UserOs = detectUserOs(),
): T[] {
  const getOs = (tab: T) =>
    matchTabToUserOs(tab.id) ?? matchTabToUserOs(tab.title ?? null)

  const allAreOs = tabs.length > 0 && tabs.every((tab) => getOs(tab) != null)

  if (allAreOs) {
    return orderByPreferredOs(tabs, preferred, (tab) => {
      if (tabMatchesUserOs(tab.id, preferred) || tabMatchesUserOs(tab.title, preferred)) {
        return preferred
      }
      return getOs(tab)
    })
  }

  const osIndexes: number[] = []
  const osTabs: T[] = []
  tabs.forEach((tab, index) => {
    if (getOs(tab) != null) {
      osIndexes.push(index)
      osTabs.push(tab)
    }
  })
  if (osTabs.length === 0) return [...tabs]

  const orderedOs = orderByPreferredOs(osTabs, preferred, (tab) => {
    if (
      tabMatchesUserOs(tab.id, preferred) ||
      tabMatchesUserOs(tab.title, preferred)
    ) {
      return preferred
    }
    return getOs(tab)
  })

  const next = [...tabs]
  osIndexes.forEach((index, i) => {
    next[index] = orderedOs[i]!
  })
  return next
}

/** Default CLI shell tab for create-deployment dialogs. */
export type CliShellTab = 'unix' | 'cmd' | 'powershell'

export function resolveDefaultCliShellTab(
  os: UserOs = detectUserOs(),
): CliShellTab {
  return os === 'windows' ? 'cmd' : 'unix'
}

export function orderCliShellTabs(
  os: UserOs = detectUserOs(),
): CliShellTab[] {
  if (os === 'windows') return ['cmd', 'powershell', 'unix']
  return ['unix', 'cmd', 'powershell']
}
