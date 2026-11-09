/**
 * Hand-written Alerts client: the alerts API has not shipped in a Console SDK
 * release yet, so the pinned SDK carries no `Alerts` service. Copied from the code the
 * SDK generator produces for the `/alerts` API; delete this folder for the generated one once it ships.
 */

import {
  AppwriteException,
  type Client,
  type Payload,
} from '@appwrite.io/console'
import type { Alert, AlertList } from './api'

export class Alerts {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  /**
   * Get a list of the alerts the project's monitors raised, open and resolved. An alert is created when a monitor starts firing and resolved when it recovers. Use queries to filter by monitor, status, type or resource, order by `$createdAt` for the latest first, and `Query.search('search', term)` to search alert and monitor IDs, names and metrics.
   *
   * @param {string[]} params.queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: monitorId, name, status, type, resourceType, resourceId, metric, resolvedAt, search
   * @param {boolean} params.total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<AlertList>}
   */
  list(params?: { queries?: string[]; total?: boolean }): Promise<AlertList>
  /**
   * Get a list of the alerts the project's monitors raised, open and resolved. An alert is created when a monitor starts firing and resolved when it recovers. Use queries to filter by monitor, status, type or resource, order by `$createdAt` for the latest first, and `Query.search('search', term)` to search alert and monitor IDs, names and metrics.
   *
   * @param {string[]} queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: monitorId, name, status, type, resourceType, resourceId, metric, resolvedAt, search
   * @param {boolean} total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<AlertList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  list(queries?: string[], total?: boolean): Promise<AlertList>
  list(
    paramsOrFirst?: { queries?: string[]; total?: boolean } | string[],
    ...rest: [boolean?]
  ): Promise<AlertList> {
    let params: { queries?: string[]; total?: boolean }

    if (
      (typeof paramsOrFirst === 'undefined' && rest.length === 0) ||
      (paramsOrFirst &&
        typeof paramsOrFirst === 'object' &&
        !Array.isArray(paramsOrFirst))
    ) {
      params = (paramsOrFirst || {}) as {
        queries?: string[]
        total?: boolean
      }
    } else {
      params = {
        queries: paramsOrFirst as string[],
        total: rest[0] as boolean,
      }
    }

    const queries = params.queries
    const total = params.total

    const apiPath = '/alerts'
    const apiPayload: Payload = {}
    if (typeof queries !== 'undefined') {
      apiPayload['queries'] = queries
    }
    if (typeof total !== 'undefined') {
      apiPayload['total'] = total
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)

    return request
  }

  /**
   * Get an alert by its unique ID: the monitor that raised it, when it fired and resolved, and the readings while it fired.
   *
   * @param {string} params.alertId - Alert ID.
   * @throws {AppwriteException}
   * @returns {Promise<Alert>}
   */
  get(params: { alertId: string }): Promise<Alert>
  /**
   * Get an alert by its unique ID: the monitor that raised it, when it fired and resolved, and the readings while it fired.
   *
   * @param {string} alertId - Alert ID.
   * @throws {AppwriteException}
   * @returns {Promise<Alert>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  get(alertId: string): Promise<Alert>
  get(paramsOrFirst: { alertId: string } | string): Promise<Alert> {
    let params: { alertId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { alertId: string }
    } else {
      params = {
        alertId: paramsOrFirst as string,
      }
    }

    const alertId = params.alertId

    if (typeof alertId === 'undefined' || alertId === '') {
      throw new AppwriteException('Missing required parameter: "alertId"')
    }
    const apiPath = '/alerts/{alertId}'.replace(
      '{alertId}',
      encodeURIComponent(alertId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)

    return request
  }
}
