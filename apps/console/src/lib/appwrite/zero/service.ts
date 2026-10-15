/**
 * Hand-written Zero client: the published Console SDK has no `zero` service
 * yet. Delete this folder for the generated one once it ships.
 */

import type { Client } from '@appwrite.io/console'

import type { ZeroProject } from './models'

export class Zero {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  private headers(): Record<string, string> {
    return {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }
  }

  /**
   * Create a project without signing up. Also signs the browser in as the
   * anonymous owner of the project.
   */
  create(params: { source?: string } = {}): Promise<ZeroProject> {
    const payload: Record<string, unknown> = {}
    if (typeof params.source !== 'undefined') {
      payload['source'] = params.source
    }
    const uri = new URL(this.client.config.endpoint + '/zero')
    return this.client.call('post', uri, this.headers(), payload)
  }

  /**
   * Get a Zero project's status with its claim secret.
   */
  get(params: { zeroId: string; secret: string }): Promise<ZeroProject> {
    const uri = new URL(
      this.client.config.endpoint +
        '/zero/' +
        encodeURIComponent(params.zeroId),
    )
    return this.client.call('get', uri, this.headers(), {
      secret: params.secret,
    })
  }

  /**
   * Claim a Zero project for the signed-in account. Without an organization
   * the account takes over the project's free organization.
   */
  claim(params: {
    zeroId: string
    secret: string
    organizationId?: string
  }): Promise<ZeroProject> {
    const payload: Record<string, unknown> = { secret: params.secret }
    if (typeof params.organizationId !== 'undefined') {
      payload['organizationId'] = params.organizationId
    }
    const uri = new URL(
      this.client.config.endpoint +
        '/zero/' +
        encodeURIComponent(params.zeroId) +
        '/claim',
    )
    return this.client.call('post', uri, this.headers(), payload)
  }
}
