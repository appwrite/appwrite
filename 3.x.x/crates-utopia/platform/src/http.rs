#[cfg(feature = "http")]
use std::sync::Arc;

#[cfg(feature = "http")]
use crate::action::{Action, ActionType};
#[cfg(feature = "http")]
use crate::error::{PlatformError, Result};
#[cfg(feature = "http")]
use crate::hook_meta::SharedValidator;

/// Adapter for registering platform actions onto an HTTP runtime.
///
/// Implement this trait to integrate `Platform::init_http` with a custom HTTP stack.
/// The default `http` feature provides [`UtopiaHttpRegistrar`] for [`utopia_http::Http`].
#[cfg(feature = "http")]
pub trait HttpRegistrar {
    fn register_action(&mut self, action: &Action) -> Result<()>;
}

#[cfg(feature = "http")]
#[derive(Debug)]
pub struct UtopiaHttpRegistrar<'a> {
    http: &'a mut utopia_http::Http,
}

#[cfg(feature = "http")]
impl<'a> UtopiaHttpRegistrar<'a> {
    pub fn new(http: &'a mut utopia_http::Http) -> Self {
        Self { http }
    }
}

#[cfg(feature = "http")]
impl HttpRegistrar for UtopiaHttpRegistrar<'_> {
    fn register_action(&mut self, action: &Action) -> Result<()> {
        let callback = action.resolve_http_callback()?;
        let groups = action.get_groups().to_vec();
        let desc = action.get_desc().unwrap_or("").to_string();

        match action.action_type() {
            ActionType::Init => {
                register_lifecycle_hook(
                    self.http.on_init({
                        let callback = callback.clone();
                        move |ctx| {
                            let callback = callback.clone();
                            async move { callback(ctx).await }
                        }
                    }),
                    action,
                )?;
            }
            ActionType::Error => {
                register_lifecycle_hook(
                    self.http.on_error({
                        let callback = callback.clone();
                        move |ctx| {
                            let callback = callback.clone();
                            async move { callback(ctx).await }
                        }
                    }),
                    action,
                )?;
            }
            ActionType::Shutdown => {
                register_lifecycle_hook(
                    self.http.on_shutdown({
                        let callback = callback.clone();
                        move |ctx| {
                            let callback = callback.clone();
                            async move { callback(ctx).await }
                        }
                    }),
                    action,
                )?;
            }
            ActionType::Options => {
                register_lifecycle_hook(
                    self.http.on_options({
                        let callback = callback.clone();
                        move |ctx| {
                            let callback = callback.clone();
                            async move { callback(ctx).await }
                        }
                    }),
                    action,
                )?;
            }
            ActionType::Default | ActionType::WorkerStart | ActionType::WorkerStop => {
                let path = action
                    .get_http_path()
                    .ok_or(PlatformError::MissingHttpPath)?;
                let methods: Vec<&str> = action
                    .get_http_methods()
                    .iter()
                    .map(String::as_str)
                    .collect();
                if methods.is_empty() {
                    return Err(PlatformError::MissingHttpMethods);
                }

                let route = self.http.routes(&methods, path)?;
                route.groups(groups).desc(desc);
                for (key, value) in action.get_labels() {
                    route.label(key, value.clone());
                }
                for alias in action.get_http_aliases() {
                    route.alias(self.http.router(), alias)?;
                }
                apply_route_metadata(&route, action)?;
                route.action({
                    let callback = callback.clone();
                    move |ctx| {
                        let callback = callback.clone();
                        async move { callback(ctx).await }
                    }
                });
            }
        }

        Ok(())
    }
}

#[cfg(feature = "http")]
fn register_lifecycle_hook(
    mut builder: utopia_http::HookBuilder<'_>,
    action: &Action,
) -> Result<()> {
    builder = builder.groups(action.get_groups().iter().cloned());
    for (key, param) in action.get_params() {
        builder = builder.param_full(
            key,
            param.default.clone(),
            SharedValidator(param.validator.clone()),
            &param.description,
            param.optional,
            param.injections.clone(),
            param.skip_validation,
            param.deprecated,
            &param.example,
            param.aliases.clone(),
            crate::hook_meta::enum_to_meta(param.enum_meta.as_ref()),
        );
    }
    for injection in action.get_injections() {
        builder = builder.inject(injection)?;
    }
    Ok(())
}

#[cfg(feature = "http")]
fn apply_route_metadata(route: &Arc<utopia_http::Route>, action: &Action) -> Result<()> {
    for (key, param) in action.get_params() {
        route.param_full(
            key,
            param.default.clone(),
            SharedValidator(param.validator.clone()),
            &param.description,
            param.optional,
            param.injections.clone(),
            param.skip_validation,
            param.deprecated,
            &param.example,
            param.aliases.clone(),
            crate::hook_meta::enum_to_meta(param.enum_meta.as_ref()),
        );
    }
    for injection in action.get_injections() {
        route.inject(injection)?;
    }
    Ok(())
}
