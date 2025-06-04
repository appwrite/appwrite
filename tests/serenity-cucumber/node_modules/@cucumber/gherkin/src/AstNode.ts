import { RuleType, TokenType } from './Parser'
import IToken from './IToken'

export default class AstNode {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private readonly subItems = new Map<any, any[]>()

  constructor(public readonly ruleType: RuleType) {}

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  public add(type: any, obj: any) {
    let items = this.subItems.get(type)
    if (items === undefined) {
      items = []
      this.subItems.set(type, items)
    }
    items.push(obj)
  }

  public getSingle(ruleType: RuleType) {
    return (this.subItems.get(ruleType) || [])[0]
  }

  public getItems(ruleType: RuleType) {
    return this.subItems.get(ruleType) || []
  }

  public getToken(tokenType: TokenType) {
    return (this.subItems.get(tokenType) || [])[0]
  }

  public getTokens(tokenType: TokenType): IToken<TokenType>[] {
    return this.subItems.get(tokenType) || []
  }
}
