(function(window){
    document.addEventListener('alpine:init', () => {
        Alpine.data('permissionsMatrix', () => ({
            permissions: [],
            rawPermissions: [],
            load(permissions) {
                this.rawPermissions = permissions;

                permissions.map(p => {
                    let { type, role } = this.parsePermission(p);
                    let index = -1;
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
                        });
                    }
                    if (index !== -1) {
                        existing[type] = true;
                        this.permissions[index] = existing;
                    }
                });
            },
            parsePermission(permission) {
                let parts = permission.split('(');
                let type = parts[0];
                let role = parts[1].replace(')', '').replace(' ', '');
                return { type, role };
            },
            addPermission(role, read, create, update, xdelete) {
                if (!document.getElementById('role').reportValidity()) return;
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

                this.reset();
            },
            updatePermission(index) {
                // Because the x-model does not update before the click event,
                // we setTimeout to give Alpine enough time to update the model.
                setTimeout(() => {
                    const permission = this.permissions[index];
                    for (const key of Object.keys(permission)) {
                        if (key === 'role') {
                            continue;
                        }
                        const parsedKey = this.parseKey(key);
                        if (permission[key]) {
                            if (!this.rawPermissions.includes(`${parsedKey}(${permission.role})`)) {
                                this.rawPermissions.push(`${parsedKey}(${permission.role})`);
                            }
                        } else {
                            this.rawPermissions = this.rawPermissions.filter(p => {
                                return !p.includes(`${parsedKey}(${permission.role})`);
                            });
                        }
                    }
                });
            },
            removePermission(index) {
                let row = this.permissions.splice(index, 1);
                if (row.length === 1) {
                    this.rawPermissions = this.rawPermissions.filter(p => !p.includes(row[0].role));
                }
            },
            parseKey(key) {
                if (key === 'xdelete') {
                    return 'delete';
                }
                return key;
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
