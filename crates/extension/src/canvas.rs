//! `Utopia\Image\Canvas` and `Utopia\Image\Font` over [`utopia_image`]. The value objects a
//! canvas takes (`Limits`, `Blank`, `Transform`, `Border`, `Point`, `Path`, `Text`) and the
//! `Format` and `Gravity` enums are PHP classes of the Composer package; their public
//! properties are read here. The work is CPU-bound and runs on the calling coroutine.

use std::sync::Arc;

use ext_php_rs::binary::Binary;
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendCallable, ZendClassObject, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use utopia_image::{Drawing, Error, FocalPoint, Gravity, Limits, OutputFormat, Transform, has_glyph};

const NAMESPACE: &str = "Utopia\\Image\\";

/// `error` as the exception class of the package for it; without the package, `\Exception`.
fn thrown(error: Error) -> PhpException {
    let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
    PhpException::new(error.to_string(), 0, class)
}

fn invalid(message: impl Into<String>) -> Error {
    Error::InvalidArgument(message.into())
}

/// Fails unless `object` is an instance of the package's class `name`.
fn expect(object: &ZendObject, name: &str) -> Result<(), Error> {
    let class = ClassEntry::try_find(&format!("{NAMESPACE}{name}"));
    if class.is_some_and(|class| object.instance_of(class)) {
        return Ok(());
    }
    let given = object.get_class_name().unwrap_or_default();
    Err(invalid(format!("Expected {NAMESPACE}{name}, {given} given")))
}

/// The public property `name` of a value object. Read from the property table: the
/// framework's own getter fetches for writing, which a readonly property refuses.
fn property<'a, T: ext_php_rs::convert::FromZval<'a>>(object: &'a ZendObject, name: &str) -> Result<T, Error> {
    let value = object.get_properties().ok().and_then(|properties| properties.get(name));
    value.and_then(T::from_zval).ok_or_else(|| invalid(format!("Unreadable property ${name}")))
}

/// A size in pixels: a PHP int that fits a `u32`.
fn pixels(object: &ZendObject, name: &str) -> Result<u32, Error> {
    u32::try_from(property::<i64>(object, name)?).map_err(|_| invalid(format!("${name} must not be negative")))
}

/// The backing value of an enum case held in the property `name`.
fn case(object: &ZendObject, name: &str) -> Result<String, Error> {
    property::<&ZendObject>(object, name).and_then(|case| property(case, "value"))
}

fn limits(object: Option<&ZendObject>) -> Result<(Limits, Vec<utopia_image::Format>), Error> {
    let Some(object) = object else {
        return Ok((Limits::default(), Vec::new()));
    };
    expect(object, "Limits")?;
    let size = |name| property::<i64>(object, name).map(|value| value.max(0) as u64);
    let mut formats = Vec::new();
    for format in property::<Vec<&ZendObject>>(object, "formats")? {
        let value: String = property(format, "value")?;
        formats.push(input(&value)?);
    }
    Ok((Limits { width: size("width")?, height: size("height")?, memory: size("memory")? }, formats))
}

/// The input format a `Format` case names.
fn input(value: &str) -> Result<utopia_image::Format, Error> {
    Ok(match value {
        "jpg" => utopia_image::Format::Jpeg,
        "png" => utopia_image::Format::Png,
        "gif" => utopia_image::Format::Gif,
        "webp" => utopia_image::Format::Webp,
        "avif" => utopia_image::Format::Avif,
        "heic" => utopia_image::Format::Heic,
        _ => return Err(invalid(format!("Unknown format: {value}"))),
    })
}

fn transform(object: &ZendObject) -> Result<Transform, Error> {
    expect(object, "Transform")?;
    let gravity = case(object, "gravity")?;
    let focus = match property::<Option<&ZendObject>>(object, "focus")? {
        Some(point) => Some(FocalPoint::new(property(point, "x")?, property(point, "y")?)?),
        None => None,
    };
    let border = match property::<Option<&ZendObject>>(object, "border")? {
        Some(border) => Some((pixels(border, "width")?, property(border, "color")?)),
        None => None,
    };
    Ok(Transform {
        width: pixels(object, "width")?,
        height: pixels(object, "height")?,
        gravity: Gravity::from_name(&gravity).ok_or_else(|| invalid(format!("Unknown gravity: {gravity}")))?,
        focus,
        opacity: property(object, "opacity")?,
        background: property(object, "background")?,
        border,
        radius: pixels(object, "radius")?,
        rotation: property(object, "rotation")?,
    })
}

fn drawing(object: &ZendObject) -> Result<Drawing, Error> {
    if expect(object, "Path").is_ok() {
        return Ok(Drawing::Path {
            data: property(object, "data")?,
            color: property(object, "color")?,
            x: property(object, "x")?,
            y: property(object, "y")?,
            scale: property(object, "scale")?,
        });
    }
    expect(object, "Text").map_err(|_| {
        let given = object.get_class_name().unwrap_or_default();
        invalid(format!("Expected {NAMESPACE}Path or {NAMESPACE}Text, {given} given"))
    })?;
    let font = property::<&ZendObject>(object, "font")?;
    let font =
        ZendClassObject::<Font>::from_zend_obj(font).ok_or_else(|| invalid("$font must be a Utopia\\Image\\Font"))?;
    Ok(Drawing::Text {
        text: property(object, "text")?,
        font: Arc::clone(&font.0),
        size: property(object, "size")?,
        color: property(object, "color")?,
        x: property(object, "x")?,
        y: property(object, "y")?,
    })
}

/// `Utopia\Image\Font`: a TrueType or OpenType file, read once.
#[php_class]
#[php(name = "Utopia\\Image\\Font")]
#[php(flags = ClassFlags::Final.union(ClassFlags::ReadonlyClass).union(ClassFlags::NoDynamicProperties).union(ClassFlags::NotSerializable))]
pub struct Font(Arc<[u8]>);

#[php_impl]
impl Font {
    /// `__construct(string $path)`.
    pub fn __construct(path: String) -> PhpResult<Self> {
        let data = std::fs::read(&path).map_err(|error| thrown(invalid(format!("Can't read font {path}: {error}"))))?;
        // Parsed once here so an unusable file fails at construction.
        has_glyph(&data, ' ').map_err(thrown)?;
        Ok(Font(data.into()))
    }

    /// `has(string $character): bool`: whether the font draws something for the character.
    pub fn has(&self, character: String) -> PhpResult<bool> {
        let mut characters = character.chars();
        let (Some(character), None) = (characters.next(), characters.next()) else {
            return Err(thrown(invalid("Expected one character")));
        };
        has_glyph(&self.0, character).map_err(thrown)
    }
}

/// `Utopia\Image\Canvas`: an image and the work planned on it.
#[php_class]
#[php(name = "Utopia\\Image\\Canvas")]
#[php(flags = ClassFlags::Final.union(ClassFlags::ReadonlyClass).union(ClassFlags::NoDynamicProperties).union(ClassFlags::NotSerializable))]
pub struct Canvas(utopia_image::Canvas);

#[php_impl]
impl Canvas {
    /// `__construct(string|Blank $source, ?Limits $limits = null)`: reads a file's header, or
    /// makes a canvas of one colour. Nothing is decoded yet.
    pub fn __construct(source: &Zval, limits: Option<&ZendObject>) -> PhpResult<Self> {
        let canvas = limits_and(source, limits);
        canvas.map(Self).map_err(thrown)
    }

    /// `$width`: the source's width.
    #[php(getter)]
    pub fn get_width(&self) -> i64 {
        self.0.width().into()
    }

    /// `$height`: the source's height.
    #[php(getter)]
    pub fn get_height(&self) -> i64 {
        self.0.height().into()
    }

    /// `$orientation`: the source's EXIF orientation, 1 to 8.
    #[php(getter)]
    pub fn get_orientation(&self) -> i64 {
        self.0.orientation().into()
    }

    /// `$format`: the source's `Format`, or null for a blank canvas.
    #[php(getter)]
    pub fn get_format(&self) -> PhpResult<Option<Zval>> {
        let Some(format) = self.0.format() else {
            return Ok(None);
        };
        let value = match format {
            utopia_image::Format::Jpeg => "jpg",
            utopia_image::Format::Png => "png",
            utopia_image::Format::Gif => "gif",
            utopia_image::Format::Webp => "webp",
            utopia_image::Format::Avif => "avif",
            utopia_image::Format::Heic => "heic",
        };
        let from = ZendCallable::try_from_name("Utopia\\Image\\Format::from")?;
        Ok(Some(from.try_call(vec![&value])?))
    }

    /// `apply(Transform $transform): static`: a canvas with the transform added to its plan.
    pub fn apply(&self, transform: &ZendObject) -> PhpResult<Canvas> {
        self::transform(transform).and_then(|transform| self.0.apply(transform)).map(Canvas).map_err(thrown)
    }

    /// `draw(Path|Text $drawing): static`: a canvas with the drawing added to its plan.
    pub fn draw(&self, drawing: &ZendObject) -> PhpResult<Canvas> {
        self::drawing(drawing).and_then(|drawing| self.0.draw(drawing)).map(Canvas).map_err(thrown)
    }

    /// `encode(Format $format, ?int $quality = null): string`: decodes, runs the plan and writes
    /// the format. A null quality keeps a JPEG source's own, or uses the format's default.
    pub fn encode(&self, format: &ZendObject, quality: Option<i64>) -> PhpResult<Binary<u8>> {
        let encoded = expect(format, "Format")
            .and_then(|()| property::<String>(format, "value"))
            .and_then(|value| value.parse::<OutputFormat>())
            .and_then(|format| {
                let quality = match quality {
                    Some(quality) => Some(
                        u8::try_from(quality)
                            .ok()
                            .filter(|quality| *quality <= 100)
                            .ok_or_else(|| invalid("Quality must be between 0 and 100"))?,
                    ),
                    None => None,
                };
                self.0.encode(format, quality)
            });
        encoded.map(Binary::from).map_err(thrown)
    }
}

fn limits_and(source: &Zval, limits: Option<&ZendObject>) -> Result<utopia_image::Canvas, Error> {
    let (limits, formats) = self::limits(limits)?;
    if let Some(blank) = source.object() {
        expect(blank, "Blank")?;
        let background: String = property(blank, "background")?;
        return utopia_image::Canvas::blank(pixels(blank, "width")?, pixels(blank, "height")?, &background, limits);
    }
    let data = source.binary_slice::<u8>().ok_or_else(|| invalid("Expected a string or a Utopia\\Image\\Blank"))?;
    utopia_image::Canvas::decode_accepting(data, limits, &formats)
}
