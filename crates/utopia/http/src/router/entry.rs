use indexmap::IndexMap;

pub(super) struct Entry<T> {
    pub value: T,
    /// Param name -> segment index, per registered template (`Route::$pathParams`).
    pub params: IndexMap<String, IndexMap<String, usize>>,
}
