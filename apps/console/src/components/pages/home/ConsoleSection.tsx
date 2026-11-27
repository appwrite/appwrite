import { Link } from '@tanstack/react-router'
import {
  ChartNoAxesColumn,
  ChevronRight,
  KeyRound,
  LayoutGrid,
  type LucideIcon,
} from 'lucide-react'
import { SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useI18n } from '@/lib/i18n'
import { useT } from '@/lib/i18n/translate'

/** Bump with the hero screenshots so caches refetch. */
const CONSOLE_IMAGE_CACHE_BUST = '20260729'
const CONSOLE_LIGHT_SRC = `/images/heroes/console-app-light.avif?v=${CONSOLE_IMAGE_CACHE_BUST}`
const CONSOLE_DARK_SRC = `/images/heroes/console-app-dark.avif?v=${CONSOLE_IMAGE_CACHE_BUST}`

const FEATURES: { icon: LucideIcon; title: string; description: string }[] = [
  {
    icon: LayoutGrid,
    title: 'Every service in one place',
    description:
      'Auth, Databases, Storage, Functions, Messaging, and Sites, side by side in every project.',
  },
  {
    icon: ChartNoAxesColumn,
    title: 'Usage at a glance',
    description:
      'Requests, bandwidth, storage, and executions for every project and organization.',
  },
  {
    icon: KeyRound,
    title: 'Keys and roles',
    description:
      'Create scoped API keys and give every teammate the right access.',
  },
]

/**
 * The Console, moved out of the factory hero: the control room for
 * everything the line ships.
 */
export function ConsoleSection() {
  const t = useT()
  const { catalog } = useI18n()
  const homeCopy = catalog.website.home

  return (
    <section className="relative isolate overflow-hidden border-t border-border bg-background pt-16 pb-4 sm:pt-24">
      <SectionSoftLight tone="purple" position="right" align="top" />
      <div className="relative z-[1] mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className="mx-auto max-w-3xl text-center">
          <p className="text-[13px] font-medium text-[var(--brand-cta)]">
            {t('The Appwrite Console')}
          </p>
          <h2 className="font-aeonik-pro mt-3 text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[48px]">
            {t('One control room for the whole factory')}
            <span aria-hidden="true" className="heading-cursor" />
          </h2>
          <p className="mx-auto mt-5 max-w-2xl text-balance text-[14px] leading-6 text-muted-foreground sm:text-[15px] sm:leading-7">
            {t(
              'Every project, database, function, and deployment in one place. Watch usage, manage keys and teams, and ship without switching tools.',
            )}
          </p>
          <div className="mt-7 flex justify-center">
            <Button
              variant="outline"
              size="lg"
              className="h-10 text-[14px]"
              asChild
            >
              <Link
                to="/sign-up"
                search={{ redirect: '/' }}
                {...analyticsAttrs('home-console-open')}
              >
                {t('Open the Console')}
              </Link>
            </Button>
          </div>
        </div>

        <div className="mx-auto mt-12 grid max-w-5xl gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-3">
          {FEATURES.map((feature) => {
            const Icon = feature.icon
            return (
              <div
                key={feature.title}
                className="flex gap-3 bg-background p-4 sm:p-5"
              >
                <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                  <Icon
                    className="size-3.5 text-[var(--brand-cta)]"
                    aria-hidden
                  />
                </span>
                <div className="text-start">
                  <h3 className="text-[14px] font-semibold text-foreground">
                    {t(feature.title)}
                  </h3>
                  <p className="mt-1 text-pretty text-[13px] leading-5 text-muted-foreground">
                    {t(feature.description)}
                  </p>
                </div>
              </div>
            )
          })}
        </div>

        <div className="console-rise relative mx-auto mt-12 w-full max-w-[min(100%,84rem)] sm:mt-14">
          {/* A wide brand glow behind the frame: pink on one side, purple on the other. */}
          <div
            className="pointer-events-none absolute -inset-x-[30%] -top-[30%] bottom-[-10%] -z-10"
            aria-hidden
          >
            <div className="absolute inset-y-0 start-0 w-[70%] bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.22)_0%,rgba(253,54,110,0.08)_38%,transparent_70%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.16)_0%,rgba(253,54,110,0.05)_38%,transparent_70%)]" />
            <div className="absolute inset-y-0 end-0 w-[70%] bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.2)_0%,rgba(124,103,254,0.07)_38%,transparent_70%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.15)_0%,rgba(124,103,254,0.05)_38%,transparent_70%)]" />
          </div>
          <div className="relative isolate flex w-full flex-col overflow-hidden rounded-t-[20px] border-x-2 border-t-2 border-b-0 border-muted-foreground/8 bg-muted-foreground/[0.035] px-1.5 pb-0 pt-1 [mask-image:linear-gradient(to_bottom,#000_30%,rgba(0,0,0,0.55)_62%,transparent_96%)] sm:rounded-t-[28px] sm:px-4 dark:border-muted/30 dark:bg-muted/10">
            <div className="relative z-10 flex h-8 shrink-0 items-center gap-2 text-start sm:h-10">
              <div
                className="ms-1.5 flex items-center gap-1.5 sm:ms-2"
                aria-hidden
              >
                <span className="size-2 rounded-full bg-muted-foreground/30 sm:size-2.5" />
                <span className="size-2 rounded-full bg-muted-foreground/30 sm:size-2.5" />
                <span className="size-2 rounded-full bg-muted-foreground/30 sm:size-2.5" />
              </div>
              <div className="ms-1.5 flex min-w-0 items-center gap-1.5 pe-2 text-[11px] text-muted-foreground sm:ms-2 sm:pe-4 sm:text-[12px]">
                <span className="font-medium text-foreground">
                  {homeCopy.heroPreviewWorkspace}
                </span>
                <ChevronRight className="size-3 rtl:-scale-x-100" />
                <span className="truncate">
                  {homeCopy.heroPreviewOrganization}
                </span>
                <ChevronRight className="size-3 rtl:-scale-x-100" />
                <span className="truncate">{homeCopy.heroPreviewProject}</span>
              </div>
            </div>
            <div className="relative z-10 aspect-[148/65] w-full overflow-hidden">
              <img
                src={CONSOLE_LIGHT_SRC}
                alt={homeCopy.heroImageAlt}
                width={1920}
                height={1234}
                loading="lazy"
                decoding="async"
                className="block h-full w-full rounded-t-md object-cover object-top opacity-90 dark:hidden sm:rounded-t-lg"
              />
              <img
                src={CONSOLE_DARK_SRC}
                alt={homeCopy.heroImageAlt}
                width={1920}
                height={1234}
                loading="lazy"
                decoding="async"
                className="hidden h-full w-full rounded-t-md object-cover object-top opacity-90 dark:block sm:rounded-t-lg"
              />
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}
