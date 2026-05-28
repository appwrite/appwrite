import { isHtmlDarkChrome, isResolvedThemeDarkChrome } from '@/lib/html-theme'

export type InitPresenceTheme = 'light' | 'dark'

export function parseInitPresenceTheme(value: unknown): InitPresenceTheme | undefined {
  if (value === 'light' || value === 'dark') return value
  return undefined
}

export function resolveInitPresenceTheme(
  resolvedTheme: string | undefined,
): InitPresenceTheme {
  if (resolvedTheme === 'light') return 'light'
  if (resolvedTheme === 'dark') return 'dark'
  if (!resolvedTheme) return isHtmlDarkChrome() ? 'dark' : 'light'
  if (isResolvedThemeDarkChrome(resolvedTheme)) return 'dark'
  return isHtmlDarkChrome() ? 'dark' : 'light'
}

export function countInitPresenceThemes(
  users: readonly { theme?: InitPresenceTheme }[],
): { light: number; dark: number } {
  let light = 0
  let dark = 0
  for (const user of users) {
    if (user.theme === 'light') light += 1
    else if (user.theme === 'dark') dark += 1
  }
  return { light, dark }
}
