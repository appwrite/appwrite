(function(window){
    document.addEventListener('alpine:init', () => {
        Alpine.data('permissionsMatrix', () => ({
            permissions: [],
            rawPermissions: [],
            load(permissions) {
                this.rawPermissions = permissions;

                permissions.map(p => {
                    let parts = p.split('(')
                    let type = parts[0];
                    let roles = parts[1]
                        .replace(')', '')
                        .replace(' ', '')
                        .split(',');

                    roles.map(role => {
                        let index = -1
                        let existing = this.permissions.find((p, idx) => {
                            if (p.role === role) {
                                index = idx;
                                return true;
                            }
                        })
                        if (existing === undefined) {
                            this.permissions.push({
                                role,
                                [type]: true,
                            })
                        }
                        if (index !== -1) {
                            existing[type] = true;
                            this.permissions[index] = existing;
                        }
                    });
                })
            },
            addPermission(role, read, create, update, xdelete) {
                if (read) this.rawPermissions.push(`read(${role})`);
                if (create) this.rawPermissions.push(`create(${role})`);
                if (update) this.rawPermissions.push(`update(${role})`);
                if (xdelete) this.rawPermissions.push(`delete(${role})`);

                this.permissions.push({
                    role,
                    read,
                    create,
                    update,
                    xdelete
                });

                this.reset()
            },
            removePermission(index) {
                let row = this.permissions.splice(index, 1);
                if (row.length === 1) {
                    this.rawPermissions = this.rawPermissions.filter(p => !p.includes(row[0].role));
                }
            }
        }));
        Alpine.data('permissionsRow', () => ({
            role: '',
            read: false,
            create: false,
            update: false,
            xdelete: false,

            reset() {
                this.role = '';
                this.read = this.create = this.update = this.xdelete = false;
            }
        }));
    });
})(window);
