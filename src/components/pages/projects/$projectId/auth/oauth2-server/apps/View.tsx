import { useMemo, useState } from 'react'
import { Loader2, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import {
  useCreateProjectOAuth2App,
  useDeleteProjectOAuth2App,
  useProject,
  useProjectOAuth2Apps,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { CreateProjectOAuth2App } from './_components/CreateProjectOAuth2App'

interface OAuth2ServerAppsViewProps {
  projectId: string
}

export function View({ projectId }: OAuth2ServerAppsViewProps) {
  const { project } = useProject(projectId)
  const { apps, isLoading, isFetching } = useProjectOAuth2Apps(
    projectId,
    project?.region,
  )
  const createMutation = useCreateProjectOAuth2App(projectId, project?.region)
  const deleteMutation = useDeleteProjectOAuth2App(projectId, project?.region)

  const [createOpen, setCreateOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<{
    id: string
    name: string
  } | null>(null)

  const sortedApps = useMemo(
    () =>
      [...apps].sort(
        (a, b) =>
          new Date(b.$createdAt).getTime() - new Date(a.$createdAt).getTime(),
      ),
    [apps],
  )

  const handleCreate = async (
    input: Parameters<typeof createMutation.mutateAsync>[0],
  ) => {
    try {
      await createMutation.mutateAsync(input)
      setCreateOpen(false)
      toast.success('OAuth2 app created')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to create OAuth2 app'))
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    try {
      await deleteMutation.mutateAsync(deleteTarget.id)
      toast.success('OAuth2 app deleted')
      setDeleteTarget(null)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to delete OAuth2 app'))
    }
  }

  if (isLoading && apps.length === 0) {
    return (
      <div className="flex min-h-48 items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div>
      <div className="mb-6 flex items-start justify-between gap-4">
        <div className="max-w-xl">
          <h2 className="text-[15px] font-semibold text-foreground">Apps</h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            OAuth2 clients registered against this project. These apps
            authenticate users through your project&apos;s authorization server.
          </p>
        </div>
        <Button size="sm" className="h-9 shrink-0" onClick={() => setCreateOpen(true)}>
          <Plus className="me-1.5 h-3.5 w-3.5" />
          Create app
        </Button>
      </div>

      {sortedApps.length === 0 ? (
        <EmptyState
          title="No OAuth2 apps"
          description="Create an app to register redirect URIs and issue client credentials for this project."
          action={
            <Button size="sm" onClick={() => setCreateOpen(true)}>
              Create app
            </Button>
          }
          variant="card"
        />
      ) : (
        <div className="relative rounded-lg border border-border bg-card overflow-hidden">
          {isFetching ? (
            <div className="absolute end-3 top-3 z-10">
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          ) : null}
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Name
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Client ID
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Type
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Redirect URIs
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Created
                </TableHead>
                <TableHead className="w-[100px] px-4 py-3 text-end" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {sortedApps.map((app) => (
                <TableRow key={app.$id}>
                  <TableCell className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className="text-[13px] font-medium">{app.name}</span>
                      <Badge
                        variant={app.enabled ? 'success' : 'secondary'}
                        className="text-[10px] shrink-0"
                      >
                        {app.enabled ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <CopyableId id={app.$id} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Badge variant="info" className="text-[10px] capitalize">
                      {app.type || 'confidential'}
                    </Badge>
                  </TableCell>
                  <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                    {app.redirectUris?.length ?? 0}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <DateTooltip date={app.$createdAt} />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 p-0"
                      onClick={() =>
                        setDeleteTarget({ id: app.$id, name: app.name })
                      }
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <CreateProjectOAuth2App
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreate={handleCreate}
        isSubmitting={createMutation.isPending}
      />

      <Dialog
        open={!!deleteTarget}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Delete OAuth2 app</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Delete {deleteTarget?.name}? Active tokens for this client will stop
              working.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border px-6 py-4 bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => setDeleteTarget(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={deleteMutation.isPending}
              onClick={handleDelete}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
