# PT PPM — Packaging Development System (NPD)

Specification, approval-chain and supplier-portal platform for packaging development.
Built to the signed documents in [`project documentation/`](project%20documentation/): the PRD,
FSD-PDS-002 and TSD-PDS-003 are the contract; the code is the implementation of them.

Stack: PHP 8.3 / Laravel 12, React 18 through Inertia, MySQL 8.4, Tailwind, PHPUnit 11, Laravel Dusk.

## What is here today

Phase 2 of the TSD §15 delivery plan is being written now; Phase 1 is closed at its gate. What exists so far:

| Area | Where | Status |
| --- | --- | --- |
| Database schema (users, departments, features, feature grants, matrix version, audit trail, read log, working-day calendar) | `database/migrations/` | applied on `pds` and `pds_test` |
| Permission matrix, transcribed mechanically from FSD §6 | `tools/generate-permission-matrix.php` → `database/seeders/data/permission-matrix.php` | 14 identities × 29 features = 406 cells, asserted cell by cell by test |
| Matrix resolution (union by level, deny-by-default, version invalidation) | `app/Domain/Access/` | unit and feature tested |
| Working-day arithmetic for every SLA | `app/Domain/Notify/WorkingDays.php` | unit and feature tested |
| Environment health check, including the audit grants as the database has them | `php artisan pds:doctor` | reports every prerequisite with observed values |
| Login, no public registration, forced password change, session revoke | `app/Http/Controllers/Auth/`, `app/Domain/Access/SessionInventory.php` | Phase 1.1, feature tested |
| The directory (SCR-2.1: users and departments) | `app/Domain/Access/UserDirectory.php`, `resources/js/Pages/Admin/Users.jsx` | Phase 1.2, feature tested |
| The access matrix, edited and previewed before it is saved (SCR-2.3) | `app/Domain/Access/AccessService.php`, `resources/js/Pages/Admin/Matrix.jsx` | Phase 1.3, feature tested |
| The audit trail, read (SCR-2.5) and append-only in the model and in the grants (F2, TSD-1102) | `app/Support/Audit/`, `app/Models/Concerns/AppendOnly.php`, `database/provision/app-grants.sql` | Phase 1.4, feature tested; the database half is proven by `pds:doctor` on the deployed schema |
| One list contract for every table (§2.4: server-owned page sizes and sort whitelist, browser-owned URL and memory) | `resources/js/Components/DataTable.jsx`, `resources/js/Support/listState.js` | shared by SCR-2.1 and SCR-2.5, tested on both sides |
| The internal shell and the menu it draws from the permission set (FSD-101, FSD-614) | `app/Support/Navigation.php`, `resources/js/Layouts/AppLayout.jsx` | Phase 1.5, feature tested; `NavigationTest` reads each entry's level back off the route list so menu and gate cannot drift |
| Announcements: audience, window, dismissible banner, the dasbor card and SCR-2.4 | `app/Domain/Notify/`, `app/Support/Notify/`, `resources/js/Pages/Admin/Announcements.jsx` | Phase 1.5, feature tested (see the FSD-208 note below on what "edit" means) |
| Gate 1 — every screen route answered by the same cell the menu shows (TSD §15 Phase 1 exit check) | `tests/Feature/Access/GateOneMatrixRouteTest.php` | Phase 1.6, green for all 14 identities. The walk takes its expectations from `MatrixResolver` and its routes from the route list, so a Phase 2 screen is walked the day it is added and a route that loses its `feature:` middleware fails the coverage test rather than passing quietly |
| Packaging master data (F4: categories, items, material types, units, parameter groups, analysis methods, products) | `database/migrations/2026_11_24_160001_create_packaging_master_data_tables.php`, `app/Models/`, `database/seeders/UnitsGroupsMethodsSeeder.php` | Phase 2a, schema tested (`tests/Feature/Master/MasterDataSchemaTest.php`): the three rules FSD-401/402/403 make properties of the table rather than of a form — one category per item, RESTRICT on a referenced category, an item retired rather than removed — are enforced by the database, and the six parameter groups arrive in the order the printed form shows them. Analysis methods are a table with no rows: TSD §3.6 asks the seeder for them "from the observed SBK document", and nothing supplied names one, so the gap is reported rather than filled with invented vocabulary |
| Packaging category and item screens (SCR-4.1, SCR-4.2) | `app/Domain/MasterData/`, `app/Http/Controllers/Master/`, `resources/js/Pages/Master/Categories.jsx`, `resources/js/Pages/Master/Items.jsx` | Phase 2b, feature tested (`PackagingCategoryScreenTest`, `PackagingItemScreenTest`): the item count beside a category and the number that refuses its delete are one subquery (FR-603), a retired category keeps its items readable but leaves the picker, and the item list has no DELETE verb anywhere — `testThisListHasNoWayToDeleteARow` reads that off the route table, because FSD-402's rule is an absence. One cell covers both screens: FSD §6 has no `master.item` key, and `NavigationTest` checks the two new menu entries against the `feature:` middleware the way Gate 1 checks the rest |
| The product master (SCR-4.5, FSD-409, FSD-812) | `app/Domain/MasterData/ProductService.php`, `app/Domain/MasterData/ProductDirectory.php`, `app/Http/Controllers/Master/ProductController.php`, `resources/js/Pages/Master/Products.jsx` | Phase 2c, feature tested (`ProductScreenTest`): the list is the picker FSD-409 says a specification and a design review choose a product from, so READ opens it and only Mkt, R&D Staff and the admin write it (FSD §6's own row for "Master produk"). A blank `brand`, `variant` or `pack_size` is stored as nothing and printed as "belum dicatat", because TSD §3.3.2 makes those three nullable and an empty box reads as a missing value rather than as a fact the client never recorded. Like the item list it has no DELETE verb — FSD-812 speaks only of deactivating — and the rule that would refuse a retirement is held as a tripwire against the table FSD-812 actually names, not against every key that points here (see the FSD-812 note below) |
| The specification (SBK) tables, before the screen that fills them (TSD §3.3.3, §3.3.5's numbering row) | `database/migrations/2026_11_25_160001_create_specification_tables.php`, `app/Models/Specification.php`, `app/Domain/Specification/SpecificationStatus.php` | Phase 2d (schema half), schema tested (`tests/Feature/Spec/SpecificationSchemaTest.php`): `uq(number, version)` is FSD-406's "same number, version suffix" written as data, so a revision is a second row and every earlier one stays readable; the four blocks RESTRICT against their parent rather than cascade, which is what forces the draft editor to replace its rows inside one transaction; the dimension `code` deliberately carries **no** check over §5's illustrative `TL/C/TH/OD/H`, because Phase 2e loads the client's own workbook into that column and a constraint invented from an example would refuse real rows; and `doc_status` is checked over the five FSD §4.1 words and refuses the six approval-engine words — FSD-401 says those are derived, never written. `numbering_sequences` is one row per scope per period (§4.5 locks "the period row", which is only one row if the pair is unique) and nothing in the schema rewinds it when its documents are removed, which is the half of BR-13's "never reused" a table can hold. `approval_document_id` from §3.3.3 is absent on purpose: it points into the workflow engine's table, which arrives in Phase 3. The allocator itself is `app/Domain/Numbering/Numbering.php` (TSD-430..432, `NumberingServiceTest`): it reads the pattern from the `config/numbering.php` seeded in Phase 0.7 rather than from a format a screen could choose, creates the period row on first use instead of seeding every year the system might be used in, and refuses a pattern whose tokens are not a counter (INS-{A}-{L} takes an assignment and a level no caller has yet) rather than printing a code with the braces still in it |

| The specification's authoring half, before the screen that calls it (SCR-4.3's FSD-404/406 and G-12) | `app/Domain/Specification/SpecificationService.php` | Phase 2d (domain half), tested by `tests/Feature/Spec/SpecificationDraftTest.php`: the number is taken when the draft is created and by nobody else — no method of the service accepts one, which is TSD-432's immutability written as an absence rather than as a validation rule, and the cost of that reading (an abandoned draft has consumed a number) is FSD-404 in as many words. A specification is four blocks, not one row: every block the payload names is deleted and re-inserted **inside the same transaction as the header**, in the payload's order, because the children RESTRICT against their parent and half a saved sheet is a document that lies. `sort` comes from that position and `specification_id` from the parent, never from the request, and the columns a block may carry are written out per block instead of read off `$fillable`. Saving a draft is the same version token the rest of the app uses (`RowVersion::save` → MSG-020), and the loser keeps its own rows rather than a mixture of two saves. Revising is FSD-406's second row under the SAME number: the allocator is not consulted, the counter does not move, the superseded row keeps its version, its status and its text, and whatever the payload does not name is carried forward from the version being revised — MSG-042's "Buat versi baru" opens a sheet that reads as the document it replaces, not an empty form. `doc_status` stays DRAFT throughout, and no reviewer or approver column exists to be written: FSD-401's approval words are derived and Phase 3's, and one test reads that absence off `information_schema` so the columns cannot appear by accident before the engine that fills them does |

| The specification sheet on screen (SCR-4.3: `/spec` and `/spec/{id}`) | `app/Domain/Specification/SpecificationDirectory.php`, `app/Http/Requests/Concerns/SpecificationSheet.php`, `app/Http/Controllers/Spec/SpecificationController.php`, `resources/js/Pages/Spec/List.jsx`, `resources/js/Pages/Spec/Document.jsx` | Phase 2d (screen half), feature tested by `tests/Feature/Spec/SpecificationScreenTest.php`: two addresses and six verbs, and the absence of the seventh is the requirement — there is no DELETE verb anywhere under `/spec`, because FSD-404's number is never reused and the act that retires a specification is the approval engine's withdrawal rather than an author's delete, and the test reads that off the route table so it stays true the day somebody reaches for a cleanup button. `GET /spec/create` takes no number: the blank sheet pins `numbering_sequences` at zero rows for the `sbk` scope, which is the difference between "the code is computed at save" and "every abandoned click burns a code out of the year". `number`, `version` and `doc_status` appear in no rule list, so a payload that sends them is saved without them rather than refused — the sequence and the state machine own those columns (TSD-432, FSD §4.1). The sheet's three answers come from the document's status and the caller's cell together: `editable` is FULL × DRAFT, `revise` is FULL × not-DRAFT (MSG-042's own action, so a locked document always offers "Buat versi baru" and a draft never does), and READ opens the list and the sheet for the eleven identities FSD §6 gives the cell to, which is FSD-405's register seen from a picker rather than a summary. MSG-020 and MSG-042 need no controller code: `bootstrap/app.php` already renders `RecordHasBeenChanged` and `ChangeRefused` into field errors, so the domain's refusals arrive beside the box that raised them. The blocks are replaced whole, which is why every refusal below asserts that NOTHING was saved rather than that the bad row was skipped. The reviewer and approver chips print nothing because the props carry `null`: FSD §4.5 derives who is holding a document from ordered step rows, and that table is Phase 3's. A stored `DECIMAL(12,4)` echoes as the paper prints it — `125`, not `125.0000` — because the box is where a person reads the number back, and only the echo is trimmed: the column and the save keep four places |

| The item list's live specification count, and the filter that makes its link true (SCR-4.2, FR-603) | `app/Domain/Specification/SpecificationDirectory.php`, `app/Domain/MasterData/PackagingItemDirectory.php`, `resources/js/Pages/Master/Items.jsx`, `resources/js/Pages/Spec/List.jsx` | Phase 2d (closeout), feature tested by `PackagingItemScreenTest::testTheItemCountIsTheRegisterItselfRatherThanASecondNumberKeptBesideTheItem` and `SpecificationScreenTest::testTheRegisterCanBeNarrowedToOneItemAndRefusesToGuessAtOne`: the seventh of SCR-4.2's eight columns is a count asked of the register through the register's own helper, and the link it is text for opens `/spec?item=`, an exact filter that lives in the same class — so the number and the rows behind its link are answered by one query pair and cannot drift, which is FR-603's defect (0 printed beside 77 components) closed rather than re-described. The unit printed is `versi`, not `dokumen`, because §9 lists v1 and v2 of one number as the two rows they are; a figure named for documents would print 1 beside a link showing 2. An `item` that is not a number is refused to the default list rather than guessed at, and an item nobody has written a sheet about reads `0 versi` — §2.2's zero that means something, still linked so the emptiness can be checked. The register's picker keeps retired items, and a new sheet may still be written about a pack the master has retired: FSD-402's "not offered again" names the assignment pickers, and hiding them here would strand the documents that already cite them, including the MSG-042 revision of a sheet whose item was retired after it was written |

| The import's first leg: reading the client's sheet (SCR-4.4, TSD-901) | `app/Domain/Import/SpecificationImportReader.php`, `app/Domain/Import/RowChunkFilter.php`, `app/Domain/Import/ImportTable.php`, `tests/Fixtures/Import/komponen-bahan-kemas-77.csv`, `tests/Unit/Domain/Import/SpecificationImportReaderTest.php` | Phase 2e (reader half), unit tested by eight cases that write real .xlsx files rather than arrays, because the thing under test is PhpSpreadsheet being asked a question. Three refusals and two counts. A header row is **found**, as the first row in the sheet's top 25 carrying at least two non-empty labels, so a file that opens with a title banner in A1 maps its columns instead of mapping the banner onto `Komponen` — and a sheet that never reaches such a row raises `ImportHeaderNotFound` instead of reporting zero rows, because a preview that reads "77 baris, 0 baru" against an unrecognised header is a tidy answer to the wrong question. `ImportFileUnreadable` is kept apart from it: a half-transferred upload is retried, an empty sheet is filled in, and 2e-2 has to say which. Memory is bounded by a row-band read filter (PhpSpreadsheet ships `DefaultReadFilter` and the interface only — the "chunk read filter" TSD-901 names is an example in its documentation, so it is written here), and `readPasses()` is the evidence the bound is real: 77 rows through a window of 250 is one pass, the same sheet through 7 is eleven and yields a row-for-row identical list, which is the boundary a filter with an off-by-one loses or doubles. The 5,000-row case asserts both the pass count and a peak-memory ceiling, generously set, because what the test discriminates is a reader that loads the whole sheet and not the machine it runs on. `signature()` is TSD-902's key over the header words only, insensitive to spacing and case (one file saved twice by one person) and sensitive to a rename or a move (a remembered mapping applied to columns that shifted writes the material into the component column). The table is not a list of components on purpose: FSD-407's order is mapping, preview, commit, and a reader that returned `component`/`material`/`percent` has guessed the mapping inside the class that exists to be asked about it |

Not started yet: approval documents and
their evidence, the supplier portal, the nine reports, and the mail notifications.

## Setting the project up

Composer is not on the PATH on the development machine; use `C:\laragon\bin\composer\composer.phar`.

```
composer install
npm install
copy .env.example .env        then set DB_PASSWORD and generate a key:
php artisan key:generate
php artisan migrate:fresh --seed
npm run build                 (npm run dev while working on the UI)
php artisan serve
```

`migrate` runs as the application account, so it keeps the DDL it needs (`CREATE/ALTER/DROP/INDEX/
REFERENCES`). What it must not keep is DML: [`database/provision/app-grants.sql`](database/provision/app-grants.sql)
is run by a DBA after every migration that creates a table, because the account is granted write access
table by table and a table that arrived since the last run has none (TSD-1102). `php artisan pds:doctor`
reads the grants back and names the tables still waiting, so the step cannot be forgotten quietly.

`php artisan pds:doctor` should finish with no FAIL before anything is believed about the environment.

## Tests

The suite runs on MySQL, never SQLite — CHECK constraints, collations and generated columns behave
differently and the schema depends on them. `phpunit.xml` pins the connection to the `pds_test`
schema so a `migrate:fresh` in a test cannot reach real data.

```
php artisan test
```

Reference data (roles, features, grants, calendar, departments) is seeded per test through
`tests/Concerns/SeedsReferenceData.php`; it is not assumed to be present.

## Known, deliberately unresolved gaps

- **The menu speaks the owner's words, the screens still speak the FSD's.** In November 2026 the
  product owner asked for these labels: `User Management` (with `User`, `Permission`, `Announcement`,
  `Audit Log` under it), `Master Data` (with `Kategori Bahan Kemas`, `Item Bahan Kemas`, `Master
  Product`), `Dashboard` and `User Account`. They live in `Navigation::CATALOGUE` and in the shell, so
  one edit changes the menu for every identity. What did not change is the rest of the vocabulary:
  FSD-PDS-002 §5 and §6 name their screens and features in Indonesian, and the page titles, column
  headings, form labels, MSG- catalogue sentences and audit phrases below those menu entries are still
  the document's own words — `Master produk` under a menu item called `Master Product`. A single
  language for both is a decision for the document owner, not an implementation detail: either the FSD
  is amended, or the English above is a display alias and stays only in the menu. This is recorded
  rather than half-resolved.

- **Religious public holidays.** Only dates fixed by statute are seeded into
  `database/seeders/data/holidays.php`. The Islamic, Vesak and Chinese-new-year dates come from the
  annual SKB 3 Menteri decree and have not been invented. Until they are entered, an SLA spanning one
  of them is one working day optimistic; `pds:doctor` and the seeder both say so.
- **Deployment target.** The application has not been deployed to `npd-ppm.my.id`; the hosting path,
  PHP version, database access, mail transport and scheduler on that host are still to be confirmed.
- **Package versions.** Installed Spatie permission 8.5 and PhpSpreadsheet 5.12 are newer than the
  `^6` / `^3` constraints written in the TSD. The behaviour the TSD relies on (guard-scoped roles,
  xlsx read/write) is unchanged; the deviation is recorded rather than silently accepted.
- **FSD-208 names no edit.** The requirement gives an announcement a title, body, audience, publish-at
  and expire-at, a dismissible banner and a per-user dismissal — and no verb for changing one that is
  already saved. SCR-2.4 therefore offers two recoveries instead of an editor: stop an announcement
  that has gone up (`expire_at = now`, never a delete, per G-13) and take back one that has not. If the
  FSD owner means a real edit, that is a new requirement, not a gap in this one.
- **No feature cell for announcements.** FSD §6's 29 keys have none, so SCR-2.4 is gated by
  `admin.access` (READ opens the list, FULL publishes) and the banner needs no cell at all: everybody
  is its audience, and a dismissal route that consulted a cell would refuse the person the notice was
  written for.
- **SCR-4.2 prints seven of its eight columns.** FSD §5's row for the item list also names Standar
  (active version), which counts the standard sheet — a table that arrives in Phase 5. The
  requirement's own reason for putting counts on the row is FR-603's complaint that the prototype
  printed 0 while the specification held 77 components, and a 0 typed in because nothing exists yet is
  that defect written deliberately: an omitted column says "not yet", a printed 0 says "none". The
  column arrives with the sheet it counts. Its sibling Spesifikasi did come in at Phase 2d, and it is
  linked to the register rows it counts rather than stored beside the item — see the progress row above.
- **SCR-4.5 has no column table in FSD §5.** The FSD's area F4 line names the screen and its URL
  (`/master/products`) and nothing else: no printed columns, no (S) markers, no default sort, unlike
  SCR-4.1 and SCR-4.2, which have one. §2.4's list contract supplies what the document left open — the
  searchable and sortable pair is the two identity columns `code` and `name`, the default sort is by
  name, and the row carries the three nullable attributes TSD §3.3.2 gives a product — and those are
  choices made here, recorded so that a reviewer can disagree with them rather than discover them.
- **A rolled-back transaction frees the number it had taken.** TSD §3.5 states that
  "a rolled-back document still consumes its number", while TSD-431 puts the `SELECT … FOR UPDATE`
  lock on the sequence row *inside the caller's transaction*. On one connection those two sentences
  cannot both hold: an increment written inside a transaction that never commits rolls back with it.
  What BR-13 and FSD-404 actually refuse — one number on two documents — is prevented, because the
  rolled-back transaction wrote no document, and a specification that was saved and then withdrawn
  keeps its number for good (its row stays, and the counter has already moved past it:
  `NumberingServiceTest::testTheCounterNeverRewindsToAValueItAlreadyHandedOut`). Holding §3.5's
  sentence literally would need a second database connection whose committed writes escape the
  transaction that keeps `pds_test` clean, trading a gap nobody sees for a test-isolation defect on
  every numbering test. Recorded as a deviation, like the package versions above.
- **Two people can start a revision of the same locked SBK at the same moment.**
  `SpecificationService::revise()` reads the highest version for the number and inserts the next one,
  and that read takes no lock. §2.6's Concurrency row hands a version token to "each editable
  record", and a document that is not DRAFT is not editable, so the button that opens a revision has
  no token to carry: two callers both computing version 2 means the second insert is refused by
  `uq(number, version)` as a database error rather than as MSG-020. Nothing silently lies — the
  counter never rewinds and no document is lost — but the second person gets an error page instead
  of the answer §2.6 prescribes. SCR-4.3's "Buat versi baru" is reachable now (Phase 2d-4 shipped the
  screen), so this is a live path rather than a theoretical one; it is narrow because FSD §6 gives the
  cell F to three identities, and the two of them who both click revise on the same closed sheet are
  the case. Giving the revision path the parent's token waits for Phase 3, when FSD-406's "restarts
  its own approval" decides who may revise at all. What is pinned meanwhile is the half that must not
  move: a revision leaves the superseded row's version, status and text alone
  (`SpecificationScreenTest::testRevisingFromTheScreenStartsTheNextVersionUnderTheSameNumber`).
- **A specification block's rows are validated on the server, not on blur.** §2.5 promises the
  sentence beside the field rather than after a round trip, and the sheet's header keeps that promise
  through `FormShell` — format on blur, completeness on submit, focus on the first invalid box. A
  repeating block cannot: the field list is a row count the person controls, so the per-row rules
  (`sizes.*.code`, `parameters.*.group_id`) live only in `SpecificationSheet` and their MSG-030/031
  answers arrive beside the offending cell after the save is refused. Nothing invalid is written — the
  blocks are replaced whole inside one transaction, so a bad row costs the whole sheet — but the
  timing differs from the header's, and pretending otherwise would be a claim about §2.5 this
  implementation does not meet.
- **SCR-4.3's column set is borrowed, and one column of it is left out.** FSD §5's SCR-4.3 line names
  the URL and the document layout; the only table of specifications the signed documents describe is
  §9's "Specification register" row, so the list prints its columns — number, item, version, status,
  effective date — and its filters (category, status, date). "Approvers" is the one column not
  rendered: no schema column can name an approver until Phase 3, and the author is a different person
  who is not the answer to that heading (the test reads the absence off the row's keys). §9's "current
  flagged" leg waits with the flag, because marking a version current is a statement about the
  approval chain. The searchable pair (number, item name), the sortable five and the default sort by
  printed number are choices made in `SpecificationDirectory`'s constants, recorded here so a
  reviewer can disagree with them rather than discover them.
- **FSD-812's retirement block is still not computable, and now for the document's own reason.** The
  rule refuses to deactivate a product "while referenced by an open assignment", and G-13 uses the same
  trigger word for master data in general. Phase 2d gave `specifications` a `product_id`, so a product
  can now be named by a row that exists — but a specification is not an assignment, and writing the
  refusal against it would invent a rule the signed documents do not state. The tripwire therefore
  watches `assignments` itself, and fails the day that table arrives. The same test now asserts the half
  that needs no assignment: a retired product a specification points at keeps reading its name, because
  retirement leaves the row where it is (FSD-402's rule for an item, and the reason FSD-812 says
  deactivate rather than delete).
- **Phase 2e has a reader and no pipeline yet, so nothing in the application calls it.** TSD-901 is one
  of the import's five lines; SCR-4.4 has no route, no screen and no feature cell until 2e-2, and AC-C9's
  "77 imported components are queryable and print" therefore still reads as unmet. The reader is committed
  on its own because the three ways an import invents or drops a component — an unreadable upload, a
  header the reader assumed was row 1, a row lost on a chunk boundary — are settled best before a mapping
  screen exists to be blamed for them.
- **TSD §5's NFR-10 line asks for a test that imports the real baseline file; the file is not in the
  project.** The corpus in git is invented and de-identified (`tests/Fixtures/Import`, 77 rows of generic
  component and material words) because the client's data and names do not enter the repository. The
  real-file assertion — component count in the database equal to the count in the sheet — is the last
  package of Phase 2e, and it needs the workbook, whose location has not been recorded: the material
  supplied so far held BusinessPartner exports and screenshots, not the specification sheet.
- **FSD-401 is used twice in FSD-PDS-002 v0.2.** The id names the approval-engine rule in one chapter
  and the packaging category/item rule in another (FSD-406 and FSD-407 are likewise cited from chapters
  that do not define them). The code follows the master-data reading, since that is the text §5's F4
  and the flowchart both describe. This is a traceability defect in the signed document and belongs to
  its owner: it is reported here rather than resolved by renumbering a requirement in the
  implementation, which would make the code un-traceable to the PDF.
