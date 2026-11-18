/**
 * Hand-written client for the Cloud team rule endpoints (`proxy.createTeamRule`,
 * `proxy.updateTeamRule`): they have not shipped in a Console SDK release yet.
 * Listing, verifying and deleting team rules use the generated `Proxy` service.
 * Delete this folder once the Console SDK carries both methods.
 */

import type { Client } from '@appwrite.io/console'

import type { TeamRule } from './models'

export class TeamRules {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  /**
   * Claim an email domain for a team. It stays unverified until the TXT record
   * `_appwrite.<domain>` holds `team:<teamId>`.
   *
   * @param {string} params.domain - Email domain, for example `example.com`.
   * @param {string} params.teamId - ID of the team people join.
   * @param {string[]} params.roles - Roles given to people who join through this domain.
   * @throws {AppwriteException}
   * @returns {Promise<TeamRule>}
   */
  create(params: {
    domain: string
    teamId: string
    roles: string[]
  }): Promise<TeamRule> {
    return this.client.call(
      'post',
      this.uri('/proxy/rules/team'),
      this.headers(),
      {
        domain: params.domain,
        teamId: params.teamId,
        roles: params.roles,
      },
    )
  }

  /**
   * Update the roles people get when they join through a team rule. People who
   * already joined keep their roles.
   *
   * @param {string} params.ruleId - Rule ID.
   * @param {string[]} params.roles - Roles given to people who join from now on.
   * @throws {AppwriteException}
   * @returns {Promise<TeamRule>}
   */
  update(params: { ruleId: string; roles: string[] }): Promise<TeamRule> {
    const apiPath = '/proxy/rules/{ruleId}/team'.replace(
      '{ruleId}',
      encodeURIComponent(params.ruleId),
    )
    return this.client.call('patch', this.uri(apiPath), this.headers(), {
      roles: params.roles,
    })
  }

  private uri(apiPath: string): URL {
    return new URL(this.client.config.endpoint + apiPath)
  }

  private headers(): Record<string, string> {
    return {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }
  }
}
