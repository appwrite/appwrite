import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { ExternalLink, Hash, Headphones, Mail } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { RESOURCE_CARD_PADDED_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { getSupportHoursInLocalTime } from '@/lib/support'
import { APPWRITE_SUPPORT_EMAIL } from '@/lib/utils/error-formatting'
import { PARTNER_SLACK_TEAM } from '@/lib/partners'
import { cn } from '@/lib/utils'

function PartnerSlackChannel({
  slackUrl,
  slackName,
}: {
  slackUrl?: string | null
  slackName?: string | null
}) {
  const channelLabel = slackName || 'Slack channel'

  return (
    <div className="grid grid-cols-[2.5rem_minmax(0,1fr)] gap-x-3 gap-y-4">
      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
        <img src="/icons/slack.svg" alt="" className="h-5 w-5" />
      </div>
      <div className="min-w-0">
        <h3 className="text-[14px] font-medium text-foreground">
          Slack channel
        </h3>
        <p className="mt-0.5 text-[13px] text-muted-foreground">
          {slackUrl
            ? 'Your private channel with the Appwrite partner team.'
            : 'When Appwrite assigns a channel, it will show up here.'}
        </p>
      </div>
      <div
        className="col-start-2 flex -space-x-2"
        aria-label="Appwrite team members"
      >
        {PARTNER_SLACK_TEAM.map((person, index) => (
          <img
            key={person.name}
            src={person.src}
            alt={person.name}
            title={person.name}
            className="h-8 w-8 rounded-full object-cover ring-2 ring-card"
            style={{ zIndex: PARTNER_SLACK_TEAM.length - index }}
          />
        ))}
      </div>
      {slackUrl ? (
        <Button asChild variant="outline" size="sm" className="col-start-2 w-fit">
          <a href={slackUrl} target="_blank" rel="noopener noreferrer">
            <Hash className="h-3.5 w-3.5" />
            {channelLabel.replace(/^#/, '')}
            <ExternalLink className="h-3 w-3 text-muted-foreground" />
          </a>
        </Button>
      ) : null}
    </div>
  )
}

type PartnerSupportCardsProps = {
  partnerId: string
  slackUrl?: string | null
  slackName?: string | null
}

export function PartnerSupportCards({
  partnerId,
  slackUrl,
  slackName,
}: PartnerSupportCardsProps) {
  const [hours] = useState(() => getSupportHoursInLocalTime())

  return (
    <div className="mt-8 grid min-w-0 gap-3 @[640px]:grid-cols-2">
      <div className={cn(RESOURCE_CARD_PADDED_CLASSNAME, 'flex flex-col')}>
        <PartnerSlackChannel slackUrl={slackUrl} slackName={slackName} />
      </div>

      <div className={cn(RESOURCE_CARD_PADDED_CLASSNAME, 'flex flex-col')}>
        <div className="flex items-start gap-3">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <Headphones className="h-5 w-5" />
          </div>
          <div className="min-w-0">
            <h3 className="text-[14px] font-medium text-foreground">
              Priority support
            </h3>
            <p className="mt-0.5 text-[13px] text-muted-foreground">
              Open a ticket for production issues, billing, and client
              incidents. Slack is monitored on weekdays during support hours.
            </p>
          </div>
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          <Button asChild size="sm">
            <Link
              to="/organizations/$orgId/support"
              params={{ orgId: partnerId }}
              target="_blank"
              rel="noopener noreferrer"
            >
              Contact support
            </Link>
          </Button>
          <Button asChild variant="outline" size="sm">
            <a href={`mailto:${APPWRITE_SUPPORT_EMAIL}`}>
              <Mail className="h-4 w-4" />
              {APPWRITE_SUPPORT_EMAIL}
            </a>
          </Button>
        </div>
        <p className="mt-4 text-[12px] text-muted-foreground">
          Support hours: Mon–Fri {hours.startLocal} – {hours.endLocal} (
          {hours.timezone})
          {hours.isOpen ? ' · Online now' : ''}
        </p>
      </div>
    </div>
  )
}
