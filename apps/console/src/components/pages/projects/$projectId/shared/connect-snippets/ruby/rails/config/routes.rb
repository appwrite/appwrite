Rails.application.routes.draw do
  patch '/v1/policies', to: 'policies#update'
  get '/v1/policies', to: 'policies#index'
end
