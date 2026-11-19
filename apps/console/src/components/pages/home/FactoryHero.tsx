import { Link } from '@tanstack/react-router'
import { ArrowRight, Megaphone } from 'lucide-react'
import { FactoryStage } from '@/components/pages/home/FactoryStage'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useI18n } from '@/lib/i18n'
import { useT } from '@/lib/i18n/translate'

/**
 * `/home1` hero: copy on the start side, the software factory filling the
 * rest of the first screen (behind the copy on large screens, below it on
 * small ones).
 */
export function FactoryHero() {
  const t = useT()
  const { catalog } = useI18n()
  const homeCopy = catalog.website.home

  return (
    <section className="relative isolate flex flex-col overflow-hidden border-b border-border bg-background lg:block lg:h-[clamp(720px,calc(100dvh-4rem),960px)]">
      <HomeSoftLights />

      <div className="relative z-[1] mx-auto w-full max-w-7xl px-4 pt-12 sm:px-6 sm:pt-16 lg:pointer-events-none lg:px-8 lg:pt-[clamp(3.5rem,7dvh,6rem)]">
        {/* On large screens the scene runs under the copy: only the copy itself takes the pointer, and the machines' holograms keep clear of the title and description (`data-factory-copy`), standing in front of whatever else they meet. */}
        <div className="max-w-xl xl:max-w-2xl">
          <Button
            variant="outline"
            size="sm"
            className="group h-7 rounded-full bg-background/70 px-3 text-[12px] backdrop-blur-sm lg:pointer-events-auto"
            asChild
          >
            <MarketingSiteLink href="/init">
              <Megaphone className="size-3.5" />
              <span className="text-[var(--brand-cta)]">
                {homeCopy.announcementNew}
              </span>
              {homeCopy.announcementText}
              <ArrowRight className="size-3.5 transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
            </MarketingSiteLink>
          </Button>

          {/* Sized from the viewport so "Your software factory" always holds one line. */}
          <h1
            data-factory-copy
            className="font-aeonik-pro mt-6 w-max lg:pointer-events-auto max-w-[calc(100vw-2rem)] pb-3 text-start text-[clamp(32px,8.4vw,48px)] font-normal leading-[1.04] tracking-[-0.025em] text-foreground sm:text-[clamp(48px,6.6vw,64px)] lg:text-[clamp(56px,5.3vw,80px)]"
          >
            {t('Your software factory')}
            <br />
            {t('starts here')}
            <span className="text-[var(--brand-cta)]">_</span>
          </h1>

          <p
            data-factory-copy
            className="mt-5 max-w-lg lg:pointer-events-auto text-pretty text-[15px] leading-6 text-muted-foreground sm:text-[16px] sm:leading-7"
          >
            {homeCopy.heroDescriptionLineOne} {homeCopy.heroDescriptionLineTwo}
          </p>

          <div className="mt-7 flex flex-wrap items-center gap-2">
            <Button
              variant="brandCta"
              size="lg"
              className="h-10 text-[14px] lg:pointer-events-auto"
              asChild
            >
              <Link
                to="/sign-up"
                search={{ redirect: '/' }}
                {...analyticsAttrs('home-start-project')}
              >
                {homeCopy.startProject}
              </Link>
            </Button>
            <Button
              variant="outline"
              size="lg"
              className="h-10 bg-background/70 text-[14px] backdrop-blur-sm lg:pointer-events-auto"
              asChild
            >
              <MarketingSiteLink
                href="/docs"
                {...analyticsAttrs('home-view-docs')}
              >
                {homeCopy.viewDocs}
              </MarketingSiteLink>
            </Button>
          </div>
        </div>
      </div>

      <FactoryStage className="mt-4 h-[clamp(400px,70vw,560px)] sm:mt-6 lg:absolute lg:inset-0 lg:z-0 lg:mt-0 lg:h-auto" />
      {/* Over the copy: a hologram that is on is drawn here, so it stands in front of the copy (see the engine's `setFront`). */}
      <canvas
        data-factory-front
        aria-hidden
        className="factory-stage pointer-events-none absolute inset-0 z-[2] hidden h-full w-full lg:block"
      />
    </section>
  )
}
