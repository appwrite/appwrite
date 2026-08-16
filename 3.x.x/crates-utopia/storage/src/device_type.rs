/// Storage backend kind.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum DeviceType {
    Local,
    #[cfg(feature = "s3")]
    S3,
    #[cfg(feature = "s3")]
    AwsS3,
    #[cfg(feature = "s3")]
    DoSpaces,
    #[cfg(feature = "s3")]
    Wasabi,
    #[cfg(feature = "s3")]
    Backblaze,
    #[cfg(feature = "s3")]
    Linode,
}

impl DeviceType {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Local => "local",
            #[cfg(feature = "s3")]
            Self::S3 => "s3",
            #[cfg(feature = "s3")]
            Self::AwsS3 => "awss3",
            #[cfg(feature = "s3")]
            Self::DoSpaces => "dospaces",
            #[cfg(feature = "s3")]
            Self::Wasabi => "wasabi",
            #[cfg(feature = "s3")]
            Self::Backblaze => "backblaze",
            #[cfg(feature = "s3")]
            Self::Linode => "linode",
        }
    }
}
