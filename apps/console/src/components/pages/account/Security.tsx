import { useMemo } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { fetchAccountIdentities } from '@/lib/react-query/hooks'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  IdentitiesSection,
  MFASection,
  UpdatePasswordSection,
} from './Overview'

export type AccountSecurityInitialData = {
  identities?: Awaited<ReturnType<typeof fetchAccountIdentities>>
}

export function AccountSecurity({
  initialData,
}: {
  initialData?: AccountSecurityInitialData
} = {}) {
  const { features } = useConsoleProfile()

  const cards = useMemo<SettingsCardItem[]>(() => {
    const items: SettingsCardItem[] = [
      {
        id: 'password',
        search: {
          title: 'Update password',
          keywords: ['password', 'change', 'reset', 'recovery'],
        },
        node: <UpdatePasswordSection />,
      },
    ]

    if (features.accountIdentities) {
      items.push({
        id: 'identities',
        search: {
          title: 'Identities',
          keywords: ['oauth', 'github', 'google', 'social', 'login'],
        },
        node: <IdentitiesSection initialData={initialData?.identities} />,
      })
    }

    if (features.accountMfa) {
      items.push({
        id: 'mfa',
        search: {
          title: 'Multi-factor authentication',
          keywords: ['mfa', '2fa', 'totp', 'authenticator', 'recovery codes'],
        },
        node: <MFASection />,
      })
    }

    return items
  }, [features.accountIdentities, features.accountMfa, initialData?.identities])

  return <SettingsCardsList cards={cards} />
}
