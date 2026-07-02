import {
  useAuthSecuritySnapshot,
  PasswordHistoryCard,
  PasswordDictionaryCard,
  PersonalDataCard,
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
  ]

  return (
    <SettingsCardsList cards={cards} emptyMessage={t('No matching policies')} />
  )
}
