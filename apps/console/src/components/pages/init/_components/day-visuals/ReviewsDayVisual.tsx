import { GitPullRequest, MessageSquareWarning } from 'lucide-react'
import { CoverCard, CoverLine, InitDayCover } from './InitDayCover'

/** The lines the finding points at, as a diff. */
const DIFF = ['while (!session) {', '  session = await retry(email)'] as const

/** Init 5 day 5: a review posted on a pull request, with its finding inline. */
export function ReviewsDayVisual() {
  return (
    <InitDayCover
      headline="Every pull request, reviewed"
      mockClassName="start-[47cqw] top-[11cqw] w-[49cqw]"
    >
      <CoverCard className="overflow-hidden">
        <div className="flex items-center gap-[0.6em] border-b border-white/8 px-[0.9em] py-[0.8em]">
          <GitPullRequest
            className="size-[1.3em] text-emerald-400"
            aria-hidden
          />
          <span className="font-medium text-white">Retry sign-in</span>
          <span className="font-mono text-[0.85em] text-white/50">#482</span>
        </div>
        <div className="p-[0.8em]">
          <div className="overflow-hidden rounded-[0.5em] border border-white/8 font-mono text-[0.9em]">
            <p className="border-b border-white/8 bg-white/5 px-[0.7em] py-[0.3em] text-white/55">
              src/auth/sign-in.ts
            </p>
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
            <p className="flex items-center gap-[0.5em] font-medium text-white">
              <MessageSquareWarning
                className="size-[1.1em] shrink-0 text-[var(--brand-cta)]"
                aria-hidden
              />
              Appwrite Reviews
              <span className="ms-auto rounded-[0.3em] bg-white/10 px-[0.4em] text-[0.75em] uppercase tracking-wider text-white/70">
                Major
              </span>
            </p>
            <p className="mt-[0.4em] text-white/75">
              This loop never stops on a wrong password.
            </p>
            <CoverLine className="mt-[0.6em] w-[55%]" />
          </div>
        </div>
      </CoverCard>
    </InitDayCover>
  )
}
