import { GitPullRequest, MessageSquareWarning } from 'lucide-react'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import { CoverCard, InitDayCover } from './InitDayCover'

/** The lines the finding points at, as a diff. */
const DIFF = ['while (!session) {', '  retry(email)'] as const

/** Git providers Appwrite connects to; Reviews works on repositories on any of them. */
const GIT_PROVIDERS = [
  { name: 'GitHub', src: '/icons/github.svg' },
  { name: 'GitLab', src: '/icons/gitlab.svg' },
  { name: 'Bitbucket', src: '/icons/bitbucket.svg' },
  { name: 'Gitea', src: '/icons/gitea.svg' },
] as const

/**
 * Init 5 day 5: a review posted on a pull request, with its finding inline,
 * and where Reviews works: connected Sites, Functions and any repository on a
 * supported Git provider.
 */
export function ReviewsDayVisual() {
  const SitesIcon = PRODUCT_REGISTRY.sites.icon
  const FunctionsIcon = PRODUCT_REGISTRY.functions.icon
  const sources = [
    { name: 'Sites', Icon: SitesIcon },
    { name: 'Functions', Icon: FunctionsIcon },
  ]

  return (
    <InitDayCover
      headline="Every pull request, reviewed"
      mockClassName="start-[47cqw] top-[6cqw] w-[49cqw]"
    >
      <div className="mb-[0.7em] flex flex-wrap items-center gap-[0.4em]">
        {sources.map(({ name, Icon }) => (
          <span
            key={name}
            className="flex items-center gap-[0.35em] rounded-[0.4em] border border-white/12 bg-white/5 px-[0.5em] py-[0.2em] text-[0.9em] text-white/85"
          >
            <Icon className="size-[1em] text-[var(--brand-cta)]" aria-hidden />
            {name}
          </span>
        ))}
        <span className="flex items-center gap-[0.45em] rounded-[0.4em] border border-white/12 bg-white/5 px-[0.5em] py-[0.3em]">
          {GIT_PROVIDERS.map((provider) => (
            <img
              key={provider.name}
              src={provider.src}
              alt=""
              className="size-[1em] opacity-85 brightness-0 invert"
              aria-hidden
            />
          ))}
        </span>
      </div>

      <CoverCard className="overflow-hidden">
        <div className="flex items-center gap-[0.6em] border-b border-white/8 px-[0.9em] py-[0.7em]">
          <GitPullRequest
            className="size-[1.3em] text-emerald-400"
            aria-hidden
          />
          <span className="font-medium text-white">Retry sign-in</span>
          <span className="font-mono text-[0.85em] text-white/50">#482</span>
        </div>
        <div className="p-[0.8em]">
          <div className="overflow-hidden rounded-[0.5em] border border-white/8 font-mono text-[0.9em]">
            {DIFF.map((code) => (
              <p
                key={code}
                className="flex gap-[0.6em] bg-emerald-500/12 px-[0.7em] py-[0.15em] text-white/90"
              >
                <span className="text-emerald-400">+</span>
                <span className="whitespace-pre">{code}</span>
              </p>
            ))}
          </div>
          <div className="mt-[0.7em] rounded-[0.6em] border border-[var(--brand-cta)] bg-[rgb(253_54_110/0.1)] px-[0.8em] py-[0.6em]">
            <p className="flex items-center gap-[0.5em] whitespace-nowrap font-medium text-white">
              <MessageSquareWarning
                className="size-[1.1em] shrink-0 text-[var(--brand-cta)]"
                aria-hidden
              />
              Appwrite Reviews
            </p>
            <p className="mt-[0.4em] text-white/75">
              This loop never stops on a wrong password.
            </p>
          </div>
        </div>
      </CoverCard>
    </InitDayCover>
  )
}
