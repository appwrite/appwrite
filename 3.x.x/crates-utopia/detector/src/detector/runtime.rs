use crate::detection::runtime::Runtime as RuntimeDetection;
use crate::detector::Strategy;
use crate::input::Input;
use crate::util::php_extension;

/// PHP `Utopia\Detector\Detector\Runtime`.
#[derive(Debug)]
pub struct Runtime {
    inputs: Vec<Input>,
    options: Vec<Box<dyn RuntimeDetection>>,
    strategy: Strategy,
    packager: String,
}

impl Runtime {
    /// PHP `__construct(Strategy $strategy, string $packager = 'pnpm')`.
    #[must_use]
    pub fn new(strategy: Strategy, packager: impl Into<String>) -> Self {
        Self {
            inputs: Vec::new(),
            options: Vec::new(),
            strategy,
            packager: packager.into(),
        }
    }

    /// PHP `addInput(string $content, string $type = '')`.
    pub fn add_input(&mut self, content: impl Into<String>, type_: impl Into<String>) -> &mut Self {
        self.inputs.push(Input::new(content, type_));
        self
    }

    /// PHP `addOption(Detection $option)`.
    pub fn add_option(&mut self, option: impl RuntimeDetection + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// PHP `detect()`.
    #[must_use]
    pub fn detect(&self) -> Option<Box<dyn RuntimeDetection>> {
        let inputs: Vec<&str> = self
            .inputs
            .iter()
            .map(|input| input.content.as_str())
            .collect();

        match self.strategy.get_value() {
            Strategy::FILEMATCH => {
                for detector in &self.options {
                    if detector
                        .get_files()
                        .iter()
                        .any(|file| inputs.contains(&file.as_str()))
                    {
                        let mut detected = detector.box_clone();
                        detected.set_packager(self.packager.clone());
                        return Some(detected);
                    }
                }
            }
            Strategy::EXTENSION => {
                let input_extensions: Vec<&str> =
                    inputs.iter().map(|file| php_extension(file)).collect();
                for detector in &self.options {
                    if detector
                        .get_file_extensions()
                        .iter()
                        .any(|ext| input_extensions.contains(&ext.as_str()))
                    {
                        let mut detected = detector.box_clone();
                        detected.set_packager(self.packager.clone());
                        return Some(detected);
                    }
                }
            }
            Strategy::LANGUAGES => {
                for detector in &self.options {
                    if detector
                        .get_languages()
                        .iter()
                        .any(|lang| inputs.contains(&lang.as_str()))
                    {
                        let mut detected = detector.box_clone();
                        detected.set_packager(self.packager.clone());
                        return Some(detected);
                    }
                }
            }
            _ => {}
        }

        None
    }
}
