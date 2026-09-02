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
| `..._provider_connections` | **T** | — | This is the row that *assigns* workforce companies, so it cannot be filtered by that axis — it has no `company_entity_id` and should not. It does carry a **platform** `company_id`, deliberately nullable because one HR install legitimately serves a whole tenant, and a tenant-scoped connection stores no company at all. Listing and configuring connections is a tenant-administration action. |
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
| `..._provider_credentials` | **D** | `connection_id` | Short-lived provider credentials inherit the company of their connection; every lookup pins the connection and rejects inactive selections. Credential revocation deliberately uses the documented escape to locate the row before resolving its tenant-owned connection. |
| `..._privileged_support_grants` | **T** | — | A tenant administration grant may optionally name a platform company scope; its service checks both actors against that scope before issuing or using it. |
| `..._privileged_support_actions` | **T** | — | Immutable evidence of a tenant administration grant; ownership follows the grant through the composite foreign key, while append-only database guards protect the evidence. |

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
4. Puts a `CompanyOwnedQuery` under the model as its *base* query builder,
   which refuses any write that sets one of the declared columns — `update()`,
   `upsert()`, `updateOrInsert()`, `increment()`/`decrement()` and their
   `$extra` maps, `toBase()`/`getQuery()` writes, and the `UPDATE` a `save()`
   issues — unless the writer first said `movingCompany($reason)`. It throws
   `CompanyMoveRefusedException`. See "Moving a row between companies" below.

The result is that `Skill::query()->forTenant($tenantId)->get()` — the exact
line all three lanes wrote — now raises an error naming the model and the
column it should have constrained. The mistake is no longer silent.

### The escape hatch, and why there is one

Some queries genuinely span companies: enumerating which companies exist, or a
connector sync run writing a whole provider payload. Those call

```php
Model::query()->withoutCompanyScope('why this query may span companies')->…
```

One thing to know before using it: **the escape covers the whole query, not
just the clause that needed it** — including anything a later caller appends. An
escape on a relation is therefore an escape on every query built from that
relation, and an unbracketed `orWhere` appended to one will read and write past
the company. Prefer pinning explicitly over reaching for the escape, and put it
as close to the query that needs it as you can.

That is not a hypothetical, and writing the warning down did not stop it. Three
relations carried an escape with a warning exactly like this one attached, and
all three were exploitable as written:

```php
$alphaCategory->skills()->orWhere('id', $betaSkillId)->get();
// where category_id = ? and category_id is not null or id = ?
```

That SQL pins no company. It also pins no tenant, because `skills()` was a
plain `hasMany` on `category_id` and `TenantOwnedModel` carries no global
tenant scope — so one appended `orWhere` reached any skill id in **any**
tenant, and the same predicate with `->update()` and `->delete()` defaced and
then removed the row.

So there is a rule about where an escape may live, and it is structural rather
than advisory:

> **Never return an escaped builder to a caller.** Build it and consume it in
> the same expression, and return a value or a model — something with nothing
> to append to. If what you want to hand back is a query, the escape is in the
> wrong place.

The escapes that remain all satisfy that: each is a builder created and
finished inside one method, and the reason on each is true of the finished
query rather than of a query somebody else will go on to extend.

The reason string is required and is not decorative. `grep -rn
withoutCompanyScope` lists every place in the repository where the company
boundary is deliberately not applied, together with the author's stated
justification. That list is short, reviewable, and countable — which is the
opposite of the situation this document was written to end, where the unscoped
queries were invisible because they looked exactly like the scoped ones.

That grep is only complete because something enforces it. Laravel's own
`withoutGlobalScope()`, `withoutGlobalScopes()`, `newQueryWithoutScope()` and
`newQueryWithoutScopes()` remove this guard just as effectively, take no
reason, and appear in no such grep. So
`CompanyIsolationContract::unreasonedGuardBypasses()` tokenizes every PHP file
in the domain and fails the suite if any of them is called anywhere except the
trait's own sanctioned line.

**One gap is left open on purpose.** `->getQuery()` steps off the Eloquent
builder onto the underlying query builder, which has no global scopes, so it
opens the guard too — and it is not linted. It is an ordinary method with many
honest uses on models that are not company-owned, and a check that flags those
gets argued down until it means nothing. It belongs in the same category as
`DB::table()`: you have left Eloquent, and the rule below about raw queries
applies. Do not reach for it on a company-owned table.

### Moving a row between companies

The scope guard checks a query's *predicates*. It cannot check its *values*,
and that leaves one write it accepts which is still wrong: a correctly pinned
update that sets `company_entity_id` to a sibling company's id.

```php
Skill::query()->forCompany($tenantId, $alpha)->update(['company_entity_id' => $beta]);
```

Both axes are pinned, on the base table, with real values — the exact shape
the guard is written to accept. And the database accepts it too, which is the
asymmetry that made this worth its own rule (blb-people-connector#18): the
tenant axis has a foreign key, so moving a row to a tenant that does not exist
fails, but the company axis has only a composite key to
`workforce_entities (id, tenant_id)`, and `$beta` *is* a real entity in the
same tenant. The write is valid; it is only wrong. Afterwards the row is not
visible as a leak. It looks like data that was never there.

So changing a company column is refused unless it is stated:

```php
$projection->movingCompany('why this row may change company')->fill($values)->save();
Model::query()->movingCompany('why')->forCompany(…)->update([…]);
```

The refusal lives on the model's **base** query builder, `CompanyOwnedQuery`,
not on the Eloquent builder and not in a model event. That placement is the
finding of the #28 review, and it is what makes the list below complete:
Eloquent's `update()` is `toBase()->update()`, `increment()`, `decrement()`,
`incrementEach()` and `decrementEach()` are `toBase()->…` with an `$extra`
column map spliced into the same `SET`, and `updateOrInsert()` is not on the
Eloquent builder at all and is forwarded to the base one. A check on the
Eloquent builder saw none of those; the base builder sees all of them, and
also `->getQuery()`/`->toBase()` writes, `saveQuietly()` and
`Model::withoutEvents()`, because nothing about it is an event. It applies to
every column in `companyScopeColumns()`, so a Class D row cannot be moved to
another company's parent either.

A statement covers **one write**. On a builder, the grant is spent by the
next `update()`/`upsert()` on that builder *or any clone of it* — the grant
is shared by reference precisely because Eloquent clones the base query on
every `toBase()`, and a flag copied into the clone would have left the
original armed. On a model, the grant is spent by the next `save()`, whether
that save succeeds, aborts at the database, or is halted by a listener before
it gets there; a `delete()` does not spend it. This is the difference from an
escape on a relation, which covered everything appended to it.

The reason is required for the same purpose as on `withoutCompanyScope()`:
`grep -rn movingCompany` is the complete list of places a row may leave its
company **through Eloquent**, and it is complete because every Eloquent route
that could move one without that marker is refused. `DB::table()` is not
Eloquent and is not covered; see "What the guard does not cover". Today there
are two, and they are the only two the domain knows of:

- **A sync pass.** `WorkforceProjectionStore` writes the provider's payload as
  observed, and the company an employee, position or unit belongs to is part of
  that payload. An employee transferring between two companies in HR2000
  arrives as exactly this update. The company axis on a projection table is
  provider truth, and this is where it is allowed to change.
- **A company merge.** `WorkforceIdentityStore::merge()` rewrites every row
  owned by the superseded company entity to its survivor, in the same
  transaction that marks the superseded entity merged. The set of tables it
  rewrites is **derived** from the models declaring `CompanyOwned` with
  `company_entity_id` as their owner column (`CompanyOwnedModels`), not
  listed by hand: a hand-kept list was three tables short by the time it was
  checked (blb-people-connector#29), and every row it missed did not become
  wrong but invisible, because a query pinned to the survivor cannot see a
  row still pointing at the merged entity. A merge that would collide on a
  unique catalog key in the survivor is refused whole with
  `WorkforceMergeConflictException`; nothing moves until a person resolves
  the duplicate. Class D rows follow their parent; a company projection is
  the entity itself and is retired rather than rewritten. The other branches
  — a merged organization unit, position, employee or user — are derived the
  same way, from `WorkforceReference` declarations on the models that carry
  a column pointing at that kind of entity (`ReferencesWorkforceEntities`),
  and `WorkforceReferenceContractTest` fails the suite when a `*_entity_id`
  column exists on any model's table and is not declared, or is declared and
  does not exist (blb-people-connector#35). A **published or
  retired proficiency scale** is carried too: its immutability guard (model
  and trigger) exempts a change of owner alone, from an entity already marked
  merged into the new owner, and nothing else — content and lifecycle stay
  immutable.

`CompanyIsolationContract` runs the whole route list against every
company-owned model — fourteen builder routes and three model routes, plus the
one-write rule on the same builder, on a clone taken while armed, and on a
model whose save was halted — so a route that is added to Eloquent later, or
a model added to the domain later, fails the suite rather than the tenant.

**Where the database also refuses.** The Skill module's catalog rows —
categories, skills, proficiency scales — change company in exactly one case:
a company merge, which carries them to the survivor. No sync writes them. So
there the backstop is the same class of thing the tenant axis has: a
`BEFORE UPDATE` trigger on each table, on both drivers, that aborts when
`company_entity_id` changes *unless* the old owner is a workforce entity in
state `merged` whose `merged_into_entity_id` is the new owner — which is what
the merge records, in the same transaction, before it rewrites anything. That
is the actual rule, expressed where the model layer cannot be bypassed. The
model-layer refusal gives the author a message; the trigger stands when the
model layer is stepped around. Note what the trigger's exemption is: a
**standing** permission, not one scoped to the merge transaction. Once entity
A is recorded as merged into B, any write that moves a catalog row from A to
B is permitted by the database from then on. That is bounded — the row can
only go where the merge would have sent it — and it is the price of a rule
the database can check without a session flag; it is stated here so nobody
reads it later as a bug. Projection tables get no trigger, because the
database cannot tell a provider-side transfer from a mistake; there the named
escape is the mechanism, and the sync store is the one caller.

### What the guard accepts

The predicate must satisfy all four of these, and each one closes a bypass
that was reproduced end to end against an earlier version of this guard.

1. **Top level, joined with `AND`.** A predicate buried inside a nested
   `orWhere` group does not count, and a top-level `orWhere` anywhere in the
   query fails the guard outright, because either can widen the result past
   the company.
2. **On the base table, under a name the query itself binds to it.** An
   unqualified column is fine — it resolves to the base table anyway. A
   qualified one must name the query's `from`, or its alias when `from` has
   one; once `from` is aliased the bare table name is *not* accepted, because
   the query has rebound it and SQL no longer addresses the base table by it.

   Both halves of that rule were reproduced as bypasses. Accepting any
   qualifier let a join whose `ON` clause correlated only on `tenant_id`
   satisfy the guard with a predicate on the *joined* table: a reviewer read a
   sibling company's skill through it, renamed it, and deleted it, all three
   reported as compliant. Fixing that by trusting the model's own table name
   left the same hole one layer in — `->from("skills as s")->join("categories
   as skills", …)` handed the freed name to the join, with no raw SQL
   anywhere.
3. **Compared to a real value, with `=` or `IN`.** A raw expression can be a
   tautology (`company_entity_id = company_entity_id`), and Laravel records a
   `whereIn` subquery as an ordinary `In` holding a single `Expression`, so an
   unbounded subquery would otherwise read as a pin. Expressions are rejected
   as values and inside value lists.
4. **Not the base table's own primary key.** Addressing a row by its `id`
   proves the caller knows an id, not that the caller may have it — and a
   leaked id is the second half of the reproduced exploit.
5. **The query must be one SELECT.** A `union()` or `unionAll()` arm is a
   second SELECT that this guard never inspects — it reads `wheres`, `from`
   and `joins`, and Laravel keeps union arms somewhere else entirely. So
   pinning the base did nothing at all to the arm:

   ```php
   Skill::query()->forCompany($tenantId, $alpha)
       ->union(fn ($q) => $q->from('people_connector_skill_skills'))
       ->get();
   ```

   returned the sibling company's row *and another tenant's row*, hydrated as
   `Skill`, and the query reported itself compliant. Ordinary Eloquent — no
   `DB::` facade, no raw SQL. A query carrying a union is now refused
   outright, for the same reason `fromSub()` and `fromRaw()` are: it reads
   like it is still inside the guarded model and it is not. Run the arms as
   separate pinned queries and merge the results.

Point 4 is about the row's *own* key. A **parent's** key is different, and
that is what a Class D table pins on: ownership genuinely lives on the parent,
so naming the parent is naming the owner. The protection a Class D table gets
is therefore weaker than a Class C table's, and the difference should be
understood rather than glossed:
`ProficiencyScaleLevel::query()->where('scale_id', $someScaleId)` is
guard-compliant and returns that scale's levels whoever owns it. What the guard
buys there is that you cannot sweep the tenant's levels — you have to name one
scale. Who may name which scale is authorization, below.

When a relationship traverses into a company-owned model by primary key, that
relationship does **not** satisfy the guard, and the answer is no longer to
switch the guard off for it:

- `SkillCategory::skills()` is deleted. It constrained `category_id`, which is
  not Skill's company column, so it could only run with an escape. Callers get
  `skillCount()` and `hasActiveSkills()` instead — both pinned to the
  category's own tenant and company, both returning a value. Code that wants
  the rows writes the pinned query where it needs it.
- `Skill::category()` keeps the relation and drops the escape. The honest cost
  is that lazy `$skill->category` now throws; load it with the company pinned,
  which every caller can do because every caller already knows the company it
  is acting for:
  `->with(['category' => fn ($q) => $q->forCompany($tenantId, $companyEntityId)])`.
- `ProficiencyScaleLevel::scale()` becomes a private `owningScale()` returning
  a model. A level names its scale only by the scale's primary key, so the
  escape there is genuinely unavoidable — but it now lives inside one method
  and never hands a builder out.

**A correlation to an enclosing query also counts as a pin**, and getting that
right removed an escape rather than adding one. `has()`, `whereHas()`,
`withCount()` and `doesntHave()` link their subquery to the parent with a
column-to-column predicate and nothing else, so the guard used to refuse them
even from a correctly pinned parent. That was fail-closed and leaked nothing —
but it left an author who merely wanted to count a scale's levels with no
legitimate way to satisfy the guard, and the next thing they reach for is
`withoutCompanyScope()` at their own call site. **A guard that has to be
switched off to do ordinary work gets switched off**, and the escape they write
under mild frustration is wide enough to cover whatever else they append. The
first attempt at this did exactly that, on `ProficiencyScale::levels()`, and a
reviewer read *and wrote* a sibling company's level through an appended
`orWhere` before it was replaced by the rule below.

The rule: a `Column` predicate pins the query when the query has **no joins at
all**, one side is an owning column on the base table, and the other side is
qualified with something that is not the base table. It binds each row of this
query to exactly one row of the enclosing query.

**What that is worth depends entirely on the enclosing query, and the rule
cannot check it.** An earlier version of this paragraph claimed the correlation
had "the same strength as pinning to one literal parent id". That is true only
when the enclosing query is itself pinned to one company — which is the case
the rule was written for, `has()` / `whereHas()` / `withCount()` on a
company-owned parent, because that parent carries the guard and therefore had
to be pinned before it could run at all.

It is not true when the outer query is unguarded. `workforce_entities` is Class
T by design, so:

```php
WorkforceEntity::query()->forTenant($tenantId)
    ->addSelect(['skill_name' => Skill::query()->select('name')
        ->whereColumn('company_entity_id', 'people_connector_connector_workforce_entities.id')
        ->limit(1)])
    ->get();
```

reads a skill name for *every* company entity in the tenant, with no escape and
no join anywhere. The correlation did its job — one inner row per outer row —
and the outer row set was the whole tenant. Before the correlation rule existed
this was refused, so the rule genuinely widened what runs.

That was accepted rather than reverted, because the alternative is worse. The
guard sees one query at a time; a subquery holds no reference to the query that
encloses it, so there is nothing for the rule to inspect. Refusing every
correlation puts `withCount('levels')` out of reach from a properly pinned
parent, and **a guard that has to be switched off to do ordinary work gets
switched off** — the failure this whole section is about. So the rule stays and
the promise is corrected instead:

> A correlation inherits the enclosing query's scoping. It cannot add any. If
> the outer query spans companies, so does the correlated read, and the guard
> will not say so.

Practically: when you correlate a company-owned subquery into a Class T outer
query, pin the outer one yourself — `forCompany()` on a company-owned outer, or
an explicit company predicate on a Class T outer. A correlation is not a
substitute for scoping the query you are writing.

The no-join condition is the whole safety argument, not a detail. A
column-to-column predicate against a table this query can see is a join
condition, not a pin: it constrains the company to whatever the joined row
happens to carry, which is how a join read a sibling company's rows in the first
place. Laravel's relation-existence subqueries carry no join, so nothing
legitimate is lost by refusing to tell the two apart when one is present.

An earlier version tried to tell them apart, by naming the tables the query
could see and accepting the correlation when the other side was *absent* from
that list. See the next section for why that was the wrong shape of rule.

`whereIn('company_entity_id', [$a, $b])` is accepted, by design. The guard
proves the column is constrained to *named* companies. It does not prove there
is only one of them, and it does not prove the caller may act for any of them.

### Prefer an inclusion test to an exclusion test

Worth stating separately, because it generalizes past this file and past this
guard.

Every rule in `RequireCompanyScope` asks whether a name **is** in a list it
trusts: is this qualifier the base table, is this column an owning column. A
name it does not recognise fails those tests, and failing them means the query
is refused. A spelling it has never seen makes the guard *stricter*.

One rule was written the other way round. To tell a correlated subquery from a
join condition, it asked whether the other side was **absent** from the list of
tables the query can see. Same list, same comparison, same possibility of a name
mismatch — and the mismatch now means the guard *accepts*. A schema-qualified
join put `public.categories` in the list while a `whereColumn` against the bare
`categories` was not in it, so a join condition read as a correlation. On
Postgres that returned both companies' rows, and the same query with `->update()`
wrote two rows across the boundary. A case-shifted alias did the same thing with
nothing more exotic than a capital letter.

The rule looked exactly like its neighbours and behaved as their mirror image
under precisely the condition that matters. So:

> **A guard should decide on what it recognises, never on what it fails to
> recognise.** An inclusion test fails closed when a name does not match. An
> exclusion test fails open on the identical mismatch, and every unfamiliar
> spelling becomes an attack.

When a question genuinely needs an exclusion — "is this name absent" — the
answer is usually to find a condition that removes the question instead. Here
that was: a correlation is only distinguishable from a join condition when there
is no join, so require no joins and delete the list.

### The guard is scoping, not authorization

This is worth saying plainly, because "make the company axis a mechanism" can
be read as "isolation is now enforced", and that is not what happened. What is
enforced is that **omitting** the axis fails.

The guard proves a company column is constrained. It does not know which
companies the actor may act for. `workforce_entities` is Class T and unguarded,
so any actor in the tenant can enumerate every entity id in it, including other
companies' company entity ids — and `forCompany($tenantId, $anyOfThem)` will
happily scope to one, as will the store methods that take a company entity id.

Authorization lives in exactly one place: `Connector/Services/CompanyAttribution`,
called from the Livewire components before any store call. Do not read a passing
guard as permission.

### What the guard does not cover

Being honest about the edges:

- **Creating a row through a model.** `Model::create()` and `$model->save()`
  build their insert without scopes, so the guard never sees them. The
  database's `NOT NULL` constraint on `company_entity_id` and the composite
  foreign key ensure a company is **named**; they do not ensure it is *yours*.
  Only the stores do that, in `SkillCatalogStore::assertDraft()` and its
  siblings. A row written straight through `Skill::create()` can carry another
  company's `company_entity_id` and another company's `category_id`, and it
  persists. A mass `Model::query()->insert()` *is* guarded, because Eloquent
  passes that through the scoped query builder.
- **Tables joined into a guarded query.** The guard runs for the query's base
  model only. Joining a second company-owned table pins the base table but not
  the joined one, so a join whose `ON` clause correlates loosely can still read
  columns from another company's rows on the joined side. Scope joins yourself.
- **Cross-company parent links written outside a store.** `skills.category_id`
  has a composite foreign key on `(category_id, tenant_id)`, not on the
  company, so a write that bypasses `SkillCatalogStore` can point a skill at
  another company's category, and `$category->skills` would then surface it.
  The store refuses this; the database does not.
- **Saving or deleting a model you already hold.** Eloquent addresses those by
  primary key without scopes. That is correct: you obtained the instance
  through a guarded query. What a held model may not do silently is change a
  company column; see "Moving a row between companies".
- **Raw `DB::table()` queries, and `->getQuery()`.** Both leave Eloquent, and
  global scopes with it. Do not use either on a company-owned table.
  (`->getQuery()` on a company-owned model still returns its
  `CompanyOwnedQuery`, so the move refusal survives the step; the scope
  guard does not.)
- **A derived or raw `from`.** `fromSub()` and `fromRaw()` are *refused*, not
  silently allowed: once the base relation is derived, the guard cannot tell
  what an unqualified column refers to. That is a deliberate failure rather
  than a gap, because `Skill::query()->fromSub(…)` reads to an author like it
  is still inside the guarded model.
- **A union.** `union()` and `unionAll()` are refused on the same grounds: the
  arm is a separate SELECT the guard never inspects, so a pinned base says
  nothing whatever about what the arm returns.
- **A correlated subquery whose enclosing query is not itself pinned.** The
  correlation rule below binds each inner row to one outer row; it inherits
  the outer query's scoping and cannot add any. Read from an unguarded Class T
  outer, that is no company boundary at all. Stated in full under the rule.
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

`Connector/Testing/CompanyIsolationContract.php` is the shared piece. It finds
every model in the domain that declares itself company-owned and states what
that has to mean, so a new slice is enrolled by adding the trait rather than by
remembering to copy a test. For each model it asserts that:

- a read scoped only to the tenant raises `MissingCompanyScopeException`;
- so does a completely unscoped read;
- so do an update and a delete addressed only by tenant;
- `forCompany()` satisfies the guard;
- `withoutCompanyScope()` opens it, but never with an empty reason.

Alongside those, one test per reproduced bypass: a join whose company predicate
sits on the joined table, a qualifier naming some other table, a join that
claims the base table's name by aliasing `from`, a derived `from`, a `whereIn`
subquery, and a raw tautology — each refused, while a join pinned on the base
table and an aliased `from` pinned on its alias both still run; and a join
correlating on a schema-qualified or case-shifted table name, which is the
exclusion-test failure above. One test that
no file in the domain calls Laravel's unreasoned scope-removal methods. And one
that counting a scale's levels works from a pinned parent, because a guard that
forces good-faith authors into the escape hatch is a guard that gets routed
around — with its companion asserting that an `orWhere` appended to that same
relation is still refused, for both reads and writes.

The relation escapes and the union hole have their own regressions, written
against a fixture that holds two companies in one tenant **and** a second
tenant, because both attacks crossed both axes:

- the appended `orWhere`, as a read, an `update()` and a `delete()`, with the
  sibling company's row and the other tenant's row checked afterwards for name
  and existence;
- `$category->skills()` and `$level->scale()` raising `BadMethodCallException`,
  so the attack fails at the language level rather than by instruction;
- `skillCount()` ignoring a cross-company skill written straight through
  `Skill::create()`, which is exactly the row the old relation would have
  surfaced;
- lazy `$skill->category` refusing, and the pinned eager load working;
- six union variants — `union`, `unionAll`, an unpinned base, an arm that is a
  pinned Eloquent builder for the sibling company, a Class D base, and the
  aggregate path — all refused, with the sibling and cross-tenant rows checked
  intact;
- the Class D exception message naming `scale_id` and no longer sending its
  reader to `forCompany()`, which raised a `LogicException` telling them not to
  call it.

The same class provides `twoCompaniesInOneTenant()` — the fixture the
repository never had, provisioned the way an adapter will: workforce entity,
external identity, company projection, one platform company each.

Two behavioural suites use it.
`Connector/Tests/Feature/CompanyIsolationContractTest.php` covers the connector
side: two companies visible to each other only through the axis, the
attribution rule offering each user only their own company, and archiving a
sibling not reopening the single-company carve-out.
`Skill/Tests/Feature/CompanyIsolationTest.php` walks the reviewed exploit step
by step with Beta's real row ids — read, rename, deactivate, publish, retire,
discard — and confirms every step is refused. Coverage through the Livewire
component lives in `Skill/Tests/Feature/CatalogPageTest.php`.

These were checked against the bug, not assumed. With `forCompany()` degraded
back to tenant-only scoping and the guard unregistered, twelve of the fifteen
original cases fail, and the rename step succeeds exactly as the reviewer
reproduced it. With the guard alone removed — the interim convention, without
the mechanism — eleven still fail. Restoring the guard's two original
weaknesses, the loose column qualifier and the accepted raw expression, fails
the three bypass tests and nothing else. Putting the escape back on
`SkillCategory::skills()`, `Skill::category()` or
`ProficiencyScaleLevel::scale()` fails the relation regressions; deleting the
three-line union refusal fails the union regression.

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
