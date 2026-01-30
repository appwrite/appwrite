import { useMemo } from 'react'
import { useParams, useLocation, useNavigate } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { ArrowLeft, Loader2 } from 'lucide-react'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { TeamOverview } from './Overview'
import { TeamMembers } from './Members'
import { useTeam } from '@/lib/react-query/hooks'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Button } from '@/components/ui/button'

export function View() {
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
        label: 'Overview',
        to: '/projects/$projectId/auth/teams/$teamId',
        params: {
          projectId: projectId as string,
          teamId: teamId as string,
        },
      },
      {
        id: 'members',
        label: 'Members',
        to: '/projects/$projectId/auth/teams/$teamId/members',
        params: {
          projectId: projectId as string,
          teamId: teamId as string,
        },
      },
    ],
    [projectId, teamId],
  )

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
            Missing project ID or team ID
          </p>
          {!projectId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              Project ID is required
            </p>
          )}
          {!teamId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              Team ID is required
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
            Error loading team
          </p>
          <p className="text-[12px] text-muted-foreground">
            {teamError instanceof Error ? teamError.message : 'Unknown error'}
          </p>
        </div>
      </div>
    )
  }

  if (!team) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">Team not found</p>
          {projectId && teamId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              Project: {projectId}, Team: {teamId}
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
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span>{teamName}</span>
            <CopyableId id={team.$id} size="xs" />
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        showFilters={false}
        fullWidthBorder
      />

      <div className="flex-1 flex flex-col">
        <div className={cn('mx-auto w-full max-w-7xl flex-1')}>
          {activeTab === 'overview' && (
            <div className="px-4 py-4 sm:px-6">
              <TeamOverview />
            </div>
          )}
          {activeTab === 'members' && (
            <div className="px-4 py-4 sm:px-6">
              <TeamMembers />
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
