import { formatCommand } from '../../src/commands/formatCommand'
import assert from 'assert'
import {
  existsSync,
  readFile as readFileCb,
  writeFile as writeFileCb,
  mkdir as mkdirCb,
  mkdtemp as mkdtempCb,
} from 'fs'
import os from 'os'
import { promisify } from 'util'
import { Readable, Writable } from 'stream'

const mkdtemp = promisify(mkdtempCb)
const mkdir = promisify(mkdirCb)
const writeFile = promisify(writeFileCb)
const readFile = promisify(readFileCb)

class BufStream extends Writable {
  public buf = Buffer.alloc(0)
  _write(chunk: Buffer, encoding: BufferEncoding, callback: (error?: Error | null) => void) {
    this.buf = Buffer.concat([this.buf, chunk])
    callback()
  }
}

describe('formatCommand', () => {
  let tmpdir: string
  beforeEach(async () => {
    tmpdir = await mkdtemp(os.tmpdir() + '/')
  })

  it('formats STDIN Gherkin to STDOUT Markdown', async () => {
    const stdin = Readable.from(Buffer.from('Feature: Hello\n'))
    const stdout = new BufStream()
    await formatCommand([], stdin, stdout, {fromSyntax: 'gherkin', toSyntax: 'markdown'})
    assert.deepStrictEqual(stdout.buf.toString('utf-8'), '# Feature: Hello\n')
  })

  it('formats STDIN Markdown to STDOUT Gherkin', async () => {
    const stdin = Readable.from(Buffer.from('# Feature: Hello\n'))
    const stdout = new BufStream()
    await formatCommand([], stdin, stdout, {fromSyntax: 'markdown', toSyntax: 'gherkin'})
    assert.deepStrictEqual(stdout.buf.toString('utf-8'), 'Feature: Hello\n')
  })

  it('formats Gherkin file in-place', async () => {
    const path = `${tmpdir}/source.feature`
    await writeFile(path, '   Feature: Hello\n', 'utf-8')

    await formatCommand([path], null, null, {})
    const gherkin = await readFile(path, 'utf-8')
    assert.deepStrictEqual(gherkin, 'Feature: Hello\n')
  })

  it('formats Markdown file in-place', async () => {
    const path = `${tmpdir}/source.feature.md`
    await writeFile(path, '# Feature: Hello\n', 'utf-8')

    await formatCommand([path], null, null, {})
    const markdown = await readFile(path, 'utf-8')
    assert.deepStrictEqual(markdown, '# Feature: Hello\n')
  })

  it('formats/moves Gherkin file to Markdown file', async () => {
    const fromPath = `${tmpdir}/source.feature`
    await writeFile(fromPath, 'Feature: Hello\n', 'utf-8')

    const toPath = `${tmpdir}/source.feature.md`

    await formatCommand([fromPath], null, null, {toSyntax: 'markdown'})
    const markdown = await readFile(toPath, 'utf-8')
    assert.deepStrictEqual(markdown, '# Feature: Hello\n')
    assert(!existsSync(fromPath))
  })

  it('formats/moves Markdown file to Gherkin file', async () => {
    const fromPath = `${tmpdir}/source.feature.md`
    await writeFile(fromPath, '# Feature: Hello\n', 'utf-8')

    const toPath = `${tmpdir}/source.feature`

    await formatCommand([fromPath], null, null, {toSyntax: 'gherkin'})
    const markdown = await readFile(toPath, 'utf-8')
    assert.deepStrictEqual(markdown, 'Feature: Hello\n')
    assert(!existsSync(fromPath))
  })

  it('throws an error when fromSyntax inconsitent with file extension', async () => {
    const fromPath = `${tmpdir}/source.feature.md`
    await assert.rejects(formatCommand([fromPath], null, null, {fromSyntax: 'gherkin'}))
  })
})
