import { OAuth2ProvidersSection } from './_components/OAuth2ProvidersSection'
import type { AuthOAuth2SettingsInitialData } from '@/lib/react-query/hooks/oauth2-providers'

type SocialProvidersProps = {
  projectId: string
  initialData?: AuthOAuth2SettingsInitialData
}

export function SocialProviders({
  projectId,
  initialData,
}: SocialProvidersProps) {
  return (
    <OAuth2ProvidersSection
      projectId={projectId}
      initialData={initialData}
      bare
    />
  )
}
