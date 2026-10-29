import { Hash } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { PartnerPage } from '@/components/pages/partners/$partnerId/PageShell'
import { RESOURCE_CARD_PADDED_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { useOrganizationById } from '@/lib/react-query/hooks'
import type { PartnerAwareOrganization } from '@/lib/partners'
import { cn } from '@/lib/utils'

function SlackIcon({ className }: { className?: string }) {
  return <img src="/icons/slack.svg" alt="" className={className} />
}

export function PartnerSettings({ partnerId }: { partnerId: string }) {
  const { organization } = useOrganizationById(partnerId)
  const partner = organization as PartnerAwareOrganization | null
  const slackUrl = partner?.programSlackChannelUrl
  const slackName = partner?.programSlackChannelName

  return (
    <PartnerPage title="Settings">
      {slackUrl ? (
        <div className={cn(RESOURCE_CARD_PADDED_CLASSNAME, 'max-w-lg')}>
          <p className="text-[15px] font-semibold text-foreground">
            Slack channel
          </p>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {slackName
              ? `Shared Slack: ${slackName}`
              : 'Shared Slack channel with Appwrite.'}
          </p>
          <Button asChild size="sm" variant="outline" className="mt-4">
            <a href={slackUrl} target="_blank" rel="noreferrer">
              <Hash className="h-3.5 w-3.5" />
              {slackName ? slackName.replace(/^#/, '') : 'Open Slack'}
            </a>
          </Button>
        </div>
      ) : (
        <EmptyState
          icon={SlackIcon}
          title="No shared Slack channel"
          description="When Appwrite assigns a channel to this partner organization, it will show up here."
          isEmpty
          variant="card"
        />
      )}
    </PartnerPage>
  )
}
