/**
 * Resource descriptions: the `description` param the pinned SDK does not know.
 *
 * The API gained an optional `description` on functions, sites, variables,
 * keys, webhooks, databases, tables/collections, columns/attributes, indexes,
 * buckets and topics. The `@appwrite.io/console` pin in package.json predates
 * it: every generated service builds its request payload from a fixed list of
 * known params, so a `description` handed to `functions.update()` is dropped
 * before the request leaves the client. Casting the params object does not
 * help: the key never reaches `Client.call`.
 *
 * `withDescription` binds a service to a client that merges `description` into
 * the payload, so the generated method still owns the path, the method and
 * every other param. Same shape as `wrapServiceObject` in
 * `slow-call-reporting.ts`: a Proxy that keeps the client's prototype, config
 * and headers. Drop this module once the pin ships the param, and pass
 * `description` to the service directly.
 *
 * Clearing: on the API an omitted or explicitly null `description` keeps the
 * current value, so `''` is what clears it. `withDescription` always sends a
 * string, never null.
 */

import { Client } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

/** `Text(1024, 0)` on every `description` param. */
export const DESCRIPTION_MAX_LENGTH = 1024

/**
 * Read `description` off a resource whose pinned SDK model predates the field.
 * Returns `''` for older servers that do not send it at all.
 */
export function readDescription(resource: unknown): string {
  const value = (resource as { description?: unknown } | null | undefined)
    ?.description
  return typeof value === 'string' ? value : ''
}

/** Trim and clamp a description to what the API accepts. */
export function normalizeDescription(description: string): string {
  return description.trim().slice(0, DESCRIPTION_MAX_LENGTH)
}

/**
 * Client that adds `description` to the payload of every request made through
 * it. Scoped to a single service instance, so nothing leaks into the shared
 * project client or into calls in flight elsewhere.
 */
function clientWithDescription(client: Client, description: string): Client {
  return new Proxy(client, {
    get(target, prop: string | symbol, receiver) {
      if (prop !== 'call') return Reflect.get(target, prop, receiver)
      return ((
        method: string,
        url: URL,
        headers?: Record<string, string>,
        params?: Record<string, unknown>,
        responseType?: string,
      ) =>
        target.call(
          method,
          url,
          headers,
          { ...params, description },
          responseType,
        )) as Client['call']
    },
  })
}

/**
 * Project-scoped SDK service that sends `description` alongside its params.
 *
 * Usage mirrors `sdk.forProject(projectId).functions.update(...)`:
 *
 * ```ts
 * await withDescription(projectId, description, Functions).update(
 *   buildFunctionUpdateParams(func, { name }),
 * )
 * ```
 *
 * Call `sdk.forProject(projectId)` first (as this does) so the client carries
 * the project's region endpoint and `X-Appwrite-Project`.
 */
export function withDescription<T>(
  projectId: string,
  description: string,
  Service: new (client: Client) => T,
): T {
  const client = sdk.forProject(projectId).client
  return new Service(clientWithDescription(client, description))
}
