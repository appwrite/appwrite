//! Points a method of a `#[php_class]` class at a handler written by hand, once every class
//! is registered. ext-php-rs runs each of its methods inside `zend_try` (a `setjmp`) and a
//! panic catch, which costs more than a getter that returns a string it already holds. The
//! handlers given here are written as the hand-built classes' are: they do not panic, and
//! whatever they call reports errors as PHP exceptions.
//!
//! The engine copies an internal method into every class that inherits it, so the method
//! is pointed at the new handler in the class that declares it and in each class that
//! inherited it. This runs in the first RINIT after MINIT, when only internal classes
//! exist; user classes that extend one later copy the new handler.

use std::sync::atomic::{AtomicBool, Ordering};

use ext_php_rs::ffi::{IS_UNDEF, ZEND_INTERNAL_FUNCTION, zend_class_entry, zend_function, zend_hash_str_find_ptr_lc};
use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ClassEntry, ExecuteData, ExecutorGlobals};

use super::leaf::FunctionHandler;

/// Whether the methods have been pointed at their handlers since the last MINIT.
static DONE: AtomicBool = AtomicBool::new(false);

/// Called in MINIT, which registers the classes anew (a FrankenPHP worker restart runs it
/// again in the same process).
pub fn reset() {
    DONE.store(false, Ordering::Release);
}

/// Runs `retarget` once after each MINIT.
pub fn once(retarget: impl FnOnce()) {
    if !DONE.swap(true, Ordering::AcqRel) {
        retarget();
    }
}

/// The handler of `class`'s internal method `name` (lowercase): ext-php-rs's, before
/// [`method`] points it elsewhere, for a hand-written handler to hand the calls it does not
/// take itself back to.
pub fn handler(class: &ClassEntry, name: &str) -> Option<Original> {
    // SAFETY: as in [`method`]: the function table lives for the process.
    unsafe {
        let function = zend_hash_str_find_ptr_lc(&raw const class.function_table, name.as_ptr().cast(), name.len())
            .cast::<zend_function>()
            .as_ref()?;
        if u32::from(function.type_) != ZEND_INTERNAL_FUNCTION {
            return None;
        }
        function.internal_function.handler.map(Original)
    }
}

/// A handler [`handler`] found.
#[derive(Clone, Copy)]
pub struct Original(unsafe extern "C" fn(*mut ext_php_rs::ffi::zend_execute_data, *mut ext_php_rs::ffi::zval));

impl Original {
    /// Runs the call as the method would have without the hand-written handler.
    pub fn call(self, ex: &mut ExecuteData, retval: &mut Zval) {
        // SAFETY: the handler is the method's own, called with its call frame and return value.
        unsafe { (self.0)(std::ptr::from_mut(ex).cast(), std::ptr::from_mut(retval).cast()) }
    }
}

/// Points `declaring`'s method `name` (lowercase) at `handler` in `declaring` and in every
/// class that inherited it. Returns how many classes were changed.
pub fn method(declaring: &ClassEntry, name: &str, handler: FunctionHandler) -> usize {
    let declaring = std::ptr::from_ref(declaring).cast::<zend_class_entry>();
    let globals = ExecutorGlobals::get();
    let Some(classes) = globals.class_table() else {
        return 0;
    };
    let mut changed = 0;
    // SAFETY: in RINIT the class table holds every class entry, each as an `IS_PTR` value
    // in a used bucket; the function tables and the internal functions in them live for
    // the process, and nothing calls a method while it is changed.
    unsafe {
        let table = std::ptr::from_ref(classes);
        for i in 0..(*table).nNumUsed as usize {
            let bucket = &*(*table).__bindgen_anon_1.arData.add(i);
            if u32::from(bucket.val.u1.v.type_) == IS_UNDEF {
                continue;
            }
            let class = bucket.val.value.ptr.cast::<zend_class_entry>();
            let function =
                zend_hash_str_find_ptr_lc(&raw const (*class).function_table, name.as_ptr().cast(), name.len())
                    .cast::<zend_function>();
            let Some(function) = function.as_mut() else { continue };
            if u32::from(function.type_) != ZEND_INTERNAL_FUNCTION || function.common.scope != declaring.cast_mut() {
                continue;
            }
            function.internal_function.handler = Some(std::mem::transmute::<
                FunctionHandler,
                unsafe extern "C" fn(*mut ext_php_rs::ffi::zend_execute_data, *mut ext_php_rs::ffi::zval),
            >(handler));
            changed += 1;
        }
    }
    changed
}
