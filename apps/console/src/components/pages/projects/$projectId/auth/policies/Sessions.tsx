import {
  useAuthSecuritySnapshot,
  SessionLengthCard,
  SessionsLimitCard,
  SessionAlertsCard,
  InvalidateSessionsCard,
} from '../Security'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useT } from '@/lib/i18n/translate'

type SessionsProps = {
  projectId: string
}

export function SessionsPolicies({ projectId }: SessionsProps) {
  const t = useT()
  const security = useAuthSecuritySnapshot(projectId)

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
  ]

  return (
    <SettingsCardsList cards={cards} emptyMessage={t('No matching policies')} />
  )
}
