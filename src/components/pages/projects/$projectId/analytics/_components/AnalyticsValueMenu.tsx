import type { ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { AnalyticsDimension } from '@appwrite.io/console'
import { Ban, Copy, ExternalLink, Filter, FilterX, Shield, ShieldCheck, X } from 'lucide-react'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuLabel,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import { buildFilterQueryString } from '@/lib/table-filters'
import { analyticsFilterAttributeForDimension } from '@/lib/analytics/analytics-filters'
import {
  firewallAllowOnlyCountryQuery,
  firewallCreateQueryForAnalyticsValue,
} from '@/lib/analytics/firewall-link'
import { firewallListSearch } from '@/lib/firewall/conditions'
import { useT } from '@/lib/i18n/translate'
import {
  useAnalyticsFilters,
  useAnalyticsValueMenuContext,
} from './analytics-filters-context'

/**
 * Right-click menu for a value in an analytics card or "Show more" list:
 * filter by it, exclude it, copy it, open it (pages / hostnames), and - when
 * the property's domain is served by an Appwrite Site - start a firewall rule
 * for that site prefilled with a matching condition.
 */
export function AnalyticsValueMenu({
  dimension,
  value,
  label,
  href,
  children,
}: {
  dimension: AnalyticsDimension
  /** Raw value (filters, firewall). */
  value: string
  /** Display value for the menu title (e.g. country name). */
  label?: string
  href?: string
  children: ReactNode
}) {
  const t = useT()
  const navigate = useNavigate()
  const { addEqualFilter, isFilterActive, onApplyFilter, filterMap } =
    useAnalyticsFilters()
  const { projectId, firewallSiteId } = useAnalyticsValueMenuContext()

  const attribute = analyticsFilterAttributeForDimension(dimension)
  const isActive = attribute ? isFilterActive(attribute, value) : false
  const isExcluded = attribute
    ? Array.from(filterMap.keys()).some(
        (key) => key.c === attribute && key.o === 'notEqual' && key.v === value,
      )
    : false

  const canCreateRule = Boolean(projectId && firewallSiteId)
  const firewallQuery = canCreateRule
    ? firewallCreateQueryForAnalyticsValue(dimension, value)
    : null
  const isCountry = dimension === AnalyticsDimension.Country
  // Countries get both directions: block this one, or allow only this one
  // (e.g. a Czech-only site denies everything outside CZ).
  const allowOnlyQuery =
    canCreateRule && isCountry ? firewallAllowOnlyCountryQuery(value) : null

  const openFirewallCreate = (query: string) => {
    if (!projectId || !firewallSiteId) return
    navigate({
      to: '/projects/$projectId/firewall/create',
      params: { projectId },
      search: {
        ...firewallListSearch({
          resourceType: 'sites',
          resourceId: firewallSiteId,
        }),
        query,
      },
    })
  }

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-60">
        <ContextMenuLabel className="truncate text-[11px] font-normal text-muted-foreground">
          {label ?? value}
        </ContextMenuLabel>
        {attribute ? (
          <>
            <ContextMenuItem onSelect={() => addEqualFilter(attribute, value)}>
              <ContextMenuIcon icon={isActive ? X : Filter} />
              {isActive ? t('Remove filter') : t('Filter by this value')}
            </ContextMenuItem>
            {!isExcluded && !isActive ? (
              <ContextMenuItem
                onSelect={() =>
                  onApplyFilter(
                    { c: attribute, o: 'notEqual', v: value },
                    buildFilterQueryString('notEqual', attribute, value),
                  )
                }
              >
                <ContextMenuIcon icon={FilterX} />
                {t('Exclude this value')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuSeparator />
          </>
        ) : null}
        <ContextMenuItem onSelect={() => copyToClipboard('Value', value)}>
          <ContextMenuIcon icon={Copy} />
          {t('Copy value')}
        </ContextMenuItem>
        {href ? (
          <ContextMenuItem onSelect={() => openInNewTab(href)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
        ) : null}
        {firewallQuery || allowOnlyQuery ? (
          <>
            <ContextMenuSeparator />
            {firewallQuery ? (
              <ContextMenuItem onSelect={() => openFirewallCreate(firewallQuery)}>
                <ContextMenuIcon icon={isCountry ? Ban : Shield} />
                {isCountry ? t('Block this country') : t('Create firewall rule')}
              </ContextMenuItem>
            ) : null}
            {allowOnlyQuery ? (
              <ContextMenuItem onSelect={() => openFirewallCreate(allowOnlyQuery)}>
                <ContextMenuIcon icon={ShieldCheck} />
                {t('Allow only this country')}
              </ContextMenuItem>
            ) : null}
          </>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}
