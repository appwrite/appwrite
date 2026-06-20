/** True for off-site URLs; relative paths and hash links stay in-app. */
export function isExternalInitHref(href: string): boolean {
  return /^https?:\/\//i.test(href) || href.startsWith('//')
}

export const INIT_TICKET_SECTION_HASH = '#ticket'
