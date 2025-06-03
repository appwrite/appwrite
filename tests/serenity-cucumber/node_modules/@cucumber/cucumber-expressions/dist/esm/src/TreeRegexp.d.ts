import Group from './Group.js';
import GroupBuilder from './GroupBuilder.js';
export default class TreeRegexp {
    readonly regexp: RegExp;
    readonly groupBuilder: GroupBuilder;
    constructor(regexp: RegExp | string);
    private static createGroupBuilder;
    private static isNonCapturing;
    match(s: string): Group | null;
}
//# sourceMappingURL=TreeRegexp.d.ts.map