//! ClamAV `INSTREAM` (`Appwrite\ClamAV\Network::scanInStream`).

use std::io::{Read, Write};

use appwrite_core::{Error, ErrorType, Result};

use crate::base::ANTIVIRUS_LIMIT;

/// Scans `path` when antivirus is enabled, the bucket asks for it, the file
/// is small enough and the device is local. Infected files are
/// `storage_invalid_file`. A scanner that cannot be reached is a 500.
pub async fn scan(path: &str, enabled: bool, size: i64) -> Result<()> {
    if utopia_system::env("_APP_STORAGE_ANTIVIRUS").as_deref() != Some("enabled") || !enabled || size > ANTIVIRUS_LIMIT
    {
        return Ok(());
    }
    let host = utopia_system::env_or("_APP_STORAGE_ANTIVIRUS_HOST", "clamav").into_owned();
    let port = appwrite_core::config::env_int("_APP_STORAGE_ANTIVIRUS_PORT", 3310) as u16;
    let path = path.to_owned();
    let reply = utopia_async::spawn_blocking(move || probe(&path, &host, port))
        .await
        .map_err(|e| Error::with_message(ErrorType::GeneralServerError, e.to_string()))?;
    match reply {
        Ok(()) => Ok(()),
        Err(Scan::Infected) => Err(Error::new(ErrorType::StorageInvalidFile)),
        Err(Scan::Failed(reply)) => Err(Error::with_message(
            ErrorType::GeneralServerError,
            format!("Unable to scan the uploaded file: {reply}"),
        )),
    }
}

enum Scan {
    Infected,
    Failed(String),
}

fn probe(path: &str, host: &str, port: u16) -> std::result::Result<(), Scan> {
    let connect = (|| {
        let mut file = std::fs::File::open(path).map_err(|e| e.to_string())?;
        let mut stream = std::net::TcpStream::connect((host, port)).map_err(|e| e.to_string())?;
        stream.set_read_timeout(Some(std::time::Duration::from_secs(30))).ok();
        stream.write_all(b"nINSTREAM\n").map_err(|e| e.to_string())?;
        let mut buf = [0_u8; 1024];
        loop {
            let read = file.read(&mut buf).map_err(|e| e.to_string())?;
            if read == 0 {
                break;
            }
            stream.write_all(&(read as u32).to_be_bytes()).map_err(|e| e.to_string())?;
            stream.write_all(&buf[..read]).map_err(|e| e.to_string())?;
        }
        stream.write_all(&0_u32.to_be_bytes()).map_err(|e| e.to_string())?;
        let mut reply = String::new();
        stream.read_to_string(&mut reply).map_err(|e| e.to_string())?;
        Ok(reply)
    })();
    match connect {
        Ok(reply) if reply.contains("FOUND") => Err(Scan::Infected),
        Ok(reply) if reply.contains("OK") => Ok(()),
        Ok(reply) => Err(Scan::Failed(reply)),
        Err(reply) => Err(Scan::Failed(reply)),
    }
}
