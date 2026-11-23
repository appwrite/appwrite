import type { ReactNode } from 'react'
import { ExternalLink } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { useT, type Translator } from '@/lib/i18n/translate'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import {
  CLAUDE_OFFICIAL_MARKETPLACE_REPO,
  CLAUDE_PLUGIN_OFFICIAL,
  CODEX_PLUGIN_ID,
  CURSOR_PLUGIN_PAGE_URL,
} from '@/lib/seo/agent-setup'

const SKILLS_REPO = 'appwrite/skills'

/** The review skills the Appwrite plugin and `appwrite/skills` ship. */
function skills(t: Translator) {
  return [
    {
      name: 'appwrite-review-status',
      description: t(
        'Reads the current tier, the open findings, and the comments the review filtered out. It does not start a review.',
      ),
    },
    {
      name: 'appwrite-review-loop',
      description: t(
        'Fixes the pull request until the review grades it Tier S and no findings are left open.',
      ),
    },
    {
      name: 'appwrite-review-learn',
      description: t(
        'Replies on a finding with a lasting rule, so later reviews of the repository follow it.',
      ),
    },
    {
      name: 'appwrite-review-setup',
      description: t(
        'Writes the repository’s review config and opens a pull request onto the base branch.',
      ),
    },
    {
      name: 'appwrite-review-threads',
      description: t(
        'Keeps the agent from resolving review threads. The review clears a thread once a later review sees the fix.',
      ),
    },
  ]
}

type ToolCardProps = {
  name: string
  iconPath?: string
  children: ReactNode
}

function ToolCard({ name, iconPath, children }: ToolCardProps) {
  return (
    <section className="rounded-xl border border-border bg-card/50 px-6 py-4">
      <h3 className="flex items-center gap-2 text-[15px] font-semibold text-foreground">
        {iconPath ? (
          <img
            src={iconPath}
            alt=""
            className={`h-4 w-4 ${PUBLIC_ICON_MUTED_CLASSES}`}
          />
        ) : null}
        {name}
      </h3>
      <div className="mt-3 space-y-3 text-[13px] leading-relaxed text-muted-foreground">
        {children}
      </div>
    </section>
  )
}

/**
 * The integration tab: how to give a coding agent the review skills, so it
 * can read a pull request's review, fix it, and teach the reviewer from the
 * chat. The skills ship in the Appwrite plugin and in `appwrite/skills`, and
 * they talk to GitHub, so the repository needs review turned on here.
 */
export function Integration({ projectId }: { projectId: string }) {
  const t = useT()

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-[15px] font-semibold text-foreground">
          {t('Use Review from your coding agent')}
        </h2>
        <p className="mt-1 max-w-2xl text-[13px] leading-relaxed text-muted-foreground">
          {t(
            'Install the Appwrite plugin in your coding agent. Its review skills read the review on a pull request, fix the findings until it grades Tier S, and teach the reviewer rules for later reviews.',
          )}
        </p>
      </div>

      <ToolCard name="Claude Code" iconPath="/icons/claude.svg">
        <CodeBlock
          code={`claude plugin marketplace add ${CLAUDE_OFFICIAL_MARKETPLACE_REPO}
claude plugin install ${CLAUDE_PLUGIN_OFFICIAL}`}
          language="bash"
          copyInside
        />
      </ToolCard>

      <ToolCard name="Codex" iconPath="/icons/codex.svg">
        <p>
          {t(
            'In Codex, run /plugins, open the OpenAI Curated marketplace, and install Appwrite. Or install it from the terminal:',
          )}
        </p>
        <CodeBlock
          code={`codex plugin add ${CODEX_PLUGIN_ID}`}
          language="bash"
          copyInside
        />
      </ToolCard>

      <ToolCard name="Cursor" iconPath="/icons/cursor-ai.svg">
        <p>{t('Install the Appwrite plugin from the Cursor Marketplace.')}</p>
        <Button
          asChild
          variant="secondary"
          size="sm"
          className="h-9 gap-1.5 text-[13px]"
        >
          <a href={CURSOR_PLUGIN_PAGE_URL} target="_blank" rel="noreferrer">
            {t('Open Cursor Marketplace')}
            <ExternalLink className="h-3.5 w-3.5" />
          </a>
        </Button>
      </ToolCard>

      <ToolCard name={t('Others')}>
        <p>
          {t(
            'Any agent that loads skills can use them. Install one skill without a plugin:',
          )}
        </p>
        <CodeBlock
          code={`npx skills add ${SKILLS_REPO} --skill appwrite-review-loop`}
          language="bash"
          copyInside
        />
        <p>
          {t(
            'Replace appwrite-review-loop with any skill below. The skills use git and the GitHub CLI; another GitHub client is fine when that is what the environment has.',
          )}
        </p>
      </ToolCard>

      <section className="rounded-xl border border-border bg-card/50 px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Skills')}
        </h3>
        <p className="mt-1 text-[13px] leading-relaxed text-muted-foreground">
          {t(
            'Pass a pull request number to work on that one. Otherwise a skill uses the pull request of the current branch. Each review the loop requests uses this project’s review credits, and it stops after 5 rounds.',
          )}
        </p>
        <ul className="mt-3 divide-y divide-border">
          {skills(t).map((skill) => (
            <li
              key={skill.name}
              className="flex flex-col gap-1 py-2.5 sm:flex-row sm:gap-4"
            >
              <code className="shrink-0 font-mono text-[12px] text-foreground sm:w-56">
                {skill.name}
              </code>
              <span className="text-[13px] leading-relaxed text-muted-foreground">
                {skill.description}
              </span>
            </li>
          ))}
        </ul>
        <p className="mt-3 text-[13px] leading-relaxed text-muted-foreground">
          {t('The repository needs review turned on in')}{' '}
          <Link
            to="/projects/$projectId/reviews/repositories"
            params={{ projectId }}
            className="text-foreground underline hover:no-underline"
          >
            {t('Repositories')}
          </Link>
          .
        </p>
      </section>
    </div>
  )
}
