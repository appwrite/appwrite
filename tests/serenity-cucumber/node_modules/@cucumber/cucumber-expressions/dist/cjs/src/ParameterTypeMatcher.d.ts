import ParameterType from './ParameterType.js';
export default class ParameterTypeMatcher {
    readonly parameterType: ParameterType<unknown>;
    private readonly regexpString;
    private readonly text;
    private matchPosition;
    private readonly match;
    constructor(parameterType: ParameterType<unknown>, regexpString: string, text: string, matchPosition?: number);
    advanceTo(newMatchPosition: number): ParameterTypeMatcher;
    get find(): boolean | RegExpMatchArray | null;
    get start(): number;
    get fullWord(): true | RegExpMatchArray | null;
    get matchStartWord(): true | RegExpMatchArray | null;
    get matchEndWord(): true | RegExpMatchArray | null;
    get group(): string;
    static compare(a: ParameterTypeMatcher, b: ParameterTypeMatcher): number;
}
//# sourceMappingURL=ParameterTypeMatcher.d.ts.map