import { useAuthSecuritySnapshot, UsersLimitCard } from '../Security'

type UsersProps = {
  projectId: string
}

export function UsersPolicies({ projectId }: UsersProps) {
  const security = useAuthSecuritySnapshot(projectId)

  return (
    <div className="space-y-6">
      <UsersLimitCard projectId={projectId} currentLimit={security.authLimit ?? 0} />
    </div>
  )
}
