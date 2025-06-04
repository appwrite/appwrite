export default class ParameterTypeMatcher {
    constructor(parameterType, regexpString, text, matchPosition = 0) {
        this.parameterType = parameterType;
        this.regexpString = regexpString;
        this.text = text;
        this.matchPosition = matchPosition;
        const captureGroupRegexp = new RegExp(`(${regexpString})`);
        this.match = captureGroupRegexp.exec(text.slice(this.matchPosition));
    }
    advanceTo(newMatchPosition) {
        for (let advancedPos = newMatchPosition; advancedPos < this.text.length; advancedPos++) {
            const matcher = new ParameterTypeMatcher(this.parameterType, this.regexpString, this.text, advancedPos);
            if (matcher.find) {
                return matcher;
            }
        }
        return new ParameterTypeMatcher(this.parameterType, this.regexpString, this.text, this.text.length);
    }
    get find() {
        return this.match && this.group !== '' && this.fullWord;
    }
    get start() {
        if (!this.match)
            throw new Error('No match');
        return this.matchPosition + this.match.index;
    }
    get fullWord() {
        return this.matchStartWord && this.matchEndWord;
    }
    get matchStartWord() {
        return this.start === 0 || this.text[this.start - 1].match(/\p{Z}|\p{P}|\p{S}/u);
    }
    get matchEndWord() {
        const nextCharacterIndex = this.start + this.group.length;
        return (nextCharacterIndex === this.text.length ||
            this.text[nextCharacterIndex].match(/\p{Z}|\p{P}|\p{S}/u));
    }
    get group() {
        if (!this.match)
            throw new Error('No match');
        return this.match[0];
    }
    static compare(a, b) {
        const posComparison = a.start - b.start;
        if (posComparison !== 0) {
            return posComparison;
        }
        const lengthComparison = b.group.length - a.group.length;
        if (lengthComparison !== 0) {
            return lengthComparison;
        }
        return 0;
    }
}
//# sourceMappingURL=ParameterTypeMatcher.js.map