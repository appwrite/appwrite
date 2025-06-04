"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var regexp_match_indices_1 = __importDefault(require("regexp-match-indices"));
var GroupBuilder_js_1 = __importDefault(require("./GroupBuilder.js"));
var TreeRegexp = /** @class */ (function () {
    function TreeRegexp(regexp) {
        if (regexp instanceof RegExp) {
            this.regexp = regexp;
        }
        else {
            this.regexp = new RegExp(regexp);
        }
        this.groupBuilder = TreeRegexp.createGroupBuilder(this.regexp);
    }
    TreeRegexp.createGroupBuilder = function (regexp) {
        var source = regexp.source;
        var stack = [new GroupBuilder_js_1.default()];
        var groupStartStack = [];
        var escaping = false;
        var charClass = false;
        for (var i = 0; i < source.length; i++) {
            var c = source[i];
            if (c === '[' && !escaping) {
                charClass = true;
            }
            else if (c === ']' && !escaping) {
                charClass = false;
            }
            else if (c === '(' && !escaping && !charClass) {
                groupStartStack.push(i);
                var nonCapturing = TreeRegexp.isNonCapturing(source, i);
                var groupBuilder = new GroupBuilder_js_1.default();
                if (nonCapturing) {
                    groupBuilder.setNonCapturing();
                }
                stack.push(groupBuilder);
            }
            else if (c === ')' && !escaping && !charClass) {
                var gb = stack.pop();
                if (!gb)
                    throw new Error('Empty stack');
                var groupStart = groupStartStack.pop();
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
        var result = stack.pop();
        if (!result)
            throw new Error('Empty stack');
        return result;
    };
    TreeRegexp.isNonCapturing = function (source, i) {
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
    };
    TreeRegexp.prototype.match = function (s) {
        var match = (0, regexp_match_indices_1.default)(this.regexp, s);
        if (!match) {
            return null;
        }
        var groupIndex = 0;
        var nextGroupIndex = function () { return groupIndex++; };
        return this.groupBuilder.build(match, nextGroupIndex);
    };
    return TreeRegexp;
}());
exports.default = TreeRegexp;
//# sourceMappingURL=TreeRegexp.js.map