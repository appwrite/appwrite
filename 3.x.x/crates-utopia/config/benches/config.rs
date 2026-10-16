use std::time::Instant;
use utopia_config::{resolve_value, Config, DotenvParser, JsonParser, VariableSource, YamlParser};

fn bench(name: &str, mut f: impl FnMut()) {
    let warmup = Instant::now();
    while warmup.elapsed().as_millis() < 50 {
        f();
    }
    let iters = 50_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn main() {
    let json_text = r#"{"db.host":"docker.internal","db.config.tls":true,"items":[1,2,3]}"#;
    let yaml_text = "db:\n  host: docker.internal\n  config:\n    tls: true\n";
    let dotenv_text = "HOST=127.0.0.1\nPORT=3306\nENABLED=true\n";

    bench("config_json_parse", || {
        let source = VariableSource::from_text(json_text);
        std::hint::black_box(Config::load_map(&source, &JsonParser).unwrap());
    });

    bench("config_yaml_parse", || {
        let source = VariableSource::from_text(yaml_text);
        std::hint::black_box(Config::load_map(&source, &YamlParser).unwrap());
    });

    bench("config_dotenv_parse", || {
        let source = VariableSource::from_text(dotenv_text);
        std::hint::black_box(Config::load_map(&source, &DotenvParser).unwrap());
    });

    let map = Config::load_map(&VariableSource::from_text(json_text), &JsonParser).unwrap();
    bench("config_resolve_value", || {
        std::hint::black_box(resolve_value(&map, "db.config.tls"));
    });
}
