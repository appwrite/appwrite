import type { ReactElement } from 'react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { VcsIcon } from '@/lib/vcs/providers'
import { useReuseVcsInstallation } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

/**
 * Picks a GitHub installation another project in the organization already
 * connected, so this project gets it without going through GitHub's install
 * flow. `installLink` is the regular install for an account that isn't
 * connected anywhere yet.
 */
export function GitInstallationReuseDialog({
  open,
  onOpenChange,
  projectId,
  installations,
  installLink,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string | null | undefined
  installations: Models.Installation[]
  installLink: ReactElement
}) {
  const t = useT()
  const reuse = useReuseVcsInstallation(projectId)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="z-[10000] sm:max-w-md"
        overlayClassName="z-[9999]"
      >
        <DialogHeader>
          <DialogTitle>{t('Connect GitHub')}</DialogTitle>
          <DialogDescription>
            {t(
              'Use an account already connected in another project of this organization, or install the app on a new one.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="divide-y divide-border rounded-lg border border-border">
          {installations.map((installation) => (
            <div
              key={installation.$id}
              className="flex items-center gap-3 px-4 py-3"
            >
              <VcsIcon type="github" className="h-4 w-4 shrink-0" />
              <span className="min-w-0 truncate text-[13px] font-medium text-foreground">
                {installation.organization}
              </span>
              {(installation as { personal?: boolean }).personal && (
                <Badge variant="secondary" className="text-[11px]">
                  {t('Personal')}
                </Badge>
              )}
              <Button
                size="sm"
                variant="outline"
                className="ms-auto h-7 text-[12px]"
                disabled={reuse.isPending}
                onClick={() =>
                  reuse.mutate(installation.$id, {
                    onSuccess: () => onOpenChange(false),
                  })
                }
              >
                {reuse.isPending && reuse.variables === installation.$id
                  ? t('Connecting...')
                  : t('Use')}
              </Button>
            </div>
          ))}
        </div>
        <Button variant="ghost" size="sm" asChild>
          {installLink}
        </Button>
      </DialogContent>
    </Dialog>
  )
}
