import type { ZeroProject } from '@/lib/appwrite/zero'
import { getRemainingMs } from '@/lib/zero/countdown'

/**
 * Where a Zero project is in its life. The deadlines count as passed as soon
 * as the clock reaches them, before the next poll reports the new status.
 */
export type ZeroPhase = 'building' | 'expired' | 'claimed' | 'deleted'

export function getZeroPhase(
  zero: Pick<ZeroProject, 'status' | 'expiresAt' | 'claimBy'>,
  now: number,
): ZeroPhase {
  if (zero.status === 'claimed') return 'claimed'
  if (zero.status === 'deleted' || getRemainingMs(zero.claimBy, now) === 0) {
    return 'deleted'
  }
  if (zero.status === 'expired' || getRemainingMs(zero.expiresAt, now) === 0) {
    return 'expired'
  }
  return 'building'
}
