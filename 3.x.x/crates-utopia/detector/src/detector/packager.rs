use crate::detection::packager::Packager as PackagerDetection;
use crate::input::Input;

/// PHP `Utopia\Detector\Detector\Packager`.
#[derive(Debug, Default)]
pub struct Packager {
    inputs: Vec<Input>,
    options: Vec<Box<dyn PackagerDetection>>,
}

impl Packager {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// PHP `addInput(string $content, string $type = '')`.
    pub fn add_input(&mut self, content: impl Into<String>, type_: impl Into<String>) -> &mut Self {
        self.inputs.push(Input::new(content, type_));
        self
    }

    /// PHP `addOption(Detection $option)`.
    pub fn add_option(&mut self, option: impl PackagerDetection + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// PHP `detect()`.
    #[must_use]
    pub fn detect(&self) -> Option<Box<dyn PackagerDetection>> {
        let files: Vec<&str> = self
            .inputs
            .iter()
            .map(|input| input.content.as_str())
            .collect();

        for packager in &self.options {
            if packager
                .get_files()
                .iter()
                .any(|file| files.contains(&file.as_str()))
            {
                return Some(packager.box_clone());
            }
        }
        None
    }
}
