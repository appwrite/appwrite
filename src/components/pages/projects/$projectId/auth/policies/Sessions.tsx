import {
  useAuthSecuritySnapshot,
  SessionLengthCard,
  SessionsLimitCard,
  SessionAlertsCard,
  InvalidateSessionsCard,
} from '../Security'

type SessionsProps = {
  projectId: string
}

export function SessionsPolicies({ projectId }: SessionsProps) {
  const security = useAuthSecuritySnapshot(projectId)

  return (
    <div className="space-y-6">
      <SessionLengthCard
        projectId={projectId}
        currentDuration={security.authDuration ?? 0}
      />
      <SessionsLimitCard
        projectId={projectId}
        currentLimit={security.authSessionsLimit ?? 10}
      />
      <SessionAlertsCard
        projectId={projectId}
        currentEnabled={security.authSessionAlerts ?? false}
      />
      <InvalidateSessionsCard
        projectId={projectId}
        currentEnabled={security.authInvalidateSessions ?? false}
      />
    </div>
  )
}
