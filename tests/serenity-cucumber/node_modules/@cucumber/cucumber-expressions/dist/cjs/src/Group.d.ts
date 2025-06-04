export default class Group {
    readonly value: string;
    readonly start: number | undefined;
    readonly end: number | undefined;
    readonly children: readonly Group[];
    constructor(value: string, start: number | undefined, end: number | undefined, children: readonly Group[]);
    get values(): string[] | null;
}
//# sourceMappingURL=Group.d.ts.map