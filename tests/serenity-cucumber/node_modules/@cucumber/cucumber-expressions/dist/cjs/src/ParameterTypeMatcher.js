"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
var ParameterTypeMatcher = /** @class */ (function () {
    function ParameterTypeMatcher(parameterType, regexpString, text, matchPosition) {
        if (matchPosition === void 0) { matchPosition = 0; }
        this.parameterType = parameterType;
        this.regexpString = regexpString;
        this.text = text;
        this.matchPosition = matchPosition;
        var captureGroupRegexp = new RegExp("(".concat(regexpString, ")"));
        this.match = captureGroupRegexp.exec(text.slice(this.matchPosition));
    }
    ParameterTypeMatcher.prototype.advanceTo = function (newMatchPosition) {
        for (var advancedPos = newMatchPosition; advancedPos < this.text.length; advancedPos++) {
            var matcher = new ParameterTypeMatcher(this.parameterType, this.regexpString, this.text, advancedPos);
            if (matcher.find) {
                return matcher;
            }
        }
        return new ParameterTypeMatcher(this.parameterType, this.regexpString, this.text, this.text.length);
    };
    Object.defineProperty(ParameterTypeMatcher.prototype, "find", {
        get: function () {
            return this.match && this.group !== '' && this.fullWord;
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(ParameterTypeMatcher.prototype, "start", {
        get: function () {
            if (!this.match)
                throw new Error('No match');
            return this.matchPosition + this.match.index;
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(ParameterTypeMatcher.prototype, "fullWord", {
        get: function () {
            return this.matchStartWord && this.matchEndWord;
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(ParameterTypeMatcher.prototype, "matchStartWord", {
        get: function () {
            return this.start === 0 || this.text[this.start - 1].match(/\p{Z}|\p{P}|\p{S}/u);
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(ParameterTypeMatcher.prototype, "matchEndWord", {
        get: function () {
            var nextCharacterIndex = this.start + this.group.length;
            return (nextCharacterIndex === this.text.length ||
                this.text[nextCharacterIndex].match(/\p{Z}|\p{P}|\p{S}/u));
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(ParameterTypeMatcher.prototype, "group", {
        get: function () {
            if (!this.match)
                throw new Error('No match');
            return this.match[0];
        },
        enumerable: false,
        configurable: true
    });
    ParameterTypeMatcher.compare = function (a, b) {
        var posComparison = a.start - b.start;
        if (posComparison !== 0) {
            return posComparison;
        }
        var lengthComparison = b.group.length - a.group.length;
        if (lengthComparison !== 0) {
            return lengthComparison;
        }
        return 0;
    };
    return ParameterTypeMatcher;
}());
exports.default = ParameterTypeMatcher;
//# sourceMappingURL=ParameterTypeMatcher.js.map