export class ID {
    public static custom(id: string): string {
        return id
    }
    
    public static unique(): string {
        return 'unique()'
    }
}