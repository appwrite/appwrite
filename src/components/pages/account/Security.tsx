import { useMemo } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { fetchAccountIdentities } from '@/lib/react-query/hooks'
import type { PasskeyList } from '@/lib/appwrite/passkeys'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  IdentitiesSection,
  MFASection,
  UpdatePasswordSection,
} from './Overview'
import { PasskeysSection } from './Passkeys'

export type AccountSecurityInitialData = {
  identities?: Awaited<ReturnType<typeof fetchAccountIdentities>>
  passkeys?: PasskeyList
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

    if (features.accountPasskeys) {
      items.push({
        id: 'passkeys',
        search: {
          title: 'Passkeys',
          keywords: [
            'passkey',
            'webauthn',
            'fingerprint',
            'face id',
            'biometric',
          ],
        },
        node: <PasskeysSection initialData={initialData?.passkeys} />,
      })
    }

    return items
  }, [
    features.accountIdentities,
    features.accountMfa,
    features.accountPasskeys,
    initialData?.identities,
    initialData?.passkeys,
  ])

  return <SettingsCardsList cards={cards} />
}
