//! `compat`: differential compatibility tester for the PHP and Rust Utopia
//! libraries. Run it through `bin/compat`. See tests/compat/README.md.

use std::path::PathBuf;
use std::process::ExitCode;

use compat::libs::LIBS;
use compat::runner::config::Config;
use compat::runner::coverage::coverage;
use compat::runner::engine::{Engine, FuzzOptions, Report};
use compat::runner::{crates, render_status, report, scaffold, spec};
use serde_json::Value;

const USAGE: &str = "usage: compat <command> [args]

  run [<lib>...] [--case <text>] [--fail-fast]   run cases on PHP and Rust and compare everything
  fuzz <lib>... [--op <op>] [--iterations <n>] [--seed <n>] [--save]
                                                 compare both runtimes on generated inputs
  record <lib> [--case <text>]                   store PHP's results as `expect` in the case files
  coverage <lib>...                              PHP API coverage, adapters, cases, ported tests
  check <lib>...                                 coverage + run + fuzz: the conversion gate
  ci                                             run + fuzz every library; check the complete ones
  status [--write]                               table of every library (tests/compat/STATUS.md)
  report [<lib>...] [--out <dir>] [--iterations <n>] [--seed <n>]
                                                 run, fuzz (200 inputs a profile by default) and
                                                 document every library as JSON for apps/compat
                                                 (default apps/compat/data)
  examples [<crate dir>...]                      run every example in the crates' docs: Rust doctests
                                                 and their PHP twins (default: every crate)
  sync <lib>... [--force]                        record that each crate matches its PHP library as the
                                                 checkout has it (after `run <lib>` passes; --force
                                                 for a crate without compat cases)
  crates [--out <dir>]                           every Utopia library's docs (Rust API, PHP API by
                                                 reflection, examples, status) as JSON for apps/crates
                                                 (default apps/crates/data)
  call <lib> <op> [<args json>] [--side php|rust]  run one operation and print both results
  new <lib>                                      scaffold tests/compat/<lib>

options: -v (list passing cases), COMPAT_PHP / COMPAT_RUST (driver commands),
COMPAT_TIMEOUT (seconds per reply), COMPAT_SEED (fuzz seed)";

struct Cli {
    positional: Vec<String>,
    case: Option<String>,
    op: Option<String>,
    iterations: Option<u64>,
    seed: Option<u64>,
    side: Option<String>,
    out: Option<String>,
    force: bool,
    fail_fast: bool,
    save: bool,
    write: bool,
    verbose: bool,
}

fn parse(args: &[String]) -> Result<Cli, String> {
    let mut cli = Cli {
        positional: Vec::new(),
        case: None,
        op: None,
        iterations: None,
        seed: None,
        side: None,
        out: None,
        force: false,
        fail_fast: false,
        save: false,
        write: false,
        verbose: false,
    };
    let mut it = args.iter();
    while let Some(a) = it.next() {
        let mut value = |name: &str| it.next().cloned().ok_or_else(|| format!("{name} needs a value"));
        match a.as_str() {
            "--case" => cli.case = Some(value("--case")?),
            "--op" => cli.op = Some(value("--op")?),
            "--iterations" => cli.iterations = Some(value("--iterations")?.parse().map_err(|_| "bad --iterations")?),
            "--seed" => cli.seed = Some(value("--seed")?.parse().map_err(|_| "bad --seed")?),
            "--side" => cli.side = Some(value("--side")?),
            "--out" => cli.out = Some(value("--out")?),
            "--fail-fast" => cli.fail_fast = true,
            "--force" => cli.force = true,
            "--save" => cli.save = true,
            "--write" => cli.write = true,
            "-v" | "--verbose" => cli.verbose = true,
            s if s.starts_with('-') => return Err(format!("unknown option {s}")),
            s => cli.positional.push(s.to_owned()),
        }
    }
    Ok(cli)
}

fn root() -> Result<PathBuf, String> {
    let mut dir = std::env::current_dir().map_err(|e| e.to_string())?;
    loop {
        if dir.join("tests/compat/compat.json").is_file() {
            return Ok(dir);
        }
        if !dir.pop() {
            return Err("run compat from inside the appwrite repository".into());
        }
    }
}

fn print_report(what: &str, r: &Report) {
    for d in &r.differences {
        eprintln!("DIFF  {d}");
    }
    for f in &r.faults {
        eprintln!("FAULT {f}");
    }
    eprintln!("{what}: {} passed, {} differences, {} faults", r.passed, r.differences.len(), r.faults.len());
}

fn seed(cli: &Cli) -> u64 {
    cli.seed.or_else(|| std::env::var("COMPAT_SEED").ok().and_then(|s| s.parse().ok())).unwrap_or_else(|| {
        std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(1)
    })
}

fn libs_or_all(cli: &Cli, root: &std::path::Path) -> Vec<String> {
    if cli.positional.len() > 1 { cli.positional[1..].to_vec() } else { spec::libs(root) }
}

fn main() -> ExitCode {
    let args: Vec<String> = std::env::args().skip(1).collect();
    match real_main(&args) {
        Ok(true) => ExitCode::SUCCESS,
        Ok(false) => ExitCode::from(1),
        Err(e) => {
            eprintln!("compat: {e}");
            ExitCode::from(2)
        }
    }
}

fn real_main(args: &[String]) -> Result<bool, String> {
    let cli = parse(args)?;
    let Some(command) = cli.positional.first().cloned() else {
        println!("{USAGE}");
        return Ok(true);
    };
    let root = root()?;
    let mut engine = Engine::new(Config::load(&root)?);
    engine.verbose = cli.verbose;
    let need_lib = |cli: &Cli| -> Result<Vec<String>, String> {
        if cli.positional.len() < 2 {
            Err(format!("`{command}` needs a library\n\n{USAGE}"))
        } else {
            Ok(cli.positional[1..].to_vec())
        }
    };
    match command.as_str() {
        "run" => {
            let mut total = Report::default();
            for lib in libs_or_all(&cli, &root) {
                let r = engine.run_lib(&lib, cli.case.as_deref(), cli.fail_fast)?;
                print_report(&format!("{lib} run"), &r);
                total.merge(r);
            }
            Ok(total.ok())
        }
        "fuzz" => {
            let mut ok = true;
            let seed = seed(&cli);
            eprintln!("seed {seed}");
            for lib in need_lib(&cli)? {
                let opts = FuzzOptions { op: cli.op.clone(), iterations: cli.iterations, seed, save: cli.save };
                let r = engine.fuzz_lib(&lib, &opts)?;
                print_report(&format!("{lib} fuzz"), &r);
                ok &= r.ok();
            }
            Ok(ok)
        }
        "record" => {
            let mut ok = true;
            for lib in need_lib(&cli)? {
                let (written, changed, faults) = engine.record_lib(&lib, cli.case.as_deref())?;
                for f in &faults {
                    eprintln!("FAULT {f}");
                }
                eprintln!(
                    "{lib} record: {written} expectations written, {changed} new or changed, {} faults",
                    faults.len()
                );
                ok &= faults.is_empty();
            }
            Ok(ok)
        }
        "coverage" => {
            let mut ok = true;
            for lib in libs_or_all(&cli, &root) {
                let c = coverage(&mut engine, &lib)?;
                print!("{}", c.render());
                ok &= c.ok();
            }
            Ok(ok)
        }
        "check" => {
            let mut ok = true;
            for lib in need_lib(&cli)? {
                let c = coverage(&mut engine, &lib)?;
                print!("{}", c.render());
                let run = engine.run_lib(&lib, None, false)?;
                print_report(&format!("{lib} run"), &run);
                let fuzz = engine.fuzz_lib(
                    &lib,
                    &FuzzOptions { op: None, iterations: cli.iterations, seed: seed(&cli), save: false },
                )?;
                print_report(&format!("{lib} fuzz"), &fuzz);
                let pass = c.ok() && run.ok() && fuzz.ok();
                eprintln!(
                    "{lib}: {}",
                    if pass { "CONVERTED (crates/CONVERSION.md §2 rules 1-5 hold)" } else { "NOT CONVERTED" }
                );
                ok &= pass;
            }
            Ok(ok)
        }
        "ci" => {
            let mut ok = true;
            for lib in spec::libs(&root) {
                let s = spec::load(&root, &lib)?;
                if s.complete {
                    let c = coverage(&mut engine, &lib)?;
                    print!("{}", c.render());
                    ok &= c.ok();
                }
                let run = engine.run_lib(&lib, None, false)?;
                print_report(&format!("{lib} run"), &run);
                let fuzz = engine.fuzz_lib(
                    &lib,
                    &FuzzOptions { op: None, iterations: cli.iterations, seed: seed(&cli), save: false },
                )?;
                print_report(&format!("{lib} fuzz"), &fuzz);
                ok &= run.ok() && fuzz.ok();
            }
            Ok(ok)
        }
        "report" => {
            let out = cli.out.as_deref().map(PathBuf::from).unwrap_or_else(|| root.join("apps/compat/data"));
            let opts = report::Options { iterations: cli.iterations.unwrap_or(200), seed: seed(&cli) };
            let ok = report::write(&mut engine, &libs_or_all(&cli, &root), &opts, &out)?;
            eprintln!("wrote {}", out.display());
            Ok(ok)
        }
        "examples" => {
            let crates: Vec<String> = if cli.positional.len() > 1 {
                cli.positional[1..].to_vec()
            } else {
                ["crates/utopia", "crates/support"]
                    .iter()
                    .flat_map(|g| {
                        std::fs::read_dir(root.join(g))
                            .into_iter()
                            .flatten()
                            .flatten()
                            .map(move |e| format!("{g}/{}", e.file_name().to_string_lossy()))
                    })
                    .filter(|d| root.join(d).join("Cargo.toml").is_file())
                    .collect()
            };
            compat::runner::examples::verify(&root, &crates, &engine.cfg.php)
        }
        "sync" => {
            let mut ok = true;
            for lib in need_lib(&cli)? {
                let crate_dir = ["crates/utopia", "crates/support"]
                    .iter()
                    .map(|g| format!("{g}/{lib}"))
                    .find(|d| root.join(d).join("Cargo.toml").is_file())
                    .ok_or_else(|| format!("{lib}: no Rust crate"))?;
                // The marker claims the crate matches PHP; only a clean run proves it.
                if root.join("tests/compat").join(&lib).join("spec.json").is_file() {
                    let r = engine.run_lib(&lib, None, false)?;
                    print_report(&format!("{lib} run"), &r);
                    if !r.ok() {
                        eprintln!("{lib}: not synced, the crate does not match PHP yet");
                        ok = false;
                        continue;
                    }
                } else if !cli.force {
                    return Err(format!(
                        "{lib} has no compat cases to prove it matches PHP; pass --force to record it anyway"
                    ));
                }
                let commit = compat::runner::sync::latest(&root, &lib)
                    .ok_or_else(|| format!("{lib}: no PHP history in packages/{lib}"))?;
                let path = root.join(&crate_dir).join("Cargo.toml");
                let manifest = std::fs::read_to_string(&path).map_err(|e| e.to_string())?;
                std::fs::write(&path, compat::runner::sync::with_marker(&manifest, &commit))
                    .map_err(|e| e.to_string())?;
                eprintln!("{lib}: synced with PHP at {}", &commit[..10.min(commit.len())]);
            }
            Ok(ok)
        }
        "crates" => {
            let out = cli.out.as_deref().map(PathBuf::from).unwrap_or_else(|| root.join("apps/crates/data"));
            let n = crates::write(Some(&mut engine), &root, &out)?;
            eprintln!("wrote {n} crates to {}", out.display());
            Ok(true)
        }
        "status" => {
            let have = spec::libs(&root);
            let mut rows = Vec::new();
            for (lib, _) in LIBS {
                let cov = if have.iter().any(|l| l == lib) { Some(coverage(&mut engine, lib)?) } else { None };
                rows.push(((*lib).to_owned(), cov));
            }
            let table = render_status(&rows);
            if cli.write {
                std::fs::write(root.join("tests/compat/STATUS.md"), &table).map_err(|e| e.to_string())?;
                eprintln!("wrote tests/compat/STATUS.md");
            }
            print!("{table}");
            Ok(true)
        }
        "call" => {
            let [_, lib, op, rest @ ..] = cli.positional.as_slice() else {
                return Err("usage: compat call <lib> <op> [<args json>]".to_owned());
            };
            let args: Value = match rest.first() {
                Some(j) => serde_json::from_str(j).map_err(|e| format!("args: {e}"))?,
                None => Value::Object(Default::default()),
            };
            let sides: Vec<&'static str> = match cli.side.as_deref() {
                Some("php") => vec!["php"],
                Some("rust") => vec!["rust"],
                _ => vec!["php", "rust"],
            };
            let results = engine.call(lib, op, &args, &sides)?;
            for (side, v) in &results {
                println!("{side:>4}: {}", serde_json::to_string_pretty(v).unwrap_or_default());
            }
            Ok(results.windows(2).all(|w| compat::runner::compare::diff(&w[0].1, &w[1].1).is_none()))
        }
        "new" => {
            for lib in need_lib(&cli)? {
                for f in scaffold(&root, &lib)? {
                    println!("created {f}");
                }
            }
            Ok(true)
        }
        other => Err(format!("unknown command `{other}`\n\n{USAGE}")),
    }
}
