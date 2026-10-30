import { useEffect, useId, useState } from 'react'
import { CheckCircle2, Eye, Link2 } from 'lucide-react'
import {
  ArtIconMask,
  ArtPanel,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  generateAIChatDeeplink,
  getIDEById,
  openAIChatDeeplink,
  type IDEConfig,
} from '@/lib/config/ide'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  ZERO_AGENT_IDE_IDS,
  ZERO_AGENT_PROMPT,
  zeroAgents,
  type ZeroAgentExample,
} from '@/lib/zero/marketing-content'

const agentIdes = ZERO_AGENT_IDE_IDS.map((id) => getIDEById(id)).filter(
  (ide): ide is IDEConfig => Boolean(ide),
)

/** Waits for the visitor to stop typing before the session replays. */
function useSettled(value: string, delayMs: number) {
  const [settled, setSettled] = useState(value)
  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(value), delayMs)
    return () => window.clearTimeout(timer)
  }, [value, delayMs])
  return settled
}

/** The session an agent runs for one request, ending with the two links it hands back. */
function ZeroAgentSession({
  request,
  example,
}: {
  request: string
  example?: ZeroAgentExample
}) {
  const t = useT()
  const middle = example ?? zeroAgents.custom
  const steps = [
    zeroAgents.created,
    { label: middle.step, detail: middle.detail },
    zeroAgents.deployed,
  ]
  const links = [
    {
      id: 'owner',
      label: t('Owner link'),
      url: `${middle.site}-6721f.appwrite.network/__appwrite/zero?token=…`,
      icon: Eye,
    },
    {
      id: 'claim',
      label: t('Claim link'),
      url: zeroAgents.claimLink,
      icon: Link2,
    },
  ]

  return (
    <ArtWindow
      title={t('Agent session')}
      trailing={
        <code dir="ltr" className="font-mono text-[11px] text-muted-foreground">
          ~/{middle.site}
        </code>
      }
      className="product-hero-rise"
      style={riseStyle(150)}
    >
      <div className="flex justify-end">
        <p className="max-w-[85%] break-words rounded-xl rounded-ee-sm bg-muted px-3 py-1.5 text-[12px] leading-5 text-foreground">
          {request}
        </p>
      </div>

      <ol className="mt-4 space-y-2">
        {steps.map((step, index) => (
          <li
            key={step.label}
            className="product-hero-rise flex min-w-0 items-center gap-2"
            style={riseStyle(300 + index * 220)}
          >
            <CheckCircle2
              className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
              aria-hidden
            />
            <span className="min-w-0 truncate text-[12px] text-foreground">
              {t(step.label)}
            </span>
            <code
              dir="ltr"
              className="ms-auto max-w-[45%] shrink-0 truncate rounded border border-border bg-muted/40 px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
            >
              {step.detail}
            </code>
          </li>
        ))}
      </ol>

      <div
        className="product-hero-rise mt-4 border-t border-border pt-4"
        style={riseStyle(1000)}
      >
        <p className="text-[12px] leading-5 text-foreground">
          {t(zeroAgents.reply)}
        </p>
        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
          {links.map((link) => (
            <li
              key={link.id}
              className={cn(
                'flex min-w-0 items-center gap-2 rounded-lg border px-2.5 py-2',
                link.id === 'claim'
                  ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.06)]'
                  : 'border-border bg-muted/30',
              )}
            >
              <link.icon
                className={cn(
                  'size-3.5 shrink-0',
                  link.id === 'claim'
                    ? 'text-[var(--tone-ink)]'
                    : 'text-muted-foreground',
                )}
                aria-hidden
              />
              <div className="min-w-0">
                <p className="text-[11px] font-medium leading-4 text-foreground">
                  {link.label}
                </p>
                <code
                  dir="ltr"
                  className="block truncate text-start font-mono text-[10px] leading-4 text-muted-foreground"
                >
                  {link.url}
                </code>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </ArtWindow>
  )
}

/** A request the visitor can rewrite, the session it starts, and the agents it can be sent to. */
export function ZeroAgentVisual() {
  const t = useT()
  const inputId = useId()
  const [request, setRequest] = useState(() =>
    t(zeroAgents.examples[0].request),
  )
  const settled = useSettled(request.trim(), 450)
  const shown = settled || t(zeroAgents.examples[0].request)
  const example = zeroAgents.examples.find((item) => t(item.request) === shown)

  const send = (ide: IDEConfig) => {
    const deeplink = generateAIChatDeeplink(
      ide,
      `${shown}. ${ZERO_AGENT_PROMPT}`,
    )
    if (deeplink) openAIChatDeeplink(deeplink)
  }

  return (
    <div className="relative mx-auto grid w-full max-w-[560px] gap-4">
      <ArtPanel innerClassName="p-3.5">
        <label
          htmlFor={inputId}
          className="text-[12px] font-semibold text-foreground"
        >
          {t('What should your agent build?')}
        </label>
        <input
          id={inputId}
          value={request}
          maxLength={60}
          autoComplete="off"
          spellCheck={false}
          onChange={(event) => setRequest(event.target.value)}
          className="mt-2 h-9 w-full min-w-0 rounded-md border border-border bg-muted/30 px-3 text-[13px] text-foreground caret-[var(--brand-cta)] outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-[rgb(var(--tone-rgb)/0.6)]"
          placeholder={t(zeroAgents.examples[0].request)}
        />
        <div className="mt-2.5 flex flex-wrap gap-1.5">
          {zeroAgents.examples.map((item) => {
            const label = t(item.request)
            const selected = label === shown
            return (
              <button
                key={item.request}
                type="button"
                aria-pressed={selected}
                className={cn(
                  'rounded-full border px-2.5 py-1 text-[11px] transition-colors',
                  selected
                    ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.08)] text-foreground'
                    : 'border-border text-muted-foreground hover:text-foreground',
                )}
                onClick={() => setRequest(label)}
              >
                {label}
              </button>
            )
          })}
        </div>
        <div className="mt-3.5 flex flex-wrap items-center gap-1.5 border-t border-border pt-3">
          <span className="me-1 text-[11px] text-muted-foreground">
            {t('Send it to')}
          </span>
          {agentIdes.map((ide) => (
            <Button
              key={ide.id}
              type="button"
              variant="outline"
              size="sm"
              className="h-7 gap-1.5 px-2.5 text-[12px]"
              onClick={() => send(ide)}
              {...analyticsAttrs('zero-open-agent')}
            >
              <ArtIconMask src={ide.iconPath} className="size-3.5" />
              {ide.name}
            </Button>
          ))}
        </div>
      </ArtPanel>

      <ZeroAgentSession key={shown} request={shown} example={example} />
    </div>
  )
}
