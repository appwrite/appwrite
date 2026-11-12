import { useAuthSecuritySnapshot } from '../Security'
import { PolicySwitchCard } from './EmailPoliciesCard'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useUpdateDenyRealtimeGuestsPolicy } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'

type RealtimeProps = {
  projectId: string
}

export function RealtimePolicies({ projectId }: RealtimeProps) {
  const t = useT()
  const security = useAuthSecuritySnapshot(projectId)
  const mutation = useUpdateDenyRealtimeGuestsPolicy(projectId)

  const cards: SettingsCardItem[] = [
    {
      id: 'guests',
      search: {
        title: 'Guests',
        keywords: ['guest', 'anonymous', 'unauthenticated', 'websocket'],
      },
      node: (
        <PolicySwitchCard
          currentEnabled={security.authDenyRealtimeGuests ?? false}
          title={t('Guests')}
          description={t(
            'Require a session to subscribe to realtime channels. Guest connections that list channels are refused, and other guests must sign in before subscribing. Channels readable by any role stop updating for signed-out users. Applies to new subscriptions.',
          )}
          switchId="deny-realtime-guests-enabled"
          switchLabel={t('Deny guests')}
          mutation={mutation}
          successMessage={t('Updated deny realtime guests policy')}
          errorMessage={t('Failed to update deny realtime guests policy')}
        />
      ),
    },
  ]

  return (
    <SettingsCardsList cards={cards} emptyMessage={t('No matching policies')} />
  )
}
