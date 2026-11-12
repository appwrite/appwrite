//! `Utopia\Image\Image` over [`utopia_image::Image`]: each method takes what
//! the PHP method takes and calls the Rust method the crate maps it to. The
//! work is CPU-bound and runs on the calling coroutine, as Imagick's does.
#![expect(non_snake_case, reason = "PHP callers name arguments such as $borderWidth")]

use ext_php_rs::binary::Binary;
use ext_php_rs::binary_slice::BinarySlice;
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendClassObject;
use ext_php_rs::zend::{ClassEntry, ce};
use utopia_image::{Error, FocalPoint, Gravity, OutputFormat, Resource, has_glyph, set_resource_limit};

/// `Utopia\Image\Image`.
#[php_class]
#[php(name = "Utopia\\Image\\Image")]
pub struct Image(utopia_image::Image);

type This<'a> = &'a mut ZendClassObject<Image>;

/// `error` as the exception class the PHP library throws for it. The library's
/// own classes load from its Composer package; without it they are `\Exception`.
fn thrown(error: Error) -> PhpException {
    let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
    PhpException::new(error.to_string(), 0, class)
}

/// A PHP `int` size as the crate's `u32`; a negative one is an invalid
/// argument, with the PHP library's message for it.
fn size(value: i64, negative: &str) -> Result<u32, Error> {
    u32::try_from(value).map_err(|_| Error::InvalidArgument(negative.into()))
}

/// The font file at `path`.
fn font(path: &str) -> Result<Vec<u8>, Error> {
    std::fs::read(path).map_err(|error| Error::InvalidArgument(format!("Can't read font {path}: {error}")))
}

/// The `$type` of `output()` and `save()`.
fn format(kind: &str) -> Result<OutputFormat, Error> {
    kind.parse()
}

#[php_impl]
impl Image {
    const GRAVITY_CENTER: &'static str = "center";
    const GRAVITY_TOP_LEFT: &'static str = "top-left";
    const GRAVITY_TOP: &'static str = "top";
    const GRAVITY_TOP_RIGHT: &'static str = "top-right";
    const GRAVITY_LEFT: &'static str = "left";
    const GRAVITY_RIGHT: &'static str = "right";
    const GRAVITY_BOTTOM_LEFT: &'static str = "bottom-left";
    const GRAVITY_BOTTOM: &'static str = "bottom";
    const GRAVITY_BOTTOM_RIGHT: &'static str = "bottom-right";

    /// `__construct(string $data)`.
    pub fn __construct(data: BinarySlice<u8>) -> PhpResult<Self> {
        utopia_image::Image::new(&data).map(Self).map_err(thrown)
    }

    /// `create(int $width, int $height, string $background = ''): static`: one colour, PNG by default.
    #[php(defaults(background = "\"\""))]
    pub fn create(width: i64, height: i64, background: String) -> PhpResult<Self> {
        let negative = "Width and height must not be negative";
        let blank = size(width, negative)
            .and_then(|width| Ok((width, size(height, negative)?)))
            .and_then(|(width, height)| utopia_image::Image::blank(width, height, &background));
        blank.map(Self).map_err(thrown)
    }

    /// `hasGlyph(string $font, string $character): bool`: whether the font file draws the character.
    pub fn has_glyph(font: String, character: String) -> PhpResult<bool> {
        let mut characters = character.chars();
        let (Some(character), None) = (characters.next(), characters.next()) else {
            return Err(thrown(Error::InvalidArgument("Expected one character".into())));
        };
        self::font(&font).and_then(|font| has_glyph(&font, character)).map_err(thrown)
    }

    /// `getWidth(): int`: the decoded width, then the last crop's.
    pub fn get_width(&self) -> i64 {
        self.0.width()
    }

    /// `getHeight(): int`.
    pub fn get_height(&self) -> i64 {
        self.0.height()
    }

    /// `getOrientation(): int`: the EXIF orientation of the source, 1 to 8.
    pub fn get_orientation(&self) -> i64 {
        i64::from(self.0.orientation())
    }

    /// `path(string $path, string $color, float $x = 0, float $y = 0, float $scale = 1): static`:
    /// fills SVG path data.
    #[php(defaults(x = 0.0, y = 0.0, scale = 1.0))]
    pub fn path<'a>(self_: This<'a>, path: String, color: String, x: f64, y: f64, scale: f64) -> PhpResult<This<'a>> {
        let drawn = self_.0.fill_path(&path, &color, x, y, scale).map(|_| ());
        drawn.map(|()| self_).map_err(thrown)
    }

    /// `text(string $text, string $font, float $size, string $color, float $x, float $y): static`:
    /// centred on `$x`, baseline at `$y`.
    pub fn text<'a>(
        self_: This<'a>,
        text: String,
        font: String,
        size: f64,
        color: String,
        x: f64,
        y: f64,
    ) -> PhpResult<This<'a>> {
        let drawn = self::font(&font).and_then(|font| self_.0.draw_text(&text, &font, size, &color, x, y).map(|_| ()));
        drawn.map(|()| self_).map_err(thrown)
    }

    /// `getGravityTypes(): array`.
    pub fn get_gravity_types() -> Vec<&'static str> {
        Gravity::ALL.map(Gravity::name).to_vec()
    }

    /// `crop(int $width, int $height, string $gravity = self::GRAVITY_CENTER,
    /// ?float $x = null, ?float $y = null): self`.
    #[php(defaults(gravity = "\"center\""))]
    pub fn crop<'a>(
        self_: This<'a>,
        width: i64,
        height: i64,
        gravity: String,
        x: Option<f64>,
        y: Option<f64>,
    ) -> PhpResult<This<'a>> {
        let cropped = FocalPoint::from_parts(x, y).and_then(|focal| {
            // PHP crops an unknown gravity around the centre, on the path every
            // gravity but `center` takes: the same as a focal point in the middle.
            let (gravity, focal) = match Gravity::from_name(&gravity) {
                Some(gravity) => (gravity, focal),
                None => (Gravity::Center, focal.or(FocalPoint::new(0.5, 0.5).ok())),
            };
            let negative = "Width and height must not be negative";
            let (width, height) = (size(width, negative)?, size(height, negative)?);
            self_.0.crop(width, height, gravity, focal).map(|_| ())
        });
        cropped.map(|()| self_).map_err(thrown)
    }

    /// `setBorder(int $borderWidth, string $borderColor): self`.
    pub fn set_border<'a>(self_: This<'a>, borderWidth: i64, borderColor: String) -> PhpResult<This<'a>> {
        let set = size(borderWidth, "Border width must not be negative")
            .and_then(|width| self_.0.set_border(width, &borderColor).map(|_| ()));
        set.map(|()| self_).map_err(thrown)
    }

    /// `setBorderRadius(int $cornerRadius): self`.
    pub fn set_border_radius(self_: This<'_>, cornerRadius: i64) -> PhpResult<This<'_>> {
        let set = size(cornerRadius, "Corner radius must not be negative")
            .and_then(|radius| self_.0.set_border_radius(radius).map(|_| ()));
        set.map(|()| self_).map_err(thrown)
    }

    /// `setOpacity(float $opacity): self`.
    pub fn set_opacity(self_: This<'_>, opacity: f64) -> This<'_> {
        self_.0.set_opacity(opacity);
        self_
    }

    /// `setRotation(int $degree): self`.
    pub fn set_rotation(self_: This<'_>, degree: i64) -> PhpResult<This<'_>> {
        let set = self_.0.set_rotation(degree).map(|_| ());
        set.map(|()| self_).map_err(thrown)
    }

    /// `setBackground($color): static`.
    pub fn set_background(self_: This<'_>, color: String) -> PhpResult<This<'_>> {
        let set = self_.0.set_background(&color).map(|_| ());
        set.map(|()| self_).map_err(thrown)
    }

    /// `output(string $type, int $quality = 75): ?string`.
    #[php(defaults(quality = 75))]
    pub fn output(&mut self, r#type: String, quality: i64) -> PhpResult<Binary<u8>> {
        let blob = format(&r#type).and_then(|format| self.0.output(format, quality));
        blob.map(Binary::from).map_err(thrown)
    }

    /// `save(?string $path = null, string $type = '', int $quality = 75): ?string`:
    /// the encoded image without a path, null once it is written to one.
    #[php(defaults(r#type = "\"\"", quality = 75))]
    pub fn save(&mut self, path: Option<String>, r#type: String, quality: i64) -> PhpResult<Option<Binary<u8>>> {
        let format = format(&r#type).map_err(thrown)?;
        // An empty path asks for the bytes, as null does.
        match path.filter(|path| !path.is_empty()) {
            Some(path) => self.0.save(path, format, quality).map(|()| None),
            None => self.0.output(format, quality).map(|blob| Some(Binary::from(blob))),
        }
        .map_err(thrown)
    }

    /// `setResourceLimit(string $type, int $value): void`: an unknown type is ignored.
    pub fn set_resource_limit(r#type: String, value: i64) {
        if let Some(resource) = Resource::from_name(&r#type) {
            set_resource_limit(resource, value);
        }
    }
}
