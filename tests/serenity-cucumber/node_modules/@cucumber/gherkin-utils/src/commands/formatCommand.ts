import fs, { unlink as unlinkCb } from 'fs'
import path from 'path'
import * as messages from '@cucumber/messages'
import { AstBuilder, GherkinClassicTokenMatcher, GherkinInMarkdownTokenMatcher, Parser, } from '@cucumber/gherkin'
import pretty, { Syntax } from '../pretty'
import { promisify } from 'util'
import { Readable, Writable } from 'stream'

const unlink = promisify(unlinkCb)

export type FormatOptions = {
  fromSyntax?: Syntax
  toSyntax?: Syntax
  language?: string
}

type FileFormat = {
  readableSyntax: Syntax
  writableSyntax: Syntax
  readable: () => Readable
  writable: () => Writable
  afterWrite: () => Promise<void>
}

export async function formatCommand(
  files: string[],
  stdin: Readable | null,
  stdout: Writable | null,
  options: FormatOptions
): Promise<void> {
  const fileFormats: FileFormat[] = files.map(file => {
    const toFile = syntaxPath(file, options.toSyntax)
    return {
      readableSyntax: syntaxFromPath(file, options.fromSyntax),
      writableSyntax: syntaxFromPath(toFile, options.toSyntax),
      readable: () => fs.createReadStream(file),
      writable: () => fs.createWriteStream(toFile),
      afterWrite: file !== toFile ? () => unlink(file) : () => Promise.resolve()
    }
  })
  if (stdin && stdout) {
    fileFormats.push({
      readableSyntax: options.fromSyntax || 'gherkin',
      writableSyntax: options.toSyntax || 'gherkin',
      readable: () => stdin,
      writable: () => stdout,
      afterWrite: () => Promise.resolve()
    })
  }
  for (const fileFormat of fileFormats) {
    await convert(fileFormat, options.language)
  }
}

async function convert(fileFormat: FileFormat, language: string) {
  const source = await read(fileFormat.readable())
  const gherkinDocument = parse(source, fileFormat.readableSyntax, language)
  const output = pretty(gherkinDocument, fileFormat.writableSyntax)
  try {
    // Sanity check that what we generated is OK.
    parse(output, fileFormat.writableSyntax, gherkinDocument.feature?.language)
  } catch (err) {
    err.message += `The generated output is not parseable. This is a bug.
Please report a bug at https://github.com/cucumber/common/issues/new

--- Generated ${fileFormat.writableSyntax} source ---
${output}
------
`
    throw err
  }
  const writable = fileFormat.writable()
  writable.write(output)
  writable.end()
  await new Promise((resolve) => writable.once('finish', resolve))
  await fileFormat.afterWrite()
}

function parse(source: string, syntax: Syntax, language: string) {
  if (!syntax) throw new Error('No syntax')
  const fromParser = new Parser(
    new AstBuilder(messages.IdGenerator.uuid()),
    syntax === 'gherkin'
      ? new GherkinClassicTokenMatcher(language)
      : new GherkinInMarkdownTokenMatcher(language)
  )
  return fromParser.parse(source)
}

async function read(readable: Readable): Promise<string> {
  const chunks = []
  for await (const chunk of readable) chunks.push(chunk)
  return Buffer.concat(chunks).toString('utf-8')
}

function syntaxPath(file: string, syntax: Syntax): string {
  if (syntax === 'markdown') {
    if (syntaxFromPath(file) === 'markdown') return file
    return file + '.md'
  }

  if (syntax === 'gherkin') {
    if (syntaxFromPath(file) === 'gherkin') return file
    return file.replace(/\.feature\.md/, '.feature')
  }

  return file
}

function syntaxFromPath(file: string, explicitSyntax?: Syntax): Syntax {
  let syntax: Syntax
  if (path.extname(file) === '.feature') syntax = 'gherkin'
  if (path.extname(file) === '.md') syntax = 'markdown'
  if (!syntax) throw new Error(`Cannot determine syntax from path ${file}`)
  if (explicitSyntax && explicitSyntax !== syntax) throw new Error(`Cannot treat ${file} as ${explicitSyntax}`)
  return syntax
}
