import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Bell,
  Copy,
  FileJson,
  Loader2,
  Pencil,
  Power,
  PowerOff,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import {
  bucketsQueryOptions,
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  fetchAlertRule,
  useAlertRules,
  useDeleteAlertRule,
  useUpdateAlertRule,
} from '@/lib/react-query/hooks'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import type { AlertRule } from '@/lib/appwrite/alerts'
import {
  RESOURCE_TYPES,
  formatCondition,
  formatMetricValue,
} from '@/lib/alerts/metrics'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { AlertsEmptyState } from './_components/AlertsEmptyState'
import { AlertDrawer } from './alerts/AlertDrawer'
import { CreateAlert } from './alerts/CreateAlert'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/** Resolves database, bucket and dedicated database names for the rules that watch one. */
function useResourceNames(projectId: string, rules: AlertRule[]) {
  const types = new Set(rules.map((rule) => rule.resourceType))
  const databases = useQuery({
    ...databasesQueryOptions(projectId, 0, 100),
    enabled: types.has('database'),
  })
  const buckets = useQuery({
    ...bucketsQueryOptions(projectId, 0, 100),
    enabled: types.has('bucket'),
  })
  const dedicated = useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    enabled: types.has('dedicatedDatabase'),
  })
  return useMemo(() => {
    const names = new Map<string, string>()
    for (const item of [
      ...(databases.data?.databases ?? []),
      ...(buckets.data?.buckets ?? []),
      ...(dedicated.data?.databases ?? []),
    ]) {
      names.set(item.$id, item.name)
    }
    return names
  }, [databases.data, buckets.data, dedicated.data])
}

interface AlertsProps {
  projectId: string
  searchValue?: string
  onFirstRunEmptyChange?: (empty: boolean) => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}

export function Alerts({
  projectId,
  searchValue = '',
  onFirstRunEmptyChange,
  createDisabled = false,
  createDisabledTooltip,
}: AlertsProps) {
  const t = useT()
  const { rules, isLoading } = useAlertRules(projectId)
  const updateMutation = useUpdateAlertRule(projectId)
  const deleteMutation = useDeleteAlertRule(projectId)
  const resourceNames = useResourceNames(projectId, rules)

  const [createOpen, setCreateOpen] = useState(false)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selected, setSelected] = useState<AlertRule | null>(null)
  const [toDelete, setToDelete] = useState<AlertRule | null>(null)

  const showsFirstRunEmptyState = !isLoading && rules.length === 0
  useEffect(() => {
    onFirstRunEmptyChange?.(showsFirstRunEmptyState)
    return () => onFirstRunEmptyChange?.(false)
  }, [onFirstRunEmptyChange, showsFirstRunEmptyState])

  const openCreate = () => {
    if (createDisabled) return
    openDialogAfterOverlayCloses(() => setCreateOpen(true))
  }

  useEffect(() => {
    const handleCreate = () =>
      openDialogAfterOverlayCloses(() => setCreateOpen(true))
    window.addEventListener('settings-create-alert-rule', handleCreate)
    return () =>
      window.removeEventListener('settings-create-alert-rule', handleCreate)
  }, [])

  const filtered = useMemo(() => {
    const search = searchValue.trim().toLowerCase()
    if (!search) return rules
    return rules.filter(
      (rule) =>
        rule.name.toLowerCase().includes(search) ||
        rule.metric.toLowerCase().includes(search) ||
        rule.$id.toLowerCase().includes(search),
    )
  }, [rules, searchValue])

  const handleUpdate = (rule: AlertRule) => {
    if (createDisabled) return
    openDialogAfterOverlayCloses(() => {
      setSelected(rule)
      setDrawerOpen(true)
    })
  }

  const handleToggle = (rule: AlertRule) => {
    updateMutation.mutate(
      { ruleId: rule.$id, changes: { enabled: !rule.enabled } },
      {
        onSuccess: () =>
          toast.success(
            rule.enabled
              ? t('Alert rule has been disabled')
              : t('Alert rule has been enabled'),
          ),
        onError: (error: Error) =>
          toast.error(getErrorMessage(error, t('Failed to update alert rule'))),
      },
    )
  }

  const requestDelete = (rule: AlertRule) =>
    openDialogAfterOverlayCloses(() => setToDelete(rule))

  const handleDelete = () => {
    if (!toDelete) return
    const ruleId = toDelete.$id
    deleteMutation.mutate(ruleId, {
      onSuccess: () => {
        closeDialogBeforeOverlayUnmount(() => {
          setToDelete(null)
          setSelected(null)
        })
        toast.success(t('Alert rule has been deleted'))
      },
      onError: (error: Error) =>
        toast.error(getErrorMessage(error, t('Failed to delete alert rule'))),
    })
  }

  const resourceLabel = (rule: AlertRule) => {
    const type = RESOURCE_TYPES.find(
      (entry) => entry.value === rule.resourceType,
    )
    const label = t(type?.label ?? rule.resourceType)
    if (rule.resourceType === 'project') return { label, detail: null }
    return {
      label,
      detail: resourceNames.get(rule.resourceId) ?? rule.resourceId,
    }
  }

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : showsFirstRunEmptyState ? (
        <AlertsEmptyState
          onCreate={openCreate}
          createDisabled={createDisabled}
          createDisabledTooltip={createDisabledTooltip}
        />
      ) : filtered.length === 0 ? (
        <EmptyState icon={Bell} hasFilters variant="card" />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={HEAD_CLASS}>{t('Name')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Condition')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Resource')}</TableHead>
                <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
                <TableHead className={`${HEAD_CLASS} text-end`}>
                  {t('Last value')}
                </TableHead>
                <TableHead className={`${HEAD_CLASS} text-end`}>
                  {t('Last evaluated')}
                </TableHead>
                <TableHead className={HEAD_CLASS}>{t('Enabled')}</TableHead>
                <TableHead className={`${HEAD_CLASS} text-end w-[80px]`} />
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((rule) => {
                const resource = resourceLabel(rule)
                return (
                  <TableRow
                    key={rule.$id}
                    className="cursor-pointer"
                    onClick={() => handleUpdate(rule)}
                  >
                    <TableCell className="px-4 py-3 font-medium">
                      {rule.name}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <code
                        dir="ltr"
                        className="whitespace-nowrap font-mono text-[12px] text-foreground"
                      >
                        {formatCondition(rule)}
                      </code>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div className="flex flex-col">
                        <span className="text-[13px]">{resource.label}</span>
                        {resource.detail && (
                          <span className="max-w-[200px] truncate text-[12px] text-muted-foreground">
                            {resource.detail}
                          </span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      {rule.status === 'firing' ? (
                        <Badge variant="error" className="text-[10px] shrink-0">
                          {t('Firing')}
                        </Badge>
                      ) : (
                        <Badge variant="info" className="text-[10px] shrink-0">
                          {t('OK')}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums">
                      {rule.value === null
                        ? '-'
                        : formatMetricValue(rule.metric, rule.value)}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      {rule.evaluatedAt ? (
                        <DateTooltip date={rule.evaluatedAt} />
                      ) : (
                        <span className="text-[13px] text-muted-foreground">
                          {t('Never')}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      {rule.enabled ? (
                        <Badge
                          variant="success"
                          className="text-[10px] shrink-0"
                        >
                          {t('Enabled')}
                        </Badge>
                      ) : (
                        <Badge
                          variant="inactive"
                          className="text-[10px] shrink-0"
                        >
                          {t('Disabled')}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <div
                        className="flex justify-end"
                        onClick={(event) => event.stopPropagation()}
                      >
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger
                              aria-label={`${t('Actions for')} ${rule.name}`}
                            />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuItem
                              disabled={createDisabled}
                              onClick={() => handleUpdate(rule)}
                            >
                              <MenuItemContent icon={Pencil}>
                                {t('Update')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              disabled={
                                createDisabled || updateMutation.isPending
                              }
                              onClick={() => handleToggle(rule)}
                            >
                              <MenuItemContent
                                icon={rule.enabled ? PowerOff : Power}
                              >
                                {rule.enabled ? t('Disable') : t('Enable')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuSub>
                              <DropdownMenuSubTrigger>
                                <MenuItemIcon icon={Copy} />
                                {t('Copy')}
                              </DropdownMenuSubTrigger>
                              <DropdownMenuSubContent>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('ID', rule.$id)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy ID')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('Name', rule.name)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy name')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    void copyResourceAsJson(() =>
                                      fetchAlertRule(projectId, rule.$id),
                                    )
                                  }
                                >
                                  <MenuItemContent icon={FileJson}>
                                    {t('Copy as JSON')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              disabled={createDisabled}
                              onClick={() => requestDelete(rule)}
                            >
                              <MenuItemContent icon={Trash2}>
                                {t('Delete')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      )}

      <CreateAlert
        open={createOpen}
        onOpenChange={setCreateOpen}
        projectId={projectId}
      />

      <AlertDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        projectId={projectId}
        rule={selected}
        onDelete={requestDelete}
      />

      <Dialog
        open={!!toDelete}
        onOpenChange={(open) => {
          if (!open && !deleteMutation.isPending) setToDelete(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete alert rule')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}{' '}
              <strong>{toDelete?.name}</strong>?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setToDelete(null)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
