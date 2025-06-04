"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
class AstNode {
    constructor(ruleType) {
        this.ruleType = ruleType;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        this.subItems = new Map();
    }
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    add(type, obj) {
        let items = this.subItems.get(type);
        if (items === undefined) {
            items = [];
            this.subItems.set(type, items);
        }
        items.push(obj);
    }
    getSingle(ruleType) {
        return (this.subItems.get(ruleType) || [])[0];
    }
    getItems(ruleType) {
        return this.subItems.get(ruleType) || [];
    }
    getToken(tokenType) {
        return (this.subItems.get(tokenType) || [])[0];
    }
    getTokens(tokenType) {
        return this.subItems.get(tokenType) || [];
    }
}
exports.default = AstNode;
//# sourceMappingURL=AstNode.js.map