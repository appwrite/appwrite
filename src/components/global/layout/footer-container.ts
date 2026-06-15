/**
 * Container-query breakpoints for ConsoleFooter compact bar.
 * Ancestor must include `@container/footer` (see FOOTER_CONTAINER).
 *
 * Responsive behavior follows footer/content column width, not viewport, so docs
 * and project pages with sidebars hide items when the content area is narrow.
 *
 * Narrow → wide (footer width):
 * - @[640px]: legal links and dot separators
 * - @[640px]: horizontal padding bump
 * - @[1080px]: social icons and SOC 2 badge
 */

export const FOOTER_CONTAINER = '@container/footer'

export const footerCompactPaddingX = 'px-4 @[640px]/footer:px-6'

export const footerShowSeparatorMd = 'hidden @[640px]/footer:block'
export const footerShowLegalDot = 'hidden @[640px]/footer:inline'
export const footerShowLegalLinks = 'hidden @[640px]/footer:flex'

export const footerShowSeparatorLg = 'hidden @[1080px]/footer:block'
export const footerShowSocialIcons = 'hidden @[1080px]/footer:flex'
export const footerShowTrustBadge =
  'hidden @[1080px]/footer:flex items-center gap-1.5'
