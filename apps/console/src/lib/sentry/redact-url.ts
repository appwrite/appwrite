import type { Breadcrumb, ErrorEvent } from '@sentry/tanstackstart-react'

const REDACTED = 'redacted'
const CIRCULAR = '[Circular]'
const QUERY_PARAMETER = /([?&#])([^=&#?\s"'<>]+)=([^&#\s"'<>]*)/g

export function redactUrl(text: string): string {
  return text.replace(QUERY_PARAMETER, `$1$2=${REDACTED}`)
}

function redactQueryString(query: string): string {
  return redactUrl(`?${query}`).slice(1)
}

function isPlainObject(value: object): value is Record<string, unknown> {
  const prototype: unknown = Object.getPrototypeOf(value)
  return prototype === Object.prototype || prototype === null
}

function redactValue(value: unknown, ancestors: WeakSet<object>): unknown {
  if (typeof value === 'string') return redactUrl(value)
  if (!value || typeof value !== 'object') return value
  if (ancestors.has(value)) return CIRCULAR
  if (!Array.isArray(value) && !isPlainObject(value)) return value

  ancestors.add(value)
  const redacted = Array.isArray(value)
    ? value.map((item) => redactValue(item, ancestors))
    : Object.fromEntries(
        Object.entries(value).map(([key, item]) => [
          key,
          redactValue(item, ancestors),
        ]),
      )
  ancestors.delete(value)
  return redacted
}

function redact<T>(value: T): T {
  return redactValue(value, new WeakSet()) as T
}

export function redactSentryBreadcrumb(breadcrumb: Breadcrumb): Breadcrumb {
  return {
    ...breadcrumb,
    message:
      breadcrumb.message === undefined
        ? undefined
        : redactUrl(breadcrumb.message),
    data: redact(breadcrumb.data),
  }
}

export function redactSentryEvent(event: ErrorEvent): ErrorEvent {
  if (event.request) {
    const { query_string: query, ...request } = event.request
    event.request = redact(request)
    if (query !== undefined) {
      event.request.query_string =
        typeof query === 'string' ? redactQueryString(query) : REDACTED
    }
  }
  if (event.message !== undefined) {
    event.message = redactUrl(event.message)
  }
  if (event.transaction !== undefined) {
    event.transaction = redactUrl(event.transaction)
  }
  for (const exception of event.exception?.values ?? []) {
    if (exception.value !== undefined) {
      exception.value = redactUrl(exception.value)
    }
  }
  event.extra = redact(event.extra)
  event.contexts = redact(event.contexts)
  event.tags = redact(event.tags)
  event.breadcrumbs = event.breadcrumbs?.map(redactSentryBreadcrumb)
  return event
}
