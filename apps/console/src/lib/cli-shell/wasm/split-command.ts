/**
 * Split a typed command line into argv.
 *
 * The old shell handed a string to almostnode, which had a real shell to parse
 * it. Nothing parses it now -- wasm takes an argv array -- so this does the one
 * part of shell syntax the CLI actually needs: quoting.
 *
 * Deliberately not a shell. No pipes, no redirection, no globbing, no variable
 * expansion. A user typing `appwrite users list | grep x` gets `|` and `grep`
 * as arguments and an error from the CLI, which is a better answer than a shell
 * that half-works.
 */
export function splitCommand(input: string): string[] {
  const argv: string[] = []
  let current = ''
  let quote: '"' | "'" | null = null
  let started = false

  for (let index = 0; index < input.length; index++) {
    const character = input[index]!

    if (character === '\\' && quote !== "'" && index + 1 < input.length) {
      current += input[++index]
      started = true
      continue
    }

    if (quote) {
      if (character === quote) {
        quote = null
        continue
      }
      current += character
      continue
    }

    if (character === '"' || character === "'") {
      quote = character
      // An empty pair of quotes is still an argument: `--data ""` passes one.
      started = true
      continue
    }

    if (/\s/.test(character)) {
      if (started) {
        argv.push(current)
        current = ''
        started = false
      }
      continue
    }

    current += character
    started = true
  }

  if (started) {
    argv.push(current)
  }

  return argv
}
