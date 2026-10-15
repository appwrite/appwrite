/**
 * Client-side helpers for project password strength policy (mirrors API validation).
 */

export const MIN_PASSWORD_LENGTH = 8
export const MAX_PASSWORD_LENGTH = 256

export type PasswordStrengthPolicy = {
  min: number
  uppercase: boolean
  lowercase: boolean
  number: boolean
  symbols: boolean
}

export const DEFAULT_PASSWORD_STRENGTH_POLICY: PasswordStrengthPolicy = {
  min: MIN_PASSWORD_LENGTH,
  uppercase: false,
  lowercase: false,
  number: false,
  symbols: false,
}

export type PasswordStrengthValidationResult = {
  valid: boolean
  failures: string[]
}

export function validatePasswordAgainstPolicy(
  password: string,
  policy: PasswordStrengthPolicy,
): PasswordStrengthValidationResult {
  const failures: string[] = []

  if (password.length < policy.min) {
    failures.push(
      `Password must be at least ${policy.min} characters (current: ${password.length})`,
    )
  }
  if (policy.uppercase && !/[A-Z]/.test(password)) {
    failures.push('Password must include at least one uppercase letter (A-Z)')
  }
  if (policy.lowercase && !/[a-z]/.test(password)) {
    failures.push('Password must include at least one lowercase letter (a-z)')
  }
  if (policy.number && !/[0-9]/.test(password)) {
    failures.push('Password must include at least one number (0-9)')
  }
  if (policy.symbols && !/[^A-Za-z0-9]/.test(password)) {
    failures.push(
      'Password must include at least one symbol (non-alphanumeric character)',
    )
  }

  return { valid: failures.length === 0, failures }
}

export type PasswordStrengthStandard = {
  id: string
  name: string
  description: string
  requirements: {
    min: number
    uppercase?: boolean
    lowercase?: boolean
    number?: boolean
    symbols?: boolean
    minCharacterClasses?: number
  }
}

export type StandardComplianceResult = {
  standard: PasswordStrengthStandard
  compliant: boolean
  reasons: string[]
}

function countCharacterClasses(policy: PasswordStrengthPolicy): number {
  return [
    policy.uppercase,
    policy.lowercase,
    policy.number,
    policy.symbols,
  ].filter(Boolean).length
}

export function checkStandardCompliance(
  policy: PasswordStrengthPolicy,
  standard: PasswordStrengthStandard,
): StandardComplianceResult {
  const reasons: string[] = []
  const req = standard.requirements

  if (policy.min < req.min) {
    reasons.push(
      `Minimum length is ${req.min} characters; your policy sets ${policy.min}`,
    )
  }
  if (req.uppercase && !policy.uppercase) {
    reasons.push('Standard requires uppercase letters')
  }
  if (req.lowercase && !policy.lowercase) {
    reasons.push('Standard requires lowercase letters')
  }
  if (req.number && !policy.number) {
    reasons.push('Standard requires numbers')
  }
  if (req.symbols && !policy.symbols) {
    reasons.push('Standard requires symbols')
  }
  if (req.minCharacterClasses != null) {
    const classCount = countCharacterClasses(policy)
    if (classCount < req.minCharacterClasses) {
      reasons.push(
        `Standard requires at least ${req.minCharacterClasses} character types; your policy requires ${classCount}`,
      )
    }
  }

  return {
    standard,
    compliant: reasons.length === 0,
    reasons,
  }
}

export const PASSWORD_STRENGTH_STANDARDS: PasswordStrengthStandard[] = [
  {
    id: 'nist',
    name: 'NIST SP 800-63B',
    description:
      'Minimum 8 characters. No mandatory complexity rules; length is the primary control.',
    requirements: { min: 8 },
  },
  {
    id: 'owasp-min',
    name: 'OWASP (minimum)',
    description: 'Baseline web application guidance with an 8-character minimum.',
    requirements: { min: 8 },
  },
  {
    id: 'owasp-l2',
    name: 'OWASP ASVS Level 2',
    description:
      'Higher assurance applications should use at least 12 characters.',
    requirements: { min: 12 },
  },
  {
    id: 'pci-dss',
    name: 'PCI DSS 4.0',
    description:
      'Payment environments typically require 8+ characters with mixed character types.',
    requirements: {
      min: 8,
      uppercase: true,
      lowercase: true,
      number: true,
      symbols: true,
    },
  },
  {
    id: 'microsoft-entra',
    name: 'Microsoft Entra ID',
    description:
      'Default cloud identity policy: 8+ characters and at least 3 of 4 character types.',
    requirements: { min: 8, minCharacterClasses: 3 },
  },
  {
    id: 'cis',
    name: 'CIS Controls',
    description:
      'Enterprise hardening guidance recommending 14+ characters for privileged access.',
    requirements: { min: 14 },
  },
  {
    id: 'google',
    name: 'Google accounts',
    description: 'Consumer account minimum of 8 characters at sign-up.',
    requirements: { min: 8 },
  },
]

export type PasswordStrengthPreset = {
  id: string
  label: string
  description: string
  policy: PasswordStrengthPolicy
}

export const PASSWORD_STRENGTH_PRESETS: PasswordStrengthPreset[] = [
  {
    id: 'appwrite-default',
    label: 'Appwrite default',
    description: '8 characters, no character-type requirements',
    policy: {
      min: 8,
      uppercase: false,
      lowercase: false,
      number: false,
      symbols: false,
    },
  },
  {
    id: 'nist',
    label: 'NIST SP 800-63B',
    description: '8 characters, no mandatory complexity',
    policy: {
      min: 8,
      uppercase: false,
      lowercase: false,
      number: false,
      symbols: false,
    },
  },
  {
    id: 'balanced',
    label: 'Balanced (OWASP)',
    description: '10 characters with letters and numbers',
    policy: {
      min: 10,
      uppercase: true,
      lowercase: true,
      number: true,
      symbols: false,
    },
  },
  {
    id: 'microsoft-entra',
    label: 'Microsoft Entra ID',
    description: '8 characters, 3 of 4 character types',
    policy: {
      min: 8,
      uppercase: true,
      lowercase: true,
      number: true,
      symbols: false,
    },
  },
  {
    id: 'pci-dss',
    label: 'PCI DSS',
    description: '8 characters with all character types',
    policy: {
      min: 8,
      uppercase: true,
      lowercase: true,
      number: true,
      symbols: true,
    },
  },
  {
    id: 'strong',
    label: 'Strong',
    description: '12 characters with all character types',
    policy: {
      min: 12,
      uppercase: true,
      lowercase: true,
      number: true,
      symbols: true,
    },
  },
]

export function policiesEqual(
  a: PasswordStrengthPolicy,
  b: PasswordStrengthPolicy,
): boolean {
  return (
    a.min === b.min &&
    a.uppercase === b.uppercase &&
    a.lowercase === b.lowercase &&
    a.number === b.number &&
    a.symbols === b.symbols
  )
}

/** First preset whose policy matches the given settings (list order wins on duplicates). */
export function findMatchingPasswordStrengthPreset(
  policy: PasswordStrengthPolicy,
): PasswordStrengthPreset | undefined {
  return PASSWORD_STRENGTH_PRESETS.find((preset) =>
    policiesEqual(policy, preset.policy),
  )
}

export function clampPasswordMinLength(
  value: number,
  min: number = MIN_PASSWORD_LENGTH,
  max: number = MAX_PASSWORD_LENGTH,
): number {
  return Math.max(min, Math.min(max, value))
}
