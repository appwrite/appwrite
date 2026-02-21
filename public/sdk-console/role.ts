export class Role {
    public static any(): string {
        return 'any'
    }

    public static user(id: string, status: string = ''): string {
        if(status === '') {
            return `user:${id}`
        }
        return `user:${id}/${status}`
    }
    
    public static users(status: string = ''): string {
        if(status === '') {
            return 'users'
        }
        return `users/${status}`
    }
    
    public static guests(): string {
        return 'guests'
    }
    
    public static team(id: string, role: string = ''): string {
        if(role === '') {
            return `team:${id}`
        }
        return `team:${id}/${role}`
    }

    public static member(id: string): string {
        return `member:${id}`
    }
    
    public static status(status: string): string {
        return `status:${status}`
    }
}