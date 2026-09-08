import { Link } from '@tanstack/react-router'
import { Github } from 'lucide-react'
import { SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import {
  MarketingCtaSection,
  MarketingHeroSection,
  MarketingInvolvementCards,
  MarketingSectionHeading,
  MarketingStatGrid,
  marketingSplitLayoutClassName,
} from '@/components/pages/marketing/MarketingSections'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { CommunityGitHubIssue } from '@/lib/community/github-issues'
import {
  communityContributors,
  communityCta,
  communityGetInvolved,
  communityHelpCards,
  communityHero,
  communityPlatforms,
  communityProjects,
  communityShowcase,
} from '@/lib/community/content'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'
import { useT } from '@/lib/i18n/translate'

type ViewProps = {
  issues: CommunityGitHubIssue[]
}

const METRICS = [
  { value: MARKETING_SOCIAL_STATS.github.stat, label: 'GitHub Stars' },
  { value: MARKETING_SOCIAL_STATS.github.pullRequests, label: 'Pull Requests' },
  { value: MARKETING_SOCIAL_STATS.github.commits, label: 'Commits' },
  { value: MARKETING_SOCIAL_STATS.github.issues, label: 'Issues' },
  { value: MARKETING_SOCIAL_STATS.github.openIssues, label: 'Open Issues' },
  { value: MARKETING_SOCIAL_STATS.github.closedIssues, label: 'Closed Issues' },
  { value: MARKETING_SOCIAL_STATS.github.forks, label: 'Forks' },
  { value: MARKETING_SOCIAL_STATS.github.contributors, label: 'Contributors' },
] as const

function SocialIconMask({
  icon,
  label,
  className = 'h-8 w-8',
}: {
  icon: string
  label: string
  className?: string
}) {
  return (
    <span
      className={`inline-block shrink-0 bg-foreground ${className}`}
      style={{
        WebkitMaskImage: `url(${icon})`,
        maskImage: `url(${icon})`,
        WebkitMaskSize: 'contain',
        maskSize: 'contain',
        WebkitMaskRepeat: 'no-repeat',
        maskRepeat: 'no-repeat',
        WebkitMaskPosition: 'center',
        maskPosition: 'center',
      }}
      role="img"
      aria-label={label}
    />
  )
}

const COMMUNITY_PLATFORM_CARDS = [
  {
    label: 'Discord',
    href: MARKETING_SOCIAL_STATS.discord.link,
    icon: '/icons/discord-simple.svg',
    stat: MARKETING_SOCIAL_STATS.discord.stat,
    statLabel: 'members',
  },
  {
    label: 'X',
    href: MARKETING_SOCIAL_STATS.twitter.link,
    icon: '/icons/x.svg',
    stat: MARKETING_SOCIAL_STATS.twitter.stat,
    statLabel: 'followers',
  },
  {
    label: 'GitHub',
    href: MARKETING_SOCIAL_STATS.github.link,
    icon: '/icons/github-circle.svg',
    stat: MARKETING_SOCIAL_STATS.github.stat,
    statLabel: 'stargazers',
  },
  {
    label: 'YouTube',
    href: MARKETING_SOCIAL_STATS.youtube.link,
    icon: '/icons/youtube.svg',
    stat: MARKETING_SOCIAL_STATS.youtube.stat,
    statLabel: 'subscribers',
  },
] as const

export function View({ issues }: ViewProps) {
  const t = useT()
  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        eyebrow={communityHero.eyebrow}
        title={communityHero.title}
        description={communityHero.description}
        align="left"
      >
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <a
            href={MARKETING_SOCIAL_STATS.discord.link}
            target="_blank"
            rel="noopener noreferrer"
          >
            {t('Join our Discord')}
          </a>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <a
            href={MARKETING_SOCIAL_STATS.github.link}
            target="_blank"
            rel="noopener noreferrer"
          >
            <Github className="me-1.5 h-4 w-4" />
            {MARKETING_SOCIAL_STATS.github.stat}
          </a>
        </Button>
      </MarketingHeroSection>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingStatGrid items={[...METRICS]} />
        </div>
      </section>

      <section className="relative border-b border-border bg-muted/20">
        <SectionSoftLight tone="purple" />
        <div className="relative z-[1] mx-auto max-w-3xl px-4 py-16 text-center sm:px-6 sm:py-20">
          <MarketingSectionHeading
            title={communityContributors.title}
            description={communityContributors.description}
            size="md"
          />
          <Button variant="outline" className="mt-8" asChild>
            <a
              href={communityContributors.contributorsUrl}
              target="_blank"
              rel="noopener noreferrer"
            >
              {t('View all contributors')}
            </a>
          </Button>
        </div>
      </section>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            align="left"
            title={communityGetInvolved.title}
            description={communityGetInvolved.description}
            size="md"
          />

          <div className="mt-10 overflow-hidden rounded-xl border border-border bg-card/50">
            <div className={marketingSplitLayoutClassName({ className: 'p-6 sm:p-8' })}>
              <div>
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t(communityGetInvolved.issuesTitle)}
                </h3>
                <p className="mt-2 text-[13px] leading-6 text-muted-foreground">
                  {t(communityGetInvolved.issuesDescription)}
                </p>
                <Button variant="outline" className="mt-6" asChild>
                  <a
                    href={communityGetInvolved.issuesUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <Github className="me-1.5 h-4 w-4" />
                    {t('View all Open Issues')}
                  </a>
                </Button>
              </div>

              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {t('Issue #')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {t('Title')}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {issues.map((issue) => (
                      <TableRow key={issue.number}>
                        <TableCell className="px-4 py-3 whitespace-nowrap">
                          <span className="text-[13px] text-muted-foreground">#{issue.number}</span>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <a
                            href={issue.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-[13px] font-medium link-neutral"
                          >
                            {issue.title}
                          </a>
                          <span className="ms-1 text-[12px] text-muted-foreground">
                            ({issue.repository})
                          </span>
                          {issue.tags.length > 0 ? (
                            <div className="mt-2 flex flex-wrap gap-2">
                              {issue.tags.map((tag) => (
                                <Badge key={tag} variant="info" className="text-[10px]">
                                  {tag}
                                </Badge>
                              ))}
                            </div>
                          ) : null}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          </div>

          <MarketingInvolvementCards
            className="mt-12"
            title={t('Other ways to help')}
            items={communityHelpCards.map((card) => ({
              ...card,
              external: false,
            }))}
          />
        </div>
      </section>

      <section className="border-b border-border bg-muted/10 py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={communityShowcase.title}
            description={communityShowcase.description}
            size="md"
          />
          <div className="mt-8 flex justify-center">
            <Button variant="outline" asChild>
              <a href={communityShowcase.href} target="_blank" rel="noopener noreferrer">
                {t('View all projects')}
              </a>
            </Button>
          </div>
          <ul className="mt-12 grid gap-4 md:grid-cols-3">
            {communityProjects.map((project) => (
              <li
                key={project.title}
                className="overflow-hidden rounded-xl border border-border bg-card/50"
              >
                <a
                  href={project.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="group block h-full transition-colors hover:bg-accent/15"
                >
                  <img
                    src={project.image}
                    alt=""
                    className="aspect-[16/10] w-full border-b border-border object-cover"
                    loading="lazy"
                  />
                  <div className="p-5">
                    <h3 className="text-[14px] font-semibold text-foreground">{project.title}</h3>
                    <p className="mt-2 text-[13px] leading-6 text-muted-foreground">
                      {t(project.description)}
                    </p>
                  </div>
                </a>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className={marketingSplitLayoutClassName()}>
            <MarketingSectionHeading
              align="left"
              size="md"
              title={communityPlatforms.title}
              description={communityPlatforms.description}
            />
            <ul className="grid gap-3 sm:grid-cols-2">
              {COMMUNITY_PLATFORM_CARDS.map((platform) => (
                <li key={platform.label}>
                  <a
                    href={platform.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex min-h-[160px] flex-col rounded-xl border border-border bg-card/50 p-5 transition-colors hover:bg-accent/15"
                  >
                    <SocialIconMask icon={platform.icon} label={platform.label} />
                    <p className="mt-auto font-aeonik-pro text-[18px] text-foreground">
                      {platform.stat} {t(platform.statLabel)}
                    </p>
                  </a>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </section>

      <MarketingCtaSection title={communityCta.title}>
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/sign-up" search={{ redirect: '/' }}>
            {t('Get started')}
          </Link>
        </Button>
      </MarketingCtaSection>
    </div>
  )
}
