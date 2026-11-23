import { Calendar, Mail, MessageSquare, ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

interface SuccessManager {
  name: string
  title: string
  email: string
  avatar: string
  calendlyUrl: string
  slackChannelUrl: string
  slackChannelName: string
}

interface EnterpriseSuccessManagerProps {
  manager?: SuccessManager
}

// Default mock data for demonstration
const defaultManager: SuccessManager = {
  name: 'Sarah Chen',
  title: 'Customer success manager',
  email: 'sarah.chen@appwrite.io',
  avatar:
    'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=128&h=128&fit=crop&crop=face',
  calendlyUrl: 'https://calendly.com/appwrite/enterprise-success',
  slackChannelUrl: 'https://appwrite-community.slack.com/archives/C0123456789',
  slackChannelName: '#acme-corp-support',
}

export function EnterpriseSuccessManager({
  manager = defaultManager,
}: EnterpriseSuccessManagerProps) {
  const t = useT()
  return (
    <div className="mt-8 rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4 border-b border-border">
        <div className="flex items-center gap-2">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Your success team')}
          </h3>
          <span className="rounded-full bg-purple-500/20 px-2 py-0.5 text-[10px] font-medium text-purple-600 dark:text-purple-300">
            {t('Custom')}
          </span>
        </div>
        <p className="mt-1 text-[13px] text-muted-foreground">
          {t('Dedicated support for your organization')}
        </p>
      </div>

      {/* Content */}
      <div className="p-6">
        {/* Manager Profile */}
        <div className="flex items-start gap-4">
          {/* Avatar */}
          <div className="relative shrink-0">
            <img
              src={manager.avatar}
              alt={manager.name}
              className="h-14 w-14 rounded-full object-cover ring-2 ring-background"
            />
            {/* Online indicator */}
            <div className="absolute bottom-0 end-0 h-3.5 w-3.5 rounded-full border-2 border-background bg-emerald-500" />
          </div>

          {/* Info */}
          <div className="flex-1 min-w-0">
            <h4 className="text-[14px] font-medium text-foreground">
              {manager.name}
            </h4>
            <p className="text-[13px] text-muted-foreground">{manager.title}</p>
            <a
              href={`mailto:${manager.email}`}
              className="mt-1 inline-flex items-center gap-1.5 link-neutral text-[12px]"
            >
              <Mail className="h-3 w-3" />
              {manager.email}
            </a>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="mt-5 flex flex-wrap gap-3">
          {/* Schedule Meeting */}
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-2 text-[13px] border-border hover:bg-accent"
            asChild
          >
            <a
              href={manager.calendlyUrl}
              target="_blank"
              rel="noopener noreferrer"
            >
              <Calendar className="h-4 w-4" />
              {t('Schedule a meeting')}
              <ExternalLink className="h-3 w-3 text-muted-foreground" />
            </a>
          </Button>

          {/* Slack Channel */}
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-2 text-[13px] border-border hover:bg-accent"
            asChild
          >
            <a
              href={manager.slackChannelUrl}
              target="_blank"
              rel="noopener noreferrer"
            >
              <MessageSquare className="h-4 w-4" />
              {manager.slackChannelName}
              <ExternalLink className="h-3 w-3 text-muted-foreground" />
            </a>
          </Button>
        </div>

        {/* Help Text */}
        <p className="mt-4 text-[12px] text-muted-foreground">
          {t(
            'Your dedicated Slack channel is monitored during business hours (9am-6pm EST). For urgent issues, please use our',
          )}{' '}
          <a href="#" className="link-neutral">
            {t('priority support portal')}
          </a>
          .
        </p>
      </div>
    </div>
  )
}
