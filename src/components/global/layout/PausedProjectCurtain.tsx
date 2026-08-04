import { Link, useNavigate } from '@tanstack/react-router'
import { PauseCircle, ArrowUpCircle, RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { useResumeProject } from '@/lib/react-query/hooks'
import { toast } from 'sonner'

type PausedProjectCurtainProps = {
  projectId: string
  teamId: string
  onRestoreSuccess?: () => void
}

/**
 * Full-screen curtain shown when a project is paused.
 * Blocks access to all project-scoped pages. Encourages upgrade to avoid pausing,
 * with a secondary "Restore project" action that resumes the project.
 */
export function PausedProjectCurtain({
  projectId,
  teamId,
  onRestoreSuccess,
}: PausedProjectCurtainProps) {
  const t = useT()
  const navigate = useNavigate()
  const resumeMutation = useResumeProject(projectId)

  async function handleRestore() {
    try {
      await resumeMutation.mutateAsync()
      onRestoreSuccess?.()
      toast.success(t('Project resumed successfully'))
      // Mutation invalidates project and all project-scoped queries. Navigate to overview
      // so the layout and overview load with fresh data and avoid HTTP errors.
      navigate({
        to: '/projects/$projectId',
        params: { projectId },
      })
    } catch (e) {
      const message =
        e && typeof e === 'object' && 'message' in e
          ? String((e as { message: string }).message)
          : t('Failed to resume project. Please try again.')
      toast.error(message)
    }
  }

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center bg-background/95 backdrop-blur-sm">
      <div className="mx-4 flex max-w-md flex-col items-center text-center">
        <div className="mb-6 flex size-16 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <PauseCircle className="size-9" />
        </div>
        <h1 className="text-[22px] font-semibold text-foreground">
          {t('Project paused')}
        </h1>
        <p className="mt-3 text-[15px] text-muted-foreground">
          {t(
            'This project has been paused due to inactivity. Your data is safe and will remain intact.',
          )}
        </p>
        <p className="mt-2 text-[14px] text-muted-foreground">
          {t(
            'Upgrade your plan to avoid pausing, or restore the project to continue using it now.',
          )}
        </p>

        {resumeMutation.error && (
          <p className="mt-4 text-[13px] text-destructive">
            {resumeMutation.error instanceof Error
              ? resumeMutation.error.message
              : t('Failed to resume project.')}
          </p>
        )}

        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:gap-2">
          <Button asChild variant="brandCta" className="gap-1.5">
            <Link to="/upgrade" search={{ orgId: teamId }}>
              <ArrowUpCircle className="size-4" />
              {t('Upgrade plan')}
            </Link>
          </Button>
          <Button
            variant="outline"
            disabled={resumeMutation.isPending}
            onClick={handleRestore}
            className="gap-1.5"
          >
            <RotateCcw className="size-4" />
            {t('Restore project')}
          </Button>
        </div>

        <Button
          variant="ghost"
          className="mt-6 text-muted-foreground"
          onClick={() =>
            navigate({
              to: '/organizations/$orgId',
              params: { orgId: teamId },
            })
          }
        >
          {t('Back to organization')}
        </Button>
      </div>
    </div>
  )
}
