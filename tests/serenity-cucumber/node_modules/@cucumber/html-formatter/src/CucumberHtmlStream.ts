import * as messages from '@cucumber/messages'
import fs from 'fs'
import { Readable, Transform, TransformCallback } from 'stream'

export default class CucumberHtmlStream extends Transform {
  private template: string | null = null
  private preMessageWritten = false
  private postMessageWritten = false
  private firstMessageWritten = false
  /**
   * @param cssPath
   * @param jsPath
   */
  constructor(
    private readonly cssPath: string,
    private readonly jsPath: string
  ) {
    super({ objectMode: true })
  }

  public _transform(
    envelope: messages.Envelope,
    encoding: string,
    callback: TransformCallback
  ): void {
    if (this.postMessageWritten) {
      return callback(new Error('Stream closed'))
    }

    this.writePreMessageUnlessAlreadyWritten((err) => {
      if (err) return callback(err)
      this.writeMessage(envelope)
      callback()
    })
  }

  public _flush(callback: TransformCallback): void {
    this.writePostMessage(callback)
  }

  private writePreMessageUnlessAlreadyWritten(callback: TransformCallback) {
    if (this.preMessageWritten) {
      return callback()
    }
    this.preMessageWritten = true
    this.writeTemplateBetween(null, '{{css}}', (err) => {
      if (err) return callback(err)
      this.writeFile(this.cssPath, (err) => {
        if (err) return callback(err)
        this.writeTemplateBetween('{{css}}', '{{messages}}', (err) => {
          if (err) return callback(err)
          callback()
        })
      })
    })
  }

  private writePostMessage(callback: TransformCallback) {
    this.writePreMessageUnlessAlreadyWritten((err) => {
      if (err) return callback(err)
      this.writeTemplateBetween('{{messages}}', '{{script}}', (err) => {
        if (err) return callback(err)
        this.writeFile(this.jsPath, (err) => {
          if (err) return callback(err)
          this.writeTemplateBetween('{{script}}', null, callback)
        })
      })
    })
  }

  private writeFile(path: string, callback: (error?: Error | null) => void) {
    const cssStream: Readable = fs.createReadStream(path, { encoding: 'utf-8' })
    cssStream.on('data', (chunk) => this.push(chunk))
    cssStream.on('error', (err) => callback(err))
    cssStream.on('end', callback)
  }

  private writeTemplateBetween(
    begin: string | null,
    end: string | null,
    callback: (err?: Error | null) => void
  ) {
    this.readTemplate((err, template) => {
      if (err) return callback(err)
      if (!template)
        return callback(new Error('template is required if error is missing'))
      const beginIndex =
        begin == null ? 0 : template.indexOf(begin) + begin.length
      const endIndex = end == null ? template.length : template.indexOf(end)
      this.push(template.substring(beginIndex, endIndex))
      callback()
    })
  }

  private readTemplate(
    callback: (error?: Error | null, data?: string) => void
  ) {
    if (this.template !== null) {
      return callback(null, this.template)
    }
    fs.readFile(
      __dirname + '/index.mustache.html',
      { encoding: 'utf-8' },
      (err, template) => {
        if (err) return callback(err)
        this.template = template
        return callback(null, template)
      }
    )
  }

  private writeMessage(envelope: messages.Envelope) {
    if (!this.firstMessageWritten) {
      this.firstMessageWritten = true
    } else {
      this.push(',')
    }
    this.push(JSON.stringify(envelope))
  }
}
