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
   * Get the Zero project the signed-in anonymous account owns, with its claim
   * link, so the browser never has to keep the claim secret.
   */
  getAccount(): Promise<ZeroProject> {
    const uri = new URL(this.client.config.endpoint + '/account/zero')
    return this.client.call('get', uri, this.headers())
  }
}
