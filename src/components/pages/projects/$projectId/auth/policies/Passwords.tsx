import {
  useAuthSecuritySnapshot,
  PasswordHistoryCard,
  PasswordDictionaryCard,
  PersonalDataCard,
  MfaFactorsCard,
} from '../Security'
import { PasswordStrengthCard } from './PasswordStrengthCard'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useT } from '@/lib/i18n/translate'

type PasswordsProps = {
  projectId: string
}

export function PasswordsPolicies({ projectId }: PasswordsProps) {
  const t = useT()
  const security = useAuthSecuritySnapshot(projectId)
  const mfaFactors = security.mfaFactors

  const cards: SettingsCardItem[] = [
    {
      id: 'strength',
      search: {
        title: 'Strength',
        keywords: [
          'length',
          'uppercase',
          'lowercase',
          'number',
          'symbol',
          'complexity',
          'nist',
          'owasp',
          'pci',
        ],
      },
      node: (
        <PasswordStrengthCard
          projectId={projectId}
          currentPolicy={security.authPasswordStrength}
        />
      ),
    },
    {
      id: 'history',
      search: {
        title: 'History',
        keywords: ['reuse', 'previous passwords'],
      },
      node: (
        <PasswordHistoryCard
          projectId={projectId}
          currentLimit={security.authPasswordHistory ?? 0}
        />
      ),
    },
    {
      id: 'dictionary',
      search: {
        title: 'Dictionary',
        keywords: ['common passwords', 'weak'],
      },
      node: (
        <PasswordDictionaryCard
          projectId={projectId}
          currentEnabled={security.authPasswordDictionary ?? false}
        />
      ),
    },
    {
      id: 'personal-data',
      search: {
        title: 'Personal data',
        keywords: ['name', 'email in password'],
      },
      node: (
        <PersonalDataCard
          projectId={projectId}
          currentEnabled={security.authPersonalDataCheck ?? false}
        />
      ),
    },
    // Servers without an mfa-factors policy report none; the old console hides
    // the card in that case rather than showing defaults it cannot save.
    ...(mfaFactors
      ? [
          {
            id: 'mfa-factors',
            search: {
              title: 'MFA factors',
              keywords: [
                'mfa',
                '2fa',
                'multi-factor',
                'totp',
                'authenticator',
                'email',
                'phone',
                'sms',
                'custom',
                'challenge',
              ],
            },
            node: (
              <MfaFactorsCard
                projectId={projectId}
                currentFactors={mfaFactors}
              />
            ),
          },
        ]
      : []),
  ]

  return (
    <SettingsCardsList cards={cards} emptyMessage={t('No matching policies')} />
  )
}
