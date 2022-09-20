(function (window) {
    document.addEventListener('alpine:init', () => {
        Alpine.data('permissionsMatrix', () => ({
            permissions: [],
            rawPermissions: [],
            load(permissions) {
                if (permissions === undefined) {
                    return;
                }

                this.rawPermissions = permissions;

                permissions.map(p => {
                    let {type, role} = this.parsePermission(p);
                    type = this.parseInputPermission(type);

                    let index = -1;
                    let existing = this.permissions.find((p, idx) => {
                        if (p.role === role) {
                            index = idx;
                            return true;
                        }
                    })
                    if (existing === undefined) {
                        let newPermission = {
                            role,
                            create: false,
                            read: false,
                            update: false,
                            xdelete: false,
                        };
                        newPermission[type] = true;
                        this.permissions.push(newPermission);
                    }
                    if (index !== -1) {
                        existing[type] = true;
                        this.permissions[index] = existing;
                    }
                });
            },
            addPermission(formId) {
                if (this.permissions.length > 0
                    && !this.validate(formId, this.permissions.length - 1)) {
                    return;
                }
                this.permissions.push({
                    role: '',
                    create: false,
                    read: false,
                    update: false,
                    xdelete: false,
                });
            },
            updatePermission(index) {
                // Because the x-model does not update before the click event,
                // we setTimeout to give Alpine enough time to update the model.
                setTimeout(() => {
                    const permission = this.permissions[index];

                    Object.keys(permission).forEach(key => {
                        if (key === 'role') {
                            return;
                        }
                        const parsedKey = this.parseOutputPermission(key);
                        const permissionString = this.buildPermission(parsedKey, permission.role);
                        if (permission[key]) {
                            if (!this.rawPermissions.includes(permissionString)) {
                                this.rawPermissions.push(permissionString);
                            }
                        } else {
                            this.rawPermissions = this.rawPermissions.filter(p => {
                                return !p.includes(permissionString);
                            });
                        }
                    });
                });
            },
            clearPermission(index) {
                let currentRole =  this.permissions[index].role;
                this.rawPermissions = this.rawPermissions.filter(p => {
                    let {type, role} = this.parsePermission(p);

                    return role !== currentRole;
                });
            },
            removePermission(index) {
                let row = this.permissions.splice(index, 1);
                if (row.length === 1) {
                    this.rawPermissions = this.rawPermissions.filter(p => !p.includes(row[0].role));
                }
            },
            parsePermission(permission) {
                let parts = permission.split('(');
                let type = parts[0];
                let role = parts[1]
                    .replace(')', '')
                    .replace(' ', '')
                    .replaceAll('"', '');
                return {type, role};
            },
            buildPermission(type, role) {
                return `${type}("${role}")`
            },
            parseInputPermission(key) {
                // Can't bind to a property named delete
                if (key === 'delete') {
                    return 'xdelete';
                }
                return key;
            },
            parseOutputPermission(key) {
                // Can't bind to a property named delete
                if (key === 'xdelete') {
                    return 'delete';
                }
                return key;
            },
            validate(formId, index) {
                const form = document.getElementById(formId);
                const input = document.getElementById(`${formId}Input${index}`);
                const permission = this.permissions[index];

                input.setCustomValidity('');

                if (permission.role === '') {
                    input.setCustomValidity('Role is required');
                } else if (!Object.entries(permission).some(([k, v]) => !k.includes('role') && v)) {
                    input.setCustomValidity('No permissions selected');
                } else if (this.permissions.some(p => p.role === permission.role && p !== permission)) {
                    input.setCustomValidity('Role entry already exists');
                }

                return form.reportValidity();
            },
            prevent(event) {
                event.preventDefault();
                event.stopPropagation();
            }
        }));
    });
})(window);
