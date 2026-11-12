/**
 * Hand-written Monitors client: the monitors API has not shipped in a Console SDK
 * release yet, so the pinned SDK carries no `Monitors` service. Copied from the code the
 * SDK generator produces for the `/monitors` API; delete this folder for the generated one once it ships.
 */

import {
  AppwriteException,
  type Client,
  type Payload,
} from '@appwrite.io/console'
import type { Monitor, MonitorList } from './api'

export class Monitors {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  /**
   * Get a list of the project's monitors of every type. Use queries to filter by type, resource, metric or status, and `Query.search('search', term)` to search monitor IDs, names, metrics and resources.
   *
   * @param {string[]} params.queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: name, type, resourceType, resourceId, metric, enabled, critical, status, evaluatedAt, triggeredAt, search
   * @param {boolean} params.total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<MonitorList>}
   */
  list(params?: { queries?: string[]; total?: boolean }): Promise<MonitorList>
  /**
   * Get a list of the project's monitors of every type. Use queries to filter by type, resource, metric or status, and `Query.search('search', term)` to search monitor IDs, names, metrics and resources.
   *
   * @param {string[]} queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: name, type, resourceType, resourceId, metric, enabled, critical, status, evaluatedAt, triggeredAt, search
   * @param {boolean} total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<MonitorList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  list(queries?: string[], total?: boolean): Promise<MonitorList>
  list(
    paramsOrFirst?: { queries?: string[]; total?: boolean } | string[],
    ...rest: [boolean?]
  ): Promise<MonitorList> {
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

    const apiPath = '/monitors'
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
   * Create a usage monitor. It reads a usage metric of the project, or of one of its databases or buckets, on a schedule, and opens an alert when all its rules hold. Each alert fires the `monitors.[monitorId].alerts.[alertId].firing` event when it opens and `.resolved` when usage recovers, for project webhooks, functions and realtime, and notifies the organization's owners by email and in the console.
   *
   * @param {string} params.monitorId - Monitor ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars.
   * @param {string} params.name - Monitor name. Max length: 128 chars.
   * @param {string[]} params.rules - Rules that must all hold for the monitor to fire, as query strings generated with the Query class: one comparing a usage metric, named as the usage endpoints name it (such as `network.outbound` or `databases.operations.reads`), with a threshold in the metric's unit (bytes for storage and bandwidth, MB-seconds for GB-hours, a count otherwise), and optionally `equal` rules on `resourceType` and `resourceId` to watch one database, bucket, function or site instead of the whole project.
   * @param {number} params.window - Seconds a counter metric is summed over, between 60 and 2592000. Gauges compare their latest value.
   * @param {number} params.cooldown - Minimum seconds between two team notifications of this monitor firing, between 300 and 2592000. Events fire on every change between firing and resolved regardless.
   * @param {boolean} params.enabled - Set to false to create the monitor without evaluating it.
   * @param {string[]} params.notify - Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Each must be an organization role, such as `owner` or `developer`. Defaults to owners; pass an empty list to rely on the alert events alone.
   * @param {boolean} params.critical - Set to true to mark the monitor critical: its alerts may be delivered with higher urgency by the notification channels that support it.
   * @throws {AppwriteException}
   * @returns {Promise<Monitor>}
   */
  createUsageMonitor(params: {
    monitorId: string
    name: string
    rules: string[]
    window?: number
    cooldown?: number
    enabled?: boolean
    notify?: string[]
    critical?: boolean
  }): Promise<Monitor>
  /**
   * Create a usage monitor. It reads a usage metric of the project, or of one of its databases or buckets, on a schedule, and opens an alert when all its rules hold. Each alert fires the `monitors.[monitorId].alerts.[alertId].firing` event when it opens and `.resolved` when usage recovers, for project webhooks, functions and realtime, and notifies the organization's owners by email and in the console.
   *
   * @param {string} monitorId - Monitor ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars.
   * @param {string} name - Monitor name. Max length: 128 chars.
   * @param {string[]} rules - Rules that must all hold for the monitor to fire, as query strings generated with the Query class: one comparing a usage metric, named as the usage endpoints name it (such as `network.outbound` or `databases.operations.reads`), with a threshold in the metric's unit (bytes for storage and bandwidth, MB-seconds for GB-hours, a count otherwise), and optionally `equal` rules on `resourceType` and `resourceId` to watch one database, bucket, function or site instead of the whole project.
   * @param {number} window - Seconds a counter metric is summed over, between 60 and 2592000. Gauges compare their latest value.
   * @param {number} cooldown - Minimum seconds between two team notifications of this monitor firing, between 300 and 2592000. Events fire on every change between firing and resolved regardless.
   * @param {boolean} enabled - Set to false to create the monitor without evaluating it.
   * @param {string[]} notify - Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Each must be an organization role, such as `owner` or `developer`. Defaults to owners; pass an empty list to rely on the alert events alone.
   * @param {boolean} critical - Set to true to mark the monitor critical: its alerts may be delivered with higher urgency by the notification channels that support it.
   * @throws {AppwriteException}
   * @returns {Promise<Monitor>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  createUsageMonitor(
    monitorId: string,
    name: string,
    rules: string[],
    window?: number,
    cooldown?: number,
    enabled?: boolean,
    notify?: string[],
    critical?: boolean,
  ): Promise<Monitor>
  createUsageMonitor(
    paramsOrFirst:
      | {
          monitorId: string
          name: string
          rules: string[]
          window?: number
          cooldown?: number
          enabled?: boolean
          notify?: string[]
          critical?: boolean
        }
      | string,
    ...rest: [
      string?,
      string[]?,
      number?,
      number?,
      boolean?,
      string[]?,
      boolean?,
    ]
  ): Promise<Monitor> {
    let params: {
      monitorId: string
      name: string
      rules: string[]
      window?: number
      cooldown?: number
      enabled?: boolean
      notify?: string[]
      critical?: boolean
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        monitorId: string
        name: string
        rules: string[]
        window?: number
        cooldown?: number
        enabled?: boolean
        notify?: string[]
        critical?: boolean
      }
    } else {
      params = {
        monitorId: paramsOrFirst as string,
        name: rest[0] as string,
        rules: rest[1] as string[],
        window: rest[2] as number,
        cooldown: rest[3] as number,
        enabled: rest[4] as boolean,
        notify: rest[5] as string[],
        critical: rest[6] as boolean,
      }
    }

    const monitorId = params.monitorId
    const name = params.name
    const rules = params.rules
    const window = params.window
    const cooldown = params.cooldown
    const enabled = params.enabled
    const notify = params.notify
    const critical = params.critical

    if (typeof monitorId === 'undefined') {
      throw new AppwriteException('Missing required parameter: "monitorId"')
    }
    if (typeof name === 'undefined') {
      throw new AppwriteException('Missing required parameter: "name"')
    }
    if (typeof rules === 'undefined') {
      throw new AppwriteException('Missing required parameter: "rules"')
    }
    const apiPath = '/monitors/usage'
    const apiPayload: Payload = {}
    if (typeof monitorId !== 'undefined') {
      apiPayload['monitorId'] = monitorId
    }
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    if (typeof rules !== 'undefined') {
      apiPayload['rules'] = rules
    }
    if (typeof window !== 'undefined') {
      apiPayload['window'] = window
    }
    if (typeof cooldown !== 'undefined') {
      apiPayload['cooldown'] = cooldown
    }
    if (typeof enabled !== 'undefined') {
      apiPayload['enabled'] = enabled
    }
    if (typeof notify !== 'undefined') {
      apiPayload['notify'] = notify
    }
    if (typeof critical !== 'undefined') {
      apiPayload['critical'] = critical
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('post', uri, apiHeaders, apiPayload)

    return request
  }

  /**
   * Update a usage monitor by its unique ID. Only the parameters passed change; the monitor's state and its open alert are kept. Passing `rules` replaces all of them.
   *
   * @param {string} params.monitorId - Monitor ID.
   * @param {string | null} params.name - Monitor name. Max length: 128 chars.
   * @param {string[] | null} params.rules - Rules that must all hold for the monitor to fire, as query strings generated with the Query class: one comparing a usage metric, named as the usage endpoints name it (such as `network.outbound` or `databases.operations.reads`), with a threshold in the metric's unit (bytes for storage and bandwidth, MB-seconds for GB-hours, a count otherwise), and optionally `equal` rules on `resourceType` and `resourceId` to watch one database, bucket, function or site instead of the whole project. Replaces the current rules.
   * @param {number | null} params.window - Seconds a counter metric is summed over, between 60 and 2592000.
   * @param {number | null} params.cooldown - Minimum seconds between two team notifications of this monitor firing, between 300 and 2592000. Events fire on every change between firing and resolved regardless.
   * @param {boolean | null} params.enabled - Whether the monitor is evaluated.
   * @param {string[] | null} params.notify - Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Each must be an organization role, such as `owner` or `developer`. Defaults to owners; pass an empty list to rely on the alert events alone.
   * @param {boolean | null} params.critical - Whether the monitor is critical: its alerts may be delivered with higher urgency by the notification channels that support it.
   * @throws {AppwriteException}
   * @returns {Promise<Monitor>}
   */
  updateUsageMonitor(params: {
    monitorId: string
    name?: string | null
    rules?: string[] | null
    window?: number | null
    cooldown?: number | null
    enabled?: boolean | null
    notify?: string[] | null
    critical?: boolean | null
  }): Promise<Monitor>
  /**
   * Update a usage monitor by its unique ID. Only the parameters passed change; the monitor's state and its open alert are kept. Passing `rules` replaces all of them.
   *
   * @param {string} monitorId - Monitor ID.
   * @param {string | null} name - Monitor name. Max length: 128 chars.
   * @param {string[] | null} rules - Rules that must all hold for the monitor to fire, as query strings generated with the Query class: one comparing a usage metric, named as the usage endpoints name it (such as `network.outbound` or `databases.operations.reads`), with a threshold in the metric's unit (bytes for storage and bandwidth, MB-seconds for GB-hours, a count otherwise), and optionally `equal` rules on `resourceType` and `resourceId` to watch one database, bucket, function or site instead of the whole project. Replaces the current rules.
   * @param {number | null} window - Seconds a counter metric is summed over, between 60 and 2592000.
   * @param {number | null} cooldown - Minimum seconds between two team notifications of this monitor firing, between 300 and 2592000. Events fire on every change between firing and resolved regardless.
   * @param {boolean | null} enabled - Whether the monitor is evaluated.
   * @param {string[] | null} notify - Organization roles whose confirmed members get an email and a console notification when the monitor fires and resolves. Each must be an organization role, such as `owner` or `developer`. Defaults to owners; pass an empty list to rely on the alert events alone.
   * @param {boolean | null} critical - Whether the monitor is critical: its alerts may be delivered with higher urgency by the notification channels that support it.
   * @throws {AppwriteException}
   * @returns {Promise<Monitor>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  updateUsageMonitor(
    monitorId: string,
    name?: string | null,
    rules?: string[] | null,
    window?: number | null,
    cooldown?: number | null,
    enabled?: boolean | null,
    notify?: string[] | null,
    critical?: boolean | null,
  ): Promise<Monitor>
  updateUsageMonitor(
    paramsOrFirst:
      | {
          monitorId: string
          name?: string | null
          rules?: string[] | null
          window?: number | null
          cooldown?: number | null
          enabled?: boolean | null
          notify?: string[] | null
          critical?: boolean | null
        }
      | string,
    ...rest: [
      (string | null)?,
      (string[] | null)?,
      (number | null)?,
      (number | null)?,
      (boolean | null)?,
      (string[] | null)?,
      (boolean | null)?,
    ]
  ): Promise<Monitor> {
    let params: {
      monitorId: string
      name?: string | null
      rules?: string[] | null
      window?: number | null
      cooldown?: number | null
      enabled?: boolean | null
      notify?: string[] | null
      critical?: boolean | null
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        monitorId: string
        name?: string | null
        rules?: string[] | null
        window?: number | null
        cooldown?: number | null
        enabled?: boolean | null
        notify?: string[] | null
        critical?: boolean | null
      }
    } else {
      params = {
        monitorId: paramsOrFirst as string,
        name: rest[0] as string | null,
        rules: rest[1] as string[] | null,
        window: rest[2] as number | null,
        cooldown: rest[3] as number | null,
        enabled: rest[4] as boolean | null,
        notify: rest[5] as string[] | null,
        critical: rest[6] as boolean | null,
      }
    }

    const monitorId = params.monitorId
    const name = params.name
    const rules = params.rules
    const window = params.window
    const cooldown = params.cooldown
    const enabled = params.enabled
    const notify = params.notify
    const critical = params.critical

    if (typeof monitorId === 'undefined' || monitorId === '') {
      throw new AppwriteException('Missing required parameter: "monitorId"')
    }
    const apiPath = '/monitors/usage/{monitorId}'.replace(
      '{monitorId}',
      encodeURIComponent(monitorId),
    )
    const apiPayload: Payload = {}
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    if (typeof rules !== 'undefined') {
      apiPayload['rules'] = rules
    }
    if (typeof window !== 'undefined') {
      apiPayload['window'] = window
    }
    if (typeof cooldown !== 'undefined') {
      apiPayload['cooldown'] = cooldown
    }
    if (typeof enabled !== 'undefined') {
      apiPayload['enabled'] = enabled
    }
    if (typeof notify !== 'undefined') {
      apiPayload['notify'] = notify
    }
    if (typeof critical !== 'undefined') {
      apiPayload['critical'] = critical
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('put', uri, apiHeaders, apiPayload)

    return request
  }

  /**
   * Get a monitor by its unique ID, with its state as of its last evaluation.
   *
   * @param {string} params.monitorId - Monitor ID.
   * @throws {AppwriteException}
   * @returns {Promise<Monitor>}
   */
  get(params: { monitorId: string }): Promise<Monitor>
  /**
   * Get a monitor by its unique ID, with its state as of its last evaluation.
   *
   * @param {string} monitorId - Monitor ID.
   * @throws {AppwriteException}
   * @returns {Promise<Monitor>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  get(monitorId: string): Promise<Monitor>
  get(paramsOrFirst: { monitorId: string } | string): Promise<Monitor> {
    let params: { monitorId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { monitorId: string }
    } else {
      params = {
        monitorId: paramsOrFirst as string,
      }
    }

    const monitorId = params.monitorId

    if (typeof monitorId === 'undefined' || monitorId === '') {
      throw new AppwriteException('Missing required parameter: "monitorId"')
    }
    const apiPath = '/monitors/{monitorId}'.replace(
      '{monitorId}',
      encodeURIComponent(monitorId),
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

  /**
   * Delete a monitor by its unique ID. It stops being evaluated; an alert it is firing is resolved and kept with the rest of its alert history.
   *
   * @param {string} params.monitorId - Monitor ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  delete(params: { monitorId: string }): Promise<{}>
  /**
   * Delete a monitor by its unique ID. It stops being evaluated; an alert it is firing is resolved and kept with the rest of its alert history.
   *
   * @param {string} monitorId - Monitor ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  delete(monitorId: string): Promise<{}>
  delete(paramsOrFirst: { monitorId: string } | string): Promise<{}> {
    let params: { monitorId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { monitorId: string }
    } else {
      params = {
        monitorId: paramsOrFirst as string,
      }
    }

    const monitorId = params.monitorId

    if (typeof monitorId === 'undefined' || monitorId === '') {
      throw new AppwriteException('Missing required parameter: "monitorId"')
    }
    const apiPath = '/monitors/{monitorId}'.replace(
      '{monitorId}',
      encodeURIComponent(monitorId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('delete', uri, apiHeaders, apiPayload)
  }
}
