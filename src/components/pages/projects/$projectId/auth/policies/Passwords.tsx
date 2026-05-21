import {
  useAuthSecuritySnapshot,
  PasswordHistoryCard,
  PasswordDictionaryCard,
  PersonalDataCard,
} from '../Security'

type PasswordsProps = {
  projectId: string
}

export function PasswordsPolicies({ projectId }: PasswordsProps) {
  const security = useAuthSecuritySnapshot(projectId)

  return (
    <div className="space-y-6">
      <PasswordHistoryCard
        projectId={projectId}
        currentLimit={security.authPasswordHistory ?? 0}
      />
      <PasswordDictionaryCard
        projectId={projectId}
        currentEnabled={security.authPasswordDictionary ?? false}
      />
      <PersonalDataCard
        projectId={projectId}
        currentEnabled={security.authPersonalDataCheck ?? false}
      />
    </div>
  )
}
