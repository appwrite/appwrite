/**
 * Zero response model. Delete with `service.ts` once the generated SDK carries it.
 */

export type ZeroStatus = 'active' | 'expired' | 'claimed' | 'deleted'

export type ZeroProject = {
  /**
   * Zero ID.
   */
  $id: string
  /**
   * Zero creation time.
   */
  $createdAt: string
  /**
   * Lifecycle status.
   */
  status: ZeroStatus
  /**
   * Organization holding the project.
   */
  organizationId: string
  /**
   * Project ID.
   */
  projectId: string
  /**
   * Project region.
   */
  region: string
  /**
   * Project API endpoint.
   */
  endpoint: string
  /**
   * Project console URL.
   */
  consoleUrl: string
  /**
   * When the project stops working unless claimed.
   */
  expiresAt: string
  /**
   * When the unclaimed project is deleted.
   */
  claimBy: string
  /**
   * Project API key. Only returned on create.
   */
  apiKey?: string
  /**
   * Claim secret. Only returned on create.
   */
  secret?: string
  /**
   * URL a person opens to claim the project. Only returned on create.
   */
  claimUrl?: string
  /**
   * Token that opens the project's site and function domains for its owner
   * until the project is claimed.
   */
  accessToken?: string
}
