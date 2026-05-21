import { useAuthSecuritySnapshot, PrivacyCard } from '../Security'

type MembershipsProps = {
  projectId: string
}

export function MembershipsPolicies({ projectId }: MembershipsProps) {
  const security = useAuthSecuritySnapshot(projectId)
  const membershipsPrivacy = security.membershipsPrivacy ?? {
    userName: true,
    userEmail: true,
    mfa: true,
    userId: true,
    userPhone: true,
  }

  return (
    <div className="space-y-6">
      <PrivacyCard projectId={projectId} currentPrivacy={membershipsPrivacy} />
    </div>
  )
}
