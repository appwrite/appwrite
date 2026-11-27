import { useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { AlertCircle, ArrowLeft, Loader2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { Sandbox } from '@/lib/appwrite/sandboxes'
import { canWriteSandboxes } from '@/lib/console-access-checks'
import {
  useDeleteSandbox,
  useOrganizationScopes,
  useProject,
  useSandbox,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ServiceHeader } from '../../shared/ServiceHeader'
import { Files } from '../_components/Files'
import { Terminal } from '../_components/Terminal'
import { SandboxStatusBadge } from '../SandboxStatusBadge'
import { imageName } from '../View'

interface ViewProps {
  initialSandbox?: Sandbox
}

export function View({ initialSandbox }: ViewProps = {}) {
  const t = useT()
  const navigate = useNavigate()
  const { projectId, sandboxId } = useParams({ strict: false }) as {
    projectId: string
    sandboxId: string
  }
  const { sandbox: sandboxFromHook, isLoading } = useSandbox(
    projectId,
    sandboxId,
  )
  const sandbox = sandboxFromHook ?? initialSandbox ?? null

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteSandboxes(access, features)

  const [tab, setTab] = useState('terminal')
  const [deleteOpen, setDeleteOpen] = useState(false)
  const deleteMutation = useDeleteSandbox(projectId)

  const backToList = () =>
    navigate({ to: '/projects/$projectId/sandboxes', params: { projectId } })

  const handleDelete = async () => {
    try {
      await deleteMutation.mutateAsync(sandboxId)
      toast.success(t('Sandbox deleted'))
      setDeleteOpen(false)
      backToList()
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  if (!sandbox) {
    if (isLoading) return null
    return (
      <div className="flex items-center justify-center p-6 py-16">
        <EmptyState
          icon={AlertCircle}
          title={t('Sandbox not found')}
          description={t(
            'This sandbox may have been deleted, or it was idle long enough to be deleted.',
          )}
          variant="card"
          iconSize="md"
        />
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 flex-1 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              aria-label={t('Back to sandboxes')}
              onClick={backToList}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span className="min-w-0 truncate font-mono">{sandbox.$id}</span>
            <CopyableId id={sandbox.$id} size="xs" className="shrink-0" />
          </div>
        }
        titleRightContent={
          <Button
            variant="outline"
            size="sm"
            className="h-8 text-[13px]"
            disabled={!canWrite || sandbox.status === 'deleting'}
            onClick={() => setDeleteOpen(true)}
          >
            <Trash2 className="h-3.5 w-3.5 me-1.5" />
            {t('Delete')}
          </Button>
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="grid grid-cols-2 gap-x-6 gap-y-4 px-6 py-4 md:grid-cols-4">
              <Detail label={t('Status')}>
                <SandboxStatusBadge status={sandbox.status} />
              </Detail>
              <Detail label={t('Image')}>
                <span className="text-[13px]">{imageName(sandbox.image)}</span>
              </Detail>
              <Detail label={t('Size')}>
                <span className="font-mono text-[12px]">
                  {sandbox.specification}
                </span>
              </Detail>
              <Detail label={t('Created')}>
                <DateTooltip
                  date={sandbox.$createdAt}
                  className="text-[12px] text-muted-foreground font-mono"
                />
              </Detail>
            </div>
          </div>

          {sandbox.status === 'ready' ? (
            <Tabs value={tab} onValueChange={setTab}>
              <TabsList className="h-9">
                <TabsTrigger value="terminal" className="text-[13px]">
                  {t('Terminal')}
                </TabsTrigger>
                <TabsTrigger value="files" className="text-[13px]">
                  {t('Files')}
                </TabsTrigger>
              </TabsList>
              {/* The terminal stays mounted so switching tabs keeps its shell. */}
              <div className={tab === 'terminal' ? 'mt-4' : 'hidden'}>
                {canWrite ? (
                  <Terminal projectId={projectId} sandboxId={sandbox.$id} />
                ) : (
                  <p className="rounded-lg border border-border bg-card px-4 py-8 text-center text-[13px] text-muted-foreground">
                    {t(
                      "You don't have permission to run commands in sandboxes.",
                    )}
                  </p>
                )}
              </div>
              <TabsContent value="files" className="mt-4">
                <Files
                  projectId={projectId}
                  sandboxId={sandbox.$id}
                  canWrite={canWrite}
                />
              </TabsContent>
            </Tabs>
          ) : (
            <SandboxNotReady status={sandbox.status} />
          )}
        </div>
      </div>

      <ConfirmActionDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        title={t('Delete sandbox')}
        description={t(
          'The sandbox, its files and its processes are deleted. This cannot be undone.',
        )}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />
    </div>
  )
}

function SandboxNotReady({ status }: { status: Sandbox['status'] }) {
  const t = useT()
  if (status === 'failed') {
    return (
      <EmptyState
        icon={AlertCircle}
        variant="card"
        title={t('Sandbox failed to start')}
        description={t('Delete it and create a new one.')}
      />
    )
  }
  return (
    <div className="flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-16 text-[13px] text-muted-foreground">
      <Loader2 className="h-4 w-4 animate-spin" />
      {status === 'deleting'
        ? t('Deleting sandbox...')
        : t('Starting sandbox...')}
    </div>
  )
}

function Detail({
  label,
  children,
}: {
  label: string
  children: React.ReactNode
}) {
  return (
    <div className="space-y-1.5">
      <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      <div>{children}</div>
    </div>
  )
}
