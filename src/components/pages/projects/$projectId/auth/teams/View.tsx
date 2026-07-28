import { useMemo, useState } from 'react'
import { useParams, useLocation, useNavigate } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { Loader2 } from 'lucide-react'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { TeamOverview } from './Overview'
import { TeamMembers } from './Members'
import { useTeam } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import { Button } from '@/components/ui/button'

export function View() {
  const t = useT()
  const { projectId, teamId } = useParams({
    strict: false,
  })
  const location = useLocation()
  const navigate = useNavigate()

  // All hooks must be called unconditionally before any early returns
  const {
    data: team,
    isLoading: teamLoading,
    error: teamError,
  } = useTeam(projectId ?? '', teamId ?? '')

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const teamIndex = pathParts.findIndex(
      (part, idx) => part === 'teams' && pathParts[idx + 1] === teamId,
    )

    if (teamIndex >= 0 && pathParts[teamIndex + 2]) {
      const tabFromPath = pathParts[teamIndex + 2]
      if (['members'].includes(tabFromPath)) {
        return tabFromPath
      }
    }

    // Default to overview for index route
    return 'overview'
  }, [location.pathname, teamId])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'overview',
        label: t('Overview'),
        to: '/projects/$projectId/auth/teams/$teamId',
        params: {
          projectId: projectId as string,
          teamId: teamId as string,
        },
      },
      {
        id: 'members',
        label: t('Memberships'),
        to: '/projects/$projectId/auth/teams/$teamId/members',
        params: {
          projectId: projectId as string,
          teamId: teamId as string,
        },
      },
    ],
    [projectId, teamId, t],
  )

  const [membersSearchValue, setMembersSearchValue] = useState('')
  const [createMembershipDialogOpen, setCreateMembershipDialogOpen] =
    useState(false)

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/auth/teams',
      params: { projectId: projectId! },
    })
  }

  // Early return if missing required params - after all hooks are called
  if (!projectId || !teamId) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Missing project ID or team ID')}
          </p>
          {!projectId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              {t('Project ID is required')}
            </p>
          )}
          {!teamId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              {t('Team ID is required')}
            </p>
          )}
        </div>
      </div>
    )
  }

  if (teamLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (teamError) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-destructive mb-2">
            {t('Error loading team')}
          </p>
          <p className="text-[12px] text-muted-foreground">
            {teamError instanceof Error
              ? teamError.message
              : t('Unknown error')}
          </p>
        </div>
      </div>
    )
  }

  if (!team) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Team not found')}
          </p>
          {projectId && teamId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              {t('Project')}: {projectId}, {t('Team')}: {teamId}
            </p>
          )}
        </div>
      </div>
    )
  }

  const teamName = team.name || '-'

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <DetailResourceHeaderTitle
            kind="team"
            label={teamName}
            resourceId={team.$id}
            projectId={projectId}
            back={{
              onClick: handleBack,
              'aria-label': t('Back to teams'),
            }}
          />
        }
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'members' ? t('Search members...') : undefined
        }
        searchValue={activeTab === 'members' ? membersSearchValue : undefined}
        onSearchChange={
          activeTab === 'members' ? setMembersSearchValue : undefined
        }
        createLabel={activeTab === 'members' ? t('Add member') : undefined}
        createAnalyticsAction={
          activeTab === 'members' ? 'add-team-member' : undefined
        }
        onCreate={
          activeTab === 'members'
            ? () => setCreateMembershipDialogOpen(true)
            : undefined
        }
        showFilters={false}
        fullWidthBorder
      />

      <div className="flex-1 flex flex-col">
        <div
          className={cn(
            'mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6',
            activeTab === 'overview' && 'pt-4 sm:pt-6',
          )}
        >
          {activeTab === 'overview' && <TeamOverview />}
          {activeTab === 'members' && (
            <TeamMembers
              searchValue={membersSearchValue}
              onSearchChange={setMembersSearchValue}
              createDialogOpen={createMembershipDialogOpen}
              onCreateDialogOpenChange={setCreateMembershipDialogOpen}
            />
          )}
        </div>
      </div>
    </div>
  )
}
