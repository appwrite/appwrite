(function (window) {
    document.addEventListener('alpine:init', () => {
        Alpine.data('events', () => ({
            events: new Set(),
            selected: null,
            action: null,
            type: null,
            subType: null,
            subSubType: null,
            resource: null,
            resourceName: '',
            subResource: null,
            subResourceName: '',
            subSubResource: null,
            subSubResourceName: '',
            hasResource: false,
            hasSubResource: false,
            hasSubSubResource: false,
            attribute: null,
            hasAttribute: false,
            attributes: [],
            load(events) {
                this.events = new Set(events);
            },
            reset() {
                this.hasResource = this.hasSubResource = this.hasSubSubResource = this.hasAttribute = false;
                this.type = this.subType = this.subSubResource = this.subResource = this.resource = this.attribute = this.selected = this.action = null;
            },
            setEvent() {
                this.hasResource = this.hasSubResource = this.hasSubSubResource = this.hasAttribute = this.action = false;

                if (!this.selected) {
                    this.reset();
                    return;
                }

                let [type, action] = this.selected.split('.');

                switch (type) {
                    case 'users':
                        if (action === 'update') {
                            this.hasAttribute = true;
                            this.attributes = ['email', 'name', 'password', 'status', 'prefs']
                        }
                        this.hasResource = true;
                        this.type = type;
                        this.resourceName = 'User ID';
                        break;

                    case 'collections':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'databases';
                        this.subType = type;
                        this.resourceName = 'Database ID';
                        this.subResourceName = 'Collection ID';
                        break;

                    case 'databases':
                        this.hasResource = true;
                        this.type = type;
                        this.resourceName = 'Database ID';
                        break;

                    case 'teams':
                        this.hasResource = true;
                        this.type = type;
                        this.resourceName = 'Team ID';
                        break;

                    case 'buckets':
                        this.hasResource = true;
                        this.type = type;
                        this.resourceName = 'Bucket ID';
                        break;

                    case 'functions':
                        this.hasResource = true;
                        this.type = type;
                        this.resourceName = 'Function ID';
                        break;

                    case 'sessions':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'users';
                        this.subType = type;
                        this.resourceName = 'User ID';
                        this.subResourceName = 'Session ID';
                        break;

                    case 'verification':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'users';
                        this.subType = type;
                        this.resourceName = 'User ID';
                        this.subResourceName = 'Verification ID';
                        break;

                    case 'recovery':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'users';
                        this.subType = type;
                        this.resourceName = 'User ID';
                        this.subResourceName = 'Recovery ID';
                        break;

                    case 'documents':
                        this.hasResource = this.hasSubResource = this.hasSubSubResource = true;
                        this.type = 'databases';
                        this.subType = 'collections';
                        this.subSubType = type;
                        this.resourceName = 'Database ID';
                        this.subResourceName = 'Collection ID';
                        this.subSubResourceName = 'Document ID';
                        break;

                    case 'attributes':
                        this.hasResource = this.hasSubResource = this.hasSubSubResource = true;
                        this.type = 'databases';
                        this.subType = 'collections';
                        this.subSubType = type;
                        this.resourceName = 'Database ID';
                        this.subResourceName = 'Collection ID';
                        this.subSubResourceName = 'Attribute ID';
                        break;

                    case 'indexes':
                        this.hasResource = this.hasSubResource = this.hasSubSubResource = true;
                        this.type = 'databases';
                        this.subType = 'collections';
                        this.subSubType = type;
                        this.resourceName = 'Database ID';
                        this.subResourceName = 'Collection ID';
                        this.subSubResourceName = 'Index ID';
                        break;

                    case 'files':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'buckets';
                        this.subType = type;
                        this.resourceName = 'Bucket ID';
                        this.subResourceName = 'File ID';
                        break;

                    case 'memberships':
                        if (action === 'update') {
                            this.hasAttribute = true;
                            this.attributes = ['status']
                        }
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'teams';
                        this.subType = type;
                        this.resourceName = 'Team ID';
                        this.subResourceName = 'Membership ID';
                        break;

                    case 'executions':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'functions';
                        this.subType = type;
                        this.resourceName = 'Function ID';
                        this.subResourceName = 'Execution ID';
                        break;

                    case 'deployments':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'functions';
                        this.subType = type;
                        this.resourceName = 'Function ID';
                        this.subResourceName = 'Deployment ID';
                        break;

                    default:
                        this.hasResource = true;
                        this.hasSubResource = true;
                        this.hasSubSubResource = true;

                        break;
                }
                this.action = action;
            },
            showModal(modal) {
                document.documentElement.classList.add("modal-open");
                modal.classList.remove("close");
                modal.classList.add("open");
            },
            closeModal(modal) {
                document.documentElement.classList.remove("modal-open");
                modal.classList.add("close");
                modal.classList.remove("open");
            },
            addEvent(modal) {
                this.closeModal(modal);

                let event = `${this.type}.${this.resource ? this.resource : '*'}`;

                if (this.hasSubResource) {
                    event += `.${this.subType}.${this.subResource ? this.subResource : '*'}`;
                }

                if (this.hasSubSubResource) {
                    event += `.${this.subSubType}.${this.subSubResource ? this.subSubResource : '*'}`;
                }

                if (this.action) {
                    event += `.${this.action}`;
                }

                if (this.attribute) {
                    event += `.${this.attribute}`;
                }

                this.events.add(event);

                this.reset();
            },
            removeEvent(value) {
                this.events.delete(value);
            }
        }));
    });
})(window);