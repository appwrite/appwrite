/**
 * Destinations the header account menu links to that are also pages of this app.
 * The menu hides the entry matching the page you are already on.
 */
export type AccountMenuSection = 'home' | 'docs' | 'changelog'

export type AccountMenuLink =
  | AccountMenuSection
  | 'console'
  /** Temporary: remove once the old console is retired. */
  | 'oldConsole'

const ACCOUNT_MENU_SECTION_PATHS: readonly (readonly [
  AccountMenuSection,
  string,
])[] = [
  ['home', '/home'],
  ['docs', '/docs'],
  ['changelog', '/changelog'],
]

/** Resolves which account-menu destination `pathname` already is, if any. */
export function getActiveAccountMenuSection(
  pathname: string,
): AccountMenuSection | null {
  const normalized = pathname.split('?')[0]?.split('#')[0] ?? pathname
  const trimmed = normalized.replace(/\/+$/, '') || '/'

  for (const [section, base] of ACCOUNT_MENU_SECTION_PATHS) {
    if (trimmed === base || trimmed.startsWith(`${base}/`)) return section
  }

  return null
}

type AccountMenuLinksOptions = {
  pathname: string
  /** True on the marketing/docs header, where the console is somewhere to go. */
  showMarketingNav: boolean
  isCloud: boolean
}

/**
 * Ordered account-menu destinations, minus the page you are already on.
 * The header renders these in this order; keep the JSX in sync.
 */
export function getAccountMenuLinks({
  pathname,
  showMarketingNav,
  isCloud,
}: AccountMenuLinksOptions): AccountMenuLink[] {
  const active = getActiveAccountMenuSection(pathname)
  const links: AccountMenuLink[] = []

  if (active !== 'home') links.push('home')
  if (showMarketingNav) links.push('console')
  if (active !== 'docs') links.push('docs')
  if (active !== 'changelog') links.push('changelog')
  if (isCloud) links.push('oldConsole')

  return links
}
