class PoliciesController < ApplicationController
  def update
    policy = project.update_password_strength_policy(
      min: 8,
      uppercase: true,
      number: true,
      symbols: true
    )

    render json: policy.to_map
  end

  def index
    render json: project.list_policies.to_map
  end

  private

  def project
    @project ||= Appwrite::Project.new(APPWRITE_CLIENT)
  end
end
