# Tenant and company ownership in the People Connector

**Document type:** Data-ownership contract
**Status:** Active
**Issue:** BelimbingApp/blb-people-connector#6 (cross-links BelimbingApp/blb-people#21)
**Last updated:** 2026-09-02

---

## Why this document exists

Three separate slices of this repository were written by three different
authors, and all three shipped the same defect. A reviewer reproduced each one
end to end:

- a provider health store cached per Octane worker, pinning one tenant;
- a merge run driven through Company B that retired Company A's identity;
- a user at Company Alpha who read Company Beta's skill catalog, renamed a
  skill to "DEFACED BY ALPHA", deactivated it, and published Beta's draft
  proficiency scale — all inside one tenant.

None of that was carelessness. `forTenant()` reads as though it is *the*
isolation call, nothing in the base classes hints that a second boundary
exists, and every isolation test in the repository compared two tenants. A
careful author following the house pattern produced the bug, and the suite
passed.

So this document does two things. It states which data belongs to a tenant and
which belongs to a company, table by table and with a reason. And it names the
mechanism that makes forgetting the company boundary raise an error instead of
quietly returning somebody else's rows.

---

## The two words that sound the same

Almost all of the confusion comes from one collision of names. There are two
different things called "company" in this repository, living in two different
id spaces.

**Platform company** — a row in the framework's `companies` table. This is the
legal entity a Belimbing user belongs to (`users.company_id`). It is the id
that `provider_connections.company_id` stores.

**Workforce company** — a row in
`people_connector_connector_workforce_entities` whose `resource_type` is
`company`. This is the connector's provider-neutral identity for a company as
the HR provider describes it. It is the id that every `company_entity_id`
column stores, and it is the axis this document is about.

The two are not interchangeable and there is currently no column linking one to
the other. See "What this contract cannot yet decide" below.

**Rule of thumb:** a column named `company_id` means a platform company. A
column ending in `_entity_id` — including `company_entity_id` — means a
workforce entity id. Never assign one to the other.

---

## The rule

Every connector table is tenant-owned. `tenant_id` is on every table, every
foreign key is composite `(id, tenant_id)`, and no query may omit it. That part
of the contract was already true and already good.

On top of that, each table falls into exactly one of three classes.

### Class T — tenant-wide

The table holds no company-owned fact. Its rows are deliberately shared by
every company in the tenant, and adding a company filter would be wrong, not
merely unnecessary.

### Class C — company-owned, direct

The table carries `company_entity_id` (or the row *is* a company). A query that
does not constrain that column may return, update, or delete another company's
rows. Tenant scoping alone is not isolation for these tables.

### Class D — company-owned, derived

The table holds a company-owned fact but carries no company column. Ownership
is inherited through one named parent column, and constraining that parent is
what pins the query to a company.

### How an author tells them apart

Ask one question about the table being added: *if this tenant contained two
companies, would a row belonging to one of them be wrong to show to the other?*

- No, the row is a shared fact about the tenant or about the integration
  itself → **Class T**.
- Yes, and the row can name its owner directly → **Class C**: add
  `company_entity_id`, a composite foreign key to the workforce entities table,
  and a `(tenant_id, company_entity_id, …)` index; put the trait on the model.
- Yes, but the row only makes sense as part of a bigger thing that already has
  an owner (a scale's levels, a connection's checkpoints) → **Class D**: name
  the parent column in `companyScopeColumns()`.

If you cannot answer the question confidently, that is a signal the table's
ownership is genuinely undecided. Say so on the issue instead of guessing —
guessing is how the connector reached three identical defects.

---

## Table-by-table classification

### Connector module

| Table | Class | Company key | Why |
|---|---|---|---|
| `..._provider_connections` | **T** | — | This is the row that *assigns* companies, so it cannot be filtered by the axis it defines. Its `company_id` is a platform company and is deliberately nullable: one HR install legitimately serves a whole tenant. Listing and configuring connections is a tenant-administration action. |
| `..._workforce_entities` | **T** | — | A bare identity token: an id, a `resource_type`, a state, and a merge pointer. It carries no company-owned fact, and it is shared on purpose — one person keeps one connector id no matter which connection observed them. See the caveat below; the *row* is tenant-wide, but *changing its state* is not. |
| `..._external_identities` | **D** | `connection_id` | The (provider, external id) → entity mapping. Its company is whatever company its connection belongs to. This is exactly where the merge defect lived: the code scoped to the tenant and the connection boundary stopped there. |
| `..._workforce_companies` | **C** (self) | `workforce_entity_id` | The company projection *is* the company. Its own workforce entity id is the axis value; there is no separate `company_entity_id` column and there should not be one. |
| `..._workforce_organization_units` | **C** | `company_entity_id` | A department belongs to one company. |
| `..._workforce_positions` | **C** | `company_entity_id` | A position belongs to one company. |
| `..._workforce_employees` | **C** | `company_entity_id` | An employment record belongs to one company. The most sensitive projection in the module. |
| `..._workforce_snapshots` | **D** | `connection_id` | Append-only raw provider payloads. Company follows the connection that produced them. |
| `..._sync_checkpoints` | **D** | `connection_id` | A resume cursor for one stream on one connection. Meaningless except through its connection. |
| `..._sync_checkpoint_events` | **D** | `checkpoint_id` | Append-only history of one checkpoint, which belongs to one connection. |
| `..._reconciliation_issues` | **D** | `connection_id` | An open problem with one connection's data. |

### Skill module

| Table | Class | Company key | Why |
|---|---|---|---|
| `..._skill_categories` | **C** | `company_entity_id` | Each company groups its catalog its own way. |
| `..._skill_skills` | **C** | `company_entity_id` | The skill catalog is the company's own competency definitions, and `code` is unique per company, not per tenant. |
| `..._skill_proficiency_scales` | **C** | `company_entity_id` | Scale codes and versions are per company; publishing retires the previous version of the same code *within the company*. |
| `..._skill_proficiency_scale_levels` | **D** | `scale_id` | The behavioural anchors of one scale. They have no meaning apart from their scale, and the scale names the company. |

### The one caveat on Class T

`workforce_entities` rows are tenant-wide, but the *operations* on them are
not. Deactivating an entity, or merging one entity into another, changes facts
that a specific company owns — and the reproduced merge defect is precisely an
operation performed with the wrong company's authority. Classifying the table
as T says "do not put a `company_entity_id` column on it"; it does **not** say
"anyone in the tenant may merge anything". Authority for those operations is
attribution, not query scoping, and it is the open question below.

---

## The mechanism

A convention would not have helped. Three authors already followed the house
convention faithfully and produced the same hole. So the company axis is
enforced by something that fails when it is omitted.

### What it is

`App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned` is a trait
that a Class C or Class D model uses. It does three things:

1. Declares which columns pin a query to one company, via
   `companyScopeColumns()`. The default is `['company_entity_id']`; Class D
   models and the company projection override it.
2. Adds `forCompany(int $tenantId, int $companyEntityId)` — one call that
   applies **both** axes, so a query cannot be half-scoped.
3. Registers the `RequireCompanyScope` global scope, which inspects the query
   just before it runs. If no top-level `AND` predicate constrains one of the
   declared columns, it throws `MissingCompanyScopeException` instead of
   executing.

The result is that `Skill::query()->forTenant($tenantId)->get()` — the exact
line all three lanes wrote — now raises an error naming the model and the
column it should have constrained. The mistake is no longer silent.

### The escape hatch, and why there is one

Some queries genuinely span companies: enumerating which companies exist, or a
connector sync run writing a whole provider payload. Those call

```php
Model::query()->withoutCompanyScope('why this query may span companies')->…
```

The reason string is required and is not decorative. `grep -rn
withoutCompanyScope` lists every place in the repository where the company
boundary is deliberately not applied, together with the author's stated
justification. That list is short, reviewable, and countable — which is the
opposite of the situation this document was written to end, where the
unscoped queries were invisible because they looked exactly like the scoped
ones.

### What the guard accepts

The predicate must be at the top level of the query and joined with `AND`,
using `=` or `IN`. Predicates buried inside a nested `orWhere` group do not
count, and a top-level `orWhere` anywhere in the query fails the guard
outright, because either of those can widen the result set past the company.
This is deliberately strict: a guard that can be talked into passing is not a
guard.

The primary key does **not** count as a pin. Addressing a row by its `id`
proves the caller knows an id, not that the caller may have it — and a leaked
id is the second half of the reproduced exploit. When a relationship must
traverse into a company-owned model by primary key (a skill reaching its
category, a level reaching its scale), the relationship states so explicitly
with `withoutCompanyScope()` and says why it is safe.

### What the guard does not cover

Being honest about the edges:

- **Creating a row through a model.** `Model::create()` and `$model->save()`
  build their insert without scopes, so the guard never sees them. The
  database's `NOT NULL` constraint on `company_entity_id` and the composite
  foreign key are the backstop, and the stores validate the entity before
  writing. A mass `Model::query()->insert()` *is* guarded, because Eloquent
  passes that through the scoped query builder.
- **Saving or deleting a model you already hold.** Eloquent addresses those by
  primary key without scopes. That is correct: you obtained the instance
  through a guarded query.
- **Raw `DB::table()` queries.** They bypass Eloquent entirely. Do not use them
  on a company-owned table.
- **Class D tables in the Connector module.** They are classified above but
  their models do not yet carry the trait, because adopting it there means
  changing the identity, checkpoint, and reconciliation stores while those are
  still in review. Adoption is staged; the mechanism already supports them.

### Why this shape and not another

- **A `forCompany()` scope on its own** is what the interim work in PR #5
  built, and its instinct was right. But it is additive: `forTenant()` stays
  inherited and reachable, so omitting the company axis still silently
  succeeds. That is today's failure mode with a better name on it. This
  mechanism keeps that method — renamed from `forOwner()` — and adds the part
  that makes forgetting it fail.
- **A global scope that fills in the current company automatically** would be
  unmissable, but it would need an ambient "current company" object, and
  building one repeats the mistake that produced the health-store defect: a
  container-scoped singleton that survives an Octane worker and pins the wrong
  value. Worse, auto-filling silently *changes* results, so a sync run that
  legitimately spans companies would quietly write half its rows. Refusing is
  loud; guessing is not.
- **A guard in the database connection layer** would also catch raw queries,
  but it has to parse SQL text, cannot see the author's intent, and produces
  false positives on joins and subqueries. Higher cost, lower precision.
- **A separate `CompanyOwnedModel` base class** with `forTenant()` removed
  would fork the model hierarchy and still only protect the scope-method path;
  a plain `->where('tenant_id', $id)` would slip straight through. The global
  scope catches both and composes with the existing `TenantOwnedModel`.

---

## The test

`Connector/Testing/CompanyIsolationContract.php` holds one reusable assertion
and one discovery test runs it over **every** model in the repository that
declares itself company-owned. A new slice does not copy a test; adding the
trait enrolls the model automatically. For each one it asserts that:

- a query scoped to Company A never returns Company B's rows;
- an unscoped query raises `MissingCompanyScopeException` rather than
  returning the tenant;
- an update and a delete addressed only by tenant are refused the same way.

Alongside it, `CompanyIsolationTest` reproduces the reviewed exploit in full:
two companies inside one tenant, and a user at Alpha who can neither read nor
rename nor deactivate anything belonging to Beta, at the store layer and
through the Livewire component.

---

## What this contract cannot yet decide

One question is genuinely open, and this document does not answer it.

**Which workforce companies may a given user act for?** The chain that exists
today runs from the projection to its source identity to that identity's
connection to `connections.company_id`, and then compares that platform company
to the actor's own. That chain works only when the connection is
company-scoped. When a connection is tenant-scoped — one HR2000 install serving
five companies, which is a normal deployment, not an edge case — there is no
stored fact anywhere that says which platform company a given workforce company
corresponds to.

`Connector/Services/CompanyAttribution` therefore fails closed in that case: an
unattributable workforce company is offered to nobody. It keeps one carve-out,
that a tenant which has only ever held a single platform company has no
cross-company boundary to violate.

What would actually close the gap is a stored link from a workforce company to
a platform company — a nullable `company_id` on
`people_connector_connector_workforce_companies`, set when an administrator
confirms the mapping. That is a schema and product decision, and it belongs to
the ownership contract in BelimbingApp/blb-people#21 rather than to a query
guard. Until it lands, the fail-closed rule stands and this paragraph is the
reason.

Two smaller things are also deliberately left open:

- Merge and deactivate authority on `workforce_entities`, per the caveat above.
  Query scoping cannot express it; it needs the attribution answer first.
- Whether `provider_connections` should ever be readable by a company
  administrator rather than only a tenant administrator. Today it is Class T
  and tenant-administered, which is safe but may be too coarse.
