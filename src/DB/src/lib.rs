use std::{
    collections::HashMap,
    time::{SystemTime, UNIX_EPOCH},
    sync::Arc,
};
use dashmap::DashMap;
use serde::{Serialize, Deserialize};
use thiserror::Error;
use std::ffi::{CStr, CString};
use std::os::raw::c_char;

#[derive(Error, Debug)]
pub enum DbError {
    #[error("Key not found")]
    KeyNotFound,
    #[error("Invalid value type")]
    InvalidType,
    #[error("IO error: {0}")]
    Io(#[from] std::io::Error),
    #[error("Serialization error: {0}")]
    Serialization(#[from] serde_json::Error),
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum Value {
    String(String),
    Integer(i64),
    Float(f64),
}

#[derive(Debug, Clone, Serialize, Deserialize)]
struct Entry {
    value: Value,
    expiry: Option<u64>,
}

#[derive(Serialize, Deserialize)]
struct SerializableDb {
    data: HashMap<String, Entry>,
}

pub struct Database {
    data: Arc<DashMap<String, Entry>>,
    storage_path: String,
}

impl Database {
    pub fn new(storage_path: &str) -> Self {
        let data = Arc::new(DashMap::new());
        
        if let Ok(content) = std::fs::read_to_string(storage_path) {
            if let Ok(saved_data) = serde_json::from_str::<SerializableDb>(&content) {
                for (key, value) in saved_data.data {
                    data.insert(key, value);
                }
            }
        }
        
        Database {
            data,
            storage_path: storage_path.to_string(),
        }
    }

    fn current_timestamp() -> u64 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_secs()
    }

    // Přidáme pomocné metody pro vytváření hodnot
    pub fn create_string_value(value: String) -> Value {
        Value::String(value)
    }

    pub fn create_integer_value(value: i64) -> Value {
        Value::Integer(value)
    }

    pub fn create_float_value(value: f64) -> Value {
        Value::Float(value)
    }

    pub fn set(&self, key: &str, value: Value, expires_in: Option<u64>) -> Result<(), DbError> {
        let expiry = expires_in.map(|seconds| Self::current_timestamp() + seconds);
        
        self.data.insert(key.to_string(), Entry { value, expiry });
        self.save_to_disk()?;
        Ok(())
    }

    pub fn get(&self, key: &str) -> Result<Value, DbError> {
        if let Some(entry) = self.data.get(key) {
            if let Some(expiry) = entry.expiry {
                if expiry < Self::current_timestamp() {
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

    pub fn increment(&self, key: &str) -> Result<i64, DbError> {
        let mut entry = self.data.get_mut(key).ok_or(DbError::KeyNotFound)?;
        
        match entry.value {
            Value::Integer(ref mut n) => {
                *n += 1;
                self.save_to_disk()?;
                Ok(*n)
            }
            _ => Err(DbError::InvalidType),
        }
    }

    fn save_to_disk(&self) -> Result<(), DbError> {
        let serializable = SerializableDb {
            data: self.data.iter()
                .map(|ref_multi| {
                    (ref_multi.key().clone(), ref_multi.value().clone())
                })
                .collect(),
        };
        
        let json = serde_json::to_string(&serializable)?;
        std::fs::write(&self.storage_path, json)?;
        Ok(())
    }
}

// FFI rozhraní upraveno pro práci s public Value enum
#[no_mangle]
pub extern "C" fn db_create(path: *const c_char) -> *mut Database {
    let c_str = unsafe { CStr::from_ptr(path) };
    let path_str = c_str.to_str().unwrap();
    Box::into_raw(Box::new(Database::new(path_str)))
}

#[no_mangle]
pub extern "C" fn db_set(db: *mut Database, key: *const c_char, value: *const c_char) -> bool {
    let database = unsafe { &*db };
    let key_str = unsafe { CStr::from_ptr(key) }.to_str().unwrap();
    let value_str = unsafe { CStr::from_ptr(value) }.to_str().unwrap();
    
    database.set(
        key_str,
        Database::create_string_value(value_str.to_string()),
        None
    ).is_ok()
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
pub extern "C" fn db_delete(db: *mut Database, key: *const c_char) -> bool {
    let database = unsafe { &*db };
    let key_str = unsafe { CStr::from_ptr(key) }.to_str().unwrap();
    
    database.delete(key_str).is_ok()
}

#[no_mangle]
pub extern "C" fn db_increment(db: *mut Database, key: *const c_char) -> i64 {
    let database = unsafe { &*db };
    let key_str = unsafe { CStr::from_ptr(key) }.to_str().unwrap();
    
    database.increment(key_str).unwrap_or(-1)
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