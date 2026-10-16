use serde_json::Value;
use utopia_cli::adapters::Generic;
use utopia_cli::{Cli, Params};
use utopia_console::Console;
use utopia_domains::sync::{
    default_data_dir, fetch_psl_json, load_psl_json, write_psl_json, PSL_URL,
};
use utopia_validators::{Boolean, Text};

fn main() {
    let mut cli = match Cli::new(
        Some(Box::new(Generic::new())),
        std::env::args().collect(),
        None,
    ) {
        Ok(cli) => cli,
        Err(err) => {
            let _ = Console::error(&format!("{err}"));
            let _ = Console::info(
                "Usage: utopia-domains-sync psl [--commit=true] [--force=true] [--data-dir=PATH]",
            );
            Console::exit(1);
        }
    };

    cli.task("psl")
        .desc("Refresh data/psl.json from the Public Suffix List")
        .param(
            "commit",
            Value::Bool(false),
            Boolean::new().loose(true),
            "Write psl.json when the snapshot changed",
            true,
        )
        .param(
            "force",
            Value::Bool(false),
            Boolean::new().loose(true),
            "Write even when the snapshot is unchanged",
            true,
        )
        .param(
            "data-dir",
            Value::String(String::new()),
            Text::new(4096),
            "Output directory (default: crate data/)",
            true,
        )
        .param(
            "url",
            Value::String(String::new()),
            Text::new(2048),
            "Override Public Suffix List URL",
            true,
        )
        .action(|params: &Params| {
            let commit = params.get_bool("commit").unwrap_or(false);
            let force = params.get_bool("force").unwrap_or(false);
            let data_dir = params
                .get_str("dataDir")
                .filter(|s| !s.is_empty())
                .map_or_else(default_data_dir, std::path::PathBuf::from);
            let url = params
                .get_str("url")
                .filter(|s| !s.is_empty())
                .unwrap_or(PSL_URL);

            Console::title("PSL");
            let _ = Console::success("Utopia Domains Public Suffix List update has started");

            match run_psl(url, &data_dir, commit, force) {
                Ok(code) => Console::exit(code),
                Err(err) => {
                    let _ = Console::error(&format!("{err}"));
                    Console::exit(1);
                }
            }
        });

    cli.run();
}

fn run_psl(
    url: &str,
    data_dir: &std::path::Path,
    commit: bool,
    force: bool,
) -> Result<i32, utopia_domains::sync::SyncError> {
    let json = fetch_psl_json(url)?;
    let path = data_dir.join("psl.json");
    let current = load_psl_json(&path)?;
    let unchanged = current.as_deref() == Some(json.as_str());

    let _ = Console::info(&format!("Fetched {} bytes of psl.json", json.len()));

    if unchanged && !force {
        let _ = Console::success("Public Suffix List is already up to date");
        return Ok(0);
    }

    if !unchanged {
        let prev = current.as_ref().map_or(0, String::len);
        let _ = Console::info("Changes detected:");
        let _ = Console::info(&format!("- Previous snapshot bytes: {prev}"));
        let _ = Console::info(&format!("- New snapshot bytes: {}", json.len()));
    }

    if commit {
        let written = write_psl_json(data_dir, &json)?;
        let _ = Console::success(&format!("Successfully updated {}", written.display()));
        Ok(0)
    } else {
        let _ = Console::warning(
            "Changes not yet committed. Provide --commit=true to write data/psl.json.",
        );
        Ok(0)
    }
}
