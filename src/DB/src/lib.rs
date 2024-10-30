use std::{
    collections::HashMap,
    time::{SystemTime, UNIX_EPOCH},
    sync::Arc,
    path::Path,
    fs,
};
use dashmap::DashMap;
use serde::{Serialize, Deserialize};
use thiserror::Error;
use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use nix::mount::{mount, MsFlags};
use chrono::{DateTime, Utc};
use std::path::PathBuf;
use glob::Pattern;

#[derive(Error, Debug)]
pub enum DbError {
    #[error("Key not found")]
    KeyNotFound,
    #[error("Invalid value type")]
    InvalidType,
    #[error("Invalid path format")]
    InvalidPath,
    #[error("IO error: {0}")]
    Io(#[from] std::io::Error),
    #[error("Serialization error: {0}")]
    Serialization(#[from] serde_json::Error),
    #[error("System error: {0}")]
    System(String),
    #[error("Mount error: {0}")]
    Mount(#[from] nix::Error),
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum Value {
    String(String),
    Integer(i64),
    Float(f64),
    Bool(bool),
    Array(Vec<String>),
    Map(HashMap<String, String>),
    Null,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
struct Entry {
    value: Value,
    path_components: Vec<String>,
    expiry: Option<u64>,
    #[serde(with = "chrono::serde::ts_seconds")]
    created_at: DateTime<Utc>,
    #[serde(with = "chrono::serde::ts_seconds")]
    updated_at: DateTime<Utc>,
}

impl Entry {
    fn new(value: Value, path: &str) -> Result<Self, DbError> {
        Ok(Entry {
            value,
            path_components: Self::parse_path(path)?,
            expiry: None,
            created_at: Utc::now(),
            updated_at: Utc::now(),
        })
    }

    fn parse_path(path: &str) -> Result<Vec<String>, DbError> {
        if path.starts_with('/') || path.ends_with('/') {
            return Err(DbError::InvalidPath);
        }
        
        Ok(path.split('/')
            .map(|s| s.to_string())
            .collect())
    }

    fn matches_pattern(&self, pattern_components: &[String]) -> bool {
        if pattern_components.len() > self.path_components.len() {
            return false;
        }

        for (pattern, component) in pattern_components.iter().zip(self.path_components.iter()) {
            if pattern != "*" && pattern != component {
                return false;
            }
        }
        true
    }
}

#[derive(Debug, Serialize, Deserialize)]
struct SerializableDb {
    data: HashMap<String, Entry>,
    #[serde(with = "chrono::serde::ts_seconds")]
    created_at: DateTime<Utc>,
    #[serde(with = "chrono::serde::ts_seconds_option")]
    last_backup: Option<DateTime<Utc>>,
    version: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DbStats {
    pub total_keys: usize,
    pub expired_keys: usize,
    pub memory_usage: u64,
    #[serde(with = "chrono::serde::ts_seconds")]
    pub created_at: DateTime<Utc>,
    #[serde(with = "chrono::serde::ts_seconds_option")]
    pub last_backup: Option<DateTime<Utc>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DetailedDbStats {
    pub total_keys: usize,
    pub expired_keys: usize,
    pub active_keys: usize,
    pub keys_with_expiry: usize,
    pub string_values: usize,
    pub integer_values: usize,
    pub float_values: usize,
    pub bool_values: usize,
    pub array_values: usize,
    pub map_values: usize,
    pub null_values: usize,
    pub memory_usage: u64,
    #[serde(with = "chrono::serde::ts_seconds")]
    pub created_at: DateTime<Utc>,
    #[serde(with = "chrono::serde::ts_seconds_option")]
    pub last_backup: Option<DateTime<Utc>>,
    pub average_path_depth: f64,
}

pub struct Database {
    data: Arc<DashMap<String, Entry>>,
    storage_path: String,
    created_at: DateTime<Utc>,
    last_backup: Option<DateTime<Utc>>,
    version: String,
}

impl Database {
    pub fn new(storage_path: &str) -> Self {
        let mut db = Database {
            data: Arc::new(DashMap::new()),
            storage_path: storage_path.to_string(),
            created_at: Utc::now(),
            last_backup: None,
            version: env!("CARGO_PKG_VERSION").to_string(),
        };
        
        if Path::new(storage_path).exists() {
            if let Ok(content) = std::fs::read_to_string(storage_path) {
                if let Ok(saved) = serde_json::from_str::<SerializableDb>(&content) {
                    for (key, value) in saved.data {
                        db.data.insert(key, value);
                    }
                    db.created_at = saved.created_at;
                    db.last_backup = saved.last_backup;
                }
            }
        }
        
        db
    }

    fn to_serializable(&self) -> SerializableDb {
        SerializableDb {
            data: self.data.iter()
                .map(|ref_multi| (ref_multi.key().clone(), ref_multi.value().clone()))
                .collect(),
            created_at: self.created_at,
            last_backup: self.last_backup,
            version: self.version.clone(),
        }
    }

    pub fn init_btrfs_volume(mount_path: &str, size_mb: u64) -> Result<(), DbError> {
        let mount_path_buf = PathBuf::from(mount_path);
        let file_path = mount_path_buf.join("db.img");
        let file_path_str = file_path.to_str().ok_or_else(|| 
            DbError::System("Invalid path".to_string()))?;
        
        // Vytvoření image souboru
        std::process::Command::new("dd")
            .args(&[
                "if=/dev/zero",
                &format!("of={}", file_path_str),
                "bs=1M",
                &format!("count={}", size_mb)
            ])
            .output()
            .map_err(|e| DbError::System(e.to_string()))?;

        // Formátování na Btrfs
        std::process::Command::new("mkfs.btrfs")
            .arg(file_path_str)
            .output()
            .map_err(|e| DbError::System(e.to_string()))?;

        // Vytvoření mount pointu
        fs::create_dir_all(mount_path)?;

        // Připojení svazku
        mount(
            Some(file_path_str),
            mount_path,
            Some("btrfs"),
            MsFlags::MS_NOATIME,
            None::<&str>,
        )?;

        Ok(())
    }

    pub fn set(&self, path: &str, value: Value) -> Result<(), DbError> {
        let entry = Entry::new(value, path)?;
        self.data.insert(path.to_string(), entry);
        self.save_to_disk()?;
        Ok(())
    }

    // New method to find entries by path pattern
    pub fn find_by_path(&self, pattern: &str) -> Result<HashMap<String, Value>, DbError> {
        let pattern_components = pattern.split('/')
            .map(|s| s.to_string())
            .collect::<Vec<_>>();

        let mut results = HashMap::new();
        
        for entry in self.data.iter() {
            if entry.value().matches_pattern(&pattern_components) {
                results.insert(
                    entry.key().clone(),
                    entry.value().value.clone()
                );
            }
        }

        Ok(results)
    }

    // Helper method to list all entries under a path
    pub fn list_directory(&self, prefix: &str) -> Result<Vec<String>, DbError> {
        let prefix_components = prefix.split('/')
            .map(|s| s.to_string())
            .collect::<Vec<_>>();

        let mut results = Vec::new();
        
        for entry in self.data.iter() {
            let components = &entry.value().path_components;
            if components.starts_with(&prefix_components) {
                if components.len() > prefix_components.len() {
                    let next_component = &components[prefix_components.len()];
                    if !results.contains(next_component) {
                        results.push(next_component.clone());
                    }
                }
            }
        }

        Ok(results)
    }

    pub fn get(&self, key: &str) -> Result<Value, DbError> {
        if let Some(entry) = self.data.get(key) {
            if let Some(expiry) = entry.expiry {
                if expiry < SystemTime::now()
                    .duration_since(UNIX_EPOCH)
                    .unwrap()
                    .as_secs() 
                {
                    self.data.remove(key);
                    return Err(DbError::KeyNotFound);
                }
            }
            Ok(entry.value.clone())
        } else {
            Err(DbError::KeyNotFound)
        }
    }

    pub fn delete(&self, key: &str) -> Result<(), DbError> {
        if self.data.remove(key).is_some() {
            self.save_to_disk()?;
            Ok(())
        } else {
            Err(DbError::KeyNotFound)
        }
    }

    pub fn increment(&self, path: &str) -> Result<i64, DbError> {
        let mut current_value = match self.get(path) {
            Ok(Value::Integer(n)) => n,
            Ok(Value::String(s)) => s.parse::<i64>().map_err(|_| DbError::InvalidType)?,
            Ok(_) => return Err(DbError::InvalidType),
            Err(DbError::KeyNotFound) => 0,
            Err(e) => return Err(e),
        };

        current_value += 1;
        self.set(path, Value::Integer(current_value))?;
        Ok(current_value)
    }

    pub fn set_expiry(&self, path: &str, seconds: u64) -> Result<(), DbError> {
        if let Some(mut entry) = self.data.get_mut(path) {
            let expiry = SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_secs() + seconds;
            
            entry.expiry = Some(expiry);
            self.save_to_disk()?;
            Ok(())
        } else {
            Err(DbError::KeyNotFound)
        }
    }

    pub fn remove_expiry(&self, path: &str) -> Result<(), DbError> {
        if let Some(mut entry) = self.data.get_mut(path) {
            entry.expiry = None;
            self.save_to_disk()?;
            Ok(())
        } else {
            Err(DbError::KeyNotFound)
        }
    }

    // Získání času do expirace
    pub fn ttl(&self, path: &str) -> Result<Option<u64>, DbError> {
        if let Some(entry) = self.data.get(path) {
            if let Some(expiry) = entry.expiry {
                let now = SystemTime::now()
                    .duration_since(UNIX_EPOCH)
                    .unwrap()
                    .as_secs();
                
                if expiry > now {
                    return Ok(Some(expiry - now));
                }
            }
            Ok(None)
        } else {
            Err(DbError::KeyNotFound)
        }
    }

    pub fn exists(&self, path: &str) -> bool {
        if let Some(entry) = self.data.get(path) {
            if let Some(expiry) = entry.expiry {
                let now = SystemTime::now()
                    .duration_since(UNIX_EPOCH)
                    .unwrap()
                    .as_secs();
                return expiry > now;
            }
            true
        } else {
            false
        }
    }

    pub fn delete_by_pattern(&self, pattern: &str) -> Result<usize, DbError> {
        let mut deleted = 0;
        let pattern_components: Vec<String> = pattern.split('/')
            .map(|s| s.to_string())
            .collect();

        let keys_to_delete: Vec<String> = self.data.iter()
            .filter(|entry| entry.value().matches_pattern(&pattern_components))
            .map(|entry| entry.key().clone())
            .collect();

        for key in keys_to_delete {
            self.data.remove(&key);
            deleted += 1;
        }

        if deleted > 0 {
            self.save_to_disk()?;
        }

        Ok(deleted)
    }

    pub fn get_detailed_stats(&self) -> DetailedDbStats {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_secs();

        let mut stats = DetailedDbStats {
            total_keys: self.data.len(),
            expired_keys: 0,
            active_keys: 0,
            keys_with_expiry: 0,
            string_values: 0,
            integer_values: 0,
            float_values: 0,
            bool_values: 0,
            array_values: 0,
            map_values: 0,
            null_values: 0,
            memory_usage: std::mem::size_of_val(&*self.data) as u64,
            created_at: self.created_at,
            last_backup: self.last_backup,
            average_path_depth: 0.0,
        };

        let mut total_depth = 0;

        for entry in self.data.iter() {
            let is_expired = entry.value().expiry
                .map(|exp| exp < now)
                .unwrap_or(false);

            if is_expired {
                stats.expired_keys += 1;
            } else {
                stats.active_keys += 1;
            }

            if entry.value().expiry.is_some() {
                stats.keys_with_expiry += 1;
            }

            match entry.value().value {
                Value::String(_) => stats.string_values += 1,
                Value::Integer(_) => stats.integer_values += 1,
                Value::Float(_) => stats.float_values += 1,
                Value::Bool(_) => stats.bool_values += 1,
                Value::Array(_) => stats.array_values += 1,
                Value::Map(_) => stats.map_values += 1,
                Value::Null => stats.null_values += 1,
            }

            total_depth += entry.value().path_components.len();
        }

        if stats.total_keys > 0 {
            stats.average_path_depth = total_depth as f64 / stats.total_keys as f64;
        }

        stats
    }

    pub fn get_stats(&self) -> DbStats {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_secs();

        let expired = self.data.iter()
            .filter(|ref_multi| {
                if let Some(expiry) = ref_multi.expiry {
                    expiry < now
                } else {
                    false
                }
            })
            .count();

        DbStats {
            total_keys: self.data.len(),
            expired_keys: expired,
            memory_usage: std::mem::size_of_val(&*self.data) as u64,
            created_at: self.created_at,
            last_backup: self.last_backup,
        }
    }

    pub fn create_backup(&mut self, backup_path: &str) -> Result<(), DbError> {
        self.save_to_disk()?;
        fs::copy(&self.storage_path, backup_path)?;
        self.last_backup = Some(Utc::now());
        Ok(())
    }

    pub fn restore_from_backup(&mut self, backup_path: &str) -> Result<(), DbError> {
        let content = fs::read_to_string(backup_path)?;
        let saved: SerializableDb = serde_json::from_str(&content)?;
        
        self.data.clear();
        for (key, value) in saved.data {
            self.data.insert(key, value);
        }
        
        self.created_at = saved.created_at;
        self.last_backup = saved.last_backup;
        Ok(())
    }

    pub fn export_json(&self, export_path: &str) -> Result<(), DbError> {
        let json = serde_json::to_string_pretty(&self.to_serializable())?;
        fs::write(export_path, json)?;
        Ok(())
    }

    pub fn import_json(&mut self, import_path: &str) -> Result<(), DbError> {
        let content = fs::read_to_string(import_path)?;
        let saved: SerializableDb = serde_json::from_str(&content)?;
        
        self.data.clear();
        for (key, value) in saved.data {
            self.data.insert(key, value);
        }
        Ok(())
    }

    pub fn flush(&self) -> Result<(), DbError> {
        self.save_to_disk()
    }

    pub fn clear(&self) -> Result<(), DbError> {
        self.data.clear();
        self.save_to_disk()
    }

    fn save_to_disk(&self) -> Result<(), DbError> {
        let serializable = SerializableDb {
            data: self.data.iter()
                .map(|ref_multi| {
                    (ref_multi.key().clone(), ref_multi.value().clone())
                })
                .collect(),
            created_at: self.created_at,
            last_backup: self.last_backup,
            version: self.version.clone(),
        };
        
        let json = serde_json::to_string(&serializable)?;
        fs::write(&self.storage_path, json)?;
        Ok(())
    }
}

// FFI rozhraní
#[no_mangle]
pub extern "C" fn db_create(path: *const c_char) -> *mut Database {
    let c_str = unsafe { CStr::from_ptr(path) };
    let path_str = c_str.to_str().unwrap();
    Box::into_raw(Box::new(Database::new(path_str)))
}

#[no_mangle]
pub extern "C" fn db_init_volume(path: *const c_char, size_mb: u64) -> bool {
    let c_str = unsafe { CStr::from_ptr(path) };
    let path_str = c_str.to_str().unwrap();
    
    Database::init_btrfs_volume(path_str, size_mb).is_ok()
}

#[no_mangle]
pub extern "C" fn db_set(db: *mut Database, key: *const c_char, value: *const c_char) -> bool {
    let database = unsafe { &*db };
    let key_str = unsafe { CStr::from_ptr(key) }.to_str().unwrap();
    let value_str = unsafe { CStr::from_ptr(value) }.to_str().unwrap();
    
    database.set(key_str, Value::String(value_str.to_string())).is_ok()
}

#[no_mangle]
pub extern "C" fn db_get(db: *mut Database, key: *const c_char) -> *mut c_char {
    let database = unsafe { &*db };
    let key_str = unsafe { CStr::from_ptr(key) }.to_str().unwrap();
    
    match database.get(key_str) {
        Ok(Value::String(s)) => {
            let c_string = CString::new(s).unwrap();
            c_string.into_raw()
        }
        _ => std::ptr::null_mut(),
    }
}

#[no_mangle]
pub extern "C" fn db_find_by_path(db: *mut Database, pattern: *const c_char) -> *mut c_char {
    let database = unsafe { &*db };
    let pattern_str = unsafe { CStr::from_ptr(pattern) }.to_str().unwrap();
    
    match database.find_by_path(pattern_str) {
        Ok(results) => {
            match serde_json::to_string(&results) {
                Ok(json) => {
                    let c_string = CString::new(json).unwrap();
                    c_string.into_raw()
                }
                Err(_) => std::ptr::null_mut(),
            }
        }
        Err(_) => std::ptr::null_mut(),
    }
}

#[no_mangle]
pub extern "C" fn db_increment(db: *mut Database, path: *const c_char) -> i64 {
    let database = unsafe { &*db };
    let path_str = unsafe { CStr::from_ptr(path) }.to_str().unwrap();
    
    match database.increment(path_str) {
        Ok(value) => value,
        Err(_) => -1,
    }
}

#[no_mangle]
pub extern "C" fn db_set_expiry(db: *mut Database, path: *const c_char, seconds: u64) -> bool {
    let database = unsafe { &*db };
    let path_str = unsafe { CStr::from_ptr(path) }.to_str().unwrap();
    
    database.set_expiry(path_str, seconds).is_ok()
}

#[no_mangle]
pub extern "C" fn db_ttl(db: *mut Database, path: *const c_char) -> i64 {
    let database = unsafe { &*db };
    let path_str = unsafe { CStr::from_ptr(path) }.to_str().unwrap();
    
    match database.ttl(path_str) {
        Ok(Some(ttl)) => ttl as i64,
        _ => -1,
    }
}

#[no_mangle]
pub extern "C" fn db_exists(db: *mut Database, path: *const c_char) -> bool {
    let database = unsafe { &*db };
    let path_str = unsafe { CStr::from_ptr(path) }.to_str().unwrap();
    
    database.exists(path_str)
}

#[no_mangle]
pub extern "C" fn db_delete_by_pattern(db: *mut Database, pattern: *const c_char) -> i64 {
    let database = unsafe { &*db };
    let pattern_str = unsafe { CStr::from_ptr(pattern) }.to_str().unwrap();
    
    match database.delete_by_pattern(pattern_str) {
        Ok(count) => count as i64,
        Err(_) => -1,
    }
}

#[no_mangle]
pub extern "C" fn db_get_detailed_stats(db: *mut Database) -> *mut c_char {
    let database = unsafe { &*db };
    let stats = database.get_detailed_stats();
    
    match serde_json::to_string(&stats) {
        Ok(json) => {
            let c_string = CString::new(json).unwrap();
            c_string.into_raw()
        }
        Err(_) => std::ptr::null_mut(),
    }
}

#[no_mangle]
pub extern "C" fn db_delete(db: *mut Database, key: *const c_char) -> bool {
    let database = unsafe { &*db };
    let key_str = unsafe { CStr::from_ptr(key) }.to_str().unwrap();
    
    database.delete(key_str).is_ok()
}

#[no_mangle]
pub extern "C" fn db_free_string(s: *mut c_char) {
    unsafe {
        if !s.is_null() {
            let _ = CString::from_raw(s);
        }
    }
}

#[no_mangle]
pub extern "C" fn db_destroy(db: *mut Database) {
    unsafe {
        let _ = Box::from_raw(db);
    }
}

#[no_mangle]
pub extern "C" fn db_backup(db: *mut Database, path: *const c_char) -> bool {
    let database = unsafe { &mut *db };
    let path_str = unsafe { CStr::from_ptr(path) }.to_str().unwrap();
    
    database.create_backup(path_str).is_ok()
}

#[no_mangle]
pub extern "C" fn db_restore(db: *mut Database, path: *const c_char) -> bool {
    let database = unsafe { &mut *db };
    let path_str = unsafe { CStr::from_ptr(path) }.to_str().unwrap();
    
    database.restore_from_backup(path_str).is_ok()
}

#[no_mangle]
pub extern "C" fn db_export(db: *mut Database, path: *const c_char) -> bool {
    let database = unsafe { &*db };
    let path_str = unsafe { CStr::from_ptr(path) }.to_str().unwrap();
    
    database.export_json(path_str).is_ok()
}

#[no_mangle]
pub extern "C" fn db_import(db: *mut Database, path: *const c_char) -> bool {
    let database = unsafe { &mut *db };
    let path_str = unsafe { CStr::from_ptr(path) }.to_str().unwrap();
    
    database.import_json(path_str).is_ok()
}