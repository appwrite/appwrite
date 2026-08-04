/**
 * Wraps Appwrite SDK service objects so that any call taking longer than
 * SLOW_CALL_THRESHOLD_MS is reported to Sentry. Does not throw or break the UI.
 */

import * as Sentry from '@sentry/tanstackstart-react'
import { canTrackAnalytics } from '@/lib/cookie-consent/consent-state'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { initSentryClient } from '@/lib/sentry/init-client'

export const SLOW_CALL_THRESHOLD_MS = 5000

function isSentryEnabled(): boolean {
  return !!getRuntimeConfig().sentryDsn && canTrackAnalytics()
}

function reportSlowSdkCall(
  scope: string,
  method: string,
  durationMs: number,
): void {
  if (!isSentryEnabled()) return
  if (!initSentryClient()) return
  try {
    Sentry.captureMessage(`Slow Appwrite SDK call: ${scope}.${method}`, {
      level: 'warning',
      tags: {
        sdk_scope: scope,
        sdk_method: method,
        duration_ms: String(durationMs),
      },
      extra: {
        scope,
        method,
        durationMs,
        thresholdMs: SLOW_CALL_THRESHOLD_MS,
      },
    })
  } catch {
    // Never break the app if Sentry fails
  }
}

function isPromiseLike(
  value: unknown,
): value is PromiseLike<unknown> {
  return (
    value !== null &&
    typeof value === 'object' &&
    typeof (value as PromiseLike<unknown>).then === 'function'
  )
}

/**
 * Wraps SDK methods to measure duration and report to Sentry when >= SLOW_CALL_THRESHOLD_MS.
 * Sync methods (e.g. URL builders like `avatars.getQR`) keep their return type; async methods
 * are timed until the promise settles.
 */
function wrapWithTiming<T extends (...args: unknown[]) => unknown>(
  fn: T,
  scope: string,
  method: string,
): T {
  return ((...args: Parameters<T>) => {
    const start = Date.now()
    try {
      const result = fn(...args)
      if (isPromiseLike(result)) {
        return result.then(
          (resolved) => {
            const duration = Date.now() - start
            if (duration >= SLOW_CALL_THRESHOLD_MS) {
              reportSlowSdkCall(scope, method, duration)
            }
            return resolved
          },
          (err: unknown) => {
            const duration = Date.now() - start
            if (duration >= SLOW_CALL_THRESHOLD_MS) {
              reportSlowSdkCall(scope, method, duration)
            }
            throw err
          },
        )
      }
      const duration = Date.now() - start
      if (duration >= SLOW_CALL_THRESHOLD_MS) {
        reportSlowSdkCall(scope, method, duration)
      }
      return result
    } catch (err) {
      const duration = Date.now() - start
      if (duration >= SLOW_CALL_THRESHOLD_MS) {
        reportSlowSdkCall(scope, method, duration)
      }
      throw err
    }
  }) as T
}

/** Keys we skip when recursing (e.g. client instance, not a service with API methods). */
const SKIP_KEYS = new Set(['client'])

/**
 * Recursively wraps an SDK-like object with a Proxy so that:
 * - All function properties (including from prototype) are wrapped with timing and slow-call reporting.
 * - Nested service objects are wrapped on first access.
 * - Circular references are avoided via a visited WeakSet.
 * This preserves the prototype chain so class instances (Account, Projects, etc.) keep all their methods.
 */
export function wrapServiceObject<T extends Record<string, unknown>>(
  obj: T,
  scope: string,
  visited: WeakSet<object> = new WeakSet(),
): T {
  if (obj === null || typeof obj !== 'object') return obj
  if (visited.has(obj)) return obj
  visited.add(obj)

  return new Proxy(obj, {
    get(target, prop: string, receiver) {
      const value = Reflect.get(target, prop, receiver)
      if (SKIP_KEYS.has(prop)) return value
      if (typeof value === 'function') {
        return wrapWithTiming(value.bind(target), scope, prop)
      }
      if (
        value !== null &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        typeof (value as Record<string, unknown>).then !== 'function'
      ) {
        return wrapServiceObject(
          value as Record<string, unknown>,
          `${scope}.${prop}`,
          visited,
        ) as T
      }
      return value
    },
  }) as T
}
