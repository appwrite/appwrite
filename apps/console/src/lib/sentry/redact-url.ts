import type { Breadcrumb, ErrorEvent } from '@sentry/tanstackstart-react'

const REDACTED = 'redacted'
const SENSITIVE_SEARCH_PARAMS = ['secret', 'userId', 'token', 'code', 'jwt']
const BREADCRUMB_URL_FIELDS = ['from', 'to', 'url']

function redactSearch(search: string): string {
  const params = new URLSearchParams(search)
  const sensitive = SENSITIVE_SEARCH_PARAMS.filter((name) => params.has(name))
  if (sensitive.length === 0) return search
  for (const name of sensitive) {
    params.set(name, REDACTED)
  }
  return params.toString()
}

export function redactUrl(url: string): string {
  const searchStart = url.indexOf('?')
  if (searchStart === -1) return url
  const hashStart = url.indexOf('#', searchStart)
  const searchEnd = hashStart === -1 ? url.length : hashStart
  const search = url.slice(searchStart + 1, searchEnd)
  const redacted = redactSearch(search)
  if (redacted === search) return url
  return `${url.slice(0, searchStart)}?${redacted}${url.slice(searchEnd)}`
}

export function redactSentryEvent(event: ErrorEvent): ErrorEvent {
  const request = event.request
  if (!request) return event
  if (request.url) {
    request.url = redactUrl(request.url)
  }
  if (typeof request.query_string === 'string') {
    request.query_string = redactSearch(request.query_string)
  }
  if (request.headers?.Referer) {
    request.headers.Referer = redactUrl(request.headers.Referer)
  }
  return event
}

export function redactSentryBreadcrumb(breadcrumb: Breadcrumb): Breadcrumb {
  const data = breadcrumb.data
  if (!data) return breadcrumb
  for (const field of BREADCRUMB_URL_FIELDS) {
    const value = data[field]
    if (typeof value === 'string') {
      data[field] = redactUrl(value)
    }
  }
  return breadcrumb
}
