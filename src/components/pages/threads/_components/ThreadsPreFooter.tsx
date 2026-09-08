import { Link } from '@tanstack/react-router'
import { MarketingCtaSection } from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'

export function ThreadsPreFooter() {
  return (
    <MarketingCtaSection title="Need support?">
      <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
        <a
          href={MARKETING_SOCIAL_STATS.discord.link}
          target="_blank"
          rel="noopener noreferrer"
        >
          Join Discord
        </a>
      </Button>
      <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
        <Link to="/pricing">Get premium support</Link>
      </Button>
    </MarketingCtaSection>
  )
}
