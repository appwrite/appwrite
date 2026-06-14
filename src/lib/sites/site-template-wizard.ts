import type { Models } from '@appwrite.io/console'

/** Public catalog project for site templates (use sdk.forConsole, not forProject admin mode). */
export const MARKETING_SITE_TEMPLATES_PROJECT_ID = 'console'

/** Default page size for the site template gallery (3-column grid; matches create wizard). */
export const SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE = 9

/** Marketing product page: 4 columns × 2 rows. */
export const MARKETING_SITE_TEMPLATES_PAGE_SIZE = 8
export const MARKETING_SITE_TEMPLATES_COLUMNS = 4

/** @deprecated Use SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE */
export const PRODUCT_SITES_TEMPLATES_PREVIEW_LIMIT = SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE

/** Use case options for template filtering (must match API enum values). */
export const SITE_TEMPLATE_USE_CASE_OPTIONS = [
  { value: 'all', label: 'All use cases' },
  { value: 'starter', label: 'Starter' },
  { value: 'ai', label: 'AI' },
  { value: 'databases', label: 'Databases' },
  { value: 'messaging', label: 'Messaging' },
  { value: 'dev-tools', label: 'Dev tools' },
  { value: 'utilities', label: 'Utilities' },
] as const

export function getSiteTemplateScreenshotUrl(
  template: Pick<Models.TemplateSite, 'screenshotDark' | 'screenshotLight'>,
  isDark: boolean,
): string | undefined {
  return isDark ? template.screenshotDark : template.screenshotLight
}

export type SiteTemplateFrameworkOption = {
  value: string
  label: string
}

/** Framework filter options from API framework list (same shape as create wizard). */
export function buildSiteTemplateFrameworkOptions(
  frameworks: unknown[] | undefined,
): SiteTemplateFrameworkOption[] {
  const options: SiteTemplateFrameworkOption[] = [
    { value: 'all', label: 'All frameworks' },
  ]
  if (!frameworks?.length) return options

  for (const fw of frameworks) {
    const key =
      typeof fw === 'string'
        ? fw
        : typeof fw === 'object' && fw !== null
          ? String(
              (fw as Record<string, unknown>).key ??
                (fw as Record<string, unknown>).name ??
                (fw as Record<string, unknown>).id ??
                '',
            )
          : ''
    const name =
      typeof fw === 'string'
        ? fw
        : typeof fw === 'object' && fw !== null
          ? String(
              (fw as Record<string, unknown>).name ??
                (fw as Record<string, unknown>).key ??
                (fw as Record<string, unknown>).id ??
                '',
            )
          : ''
    if (key && name) {
      options.push({ value: key, label: name })
    }
  }

  return options
}
