import { getRouteApi } from '@tanstack/react-router'
import { CodeBlock } from './Code'
import { Markdown } from './Markdown'
import { MigrationCard } from './Migration'
import { Section } from './Page'
import { sourceUrl, taskHash, type PhpTask, type Task, type TaskEntry } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'

const root = getRouteApi('__root__')

type Ported = Extract<Task, { status: 'ported' }>

/** A `name: description` list (commands, arguments, environment, exit codes) as a table. */
function Entries({ title, entries }: { title: string; entries: TaskEntry[] }) {
  if (!entries.length) return null
  return (
    <div className="grid gap-1.5">
      <h4 className="text-[13px] font-medium">{title}</h4>
      <div className="overflow-hidden rounded-lg border border-border">
        {entries.map((e, i) => (
          <div key={i} className="grid gap-x-4 border-b border-border px-3.5 py-2 last:border-b-0 md:grid-cols-[minmax(10rem,16rem)_minmax(0,1fr)]">
            <code className="font-mono text-[12.5px] font-medium [overflow-wrap:anywhere]">{e.name}</code>
            <div className="text-[13px] text-muted-foreground [&_p]:m-0">
              <Markdown text={e.description} />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

function PhpTaskCard({ task, notes }: { task: { php: PhpTask }; notes?: string }) {
  const index = root.useLoaderData()
  const php = task.php
  const file = php.kind === 'script' ? null : php.id
  // From the package directory; each composer script that runs the task too.
  const commands = [`cd ${php.dir}`, php.command, ...php.scripts.filter((s) => `composer ${s.name}` !== php.command).map((s) => `composer ${s.name}  # ${s.command}`)]
  return (
    <MigrationCard title="PHP equivalent">
      <CodeBlock code={commands.join('\n')} lang="bash" migration />
      {file ? (
        <a href={sourceUrl(index?.branch ?? index?.commit, file)} target="_blank" rel="noopener" className="text-[12px] text-muted-foreground hover:text-foreground">
          {file}
        </a>
      ) : null}
      {notes ? <Markdown text={notes} /> : null}
    </MigrationCard>
  )
}

function PortedTask({ task }: { task: Ported }) {
  return (
    <article id={taskHash(task.name)} className="grid min-w-0 scroll-mt-20 gap-3 rounded-lg border border-border bg-card p-4">
      <div className="grid gap-1">
        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <h3 className="font-mono text-[15px] font-medium">{task.bin}</h3>
          <span className="text-[12px] text-muted-foreground">{task.name}</span>
        </div>
        {task.summary ? <div className="text-[13.5px] [&_p]:m-0"><Markdown text={task.summary} /></div> : null}
      </div>
      <CodeBlock code={(task.usage.length ? task.usage : [task.command]).join('\n')} lang="bash" />
      {task.doc ? <Markdown text={task.doc} /> : null}
      <Entries title="Commands" entries={task.commands} />
      <Entries title="Arguments" entries={task.arguments} />
      <Entries title="Environment" entries={task.environment} />
      <Entries title="Exit codes" entries={task.exit_codes} />
      {task.writes.length ? (
        <div className="grid gap-1.5">
          <h4 className="text-[13px] font-medium">Writes</h4>
          <ul className="m-0 grid list-none gap-1 p-0">
            {task.writes.map((w) => (
              <li key={w}>
                <code className="font-mono text-[12.5px] [overflow-wrap:anywhere]">{w}</code>
              </li>
            ))}
          </ul>
          <p className="m-0 text-[12.5px] text-muted-foreground">The crate embeds these files when it is compiled: rebuild after running the task.</p>
        </div>
      ) : null}
      <PhpTaskCard task={task} notes={task.php_notes} />
    </article>
  )
}

/**
 * A library's tasks: the binaries the crate ships besides its API, Rust first; in migration
 * mode, the PHP command each replaces and the PHP tasks not ported (waived or pending).
 */
export function Tasks({ id, tasks }: { id: string; tasks: Task[] }) {
  const migration = useMigration()
  const ported = tasks.filter((t): t is Ported => t.status === 'ported')
  const other = tasks.filter((t) => t.status !== 'ported')
  if (!ported.length && !(migration && other.length)) return null
  return (
    <Section id={id} title="Commands" description="Programs the crate ships besides its API. Run them with cargo from the repository root.">
      {ported.map((t) => (
        <PortedTask key={t.name} task={t} />
      ))}
      {migration && other.length ? (
        <MigrationCard title="PHP tasks without a Rust binary">
          <div className="overflow-hidden rounded-lg border border-border bg-background">
            {other.map((t) => (
              <div key={t.php.id} className="grid gap-x-4 border-b border-border px-3.5 py-2.5 last:border-b-0 md:grid-cols-[minmax(12rem,22rem)_minmax(0,1fr)]">
                <code className="font-mono text-[12.5px] [overflow-wrap:anywhere]">{t.php.id}</code>
                <span className="text-[13px] text-muted-foreground">{t.status === 'waived' ? `Waived: ${t.reason}` : 'Pending: to be ported with the library.'}</span>
              </div>
            ))}
          </div>
        </MigrationCard>
      ) : null}
    </Section>
  )
}
