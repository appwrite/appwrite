import type { Models } from '@appwrite.io/console'

export type MfaPreviewFactor = 'totp' | 'email' | 'phone' | 'recovery'

export function getMfaPreviewFactors(
  factor: MfaPreviewFactor,
): Models.MfaFactors {
  return {
    totp: factor === 'totp',
    email: factor === 'email',
    phone: factor === 'phone',
    recoveryCode: factor === 'recovery',
    custom: false,
  }
}
