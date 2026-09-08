use crate::detection::rendering::{Rendering as RenderingDetection, XStatic};
use crate::input::Input;
use crate::util::php_extension;

/// PHP `Utopia\Detector\Detector\Rendering`.
#[derive(Debug)]
pub struct Rendering {
    inputs: Vec<Input>,
    options: Vec<Box<dyn RenderingDetection>>,
    framework: String,
}

impl Rendering {
    /// PHP `__construct(string $framework)`.
    #[must_use]
    pub fn new(framework: impl Into<String>) -> Self {
        Self {
            inputs: Vec::new(),
            options: Vec::new(),
            framework: framework.into(),
        }
    }

    /// PHP `addInput(string $content, string $type = '')`.
    pub fn add_input(&mut self, content: impl Into<String>, type_: impl Into<String>) -> &mut Self {
        self.inputs.push(Input::new(content, type_));
        self
    }

    /// PHP `addOption(Detection $option)`.
    pub fn add_option(&mut self, option: impl RenderingDetection + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// PHP `detect()` - always returns a rendering strategy.
    #[must_use]
    pub fn detect(&self) -> Box<dyn RenderingDetection> {
        let files: Vec<&str> = self
            .inputs
            .iter()
            .map(|input| input.content.as_str())
            .collect();

        for strategy in &self.options {
            if strategy
                .get_files(&self.framework)
                .iter()
                .any(|file| files.contains(&file.as_str()))
            {
                return strategy.box_clone();
            }
        }

        let html_files: Vec<&str> = files
            .iter()
            .copied()
            .filter(|file| php_extension(file) == "html")
            .collect();

        if html_files.len() == 1 {
            Box::new(XStatic::new(Some(html_files[0].to_string())))
        } else {
            Box::new(XStatic::new(None))
        }
    }
}
