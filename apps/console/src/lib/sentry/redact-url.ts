/**
 * Console URLs carry recovery, magic-link and verification secrets in the
 * query string and OAuth results in the hash, so Sentry only gets the path.
 */
export function redactSentryUrl(url: string): string {
  let end = url.length
  for (const separator of ['?', '#']) {
    const index = url.indexOf(separator)
    if (index !== -1 && index < end) end = index
  }
  return url.slice(0, end)
}

type RedactableBreadcrumb = {
  data?: { [key: string]: unknown }
}

type RedactableEvent = {
  request?: { url?: string; query_string?: unknown }
  contexts?: { [key: string]: { [key: string]: unknown } | null | undefined }
  extra?: { [key: string]: unknown }
  breadcrumbs?: RedactableBreadcrumb[]
}

const BREADCRUMB_URL_KEYS = ['url', 'from', 'to']

export function redactSentryBreadcrumb<T extends RedactableBreadcrumb>(
  breadcrumb: T,
): T {
  const data = breadcrumb.data
  if (!data) return breadcrumb
  for (const key of BREADCRUMB_URL_KEYS) {
    const value = data[key]
    if (typeof value === 'string') data[key] = redactSentryUrl(value)
  }
  return breadcrumb
}

export function redactSentryEvent<T extends RedactableEvent>(event: T): T {
  if (event.request) {
    if (event.request.url) {
      event.request.url = redactSentryUrl(event.request.url)
    }
    delete event.request.query_string
  }

  const navigation = event.contexts?.navigation
  if (navigation && typeof navigation.fullUrl === 'string') {
    navigation.fullUrl = redactSentryUrl(navigation.fullUrl)
  }

  if (typeof event.extra?.url === 'string') {
    event.extra.url = redactSentryUrl(event.extra.url)
  }

  event.breadcrumbs?.forEach(redactSentryBreadcrumb)
  return event
}
