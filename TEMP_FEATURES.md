# Auth Features Structure

## Collections

- `auth_features` (project-level catalog)

  - `featureId` (string): unique feature identifier
  - `name` (string): display name
  - `type` (string): `boolean` or `metered`
  - `description` (string)
  - `active` (boolean)

- `auth_plan_features` (assignment of features to plans)

  - `projectId` (string)
  - `planId` (string)
  - `featureId` (string)
  - `type` (string): `boolean` or `metered`
  - For `boolean`:
    - `enabled` (boolean)
  - For `metered`:
    - `currency` (string, 3 letters)
    - `interval` (string: day|week|month|year)
    - `includedUnits` (int)
    - `tiersMode` (string: graduated|volume)
    - `tiers` (array of objects): each `{ to: number|"inf", unitAmount: number, flatAmount?: number }`
    - `stripePriceId` (string): linked Stripe price for this feature
  - `active` (boolean)

- `auth_plans` (unchanged fields plus Stripe product/price for base plan)

## Endpoints

- Manage features (project-level):

  - `POST /v1/projects/:projectId/auth/features`
  - `GET /v1/projects/:projectId/auth/features`
  - `PUT /v1/projects/:projectId/auth/features/:featureId`
  - `DELETE /v1/projects/:projectId/auth/features/:featureId`

- Assign features to a plan:
  - `POST /v1/projects/:projectId/auth/plans/:planId/features`
  - `GET /v1/projects/:projectId/auth/plans/:planId/features`

## Stripe Mapping

- Base plan uses `auth_plans.stripePriceId` for subscription primary item.
- Each metered feature assignment creates a separate Stripe Price with:
  - `usage_type = metered`, `billing_scheme = tiered`, `tiers_mode = graduated|volume`, `aggregate_usage = sum`
  - Backed by a Stripe Meter; `stripeMeterId` is stored in `auth_plan_features`
  - Tiers include a free tier for `includedUnits` with `unit_amount = 0`
  - Price `nickname` = `Feature: {feature.name}`
  - `metadata`: `project_id`, `plan_id`, `feature_id`, `type = auth_plan_feature_price`

## Checkout Behavior

- Checkout adds the plan price item plus one line item per assigned metered feature price.
- Boolean features apply immediately on plan assignment and do not generate additional Stripe items.

## Evaluation

- User effective features are derived from plan:
  - Booleans: enabled if assignment exists with `enabled=true`.
  - Metered: tracked externally via Stripe usage records tied to `stripePriceId`; included units are tiered free usage.
