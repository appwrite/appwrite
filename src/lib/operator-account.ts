import type { Models } from '@appwrite.io/console'

/**
 * Console account shape with the operator-only fields the impersonation gate
 * relies on. The base `Models.User` type does not surface these, so operator
 * UIs (Blocks, Cache, the impersonation popover) widen the account to this.
 */
export type OperatorAccount = Models.User & {
  impersonator?: boolean
  impersonatorUserId?: string
}

/**
 * Gate used by operator-only surfaces: the account either carries the
 * `impersonator` flag, or is already inside an impersonation session (operator
 * context is preserved). Server-side auth on the Manager SDK is the real
 * enforcement; this just controls what we render.
 */
export function isOperatorAccount(
  account: OperatorAccount | null | undefined,
): boolean {
  return account?.impersonator === true || !!account?.impersonatorUserId
}
