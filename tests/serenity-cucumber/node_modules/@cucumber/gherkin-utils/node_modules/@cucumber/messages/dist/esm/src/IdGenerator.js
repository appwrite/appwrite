import { v4 as uuidv4 } from 'uuid';
export function uuid() {
    return () => uuidv4();
}
export function incrementing() {
    let next = 0;
    return () => (next++).toString();
}
//# sourceMappingURL=IdGenerator.js.map