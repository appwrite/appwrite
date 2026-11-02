/**
 * Shown on every page of a project environment: a purple bar naming the
 * environment, so work meant for production is not done here by mistake, with
 * the way back to production, which is the project with no environment named.
 * Cloud only, behind the projectEnvironments feature like the switcher.
 */

import { useNavigate } from '@tanstack/react-router'
import { Layers } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useProject } from '@/lib/react-query/hooks'
import { setActiveEnvironment } from '@/lib/active-environment'
import { useT } from '@/lib/i18n/translate'

export function ProjectEnvironmentBanner({ projectId }: { projectId: string }) {
  const t = useT()
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const { projectData } = useProject(projectId)
  // The project answers as the environment while the Console is in one
  const environmentId =
    (projectData as { environmentId?: string | null } | undefined)
      ?.environmentId || undefined

  if (!features.projectEnvironments || !projectData || !environmentId) {
    return null
  }

  const name = projectData.name || environmentId

  return (
    <HeaderAlertBar
      variant="environment"
      icon={Layers}
      role="status"
      aria-label={`${t('Project environment')}: ${name}`}
      action={
        <button
          type="button"
          className={headerAlertOutlineButtonClass('environment')}
          onClick={() => {
            setActiveEnvironment(projectId, null)
            void navigate({ to: '/projects/$projectId', params: { projectId } })
          }}
        >
          {t('Switch to production')}
        </button>
      }
    >
      <>
        {t('Project environment')}:{' '}
        <span className="text-foreground">{name}</span>.{' '}
        {t('Changes here do not affect production.')}
      </>
    </HeaderAlertBar>
  )
}
