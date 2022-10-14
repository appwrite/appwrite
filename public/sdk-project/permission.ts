export class Permission {

    static read = (role: string): string => {
        return `read("${role}")`
    }

    static write = (role: string): string => {
        return `write("${role}")`
    }

    static create = (role: string): string => {
        return `create("${role}")`
    }

    static update = (role: string): string => {
        return `update("${role}")`
    }

    static delete = (role: string): string => {
        return `delete("${role}")`
    }
}
