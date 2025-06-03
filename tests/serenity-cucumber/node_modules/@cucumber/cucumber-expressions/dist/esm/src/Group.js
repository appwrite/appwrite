export default class Group {
    constructor(value, start, end, children) {
        this.value = value;
        this.start = start;
        this.end = end;
        this.children = children;
    }
    get values() {
        return (this.children.length === 0 ? [this] : this.children).map((g) => g.value);
    }
}
//# sourceMappingURL=Group.js.map