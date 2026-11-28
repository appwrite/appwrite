import { useMemo, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import type { UseMutationResult } from '@tanstack/react-query'
import { CheckCircle2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE } from '@/lib/databases/dedicated-database-status'
import {
  compareEngineVersions,
  newerEngineVersions,
} from '@/lib/databases/engine-versions'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type DedicatedDatabaseVersionCardProps = {
  /** Engine display name, e.g. `PostgreSQL`. */
  engine: string
  /** Versions the API accepts as an upgrade target, oldest first. */
  versions: readonly string[]
  database: Models.DedicatedDatabase
  canWrite: boolean
  /** The engine's `createUpgrade` mutation, called with the target version. */
  upgrade: UseMutationResult<Models.DedicatedDatabase, Error, string>
  /** Engine-specific lines (translation keys) added to the confirm dialog. */
  notes?: string[]
}

/**
 * Current engine version plus every newer supported one, each with an
 * Upgrade button. The upgrade then runs like a compute tier change: the
 * database reports `upgrading` and the status header alert follows it.
 */
export function DedicatedDatabaseVersionCard({
  engine,
  versions,
  database,
  canWrite,
  upgrade,
  notes = [],
}: DedicatedDatabaseVersionCardProps) {
  const t = useT()
  const [targetVersion, setTargetVersion] = useState<string | null>(null)

  const currentVersion = database.version?.trim() ?? ''
  const newerVersions = useMemo(
    () => newerEngineVersions(currentVersion, versions),
    [currentVersion, versions],
  )
  const latestVersion = versions[versions.length - 1] ?? ''
  const upgrading = database.status === 'upgrading'
  const ready = database.status === 'ready'
  const upgradeDisabled = !canWrite || !ready || upgrade.isPending
  const upgradeTooltip = !canWrite
    ? t("You don't have permission to change database settings.")
    : !ready
      ? t(DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE)
      : undefined

  const versionLabel = (version: string) => `${engine} ${version}`

  const handleUpgrade = () => {
    if (!targetVersion) return
    upgrade.mutate(targetVersion, {
      onSuccess: () => {
        toast.success(
          t('Upgrade to {version} started').replace(
            '{version}',
            versionLabel(targetVersion),
          ),
        )
        setTargetVersion(null)
      },
      onError: (error) =>
        toast.error(getErrorMessage(error, t('Failed to start the upgrade'))),
    })
  }

  if (!currentVersion) return null

  const rows = [currentVersion, ...newerVersions]

  return (
    <div
      data-card-id="version"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Version')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Upgrade to a newer {engine} version. Upgrades run online while your database keeps serving traffic. Downgrades are not supported.',
          ).replace('{engine}', engine)}
        </p>
      </div>
      <div className="border-t border-border" />
      <Table>
        <TableBody>
          {rows.map((version) => {
            const isCurrent = version === currentVersion
            const isLatest =
              latestVersion !== '' &&
              compareEngineVersions(version, latestVersion) >= 0
            return (
              <TableRow
                key={version}
                className={cn(
                  'border-b border-border last:border-b-0 hover:bg-transparent',
                  isCurrent && 'bg-primary/5 hover:bg-primary/5',
                )}
              >
                <TableCell className="px-6 py-3">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[13px] font-medium text-foreground">
                      {versionLabel(version)}
                    </span>
                    {isCurrent && upgrading ? (
                      <Badge variant="warning" className="text-[10px] shrink-0">
                        {t('Upgrading')}
                      </Badge>
                    ) : isCurrent ? (
                      <Badge
                        variant="success"
                        className="gap-1 text-[10px] shrink-0"
                      >
                        <CheckCircle2 className="h-3 w-3" />
                        {t('Current')}
                      </Badge>
                    ) : null}
                    {isLatest ? (
                      <Badge variant="outline" className="text-[10px] shrink-0">
                        {t('Latest')}
                      </Badge>
                    ) : null}
                  </span>
                </TableCell>
                <TableCell className="px-6 py-3 text-end w-[140px]">
                  {!isCurrent ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-[12px]"
                      disabled={upgradeDisabled}
                      title={upgradeTooltip}
                      onClick={() => setTargetVersion(version)}
                    >
                      {t('Upgrade')}
                    </Button>
                  ) : null}
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
      {newerVersions.length === 0 && !upgrading ? (
        <div className="px-6 py-3 border-t border-border">
          <p className="text-[12px] text-muted-foreground">
            {t('This database runs the latest supported version.')}
          </p>
        </div>
      ) : null}

      <AlertDialog
        open={targetVersion != null}
        onOpenChange={(open) => {
          if (!open && !upgrade.isPending) setTargetVersion(null)
        }}
      >
        <AlertDialogContent className="sm:max-w-md p-0">
          <AlertDialogHeader className="px-6 pt-6 pb-4 text-left">
            <AlertDialogTitle>
              {t('Upgrade to {version}').replace(
                '{version}',
                versionLabel(targetVersion ?? ''),
              )}
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div className="mt-2 space-y-2 text-[13px]">
                <p>
                  {t(
                    'A new instance is created on the target version and your data is copied over while the database stays online. Traffic switches over once it has caught up.',
                  )}
                </p>
                {notes.map((note) => (
                  <p key={note}>{t(note)}</p>
                ))}
                <p>{t('You cannot downgrade afterwards.')}</p>
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="px-6 py-4 border-t border-border bg-muted/30 flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AlertDialogCancel disabled={upgrade.isPending}>
              {t('Cancel')}
            </AlertDialogCancel>
            <Button disabled={upgrade.isPending} onClick={handleUpgrade}>
              {upgrade.isPending ? t('Starting...') : t('Upgrade')}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
