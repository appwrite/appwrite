import Group from './Group.js';
export default class GroupBuilder {
    constructor() {
        this.capturing = true;
        this.groupBuilders = [];
    }
    add(groupBuilder) {
        this.groupBuilders.push(groupBuilder);
    }
    build(match, nextGroupIndex) {
        const groupIndex = nextGroupIndex();
        const children = this.groupBuilders.map((gb) => gb.build(match, nextGroupIndex));
        const value = match[groupIndex];
        const index = match.indices[groupIndex];
        const start = index ? index[0] : undefined;
        const end = index ? index[1] : undefined;
        return new Group(value, start, end, children);
    }
    setNonCapturing() {
        this.capturing = false;
    }
    get children() {
        return this.groupBuilders;
    }
    moveChildrenTo(groupBuilder) {
        this.groupBuilders.forEach((child) => groupBuilder.add(child));
    }
}
//# sourceMappingURL=GroupBuilder.js.map