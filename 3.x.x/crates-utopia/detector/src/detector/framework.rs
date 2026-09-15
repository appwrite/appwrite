use crate::detection::framework::{Astro, Framework as FrameworkDetection};
use crate::error::DetectorError;
use crate::input::Input;

/// PHP `Utopia\Detector\Detector\Framework`.
#[derive(Debug)]
pub struct Framework {
    inputs: Vec<Input>,
    options: Vec<Box<dyn FrameworkDetection>>,
    packager: String,
}

impl Framework {
    /// PHP `Framework::INPUT_FILE`.
    pub const INPUT_FILE: &'static str = "file";
    /// PHP `Framework::INPUT_PACKAGES`.
    pub const INPUT_PACKAGES: &'static str = "packages";

    /// PHP `__construct(string $packager = 'pnpm')`.
    #[must_use]
    pub fn new(packager: impl Into<String>) -> Self {
        Self {
            inputs: Vec::new(),
            options: Vec::new(),
            packager: packager.into(),
        }
    }

    /// PHP `addInput(string $content, string $type = '')`.
    pub fn add_input(
        &mut self,
        content: impl Into<String>,
        type_: impl Into<String>,
    ) -> Result<&mut Self, DetectorError> {
        let type_ = type_.into();
        if type_ != Self::INPUT_FILE && type_ != Self::INPUT_PACKAGES {
            return Err(DetectorError::InvalidInputType(type_));
        }
        self.inputs.push(Input::new(content, type_));
        Ok(self)
    }

    /// PHP `addOption(Detection $option)`.
    pub fn add_option(&mut self, option: impl FrameworkDetection + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// PHP `detect()`.
    #[must_use]
    pub fn detect(&self) -> Option<Box<dyn FrameworkDetection>> {
        let files: Vec<&str> = self
            .inputs
            .iter()
            .filter(|input| input.type_ == Self::INPUT_FILE)
            .map(|input| input.content.as_str())
            .collect();
        let packages: Vec<&str> = self
            .inputs
            .iter()
            .filter(|input| input.type_ == Self::INPUT_PACKAGES)
            .map(|input| input.content.as_str())
            .collect();

        let mut matches: Vec<(usize, usize, usize)> = Vec::new();
        for (index, detector) in self.options.iter().enumerate() {
            let mut count = 0usize;
            for package_json in &packages {
                for package_needed in detector.get_packages() {
                    let needle = format!("\"{package_needed}\"");
                    if package_json.contains(&needle) {
                        count += 1;
                    }
                }
            }
            count += detector
                .get_files()
                .iter()
                .filter(|file| files.contains(&file.as_str()))
                .count();
            matches.push((index, count, detector.parent_count()));
        }

        let positive: Vec<(usize, usize, usize)> = matches
            .into_iter()
            .filter(|(_, count, _)| *count > 0)
            .collect();
        if positive.is_empty() {
            return None;
        }

        let highest = positive
            .iter()
            .map(|(_, count, _)| *count)
            .max()
            .unwrap_or(0);
        let mut winners: Vec<(usize, usize, usize)> = positive
            .into_iter()
            .filter(|(_, count, _)| *count == highest)
            .collect();

        let best_index = if winners.len() == 1 {
            winners[0].0
        } else {
            winners.sort_by(|a, b| a.2.cmp(&b.2));
            let astro = Astro::new().get_name();
            let first = winners[0].0;
            if self.options[first].get_name() == astro && winners.len() > 1 {
                winners[1].0
            } else {
                first
            }
        };

        let mut detected = self.options[best_index].box_clone();
        detected.set_packager(self.packager.clone());
        Some(detected)
    }
}
