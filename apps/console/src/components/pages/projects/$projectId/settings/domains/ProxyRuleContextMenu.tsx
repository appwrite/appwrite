import type { Models } from '@appwrite.io/console'
import { useNavigate } from '@tanstack/react-router'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  FileText,
  RefreshCw,
  Globe,
  Copy,
  Link2,
  FileJson,
  ExternalLink,
  Square,
  Trash2,
} from 'lucide-react'
import { fetchProxyRule } from '@/lib/react-query/hooks'
import {
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { getApexDomain } from '@/lib/utils/proxy-domains'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { domainUrl } from '@/lib/domains/url'

interface ProxyRuleContextMenuProps {
  projectId: string
  rule: Models.ProxyRule
  projectTeamId?: string
  apexToOrgDomainId: Map<string, string>
  onViewLogs: (rule: Models.ProxyRule) => void
  onRetry: (rule: Models.ProxyRule) => void
  onDelete: (rule: Models.ProxyRule) => void
  children: React.ReactNode
}

export function ProxyRuleContextMenu({
  projectId,
  rule,
  projectTeamId,
  apexToOrgDomainId,
  onViewLogs,
  onRetry,
  onDelete,
  children,
}: ProxyRuleContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const ruleUrl = domainUrl(rule.domain)
  const apex = getApexDomain(rule.domain)
  const orgDomainId = apex
    ? apexToOrgDomainId.get(apex.toLowerCase())
    : undefined
  const canOpenDnsRecords = !!projectTeamId && !!orgDomainId
  const canRetry = rule.status === 'created' || rule.status === 'unverified'

  const handleOpenDnsRecords = () => {
    if (!projectTeamId || !orgDomainId) return
    navigate({
      to: '/organizations/$orgId/domains/$domainId',
      params: { orgId: projectTeamId, domainId: orgDomainId },
    })
  }

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {rule.status !== 'verified' && (
          <ContextMenuItem
            onSelect={() =>
              openDialogAfterOverlayCloses(() => onViewLogs(rule))
            }
          >
            <ContextMenuIcon icon={FileText} />
            {t('View logs')}
          </ContextMenuItem>
        )}
        {canRetry && (
          <ContextMenuItem
            onSelect={() => openDialogAfterOverlayCloses(() => onRetry(rule))}
          >
            <ContextMenuIcon icon={RefreshCw} />
            {t('Retry')}
          </ContextMenuItem>
        )}
        <ContextMenuItem
          onSelect={handleOpenDnsRecords}
          disabled={!canOpenDnsRecords}
        >
          <ContextMenuIcon icon={Globe} />
          {t('DNS Records')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem onSelect={() => copyToClipboard('ID', rule.$id)}>
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Domain', rule.domain)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy domain')}
            </ContextMenuItem>
            <ContextMenuItem onSelect={() => copyToClipboard('Link', ruleUrl)}>
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchProxyRule(projectId, rule.$id),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(ruleUrl)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(ruleUrl)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem
          onSelect={() => openDialogAfterOverlayCloses(() => onDelete(rule))}
        >
          <ContextMenuIcon icon={Trash2} />
          {t('Delete')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
