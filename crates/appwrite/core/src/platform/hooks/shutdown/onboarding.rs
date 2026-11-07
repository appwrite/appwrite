//! Onboarding stages (PHP: `app/controllers/shared/api.php`, the `api`
//! group's shutdown hook that completes the project's onboarding stages;
//! hooks.json `onboarding`).

use serde_json::{Map, Value};
use utopia_database::Document;

use super::Job;
use crate::auth::KeyType;
use crate::database::{Databases, filters};
use crate::datetime;
use crate::platform::context::Mode;

/// The onboarding stages (`app/config/onboarding.php`).
const ONBOARDING: &[&str] = &[
    "project.createWebPlatform",
    "project.createAndroidPlatform",
    "project.createApplePlatform",
    "project.createWindowsPlatform",
    "project.createLinuxPlatform",
    "project.createKey",
    "organization.createProjectKey",
    "cli.install",
    "mcp.install",
    "users.create",
    "account.create",
    "account.createAnonymousSession",
    "teams.create",
    "tablesDB.create",
    "tablesDB.createTable",
    "tablesDB.createRow",
    "documentsDB.create",
    "documentsDB.createCollection",
    "documentsDB.createDocument",
    "databases.create",
    "databases.createCollection",
    "databases.createDocument",
    "mysql.create",
    "postgresql.create",
    "mongo.create",
    "storage.createBucket",
    "storage.createFile",
    "functions.create",
    "functions.createDeployment",
    "functions.createTemplateDeployment",
    "functions.createVcsDeployment",
    "functions.updateFunctionDeployment",
    "messaging.createTopic",
    "messaging.createMailgunProvider",
    "messaging.createSendgridProvider",
    "messaging.createSesProvider",
    "messaging.createResendProvider",
    "messaging.createSmtpProvider",
    "messaging.createSMTPProvider",
    "messaging.createMsg91Provider",
    "messaging.createTelesignProvider",
    "messaging.createTextmagicProvider",
    "messaging.createTwilioProvider",
    "messaging.createVonageProvider",
    "messaging.createFcmProvider",
    "messaging.createFCMProvider",
    "messaging.createApnsProvider",
    "messaging.createAPNSProvider",
    "sites.create",
    "sites.createDeployment",
    "sites.createTemplateDeployment",
    "sites.createVcsDeployment",
    "sites.updateSiteDeployment",
];

impl Job {
    pub(super) async fn onboarding(&self) {
        if !self.status.is_success() || self.project.is_console() {
            return;
        }
        // The route's SDK method, and the CLI or MCP install named by the
        // client's SDK headers.
        let mut methods: Vec<String> = Vec::new();
        let sdk = self.request.sdk_name.as_str();
        let cli = matches!(sdk, "cli" | "Command Line") || self.request.sdk_language == "cli";
        if self.route.namespace.is_empty() && sdk != "mcp" && !cli {
            return;
        }
        let method = format!("{}.{}", self.route.namespace, self.route.sdk_method);
        if ONBOARDING.contains(&method.as_str()) {
            methods.push(method);
        }
        let install = match sdk {
            "mcp" => Some("mcp.install"),
            _ if cli => Some("cli.install"),
            _ => None,
        };
        if let Some(install) = install.filter(|i| ONBOARDING.contains(i)) {
            methods.push(install.to_owned());
        }
        if methods.is_empty() {
            return;
        }
        let mut stages = match &self.project.onboarding {
            Value::Object(m) => m.clone(),
            _ => Map::new(),
        };
        methods.retain(|m| stages.get(m).and_then(|r| r.get("status")).and_then(Value::as_str) != Some("completed"));
        if methods.is_empty() {
            return;
        }
        let actor_type = match self.key_kind {
            Some(KeyType::Account) => "keyAccount",
            Some(KeyType::Organization) => "keyOrganization",
            Some(_) => "keyProject",
            None if self.has_user => {
                if self.mode == Mode::Admin {
                    "admin"
                } else {
                    "user"
                }
            }
            None => "guest",
        };
        let at = datetime::format_db(&datetime::now());
        for method in methods {
            let mut row = Map::new();
            row.insert("status".into(), Value::String("completed".into()));
            row.insert("at".into(), Value::String(at.clone()));
            row.insert("actorType".into(), Value::String(actor_type.into()));
            stages.insert(method, Value::Object(row));
        }
        // The `json` filter passes text through: stored as PHP encodes it.
        let encoded = crate::json::to_string(&Value::Object(stages));
        let Ok(db) = self.state.databases.platform(Databases::unchecked()) else { return };
        let project = self.project.clone();
        let state = self.state.clone();
        let lock_key = format!("lock:platform:{}:onboarding", project.sequence);
        let _ = self
            .state
            .lock
            .try_with_key(&lock_key, async move {
                let mut updates = Document::default();
                updates.put("onboarding", utopia_database::Value::from(encoded.as_str()));
                let skipped = filters::names(filters::PROJECTS_SUBQUERIES);
                let updated = db
                    .skip_filters(Some(skipped.as_slice()), db.update_document("projects", &project.id, updates))
                    .await;
                // A missing `onboarding` attribute must not break the request.
                if updated.is_ok() {
                    state.projects.forget(&project.id);
                }
            })
            .await;
    }
}

#[cfg(test)]
mod onboarding_tests {
    /// The stages match `app/config/onboarding.php`.
    #[test]
    fn stages_follow_the_php_config() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/../../../app/config/onboarding.php");
        let php = std::fs::read_to_string(path).expect("onboarding.php");
        let keys: Vec<&str> = php
            .lines()
            .map(str::trim)
            .filter(|l| l.starts_with('\'') && l.contains("=> true,"))
            .filter_map(|l| l[1..].split('\'').next())
            .collect();
        assert_eq!(keys, super::ONBOARDING);
    }
}
