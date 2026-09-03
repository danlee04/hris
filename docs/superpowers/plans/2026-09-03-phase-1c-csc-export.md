# HRIS Phase 1c — CSC Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn a stored PDS into the official CS Form 212 `.xlsx`, filled and ready to print, without anyone retyping it.

**Architecture:** The official template is opened with PhpSpreadsheet, values are written into it, and the result is streamed to the browser. Every cell reference lives in one config file. Overflow goes onto the continuation sheets the CSC already provides.

**Tech Stack:** Laravel 13.17, PHP 8.3, `phpoffice/phpspreadsheet` ^5.9 (installed), Livewire 4, Flux UI, PHPUnit 12.5.

**Spec:** `docs/superpowers/specs/2026-09-03-phase-1-core-and-pds-design.md`

**Builds on:** Phase 1a (foundation) and Phase 1b (the nine PDS sections), both complete.

## Global Constraints

- **English only** in code, comments, commit messages, and every user-facing string.
- **Never run `git commit` or `git push`.** Hand the author a commit message.
- **Every export passes through `EmployeePolicy::exportPds()`.** It already exists and is unused; this phase is its caller.
- **Exporting somebody else's PDS is recorded** through `AuditRecorder`, the same as reading it.
- Models declare fillable with `#[Fillable([...])]`.
- Tests are PHPUnit classes, `test_snake_case`, `RefreshDatabase`.
- `vendor/bin/pint --dirty` before each commit; `npm run build` whenever a Blade file changed.

## What the template actually is

`storage/app/templates/CS Form No. 212 Revised 2026 Personal Data Sheet PDS.xlsx`

**Revised 2026, not 2017.** The schema was aligned to it in the last commit of Phase 1b. Eleven sheets:

| Sheet | Holds |
| --- | --- |
| `C1` | Page 1 — personal information (1-21), family background (22-25), education (26) |
| `C2` | Page 2 — civil service eligibility (27), work experience (28) |
| `C3` | Page 3 — L&D (29), voluntary work (30), other information (31-33) |
| `C4` | Page 4 — declarations (34-40), references (41), government ID (42), photo, thumbmark |
| `C5_L&D cont.` | Overflow for item 29 |
| `C6_Work Exp cont.` | Overflow for item 28 |
| `C7_Family Background cont.` | Overflow for items 22-25 |
| `C8_Educ. Background cont.` | Overflow for item 26 |
| `C9_Elig cont.` | Overflow for item 27 |
| `C10_Vol. Work cont.` | Overflow for item 30 |
| `C11_Other Info cont.` | Overflow for items 31-33 |

**The CSC supplies the continuation sheets.** The design doc says the exporter should create them; it should not. The form itself prints "(Continue on sheet C7 if necessary)" at the foot of each section. Fill the sheets that are already there.

### How the layout reads

Input cells are the **empty merged ranges**, and on this form the caption usually sits *below* the box rather than beside it. On C1:

```
I17:K17   (empty — the value goes here)
I18:K18   "House/Block/Lot No."      ← the caption for the cell above
I19:K20   (empty)
I21:K21   "Subdivision/Village"
```

So `I17` takes the house number and `I19` takes the subdivision. Reading a caption and writing into the cell beside it will silently put every address field one row out of place, and the result still looks like a filled form.

**Write to the top-left cell of a merged range.** Writing to any other cell in the range is discarded without an error.

---

## File Structure

- `config/pds_template.php` — every cell reference, and nothing else. The one file to open when the CSC revises the form.
- `app/Services/Pds/PdsExporter.php` — orchestrates: load template, write sections, stream.
- `app/Services/Pds/SectionWriter.php` — writes one repeating section into its rows, and spills the remainder onto its continuation sheet.
- `app/Services/Pds/TemplateMap.php` — reads the config and fails loudly on a reference that does not exist.
- `resources/views/pages/pds/⚡export.blade.php` — the download page.
- `tests/Feature/Pds/PdsExportTest.php`
- `tests/Feature/Pds/SectionWriterTest.php`

---

## Task 1: The cell map for page 1

**Files:**
- Create: `config/pds_template.php`
- Create: `app/Services/Pds/TemplateMap.php`
- Test: `tests/Feature/Pds/TemplateMapTest.php`

**Interfaces:**
- Produces: `TemplateMap::cell(string $path): string` — dot path to an A1 reference, throwing when the path is missing; `TemplateMap::sheet(string $key): string`; `TemplateMap::section(string $key): array`.

- [ ] **Step 1: Read the template rather than guessing**

Run this and keep the output beside you while writing the config. It lists every merged range on a sheet with its content, which is how an input cell is told from a caption:

```bash
php artisan tinker
```

```php
$book = PhpOffice\PhpSpreadsheet\IOFactory::load(storage_path('app/templates/CS Form No. 212 Revised 2026 Personal Data Sheet PDS.xlsx'));
$sheet = $book->getSheetByName('C1');
foreach ($sheet->getMergeCells() as $range) {
    $first = explode(':', $range)[0];
    $value = trim((string) $sheet->getCell($first)->getValue());
    echo str_pad($range, 12), $value === '' ? '(input)' : '"'.$value.'"', PHP_EOL;
}
```

Verified anchors on C1, from that output:

| Field | Cell |
| --- | --- |
| Surname | `D10` |
| First name | `D11` |
| Middle name | `D12` |
| Name extension | `N11` (caption at `L11:M11`) |
| Date of birth | `D13` |
| Place of birth | `D15` |
| Sex at birth | `E16` |
| Civil status | `E17` |
| Height (m) | `D22` |
| Weight (kg) | `D24` |
| Blood type | `D25` |
| Citizenship | `K13` |
| Residential house/block/lot | `I17` |
| Residential street | `L17` |
| Residential subdivision | `I19` |
| Residential barangay | `L19` |
| Residential city | `I22` |
| Residential province | `L22` |
| Residential ZIP | `I24` |

Confirm each against the probe output before writing it down; the table above is a starting set, not the whole page.

- [ ] **Step 2: Write the config**

`config/pds_template.php` holds nothing but references:

```php
<?php

return [
    'path' => storage_path('app/templates/CS Form No. 212 Revised 2026 Personal Data Sheet PDS.xlsx'),

    'sheets' => [
        'page_1' => 'C1',
        'page_2' => 'C2',
        'page_3' => 'C3',
        'page_4' => 'C4',
        'learning_development_cont' => 'C5_L&D cont.',
        'work_experience_cont' => 'C6_Work Exp cont.',
        'family_background_cont' => 'C7_Family Background cont.',
        'education_cont' => 'C8_Educ. Background cont.',
        'eligibility_cont' => 'C9_Elig cont.',
        'voluntary_work_cont' => 'C10_Vol. Work cont.',
        'other_information_cont' => 'C11_Other Info cont.',
    ],

    'personal_information' => [
        'sheet' => 'page_1',
        'cells' => [
            'surname' => 'D10',
            'first_name' => 'D11',
            'middle_name' => 'D12',
            'name_extension' => 'N11',
            'date_of_birth' => 'D13',
            'place_of_birth' => 'D15',
            'sex' => 'E16',
            'civil_status' => 'E17',
            'height_m' => 'D22',
            'weight_kg' => 'D24',
            'blood_type' => 'D25',
            'citizenship' => 'K13',
            'res_house_no' => 'I17',
            'res_street' => 'L17',
            'res_subdivision' => 'I19',
            'res_barangay' => 'L19',
            'res_city' => 'I22',
            'res_province' => 'L22',
            'res_zip_code' => 'I24',
            // ... the rest of items 10-15 and 18-21, read from the probe
        ],
    ],
];
```

- [ ] **Step 3: Write the failing test**

```php
public function test_a_missing_path_is_a_loud_failure(): void
{
    // A typo in a dot path must not silently write nothing. On a page of 150
    // fields, one quietly empty cell is invisible.
    $this->expectException(InvalidArgumentException::class);

    app(TemplateMap::class)->cell('personal_information.cells.no_such_field');
}

public function test_every_configured_cell_exists_in_the_template(): void
{
    // Guards against a reference to a sheet or cell the CSC moved.
    $map = app(TemplateMap::class);
    $book = IOFactory::load(config('pds_template.path'));

    foreach (config('pds_template.sheets') as $key => $name) {
        $this->assertNotNull($book->getSheetByName($name), "sheet [{$name}] is missing");
    }
}
```

- [ ] **Step 4: Write `TemplateMap`, run the tests, commit**

```bash
vendor/bin/pint --dirty
git add config/pds_template.php app/Services/Pds/TemplateMap.php tests/Feature/Pds/TemplateMapTest.php
git commit -m "Add the CSC template cell map for page 1"
```

---

## Task 2: Export page 1 and prove a real cell

**Files:**
- Create: `app/Services/Pds/PdsExporter.php`
- Test: `tests/Feature/Pds/PdsExportTest.php`

**Interfaces:**
- Consumes: `TemplateMap`, `Employee` and its PDS relations.
- Produces: `PdsExporter::export(Employee $employee): string` — writes the filled workbook to a temporary path and returns it; `PdsExporter::filename(Employee $employee): string`.

- [ ] **Step 1: Write the failing test**

This is the test the whole phase exists for. Nothing else can catch a wrong cell reference on a page of 150 fields.

```php
public function test_the_name_and_birth_details_land_in_the_right_cells(): void
{
    $employee = Employee::factory()->create([
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'suffix' => 'Jr.',
    ]);

    PersonalInformation::factory()->create([
        'employee_id' => $employee->id,
        'date_of_birth' => '1990-04-12',
        'place_of_birth' => 'Surigao City',
        'height_m' => 1.58,
    ]);

    $sheet = $this->exportedSheet($employee, 'C1');

    $this->assertSame('Dela Cruz', $sheet->getCell('D10')->getValue());
    $this->assertSame('Juan', $sheet->getCell('D11')->getValue());
    $this->assertSame('Santos', $sheet->getCell('D12')->getValue());
    $this->assertSame('Jr.', $sheet->getCell('N11')->getValue());
    $this->assertSame('12/04/1990', $sheet->getCell('D13')->getValue());
    $this->assertSame('Surigao City', $sheet->getCell('D15')->getValue());
}
```

`exportedSheet()` runs the exporter and reloads the result:

```php
private function exportedSheet(Employee $employee, string $name): Worksheet
{
    $path = app(PdsExporter::class)->export($employee);

    return IOFactory::load($path)->getSheetByName($name);
}
```

**Dates print `dd/mm/yyyy`.** The form says so on every date field, and it is the one format a Philippine government reader will not misread. A date written as a spreadsheet date serial would render in whatever the reader's locale decides.

- [ ] **Step 2: Write the exporter, run the test, commit**

```bash
git commit -m "Export page 1 of the PDS into the CSC template"
```

---

## Task 3: Repeating sections and the continuation sheets

**Files:**
- Create: `app/Services/Pds/SectionWriter.php`
- Modify: `config/pds_template.php`, `app/Services/Pds/PdsExporter.php`
- Test: `tests/Feature/Pds/SectionWriterTest.php`

**Interfaces:**
- Produces: `SectionWriter::write(Worksheet $main, ?Worksheet $continuation, array $rows, array $section): int` — returns how many rows spilled onto the continuation sheet.

Each repeating section in the config carries where its rows start, how many fit, how far apart they are, and which column each field goes in:

```php
'work_experience' => [
    'sheet' => 'page_2',
    'continuation' => 'work_experience_cont',
    'first_row' => 18,
    'row_count' => 24,        // read off the template, not guessed
    'row_height' => 1,
    'columns' => [
        'date_from' => 'A',
        'date_to' => 'B',
        'position_title' => 'D',
        'department_agency' => 'G',
        'monthly_salary' => 'J',
        'salary_grade_step' => 'K',
        'status_of_appointment' => 'L',
        'is_government_service' => 'M',
    ],
],
```

- [ ] **Step 1: Write the failing tests**

```php
public function test_rows_beyond_the_printed_page_go_to_the_continuation_sheet(): void
{
    // Work experience is the section that overflows most often.
    $employee = Employee::factory()->create();
    WorkExperience::factory()->count(30)->create(['employee_id' => $employee->id]);

    $book = $this->exportedBook($employee);

    $this->assertNotEmpty($book->getSheetByName('C6_Work Exp cont.')->getCell('D18')->getValue());
}

public function test_a_section_that_fits_leaves_its_continuation_sheet_empty(): void
{
    $employee = Employee::factory()->create();
    WorkExperience::factory()->count(2)->create(['employee_id' => $employee->id]);

    $book = $this->exportedBook($employee);

    $this->assertSame('', (string) $book->getSheetByName('C6_Work Exp cont.')->getCell('D18')->getValue());
}

public function test_government_service_prints_y_or_n(): void
{
    // The column header says "GOV'T SERVICE (Y/ N)". A boolean cast renders
    // as 1 or nothing, which is not an answer to that question.
}
```

- [ ] **Step 2: Write `SectionWriter`, wire the eight repeating sections, run the tests, commit**

```bash
git commit -m "Write the repeating PDS sections and spill onto the CSC continuation sheets"
```

---

## Task 4: The declarations page

**Files:**
- Modify: `config/pds_template.php`, `app/Services/Pds/PdsExporter.php`
- Test: `tests/Feature/Pds/PdsExportTest.php`

Items 34-40 are yes/no boxes rather than text. Read the template to see how the answer is expressed — a tick character in one of two cells, most likely — and record both cells per question:

```php
'declarations' => [
    'sheet' => 'page_4',
    'questions' => [
        'q34_related_third_degree' => ['yes' => 'F6', 'no' => 'G6'],
        // ...
    ],
    'tick' => '/',
],
```

- [ ] Tests: a yes ticks the yes cell and leaves no empty; an unanswered question ticks neither — **unanswered and "no" are different things, and the export must not turn one into the other**; details land beside their question.

- [ ] Commit: `git commit -m "Export the PDS declarations page"`

---

## Task 5: The download page

**Files:**
- Create: `resources/views/pages/pds/⚡export.blade.php`
- Modify: `routes/pds.php`, `resources/views/components/pds/section-nav.blade.php`
- Modify: `resources/views/pages/employees/⚡index.blade.php` — a download link per employee
- Test: `tests/Feature/Pds/PdsExportTest.php`

- [ ] The page shows the completeness checklist first. **Exporting a half-filled PDS is a real use** — HR asks for it mid-way — so this warns rather than blocks.

- [ ] `$this->authorize('exportPds', $employee)` on mount and on download. The policy method exists from Phase 1b and has had no caller until now.

- [ ] Record the export through `AuditRecorder` when the subject is not the person downloading, the same rule reads follow.

- [ ] Filename: `PDS_DELACRUZ_JUAN_2026-09-03.xlsx`.

- [ ] Tests: an employee downloads their own; an employee cannot download another's; HR can; the download is recorded when it is somebody else's and not when it is their own.

- [ ] Commit: `git commit -m "Add the PDS download page"`

---

## Phase 1c is done when

1. An employee downloads their own PDS as a CS Form 212 `.xlsx` with values in the correct cells.
2. A section with more entries than the printed page holds continues onto the CSC's own continuation sheet.
3. Dates read `dd/mm/yyyy` and government service reads Y or N.
4. An unanswered declaration is left blank rather than ticked "no".
5. HR downloads anyone's, an employee only their own, and every download of somebody else's is recorded.
6. `php artisan test` passes in full and `npm run build` succeeds.

## Not in Phase 1c

- **The photograph, signature and right thumbmark.** C4 has boxes for all three, and the 2026 form labels the signature "(e-signature/digital certificate)". What the CSC will accept there is a question for the HR office before any of it is built. The form is printed and signed by hand until then.
- **A PDF.** The `.xlsx` prints; a separate renderer would be a second layout to keep in step with the first.

## Open items

- **"Single" is not in the template's civil status list** — it holds only Married, Widow/er, Separated, Solo Parent and Others. The enum keeps `Single` and the cell takes free text, so it exports. Confirm with the HR office what they expect a single employee's form to say.
- **Signature and date appear on every page** of the 2026 revision, not only the last. Decide what, if anything, goes there.
