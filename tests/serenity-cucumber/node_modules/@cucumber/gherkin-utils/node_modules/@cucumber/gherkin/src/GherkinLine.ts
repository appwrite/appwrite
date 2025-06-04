import countSymbols from './countSymbols'
import { IGherkinLine, Item } from './IToken'

export default class GherkinLine implements IGherkinLine {
  public trimmedLineText: string
  public isEmpty: boolean
  public readonly indent: number
  public column: number
  public text: string

  constructor(public readonly lineText: string, public readonly lineNumber: number) {
    this.trimmedLineText = lineText.replace(/^\s+/g, '') // ltrim
    this.isEmpty = this.trimmedLineText.length === 0
    this.indent = countSymbols(lineText) - countSymbols(this.trimmedLineText)
  }

  public startsWith(prefix: string) {
    return this.trimmedLineText.indexOf(prefix) === 0
  }

  public startsWithTitleKeyword(keyword: string) {
    return this.startsWith(keyword + ':') // The C# impl is more complicated. Find out why.
  }

  public match(regexp: RegExp) {
    return this.trimmedLineText.match(regexp)
  }

  public getLineText(indentToRemove: number) {
    if (indentToRemove < 0 || indentToRemove > this.indent) {
      return this.trimmedLineText
    } else {
      return this.lineText.substring(indentToRemove)
    }
  }

  public getRestTrimmed(length: number) {
    return this.trimmedLineText.substring(length).trim()
  }

  public getTableCells(): readonly Item[] {
    const cells = []
    let col = 0
    let startCol = col + 1
    let cell = ''
    let firstCell = true
    while (col < this.trimmedLineText.length) {
      let chr = this.trimmedLineText[col]
      col++

      if (chr === '|') {
        if (firstCell) {
          // First cell (content before the first |) is skipped
          firstCell = false
        } else {
          // Keeps newlines
          const trimmedLeft = cell.replace(/^[ \t\v\f\r\u0085\u00A0]*/g, '')
          const trimmed = trimmedLeft.replace(/[ \t\v\f\r\u0085\u00A0]*$/g, '')
          const cellIndent = cell.length - trimmedLeft.length
          const span = {
            column: this.indent + startCol + cellIndent,
            text: trimmed,
          }
          cells.push(span)
        }
        cell = ''
        startCol = col + 1
      } else if (chr === '\\') {
        chr = this.trimmedLineText[col]
        col += 1
        if (chr === 'n') {
          cell += '\n'
        } else {
          if (chr !== '|' && chr !== '\\') {
            cell += '\\'
          }
          cell += chr
        }
      } else {
        cell += chr
      }
    }

    return cells
  }
}

module.exports = GherkinLine
