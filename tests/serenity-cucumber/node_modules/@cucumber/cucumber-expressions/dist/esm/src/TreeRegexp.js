import execWithIndices from 'regexp-match-indices';
import GroupBuilder from './GroupBuilder.js';
export default class TreeRegexp {
    constructor(regexp) {
        if (regexp instanceof RegExp) {
            this.regexp = regexp;
        }
        else {
            this.regexp = new RegExp(regexp);
        }
        this.groupBuilder = TreeRegexp.createGroupBuilder(this.regexp);
    }
    static createGroupBuilder(regexp) {
        const source = regexp.source;
        const stack = [new GroupBuilder()];
        const groupStartStack = [];
        let escaping = false;
        let charClass = false;
        for (let i = 0; i < source.length; i++) {
            const c = source[i];
            if (c === '[' && !escaping) {
                charClass = true;
            }
            else if (c === ']' && !escaping) {
                charClass = false;
            }
            else if (c === '(' && !escaping && !charClass) {
                groupStartStack.push(i);
                const nonCapturing = TreeRegexp.isNonCapturing(source, i);
                const groupBuilder = new GroupBuilder();
                if (nonCapturing) {
                    groupBuilder.setNonCapturing();
                }
                stack.push(groupBuilder);
            }
            else if (c === ')' && !escaping && !charClass) {
                const gb = stack.pop();
                if (!gb)
                    throw new Error('Empty stack');
                const groupStart = groupStartStack.pop();
                if (gb.capturing) {
                    gb.source = source.substring((groupStart || 0) + 1, i);
                    stack[stack.length - 1].add(gb);
                }
                else {
                    gb.moveChildrenTo(stack[stack.length - 1]);
                }
            }
            escaping = c === '\\' && !escaping;
        }
        const result = stack.pop();
        if (!result)
            throw new Error('Empty stack');
        return result;
    }
    static isNonCapturing(source, i) {
        // Regex is valid. Bounds check not required.
        if (source[i + 1] !== '?') {
            // (X)
            return false;
        }
        if (source[i + 2] !== '<') {
            // (?:X)
            // (?=X)
            // (?!X)
            return true;
        }
        // (?<=X) or (?<!X) else (?<name>X)
        return source[i + 3] === '=' || source[i + 3] === '!';
    }
    match(s) {
        const match = execWithIndices(this.regexp, s);
        if (!match) {
            return null;
        }
        let groupIndex = 0;
        const nextGroupIndex = () => groupIndex++;
        return this.groupBuilder.build(match, nextGroupIndex);
    }
}
//# sourceMappingURL=TreeRegexp.js.map