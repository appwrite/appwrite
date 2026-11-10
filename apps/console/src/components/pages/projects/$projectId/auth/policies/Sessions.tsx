import {
  useAuthSecuritySnapshot,
  SessionLengthCard,
  SessionsLimitCard,
  SessionAlertsCard,
  InvalidateSessionsCard,
} from '../Security'
import { PolicySwitchCard } from './EmailPoliciesCard'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useUpdateAccountDevicesPolicy } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'

type SessionsProps = {
  projectId: string
}

export function SessionsPolicies({ projectId }: SessionsProps) {
  const t = useT()
  const security = useAuthSecuritySnapshot(projectId)
  const accountDevicesMutation = useUpdateAccountDevicesPolicy(projectId)

  const cards: SettingsCardItem[] = [
    {
      id: 'session-length',
      search: {
        title: 'Session length',
        keywords: ['duration', 'expiry', 'timeout', 'logout'],
      },
      node: (
        <SessionLengthCard
          projectId={projectId}
          currentDuration={security.authDuration ?? 0}
        />
      ),
    },
    {
      id: 'sessions-limit',
      search: {
        title: 'Sessions limit',
        keywords: ['maximum', 'active', 'concurrent'],
      },
      node: (
        <SessionsLimitCard
          projectId={projectId}
          currentLimit={security.authSessionsLimit ?? 10}
        />
      ),
    },
    {
      id: 'session-alerts',
      search: {
        title: 'Session alerts',
        keywords: ['notification', 'new session', 'email'],
      },
      node: (
        <SessionAlertsCard
          projectId={projectId}
          currentEnabled={security.authSessionAlerts ?? false}
        />
      ),
    },
    {
      id: 'invalidate-sessions',
      search: {
        title: 'Invalidate sessions',
        keywords: ['password change', 'revoke', 'sign out'],
      },
      node: (
        <InvalidateSessionsCard
          projectId={projectId}
          currentEnabled={security.authInvalidateSessions ?? false}
        />
      ),
    },
    {
      id: 'account-switching',
      search: {
        title: 'Account switching',
        keywords: ['device', 'devices', 'multiple accounts', 'switch'],
      },
      node: (
        <PolicySwitchCard
          currentEnabled={security.authAccountDevices ?? true}
          title={t('Account switching')}
          description={t(
            'Let a browser or app stay signed in to several accounts at once and switch between them. Sessions are grouped in a device, with one active account at a time. When disabled, new devices cannot be created and each browser is limited to a single active session.',
          )}
          switchId="account-devices-enabled"
          switchLabel={t('Allow account switching')}
          mutation={accountDevicesMutation}
          successMessage={t('Updated account switching policy')}
          errorMessage={t('Failed to update account switching policy')}
        />
      ),
    },
  ]

  return (
    <SettingsCardsList cards={cards} emptyMessage={t('No matching policies')} />
  )
}
