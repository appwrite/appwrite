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
import {
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import { getApexDomain } from '@/lib/utils/proxy-domains'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

interface ProxyRuleContextMenuProps {
  rule: Models.ProxyRule
  projectTeamId?: string
  apexToOrgDomainId: Map<string, string>
  onViewLogs: (rule: Models.ProxyRule) => void
  onRetry: (rule: Models.ProxyRule) => void
  onDelete: (rule: Models.ProxyRule) => void
  children: React.ReactNode
}

export function ProxyRuleContextMenu({
  rule,
  projectTeamId,
  apexToOrgDomainId,
  onViewLogs,
  onRetry,
  onDelete,
  children,
}: ProxyRuleContextMenuProps) {
  const navigate = useNavigate()
  const domainUrl = `https://${rule.domain}`
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
          <ContextMenuItem onSelect={() => onViewLogs(rule)}>
            <ContextMenuIcon icon={FileText} />
            View logs
          </ContextMenuItem>
        )}
        {canRetry && (
          <ContextMenuItem onSelect={() => onRetry(rule)}>
            <ContextMenuIcon icon={RefreshCw} />
            Retry
          </ContextMenuItem>
        )}
        <ContextMenuItem
          onSelect={handleOpenDnsRecords}
          disabled={!canOpenDnsRecords}
        >
          <ContextMenuIcon icon={Globe} />
          DNS Records
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            Copy
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem onSelect={() => copyToClipboard('ID', rule.$id)}>
              <ContextMenuIcon icon={Copy} />
              Copy ID
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Domain', rule.domain)}
            >
              <ContextMenuIcon icon={Copy} />
              Copy domain
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', domainUrl)}
            >
              <ContextMenuIcon icon={Link2} />
              Copy link
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard(
                  'JSON',
                  toPrettyJson({
                    id: rule.$id,
                    domain: rule.domain,
                    status: rule.status,
                    redirectUrl: rule.redirectUrl ?? null,
                  }),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              Copy as JSON
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(domainUrl)}>
          <ContextMenuIcon icon={ExternalLink} />
          Open in new tab
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(domainUrl)}>
          <ContextMenuIcon icon={Square} />
          Open in new window
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => onDelete(rule)}>
          <ContextMenuIcon icon={Trash2} />
          Delete
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
