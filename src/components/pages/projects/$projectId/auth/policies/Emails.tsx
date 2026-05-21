import { useAuthSecuritySnapshot } from '../Security'
import {
  DenyFreeEmailCard,
  DenyAliasedEmailCard,
  DenyDisposableEmailCard,
} from './EmailPoliciesCard'

type EmailsProps = {
  projectId: string
}

export function EmailsPolicies({ projectId }: EmailsProps) {
  const security = useAuthSecuritySnapshot(projectId)

  return (
    <div className="space-y-6">
      <DenyFreeEmailCard
        projectId={projectId}
        currentEnabled={security.authDenyFreeEmail ?? false}
      />
      <DenyAliasedEmailCard
        projectId={projectId}
        currentEnabled={security.authDenyAliasedEmail ?? false}
      />
      <DenyDisposableEmailCard
        projectId={projectId}
        currentEnabled={security.authDenyDisposableEmail ?? false}
      />
    </div>
  )
}
