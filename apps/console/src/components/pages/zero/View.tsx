import { Link } from '@tanstack/react-router'
import { ChevronDown } from 'lucide-react'
import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import {
  ZERO_AGENT_PROMPT,
  ZERO_CLI_INSTALL,
  ZERO_COMMAND,
  zeroAllowances,
  zeroClaimSteps,
  zeroEntries,
  zeroFaqItems,
  zeroHero,
} from '@/lib/zero/marketing-content'
import { ZeroClock } from './_components/ZeroClock'
import { ZeroCommand } from './_components/ZeroCommand'
import { ZeroTerminal } from './_components/ZeroTerminal'

function SectionTitle({ children }: { children: string }) {
  const t = useT()
  return (
    <h2 className="font-aeonik-pro text-balance text-[32px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
      {t(children)}
      <span className="text-[var(--brand-cta)]">_</span>
    </h2>
  )
}

const agentSession: Array<{ text: string; tone?: 'user' | 'ok' | 'muted' }> = [
  { text: '› Build me a guestbook and put it online', tone: 'user' },
  { text: '' },
  { text: '● Run appwrite zero' },
  { text: '  Project 6721f0c4001e8b3a9d52 · 60 min to build', tone: 'muted' },
  { text: '● Create table messages (name, body)' },
  { text: '● Write src/App.tsx with the Appwrite Web SDK' },
  { text: '● appwrite push sites' },
  { text: '  Deployment ready in 38s', tone: 'muted' },
  { text: '' },
  { text: '✓ Live at https://guestbook-6721f.appwrite.network', tone: 'ok' },
  { text: '  Open it once with your owner link:', tone: 'muted' },
  { text: '  …/__appwrite/zero?token=…', tone: 'muted' },
  { text: '  Keep the project: cloud.appwrite.io/transfer?…', tone: 'muted' },
]

export function View() {
  const t = useT()

  return (
    <div className="relative overflow-x-hidden bg-background">
      <section className="relative isolate border-b border-border">
        <div className="mx-auto grid max-w-7xl grid-cols-1 gap-12 px-4 pb-20 pt-16 sm:px-6 sm:pt-24 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-center lg:gap-14 lg:pb-28">
          <div>
            <h1 className="font-aeonik-pro text-balance text-[40px] font-normal leading-[1.02] tracking-tight text-foreground sm:text-[56px] lg:text-[64px]">
              {t(zeroHero.title)}
              <span className="text-[var(--brand-cta)]">_</span>
            </h1>
            <p className="mt-6 max-w-xl text-[16px] leading-7 text-foreground sm:text-[18px]">
              {t(zeroHero.description)}
            </p>
            <ZeroCommand
              command={ZERO_COMMAND}
              analytics="zero-copy-command"
              className="mt-8 max-w-md"
            />
            <p className="mt-4 text-[14px] leading-6 text-muted-foreground">
              {t('No CLI yet?')}{' '}
              <a
                href="#ways-in"
                className="text-foreground underline underline-offset-4 hover:text-[var(--brand-cta)]"
              >
                {t('Use HTTP or MCP instead')}
              </a>
            </p>
          </div>
          <ZeroTerminal />
        </div>
      </section>

      <section className="border-b border-border py-20 sm:py-28">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-14">
            <SectionTitle>An hour to build, a day to keep it</SectionTitle>
            <p className="max-w-[60ch] text-[16px] leading-7 text-foreground lg:pt-2">
              {t(
                'Nothing to sign and nothing to configure. The project is real from the first second, and it waits for you to decide whether to keep it. The times below assume you start now; drag the clock to see each stage.',
              )}
            </p>
          </div>
          <div className="mt-14">
            <ZeroClock />
          </div>
        </div>
      </section>

      <section
        id="ways-in"
        className="scroll-mt-20 border-b border-border bg-muted/20 py-20 sm:py-28"
      >
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className="max-w-2xl">
            <SectionTitle>Three ways in</SectionTitle>
            <p className="mt-5 max-w-[60ch] text-[16px] leading-7 text-foreground">
              {t(
                'Each one returns the same project: an endpoint, a project ID, an API key and a claim link.',
              )}
            </p>
          </div>

          <Tabs defaultValue="cli" className="mt-12">
            <TabsList className="h-10">
              {zeroEntries.map((entry) => (
                <TabsTrigger
                  key={entry.id}
                  value={entry.id}
                  className="px-4 text-[14px]"
                >
                  {entry.label}
                </TabsTrigger>
              ))}
            </TabsList>
            {zeroEntries.map((entry) => (
              <TabsContent key={entry.id} value={entry.id} className="mt-8">
                <div className="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-14">
                  <div>
                    <p className="text-[16px] leading-7 text-foreground">
                      {t(entry.summary)}
                    </p>
                    {entry.id === 'cli' ? (
                      <div className="mt-6">
                        <p className="text-[14px] text-muted-foreground">
                          {t('Install the CLI first if you do not have it:')}
                        </p>
                        <ZeroCommand
                          command={ZERO_CLI_INSTALL}
                          analytics="zero-copy-install"
                          className="mt-3"
                        />
                      </div>
                    ) : null}
                  </div>
                  <div className="min-w-0">
                    <ZeroCommand
                      command={entry.call}
                      analytics="zero-copy-entry"
                    />
                    <div className="mt-3 flex justify-center text-muted-foreground">
                      <ChevronDown className="size-4" aria-hidden />
                    </div>
                    <pre
                      dir="ltr"
                      data-ui
                      className="mt-3 overflow-x-auto rounded-lg bg-[#1d1d21] px-4 py-4 font-mono text-[13px] leading-[1.7] text-[#ededf0] ring-1 ring-white/10 sm:px-5"
                    >
                      <code>{entry.response}</code>
                    </pre>
                  </div>
                </div>
              </TabsContent>
            ))}
          </Tabs>
        </div>
      </section>

      <section className="border-b border-border py-20 sm:py-28">
        <div className="mx-auto grid max-w-7xl grid-cols-1 gap-12 px-4 sm:px-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-14">
          <div>
            <SectionTitle>Made for agents</SectionTitle>
            <p className="mt-5 max-w-[60ch] text-[16px] leading-7 text-foreground">
              {t(
                'If your agent can run a command, it can get its own backend. No key to paste, no dashboard to click through. It builds, then hands you the owner link and the claim link.',
              )}
            </p>
            <div className="mt-8 rounded-xl bg-muted/40 p-5">
              <div className="flex items-center justify-between gap-3">
                <p className="text-[14px] text-foreground">
                  {t('Paste this into Claude Code, Codex or Cursor')}
                </p>
                <div {...analyticsAttrs('zero-copy-prompt')}>
                  <CodeSnippetCopyButton content={ZERO_AGENT_PROMPT} />
                </div>
              </div>
              <pre
                dir="ltr"
                className="mt-3 whitespace-pre-wrap font-mono text-[13px] leading-6 text-foreground"
              >
                <code>{ZERO_AGENT_PROMPT}</code>
              </pre>
            </div>
          </div>
          <pre
            dir="ltr"
            data-ui
            className="self-start overflow-x-auto rounded-xl bg-[#1d1d21] px-5 py-5 font-mono text-[13px] leading-[1.75] text-[#ededf0] ring-1 ring-white/10"
          >
            <code>
              {agentSession.map((line, index) => (
                <span
                  key={index}
                  className={
                    line.tone === 'user'
                      ? 'text-white'
                      : line.tone === 'ok'
                        ? 'text-[#7ee2b8]'
                        : line.tone === 'muted'
                          ? 'text-white/55'
                          : 'text-white/85'
                  }
                >
                  {line.text}
                  {'\n'}
                </span>
              ))}
            </code>
          </pre>
        </div>
      </section>

      <section className="border-b border-border bg-muted/20 py-20 sm:py-28">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-14">
            <SectionTitle>The whole backend</SectionTitle>
            <p className="max-w-[60ch] text-[16px] leading-7 text-foreground lg:pt-2">
              {t(
                'Not a sandbox with half the features switched off. A Zero project is a normal Appwrite project on a capped plan, so what you build is what you keep.',
              )}
            </p>
          </div>
          <dl className="mt-14">
            {zeroAllowances.map((item) => (
              <div
                key={item.name}
                className="grid grid-cols-1 gap-2 border-t border-border py-6 sm:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] sm:items-baseline sm:gap-14"
              >
                <dt className="font-aeonik-pro text-[32px] leading-none tracking-tight text-foreground sm:text-[44px]">
                  {t(item.name)}
                </dt>
                <dd className="text-[16px] leading-7 text-foreground">
                  {t(item.detail)}
                </dd>
              </div>
            ))}
            <div className="grid grid-cols-1 gap-2 border-t border-border py-6 sm:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] sm:items-baseline sm:gap-14">
              <dt className="font-aeonik-pro text-[32px] leading-none tracking-tight text-muted-foreground sm:text-[44px]">
                {t('Messaging')}
              </dt>
              <dd className="text-[16px] leading-7 text-muted-foreground">
                {t('Email, SMS and push work once you claim the project.')}
              </dd>
            </div>
          </dl>
        </div>
      </section>

      <section className="border-b border-border py-20 sm:py-28">
        <div className="mx-auto grid max-w-7xl grid-cols-1 gap-12 px-4 sm:px-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-center lg:gap-14">
          <div>
            <SectionTitle>Keep what you built</SectionTitle>
            <ol className="mt-8 space-y-6">
              {zeroClaimSteps.map((step) => (
                <li key={step.title}>
                  <h3 className="text-[18px] font-medium text-foreground">
                    {t(step.title)}
                  </h3>
                  <p className="mt-1.5 text-[16px] leading-7 text-foreground">
                    {t(step.description)}
                  </p>
                </li>
              ))}
            </ol>
          </div>
          <div className="flex justify-center rounded-xl bg-muted/30 px-4 py-12 sm:py-16">
            <div
              data-ui
              className="w-full max-w-[420px] rounded-xl border border-border bg-background p-6 shadow-lg"
            >
              <h3 className="text-[16px] font-semibold text-foreground">
                {t('Claim your project')}
              </h3>
              <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">
                {t(
                  'Create an account to keep this project, with everything already built in it.',
                )}
              </p>
              <div className="mt-5 rounded-lg border border-border px-3 py-2.5">
                <p className="text-[13px] font-medium text-foreground">
                  {t('Zero project')}
                </p>
                <p className="mt-0.5 font-mono text-[12px] text-muted-foreground">
                  6721f0c4001e8b3a9d52 · fra
                </p>
              </div>
              <label
                htmlFor="zero-claim-organization"
                className="mt-4 block text-[13px] font-medium text-foreground"
              >
                {t('Target organization')}
              </label>
              <select
                id="zero-claim-organization"
                className="mt-1.5 h-9 w-full rounded-md border border-border bg-background px-3 text-[13px] text-foreground"
                defaultValue="personal"
              >
                <option value="personal">{t('Personal projects')}</option>
                <option value="new">{t('Create a free organization')}</option>
              </select>
              <div className="mt-6 flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" tabIndex={-1}>
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  variant="brandCta"
                  size="sm"
                  tabIndex={-1}
                >
                  {t('Claim project')}
                </Button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <MarketingFaqSection
        items={zeroFaqItems}
        title="Questions"
        description="What happens to the project, the clock and the limits."
      />

      <section className="bg-[var(--brand-cta)] text-[#19191c]">
        <div className="mx-auto grid max-w-7xl grid-cols-1 gap-10 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-end lg:gap-14">
          <div>
            <h2 className="font-aeonik-pro text-[48px] font-normal leading-[0.95] tracking-tight sm:text-[72px]">
              {t('Start from zero')}
              <span>_</span>
            </h2>
            <p className="mt-6 max-w-md text-[18px] leading-7">
              {t(
                'One command, a real project, and an hour on the clock. Claim it if you like it.',
              )}
            </p>
          </div>
          <div>
            <div className="flex items-start gap-2 rounded-lg bg-[#1d1d21] py-2 ps-4 pe-2 text-[#ededf0]">
              <pre
                dir="ltr"
                className="min-w-0 flex-1 overflow-x-auto py-1 font-mono text-[16px] leading-6"
              >
                <code>
                  <span className="select-none text-white/50">$ </span>
                  {ZERO_COMMAND}
                </code>
              </pre>
              <div
                className="pt-0.5 [&_button]:text-white/75 [&_button:hover]:bg-white/10 [&_button:hover]:text-white"
                {...analyticsAttrs('zero-copy-command')}
              >
                <CodeSnippetCopyButton content={ZERO_COMMAND} />
              </div>
            </div>
            <p className="mt-4 text-[16px] leading-6">
              {t('Would rather have an account from the start?')}{' '}
              <Link
                to="/sign-up"
                className="underline underline-offset-4"
                {...analyticsAttrs('zero-sign-up')}
              >
                {t('Sign up for free')}
              </Link>
            </p>
          </div>
        </div>
      </section>
    </div>
  )
}
