(function (window) {
    document.addEventListener('alpine:init', () => {
        Alpine.data('events', () => ({
            events: [],
            selected: null,
            action: null,
            type: null,
            subType: null,
            resource: null,
            resourceName: '',
            subResource: null,
            subResourceName: '',
            hasResource: false,
            hasSubResource: false,
            attribute: null,
            hasAttribute: false,
            attributes: [],
            reset() {
                this.hasResource = this.hasSubResource = this.hasAttribute = false;
                this.type = this.subType = this.subResource = this.resource = this.attribute = this.selected = this.action = null;
            },
            setEvent() {
                this.hasResource = this.hasSubResource = this.hasAttribute = this.action = false;

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
                        this.hasResource = true;
                        this.type = type;
                        this.resourceName = 'Collection ID';
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
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'collections';
                        this.subType = type;
                        this.resourceName = 'Collection ID';
                        this.subResourceName = 'Document ID';
                        break;

                    case 'attributes':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'collections';
                        this.subType = type;
                        this.resourceName = 'Collection ID';
                        this.subResourceName = 'Attribute ID';
                        break;

                    case 'indexes':
                        this.hasResource = this.hasSubResource = true;
                        this.type = 'collections';
                        this.subType = type;
                        this.resourceName = 'Collection ID';
                        this.subResourceName = 'Index ID';
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

                        break;
                }
                this.action = action;
            },
            addEvent() {
                let event = `${this.type}.${this.resource ? this.resource : '*'}`;

                if (this.hasSubResource) {
                    event += `.${this.subType}.${this.subResource ? this.subResource : '*'}`;
                }

                if (this.action) {
                    event += `.${this.action}`;
                }

                if (this.attribute) {
                    event += `.${this.attribute}`;
                }

                this.events.push(event);

                this.reset();
            },
            removeEvent(index) {
                this.events.splice(index, 1);
            }
        }));
    });
})(window);