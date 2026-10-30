import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { Boxes, Folder } from 'lucide-react'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  ResourceCard,
  RESOURCE_CARD_GRID_CLASSNAME,
} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import {
  PartnerListLoading,
  PartnerPage,
} from '@/components/pages/partners/$partnerId/PageShell'
import {
  activeProjectsQueryOptions,
  partnerClientsQueryOptions,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type ProjectRow = {
  $id: string
  name?: string
  region?: string
}

export function PartnerProjects({ partnerId }: { partnerId: string }) {
  const queryClient = useQueryClient()
  const { data: projectsData, isLoading } = useQuery(
    activeProjectsQueryOptions(partnerId, 0, GRID_DEFAULT_PAGE_SIZE),
  )
  const { data: clientsData } = useQuery(partnerClientsQueryOptions(partnerId))
  const [transferProject, setTransferProject] = useState<ProjectRow | null>(
    null,
  )
  const [targetOrgId, setTargetOrgId] = useState('')

  const transferMutation = useMutation({
    mutationFn: async () => {
      if (!transferProject || !targetOrgId) return
      await sdk.forConsole.projects.updateTeam({
        projectId: transferProject.$id,
        teamId: targetOrgId,
      })
    },
    onSuccess: async () => {
      toast.success('Project handed off')
      setTransferProject(null)
      setTargetOrgId('')
      await queryClient.invalidateQueries({ queryKey: ['projects'] })
      await queryClient.invalidateQueries({ queryKey: ['partners', partnerId] })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, 'Could not hand off project'))
    },
  })

  const projects = (projectsData?.projects ?? []) as ProjectRow[]
  const clients = clientsData?.teams ?? []

  return (
    <PartnerPage title="Projects">
      {isLoading ? (
        <PartnerListLoading label="Loading projects..." />
      ) : projects.length === 0 ? (
        <EmptyState
          icon={Boxes}
          title="No playground projects"
          description="Create a project under this partner organization, then hand it off to a client."
          isEmpty
          variant="card"
        />
      ) : (
        <div className={RESOURCE_CARD_GRID_CLASSNAME}>
          {projects.map((project) => (
            <div key={project.$id} className="relative h-full min-w-0">
              <Link
                to="/projects/$projectId"
                params={{ projectId: project.$id }}
                target="_blank"
                rel="noopener noreferrer"
                className="block h-full min-w-0"
              >
                <ResourceCard
                  title={project.name || project.$id}
                  resourceId={project.$id}
                  icon={Folder}
                  metadata={[
                    {
                      label: 'Region',
                      value: project.region || '—',
                    },
                  ]}
                />
              </Link>
              <Button
                size="sm"
                variant="outline"
                className="absolute top-3 end-3 z-10"
                disabled={clients.length === 0}
                onClick={() => {
                  setTransferProject(project)
                  setTargetOrgId(clients[0]?.$id ?? '')
                }}
              >
                Handoff
              </Button>
            </div>
          ))}
        </div>
      )}

      <Dialog
        open={!!transferProject}
        onOpenChange={(open) => {
          if (!open) setTransferProject(null)
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Handoff project</DialogTitle>
            <DialogDescription>
              Move {transferProject?.name || 'this project'} to a client
              organization.
            </DialogDescription>
          </DialogHeader>
          <Select value={targetOrgId} onValueChange={setTargetOrgId}>
            <SelectTrigger className="w-full">
              <SelectValue placeholder="Select client" />
            </SelectTrigger>
            <SelectContent>
              {clients.map((client) => (
                <SelectItem key={client.$id} value={client.$id}>
                  {client.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <DialogFooter>
            <Button
              disabled={!targetOrgId || transferMutation.isPending}
              onClick={() => transferMutation.mutate()}
            >
              Handoff
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PartnerPage>
  )
}
