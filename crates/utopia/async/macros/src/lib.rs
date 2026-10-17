//! The attributes of `utopia-async`: `#[utopia_async::main]` and
//! `#[utopia_async::test]`. Use them through `utopia_async`, which
//! re-exports them; the expansions name `::utopia_async`.

use proc_macro::TokenStream;
use quote::quote;
use syn::parse::Parser;
use syn::punctuated::Punctuated;
use syn::{Expr, ExprLit, ItemFn, Lit, MetaNameValue, Token};

/// Runs an async test function to its end on a new runtime: single-threaded
/// by default, multi-threaded with `threads = N` workers.
#[proc_macro_attribute]
pub fn test(args: TokenStream, item: TokenStream) -> TokenStream {
    match expand(args.into(), item.into(), true) {
        Ok(tokens) => tokens.into(),
        Err(error) => error.to_compile_error().into(),
    }
}

/// Runs an async `main` to its end on a new runtime: single-threaded by
/// default, multi-threaded with `threads = N` workers.
#[proc_macro_attribute]
pub fn main(args: TokenStream, item: TokenStream) -> TokenStream {
    match expand(args.into(), item.into(), false) {
        Ok(tokens) => tokens.into(),
        Err(error) => error.to_compile_error().into(),
    }
}

fn expand(
    args: proc_macro2::TokenStream,
    item: proc_macro2::TokenStream,
    test: bool,
) -> syn::Result<proc_macro2::TokenStream> {
    let mut function: ItemFn = syn::parse2(item)?;
    let mut threads: Option<usize> = None;
    for option in Punctuated::<MetaNameValue, Token![,]>::parse_terminated.parse2(args)? {
        if !option.path.is_ident("threads") {
            return Err(syn::Error::new_spanned(&option.path, "unknown option: the only option is `threads = N`"));
        }
        let Expr::Lit(ExprLit { lit: Lit::Int(count), .. }) = &option.value else {
            return Err(syn::Error::new_spanned(&option.value, "`threads` takes a number of worker threads"));
        };
        let count: usize = count.base10_parse()?;
        if count == 0 {
            return Err(syn::Error::new_spanned(&option.value, "`threads` must be at least 1"));
        }
        threads = Some(count);
    }
    if function.sig.asyncness.take().is_none() {
        return Err(syn::Error::new_spanned(function.sig.fn_token, "the function must be `async`"));
    }
    if !function.sig.inputs.is_empty() {
        return Err(syn::Error::new_spanned(&function.sig.inputs, "the function takes no arguments"));
    }
    let builder = match threads {
        Some(threads) => quote!(::utopia_async::Builder::multi_thread().threads(#threads)),
        None => quote!(::utopia_async::Builder::current_thread()),
    };
    let body = &function.block;
    let attrs = &function.attrs;
    let vis = &function.vis;
    let sig = &function.sig;
    let marker = test.then(|| quote!(#[::core::prelude::v1::test]));
    Ok(quote! {
        #marker
        #(#attrs)*
        #vis #sig {
            #builder.build().expect("utopia-async: the runtime cannot start").block_on(async #body)
        }
    })
}
