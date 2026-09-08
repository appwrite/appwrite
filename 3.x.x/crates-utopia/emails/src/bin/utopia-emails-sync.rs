use serde_json::Value;
use utopia_cli::adapters::Generic;
use utopia_cli::{Cli, Params};
use utopia_console::Console;
use utopia_emails::sync::{
    commit_update, default_data_dir, domain_statistics, load_json_list, plan_update,
    BlockingFetcher, ListKind, UpdatePlan,
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
                "Usage: utopia-emails-sync <disposable|free|all|stats> [--commit=true] [--force=true] [--source=NAME]",
            );
            Console::exit(1);
        }
    };

    register_list_task(&mut cli, "disposable", ListKind::Disposable);
    register_list_task(&mut cli, "free", ListKind::Free);

    cli.task("all")
        .desc("Update both disposable and free email domains from all sources")
        .param(
            "commit",
            Value::Bool(false),
            Boolean::new().loose(true),
            "Commit changes to config files",
            true,
        )
        .param(
            "force",
            Value::Bool(false),
            Boolean::new().loose(true),
            "Force update even if no changes detected",
            true,
        )
        .param(
            "data-dir",
            Value::String(String::new()),
            Text::new(4096),
            "Data directory (default: crate data/)",
            true,
        )
        .action(|params: &Params| {
            let commit = params.get_bool("commit").unwrap_or(false);
            let force = params.get_bool("force").unwrap_or(false);
            let data_dir = data_dir(params);
            Console::title("All Email Domains Update");
            let _ = Console::success("Utopia Emails all domains update process has started");
            let fetcher = match BlockingFetcher::new() {
                Ok(fetcher) => fetcher,
                Err(err) => {
                    let _ = Console::error(&err.to_string());
                    Console::exit(1);
                }
            };
            for kind in [ListKind::Disposable, ListKind::Free] {
                if let Err(err) = run_list(&fetcher, kind, "", &data_dir, commit, force) {
                    let _ = Console::error(&format!("Error updating all email domains: {err}"));
                    Console::exit(1);
                }
                let _ = Console::info("");
            }
            let _ = Console::success("Successfully updated all email domains");
            Console::exit(0);
        });

    cli.task("stats")
        .desc("Show statistics about current domain lists")
        .param(
            "data-dir",
            Value::String(String::new()),
            Text::new(4096),
            "Data directory (default: crate data/)",
            true,
        )
        .action(|params: &Params| {
            Console::title("Email Domains Statistics");
            let data_dir = data_dir(params);
            match show_stats(&data_dir) {
                Ok(()) => Console::exit(0),
                Err(err) => {
                    let _ = Console::error(&format!("Error showing statistics: {err}"));
                    Console::exit(1);
                }
            }
        });

    cli.run();
}

fn register_list_task(cli: &mut Cli, name: &str, kind: ListKind) {
    let desc = match kind {
        ListKind::Disposable => "Update disposable email domains from multiple sources",
        ListKind::Free => "Update free email domains from multiple sources",
    };
    cli.task(name)
        .desc(desc)
        .param(
            "commit",
            Value::Bool(false),
            Boolean::new().loose(true),
            "If set will commit changes to config file. Default is false.",
            true,
        )
        .param(
            "force",
            Value::Bool(false),
            Boolean::new().loose(true),
            "Force update even if no changes detected. Default is false.",
            true,
        )
        .param(
            "source",
            Value::String(String::new()),
            Text::new(100),
            "Specific source to update (optional). Leave empty to update all sources.",
            true,
        )
        .param(
            "data-dir",
            Value::String(String::new()),
            Text::new(4096),
            "Data directory (default: crate data/)",
            true,
        )
        .action(move |params: &Params| {
            let title = match kind {
                ListKind::Disposable => "Disposable Email Domains Update",
                ListKind::Free => "Free Email Domains Update",
            };
            Console::title(title);
            let started = match kind {
                ListKind::Disposable => {
                    "Utopia Emails disposable domains update process has started"
                }
                ListKind::Free => "Utopia Emails free domains update process has started",
            };
            let _ = Console::success(started);
            let fetcher = match BlockingFetcher::new() {
                Ok(fetcher) => fetcher,
                Err(err) => {
                    let _ = Console::error(&err.to_string());
                    Console::exit(1);
                }
            };
            let commit = params.get_bool("commit").unwrap_or(false);
            let force = params.get_bool("force").unwrap_or(false);
            let source = params.get_str("source").unwrap_or("");
            let data_dir = data_dir(params);
            match run_list(&fetcher, kind, source, &data_dir, commit, force) {
                Ok(code) => Console::exit(code),
                Err(err) => {
                    let _ = Console::error(&format!(
                        "Error updating {} email domains: {err}",
                        kind.label()
                    ));
                    Console::exit(1);
                }
            }
        });
}

fn data_dir(params: &Params) -> std::path::PathBuf {
    params
        .get_str("dataDir")
        .filter(|s| !s.is_empty())
        .map_or_else(default_data_dir, std::path::PathBuf::from)
}

fn run_list(
    fetcher: &BlockingFetcher,
    kind: ListKind,
    source: &str,
    data_dir: &std::path::Path,
    commit: bool,
    force: bool,
) -> Result<i32, utopia_emails::sync::SyncError> {
    let plan = plan_update(fetcher, kind, source, data_dir, force)?;
    print_merge(kind, &plan);

    if plan.merge.domains.is_empty() {
        return Err(utopia_emails::sync::SyncError::EmptyList(kind.label()));
    }

    let stats = domain_statistics(&plan.next);
    print_stats(&stats);

    if plan.up_to_date {
        let _ = Console::success(&format!(
            "{} email domains are already up to date",
            kind_title(kind)
        ));
        return Ok(0);
    }

    let _ = Console::info("Changes detected:");
    let _ = Console::info(&format!("- Previous domains count: {}", plan.current.len()));
    let _ = Console::info(&format!("- New domains count: {}", plan.next.len()));

    if commit {
        commit_update(kind, data_dir, &plan.next)?;
        let _ = Console::success(&format!(
            "Successfully updated {} email domains configuration",
            kind.label()
        ));
    } else {
        let _ = Console::warning(
            "Changes not yet committed to config file. Please provide --commit=true argument to commit changes.",
        );
        let _ = Console::info("Preview of changes:");
        print_preview(&plan);
    }
    Ok(0)
}

fn print_merge(kind: ListKind, plan: &UpdatePlan) {
    let total = plan.merge.reports.len();
    let _ = Console::info(&format!("Fetching from {total} sources..."));
    for (index, report) in plan.merge.reports.iter().enumerate() {
        let _ = Console::info(&format!(
            "[{}/{}] Processing {}...",
            index + 1,
            total,
            report.name
        ));
        if let Some(error) = &report.error {
            let _ = Console::warning(&format!("⚠ Failed to fetch from {}: {error}", report.name));
        } else {
            let _ = Console::info(&format!(
                "✓ Fetched {} domains from {}",
                report.fetched, report.name
            ));
        }
    }
    let _ = Console::info(&format!(
        "Total domains fetched: {}",
        plan.merge.total_fetched
    ));
    let _ = Console::info(&format!(
        "Duplicates removed: {}",
        plan.merge.duplicates_removed
    ));
    let _ = Console::info(&format!(
        "Total unique domains after merging all sources: {}",
        plan.next.len()
    ));
    let _ = Console::info(&format!(
        "Fetched {} {} email domains from all sources",
        plan.next.len(),
        kind.label()
    ));
}

fn kind_title(kind: ListKind) -> &'static str {
    match kind {
        ListKind::Disposable => "Disposable",
        ListKind::Free => "Free",
    }
}

fn print_stats(stats: &utopia_emails::sync::DomainStats) {
    let _ = Console::info("Analyzing domain statistics...");
    let total = stats.total.max(1) as f64;
    let _ = Console::info("Domain Statistics:");
    let _ = Console::info(&format!(
        "├─ Known domains: {} ({:.1}%)",
        stats.known,
        (stats.known as f64 / total) * 100.0
    ));
    let _ = Console::info(&format!(
        "├─ ICANN domains: {} ({:.1}%)",
        stats.icann,
        (stats.icann as f64 / total) * 100.0
    ));
    let _ = Console::info(&format!(
        "├─ Private domains: {} ({:.1}%)",
        stats.private,
        (stats.private as f64 / total) * 100.0
    ));
    let _ = Console::info(&format!(
        "└─ Unknown domains: {} ({:.1}%)",
        stats.unknown,
        (stats.unknown as f64 / total) * 100.0
    ));
    let _ = Console::info("Top 10 TLDs:");
    for (tld, count) in &stats.top_tlds {
        let _ = Console::info(&format!("  ├─ .{tld}: {count} domains"));
    }
}

fn print_preview(plan: &UpdatePlan) {
    if !plan.added.is_empty() {
        let _ = Console::info(&format!("Domains to be added ({}):", plan.added.len()));
        for domain in plan.added.iter().take(10) {
            let _ = Console::info(&format!("  ├─ + {domain}"));
        }
        if plan.added.len() > 10 {
            let _ = Console::info(&format!("  └─ ... and {} more", plan.added.len() - 10));
        }
    }
    if !plan.removed.is_empty() {
        let _ = Console::info(&format!("Domains to be removed ({}):", plan.removed.len()));
        for domain in plan.removed.iter().take(10) {
            let _ = Console::info(&format!("  ├─ - {domain}"));
        }
        if plan.removed.len() > 10 {
            let _ = Console::info(&format!("  └─ ... and {} more", plan.removed.len() - 10));
        }
    }
}

fn show_stats(data_dir: &std::path::Path) -> Result<(), utopia_emails::sync::SyncError> {
    let disposable = load_json_list(&data_dir.join(ListKind::Disposable.combined_file()))?;
    let free = load_json_list(&data_dir.join(ListKind::Free.combined_file()))?;
    let _ = Console::info("Current Domain Statistics:");
    let _ = Console::info(&format!("├─ Disposable domains: {}", disposable.len()));
    let _ = Console::info(&format!("└─ Free domains: {}", free.len()));
    if !disposable.is_empty() {
        let _ = Console::info("");
        let _ = Console::info("Disposable Domains Analysis:");
        print_stats(&domain_statistics(&disposable));
    }
    if !free.is_empty() {
        let _ = Console::info("");
        let _ = Console::info("Free Domains Analysis:");
        print_stats(&domain_statistics(&free));
    }
    Ok(())
}
