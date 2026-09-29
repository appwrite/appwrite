import { useAuthSecuritySnapshot } from '../Security'
import { PasskeyRelyingPartyCard } from './PasskeyRelyingPartyCard'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useT } from '@/lib/i18n/translate'

type PasskeysProps = {
  projectId: string
}

export function PasskeysPolicies({ projectId }: PasskeysProps) {
  const t = useT()
  const security = useAuthSecuritySnapshot(projectId)

  const cards: SettingsCardItem[] = [
    {
      id: 'relying-party',
      search: {
        title: 'Relying party',
        keywords: ['passkey', 'webauthn', 'rp id', 'domain', 'origins'],
      },
      node: (
        <PasskeyRelyingPartyCard
          projectId={projectId}
          currentPolicy={security.authPasskey}
        />
      ),
    },
  ]

  return (
    <SettingsCardsList cards={cards} emptyMessage={t('No matching policies')} />
  )
}
