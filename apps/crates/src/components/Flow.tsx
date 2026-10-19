import { Repeat, TriangleAlert } from 'lucide-react'
import type { ReactNode } from 'react'

/**
 * A ```` ```flow ```` block in a guide: the steps of a lifecycle, one per line, drawn as a diagram.
 *
 * - `Title | what happens` is a step, numbered in order;
 * - `> For each request` opens a loop around the steps after it, `<` closes it (the block's end closes the rest);
 * - `! Title | what happens` is what runs instead when a step of its loop (or of the block) fails.
 *
 * Text between backticks is code. Outside the site the block reads as plain text.
 */
type Step = { kind: 'step'; title: string; text: string; fail: boolean; n: number }
type Loop = { kind: 'loop'; label: string; nodes: Node[] }
type Node = Step | Loop

function parse(source: string): Node[] {
  const root: Loop = { kind: 'loop', label: '', nodes: [] }
  const stack = [root]
  let n = 0
  for (const raw of source.split('\n')) {
    const line = raw.trim()
    if (!line) continue
    const top = stack[stack.length - 1]
    if (line === '<') {
      if (stack.length > 1) stack.pop()
    } else if (line.startsWith('>')) {
      const loop: Loop = { kind: 'loop', label: line.slice(1).trim(), nodes: [] }
      top.nodes.push(loop)
      stack.push(loop)
    } else {
      const fail = line.startsWith('!')
      const [title, ...text] = (fail ? line.slice(1) : line).split('|')
      top.nodes.push({ kind: 'step', title: title.trim(), text: text.join('|').trim(), fail, n: fail ? 0 : ++n })
    }
  }
  return root.nodes
}

/** Text with `code`. */
function Inline({ text }: { text: string }) {
  return (
    <>
      {text.split('`').map((part, i) =>
        i % 2 ? (
          <code key={i} className="bg-muted px-1 py-px font-mono text-[0.88em] text-foreground">
            {part}
          </code>
        ) : (
          part
        ),
      )}
    </>
  )
}

function Body({ step }: { step: Step }) {
  return (
    <div className="grid min-w-0 gap-0.5 pt-[3px]">
      <span className={`text-[14px] leading-snug font-medium ${step.fail ? 'text-highlight' : 'text-foreground'}`}>
        <Inline text={step.title} />
      </span>
      {step.text ? (
        <span className="text-[13px] leading-normal text-muted-foreground">
          <Inline text={step.text} />
        </span>
      ) : null}
    </div>
  )
}

/** A column of steps. Each one but the last draws the line down to the next, behind a loop's box. */
function Rail({ children, fail }: { children: ReactNode; fail?: boolean }) {
  const line = fail ? '[&>li:not(:last-child)]:before:bg-highlight-line' : '[&>li:not(:last-child)]:before:bg-ring'
  return (
    <ol
      className={`m-0 grid list-none gap-4 p-0 [&>li]:relative [&>li:not(:last-child)]:before:absolute [&>li:not(:last-child)]:before:top-7 [&>li:not(:last-child)]:before:-bottom-4 [&>li:not(:last-child)]:before:left-[13.5px] [&>li:not(:last-child)]:before:w-px ${line}`}
    >
      {children}
    </ol>
  )
}

function Steps({ nodes }: { nodes: Node[] }) {
  const main = nodes.filter((n) => n.kind === 'loop' || !n.fail)
  const fails = nodes.filter((n): n is Step => n.kind === 'step' && n.fail)
  return (
    <div className="grid gap-4">
      <Rail>
        {main.map((node, i) =>
          node.kind === 'loop' ? (
            <li key={i}>
              <div className="relative grid gap-3 border border-dashed border-ring bg-card px-3 pt-2.5 pb-3">
                <span className="label flex items-center gap-1.5 text-muted-foreground">
                  <Repeat className="size-3.5" aria-hidden />
                  <Inline text={node.label} />
                </span>
                <Steps nodes={node.nodes} />
              </div>
            </li>
          ) : (
            <li key={i} className="grid grid-cols-[28px_minmax(0,1fr)] gap-3">
              <span className="z-10 grid size-7 place-items-center rounded-full border border-foreground/50 bg-background font-mono text-[11px] text-foreground tabular-nums">
                {node.n}
              </span>
              <Body step={node} />
            </li>
          ),
        )}
      </Rail>
      {fails.length ? (
        <div className="grid gap-3 border-t border-dashed border-highlight-line pt-3">
          <span className="label text-highlight">If a step fails</span>
          <Rail fail>
            {fails.map((step, i) => (
              <li key={i} className="grid grid-cols-[28px_minmax(0,1fr)] gap-3">
                <span className="z-10 grid size-7 place-items-center rounded-full border border-highlight-line bg-background text-highlight">
                  <TriangleAlert className="size-3.5" aria-hidden />
                </span>
                <Body step={step} />
              </li>
            ))}
          </Rail>
        </div>
      ) : null}
    </div>
  )
}

export function Flow({ source }: { source: string }) {
  const nodes = parse(source)
  if (!nodes.length) return null
  return (
    <figure className="m-0 grid border border-border bg-background p-4 sm:p-5" aria-label="Lifecycle diagram">
      <Steps nodes={nodes} />
    </figure>
  )
}
