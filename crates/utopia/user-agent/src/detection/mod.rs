//! The detectors (`Utopia\UserAgent\Detection\*`): pure functions from a
//! user-agent string to what it reveals. [`UserAgent`](crate::UserAgent)
//! runs them lazily and keeps their results.

mod bot;
mod client;
mod device;
mod os;

pub use bot::bot;
pub use client::client;
pub use device::device;
pub use os::operating_system;

#[cfg(test)]
mod tests {
    use super::*;

    /// Reaches every rule, so every pattern compiles (patterns compile on
    /// first use and a bad one panics).
    #[test]
    fn every_pattern_compiles() {
        let agents: [&[u8]; 14] = [
            b"x",
            b"Mozilla/5.0 (Linux; Android 13; SM-T970 Build/X) AppleWebKit/537.36 Chrome/1.0 Mobile Safari/537.36",
            b"Mozilla/5.0 (Linux; Android 9; KFMAWI) Silk/104.5.1 Focus/1 HuaweiBrowser/1",
            b"Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0",
            b"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.4 Safari/605.1.15",
            b"Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) Opera Mini/1 Presto/2",
            b"Mozilla/5.0 (Web0S; Linux/SmartTV) webOS/1.0",
            b"Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) BRAVIA",
            b"Mozilla/5.0 (Phone; OpenHarmony 5.0) HarmonyOS 4",
            b"Mozilla/5.0 (Apple TV; tvOS 17.0) Watch OS 10 WatchOS/10",
            b"Mozilla/5.0 (Windows Phone 10.0; Android 6.0.1; Microsoft; Lumia 950 XL)",
            b"Mozilla/5.0 (BB10; Touch) BlackBerry 9900/5.0 Kindle",
            b"Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.2; Trident/6.0) Smart-TV",
            b"com.example.app/1.0 iPhone17,1 iOS/18.1",
        ];
        for agent in agents {
            operating_system(agent);
            client(agent);
            device(agent);
            bot(agent);
        }
    }
}
