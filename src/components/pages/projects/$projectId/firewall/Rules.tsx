import { useState } from 'react'
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Pencil,
  Plus,
  Search,
  Shield,
  Square,
  ToggleLeft,
  ToggleRight,
  Trash2,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Skeleton } from '@/components/ui/skeleton'
import {
  fetchFirewallRule,
  useFirewallRules,
  useUpdateFirewallRule,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  getRuleRateLimit,
  getRuleRedirect,
} from '@/lib/firewall/actions'
import {
  formatConditionSummary,
  parseFirewallConditions,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  SERVICE_HEADER_CONTAINER,
  serviceHeaderIconOnlyButton,
  serviceHeaderShowLabel,
} from '../shared/service-header-container'
import { RuleActionBadge } from './_components/RuleActionBadge'
import { RuleContextMenu } from './_components/RuleContextMenu'
import {
  FirewallResourceSelector,
  type FirewallResourceSelection,
} from './_components/FirewallResourceSelector'
import { UpdateRule } from './_components/UpdateRule'
import { DeleteRule } from './_components/DeleteRule'

function emptyCopyForScope(scope: FirewallResourceType): {
  title: string
  description: string
} {
  switch (scope) {
    case 'functions':
      return {
        title: 'No function firewall rules',
        description:
          'Create a firewall rule scoped to a function to control how it handles requests.',
      }
    case 'sites':
      return {
        title: 'No site firewall rules',
        description:
          'Create a firewall rule scoped to a site to control how it handles requests.',
      }
    case 'api':
    default:
      return {
        title: 'No API firewall rules',
        description:
          'Create a firewall rule for your project API to protect it from malicious requests.',
      }
  }
}

interface RulesListProps {
  projectId: string
  canWrite: boolean
  resourceSelection: FirewallResourceSelection
  onResourceSelectionChange: (selection: FirewallResourceSelection) => void
  onCreate: () => void
  /** When true, create is disabled (plan limit or missing permission). */
  createDisabled?: boolean
  /** Tooltip when create is disabled. */
  createDisabledTooltip?: string
}

export function RulesList({
  projectId,
  canWrite,
  resourceSelection,
  onResourceSelectionChange,
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: RulesListProps) {
  const t = useT()
  const [searchValue, setSearchValue] = useState('')
  const resourceScope = resourceSelection.resourceType
  const resourceId =
    resourceScope === 'api'
      ? undefined
      : resourceSelection.resourceId?.trim() || undefined
  const { rules, isLoading, isFetching } = useFirewallRules(
    projectId,
    0,
    DEFAULT_PAGE_SIZE,
    searchValue,
    resourceScope,
    resourceId,
  )
  // Drop keepPreviousData leftovers from another scope while the filtered query loads.
  const scopedRules = rules.filter((rule) => {
    if ((rule.resourceType || 'api') !== resourceScope) return false
    if (resourceScope === 'api') return true
    return (rule.resourceId?.trim() || '') === (resourceId || '')
  })
  const updateMutation = useUpdateFirewallRule(projectId)
  const [editingRule, setEditingRule] = useState<Models.WafRule | null>(null)
  const [deletingRule, setDeletingRule] = useState<Models.WafRule | null>(null)
  const [togglingRuleId, setTogglingRuleId] = useState<string | null>(null)
  const noWritePermissionTooltip = !canWrite
    ? t("You don't have permission to update firewall rules.")
    : undefined
  const resolvedCreateDisabled = createDisabled || !canWrite
  const resolvedCreateDisabledTooltip =
    createDisabledTooltip ??
    (!canWrite
      ? t("You don't have permission to create firewall rules.")
      : t("You've reached the limit for this resource on your plan"))
  const emptyCopy = emptyCopyForScope(resourceScope)

  const handleToggleEnabled = async (rule: Models.WafRule) => {
    const nextEnabled = !rule.enabled
    const rateLimit = getRuleRateLimit(rule)
    const redirect = getRuleRedirect(rule)
    setTogglingRuleId(rule.$id)
    try {
      await updateMutation.mutateAsync({
        ruleId: rule.$id,
        action: rule.action,
        enabled: nextEnabled,
        resourceType: rule.resourceType,
        resourceId: rule.resourceId,
        name: rule.name,
        description: rule.description,
        priority: rule.priority,
        limit: rateLimit?.limit,
        interval: rateLimit?.interval,
        location: redirect?.location,
        statusCode: redirect?.statusCode,
      })
      toast.success(
        nextEnabled ? t('Firewall rule enabled') : t('Firewall rule disabled'),
      )
    } catch (error) {
      toast.error(
        getErrorMessage(error as Error, t('Failed to update firewall rule')),
      )
    } finally {
      setTogglingRuleId(null)
    }
  }

  const firewallHref = buildConsoleUrl(`/projects/${projectId}/firewall`)

  const createButtonClassName = cn(
    serviceHeaderIconOnlyButton,
    'text-[13px] font-medium',
  )
  const createButtonLabel = (
    <>
      <span className={serviceHeaderShowLabel}>{t('Create rule')}</span>
      <span className="sr-only @[640px]:hidden">{t('Create rule')}</span>
    </>
  )

  const createButton = resolvedCreateDisabled ? (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <div>
            <Button
              variant="brandCta"
              size="sm"
              disabled
              className={createButtonClassName}
              aria-label={t('Create rule')}
              {...analyticsAttrs('create-firewall-rule')}
            >
              <Plus className="h-4 w-4 shrink-0" />
              {createButtonLabel}
            </Button>
          </div>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          <p>{resolvedCreateDisabledTooltip}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  ) : (
    <Button
      variant="brandCta"
      size="sm"
      onClick={onCreate}
      className={createButtonClassName}
      aria-label={t('Create rule')}
      {...analyticsAttrs('create-firewall-rule')}
    >
      <Plus className="h-4 w-4 shrink-0" />
      {createButtonLabel}
    </Button>
  )

  const toolbarRow = (
    <div
      className={cn(
        SERVICE_HEADER_CONTAINER,
        'flex min-w-0 flex-col gap-2 @[640px]:flex-row @[640px]:flex-nowrap @[640px]:items-center @[640px]:justify-between @[640px]:gap-3',
      )}
    >
      <div className="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
        <FirewallResourceSelector
          projectId={projectId}
          value={resourceSelection}
          onValueChange={onResourceSelectionChange}
        />
        <div className="relative min-w-0 w-full max-w-xs flex-1 shrink sm:w-64 sm:max-w-none sm:flex-none sm:shrink-0">
          <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder={t('Search rules...')}
            value={searchValue}
            onChange={(e) => setSearchValue(e.target.value)}
            className="h-9 border-border bg-accent/50 ps-10 text-[13px] text-foreground placeholder:text-muted-foreground"
          />
        </div>
      </div>
      {createButton}
    </div>
  )

  const renderRuleRow = (rule: Models.WafRule) => {
    const conditions = parseFirewallConditions(rule.conditions)
    const rateLimit = getRuleRateLimit(rule)
    const redirect = getRuleRedirect(rule)
    const isToggling = togglingRuleId === rule.$id
    const toggleDisabled =
      !canWrite || isToggling || updateMutation.isPending

    const statusSwitch = (
      <Switch
        checked={rule.enabled}
        disabled={toggleDisabled}
        onCheckedChange={() => handleToggleEnabled(rule)}
        aria-label={rule.enabled ? t('Disable rule') : t('Enable rule')}
      />
    )

    return (
      <RuleContextMenu
        key={rule.$id}
        projectId={projectId}
        rule={rule}
        canWrite={canWrite}
        togglePending={isToggling}
        onUpdate={setEditingRule}
        onToggleEnabled={handleToggleEnabled}
        onDelete={setDeletingRule}
      >
        <TableRow
          className={
            isFetching ? 'cursor-pointer opacity-80' : 'cursor-pointer'
          }
          onClick={() => setEditingRule(rule)}
        >
          <TableCell
            className="px-4 py-3"
            onClick={(event) => event.stopPropagation()}
          >
            {!canWrite ? (
              <TooltipProvider delayDuration={0}>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">{statusSwitch}</span>
                  </TooltipTrigger>
                  <TooltipContent side="top">
                    <p>{noWritePermissionTooltip}</p>
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
            ) : (
              statusSwitch
            )}
          </TableCell>
          <TableCell className="max-w-[240px] px-4 py-3">
            <div className="flex min-w-0 flex-col gap-0.5">
              <span
                className="truncate text-[13px] font-medium text-foreground"
                title={rule.name}
              >
                {rule.name}
              </span>
              {rule.description ? (
                <span
                  className="truncate text-[12px] text-muted-foreground"
                  title={rule.description}
                >
                  {rule.description}
                </span>
              ) : null}
            </div>
          </TableCell>
          <TableCell className="px-4 py-3">
            <div className="flex flex-col gap-1">
              <RuleActionBadge action={String(rule.action)} />
              {rateLimit ? (
                <span className="text-[11px] text-muted-foreground">
                  {rateLimit.limit}/{rateLimit.interval}s
                </span>
              ) : null}
              {redirect ? (
                <span className="text-[11px] text-muted-foreground truncate max-w-[160px]">
                  {redirect.statusCode} → {redirect.location}
                </span>
              ) : null}
            </div>
          </TableCell>
          <TableCell className="px-4 py-3">
            <span className="text-[12px] font-mono text-muted-foreground">
              {rule.priority}
            </span>
          </TableCell>
          <TableCell className="px-4 py-3">
            <div className="flex flex-col gap-0.5 max-w-[220px]">
              {conditions.length === 0 ? (
                <span className="text-[12px] text-muted-foreground">
                  {t('All requests')}
                </span>
              ) : (
                conditions.slice(0, 2).map((condition, index) => (
                  <span
                    key={`${rule.$id}-cond-${index}`}
                    className="text-[11px] text-muted-foreground truncate"
                  >
                    {formatConditionSummary(condition)}
                  </span>
                ))
              )}
              {conditions.length > 2 ? (
                <span className="text-[11px] text-muted-foreground">
                  +{conditions.length - 2} {t('more')}
                </span>
              ) : null}
            </div>
          </TableCell>
          <TableCell className="px-4 py-3">
            <DateTooltip
              date={rule.$updatedAt}
              className="text-[12px] text-muted-foreground"
            />
          </TableCell>
          <TableCell className="px-4 py-3 text-right">
            <div
              className="flex justify-end"
              onClick={(event) => event.stopPropagation()}
            >
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <RowActionsMenuTrigger />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem
                    disabled={!canWrite}
                    onSelect={() =>
                      openDialogAfterOverlayCloses(() => setEditingRule(rule))
                    }
                  >
                    <MenuItemContent icon={Pencil}>{t('Update')}</MenuItemContent>
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    disabled={!canWrite || isToggling}
                    onClick={() => handleToggleEnabled(rule)}
                  >
                    <MenuItemContent
                      icon={rule.enabled ? ToggleLeft : ToggleRight}
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
                        onClick={() => void copyToClipboard('ID', rule.$id)}
                      >
                        <MenuItemContent icon={Copy}>
                          {t('Copy ID')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        onClick={() =>
                          void copyToClipboard('Name', rule.name)
                        }
                      >
                        <MenuItemContent icon={Copy}>
                          {t('Copy name')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        onClick={() =>
                          void copyToClipboard('Link', firewallHref)
                        }
                      >
                        <MenuItemContent icon={Link2}>
                          {t('Copy link')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        onClick={() =>
                          void copyResourceAsJson(
                            () => fetchFirewallRule(projectId, rule.$id),
                            { fallback: rule },
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
                    onClick={() => openInNewTab(firewallHref)}
                  >
                    <MenuItemContent icon={ExternalLink}>
                      {t('Open in new tab')}
                    </MenuItemContent>
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    onClick={() => openInNewWindow(firewallHref)}
                  >
                    <MenuItemContent icon={Square}>
                      {t('Open in new window')}
                    </MenuItemContent>
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    disabled={!canWrite}
                    onSelect={() =>
                      openDialogAfterOverlayCloses(() => setDeletingRule(rule))
                    }
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
      </RuleContextMenu>
    )
  }

  if (isLoading && scopedRules.length === 0) {
    return (
      <div className="space-y-4">
        {toolbarRow}
        <div className="rounded-xl border border-border bg-card/50">
          <div className="divide-y divide-border">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="p-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Skeleton className="h-5 w-8 rounded-full" />
                    <div className="space-y-2">
                      <Skeleton className="h-4 w-48" />
                      <Skeleton className="h-3 w-64" />
                    </div>
                  </div>
                  <Skeleton className="h-8 w-8 rounded" />
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {toolbarRow}

      {scopedRules.length === 0 ? (
        <EmptyState
          icon={Shield}
          title={searchValue ? undefined : t(emptyCopy.title)}
          description={searchValue ? undefined : t(emptyCopy.description)}
          isEmpty={!searchValue}
          hasFilters={!!searchValue}
          variant="card"
          iconSize="md"
        />
      ) : (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[88px]">
                  {t('Status')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider max-w-[240px]">
                  {t('Rule')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Action')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Priority')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Conditions')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Updated')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px]" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {scopedRules.map((rule) => renderRuleRow(rule))}
            </TableBody>
          </Table>
        </div>
      )}

      <UpdateRule
        open={!!editingRule}
        onOpenChange={(open) => {
          if (!open) setEditingRule(null)
        }}
        projectId={projectId}
        rule={editingRule}
      />
      <DeleteRule
        open={!!deletingRule}
        onOpenChange={(open) => {
          if (!open) setDeletingRule(null)
        }}
        projectId={projectId}
        rule={deletingRule}
      />
    </div>
  )
}
