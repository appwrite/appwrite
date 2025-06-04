import { IdGenerator } from '@cucumber/messages'
import { Command } from 'commander'
import { readFileSync } from "fs";
import { generateMessages, makeSourceEnvelope } from './dist/src/index.js'

const program = new Command()
program.option('--no-source', 'Do not output Source messages')
program.option('--no-ast', 'Do not output GherkinDocument messages')
program.option('--no-pickles', 'Do not output Pickle messages')
program.parse(process.argv)
const [path] = program.args

const options = {
    defaultDialect: 'en',
    includeSource: program.opts().source,
    includeGherkinDocument: program.opts().ast,
    includePickles: program.opts().pickles,
    newId: IdGenerator.incrementing()
}

const content = readFileSync(path, { encoding: 'utf-8' })
const { source: { data, uri, mediaType } } = makeSourceEnvelope(content, path)
const results = generateMessages(data, uri, mediaType, options)
process.stdout.write(results.map(item => JSON.stringify(item)).join('\n'))
