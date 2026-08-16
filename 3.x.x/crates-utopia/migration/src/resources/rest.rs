//! Messaging, integrations, settings, templates, domains, backups.

use crate::resource::{
    Resource, ResourceBase, TYPE_API_KEY, TYPE_BACKUP_POLICY, TYPE_MESSAGE, TYPE_PLATFORM,
    TYPE_PROJECT_EMAIL_TEMPLATE, TYPE_PROJECT_LABELS, TYPE_PROJECT_PROTOCOLS,
    TYPE_PROJECT_SERVICES, TYPE_PROJECT_VARIABLE, TYPE_PROVIDER, TYPE_RULE, TYPE_SMTP,
    TYPE_SUBSCRIBER, TYPE_TOPIC, TYPE_WEBHOOK,
};
use crate::transfer::{
    GROUP_BACKUPS, GROUP_DOMAINS, GROUP_INTEGRATIONS, GROUP_MESSAGING, GROUP_PROJECTS,
};

macro_rules! named {
    ($ty:ident, $const:expr, $group:expr) => {
        #[derive(Debug, Clone)]
        pub struct $ty {
            base: ResourceBase,
        }
        impl $ty {
            pub fn new(id: impl Into<String>) -> Self {
                Self {
                    base: ResourceBase::new(id),
                }
            }
        }
        impl Resource for $ty {
            fn get_name(&self) -> &'static str {
                $const
            }
            fn get_group(&self) -> &'static str {
                $group
            }
            fn base(&self) -> &ResourceBase {
                &self.base
            }
            fn base_mut(&mut self) -> &mut ResourceBase {
                &mut self.base
            }
        }
    };
}

pub mod messaging {
    use super::*;
    named!(Provider, TYPE_PROVIDER, GROUP_MESSAGING);
    named!(Topic, TYPE_TOPIC, GROUP_MESSAGING);
    named!(Subscriber, TYPE_SUBSCRIBER, GROUP_MESSAGING);
    named!(Message, TYPE_MESSAGE, GROUP_MESSAGING);
}

pub mod integrations {
    use super::*;
    named!(ApiKey, TYPE_API_KEY, GROUP_INTEGRATIONS);
    named!(Platform, TYPE_PLATFORM, GROUP_INTEGRATIONS);
}

pub mod settings {
    use super::*;
    named!(Webhook, TYPE_WEBHOOK, GROUP_INTEGRATIONS);
    named!(Smtp, TYPE_SMTP, GROUP_INTEGRATIONS);
    named!(ProjectVariable, TYPE_PROJECT_VARIABLE, GROUP_PROJECTS);
    named!(Protocols, TYPE_PROJECT_PROTOCOLS, GROUP_PROJECTS);
    named!(Labels, TYPE_PROJECT_LABELS, GROUP_PROJECTS);
    named!(Services, TYPE_PROJECT_SERVICES, GROUP_PROJECTS);
}

pub mod templates {
    use super::*;
    named!(EmailTemplate, TYPE_PROJECT_EMAIL_TEMPLATE, GROUP_PROJECTS);
}

pub mod domains {
    use super::*;
    named!(Rule, TYPE_RULE, GROUP_DOMAINS);
}

pub mod backups {
    use super::*;
    named!(Policy, TYPE_BACKUP_POLICY, GROUP_BACKUPS);
}
