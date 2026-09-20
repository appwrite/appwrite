import type { Models } from '@appwrite.io/console'

export type MfaPreviewFactor = 'totp' | 'email' | 'phone' | 'recovery'

export type MfaPreviewFactors = Models.MfaFactors & { recoveryCode: boolean }

export function getMfaPreviewFactors(factor: MfaPreviewFactor): MfaPreviewFactors {
  return {
    totp: factor === 'totp',
    email: factor === 'email',
    phone: factor === 'phone',
    recoveryCode: factor === 'recovery',
  }
}
