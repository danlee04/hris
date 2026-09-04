# Phase 2a-3: CS Form 6 Export — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An approved leave application prints as DTRC's CS Form 6, filled from what the system already knows, ready to be signed by hand.

**Architecture:** A config file holds every cell reference, the way `config/pds_template.php` does. `Form6Map` reads it and refuses a missing key. `Form6Exporter` loads the linked template, writes into it, and saves elsewhere — never over itself. Two kinds of write: ordinary cells, and seven captions whose blank is inside the caption text and which are therefore overwritten whole.

**Tech Stack:** Laravel 13 / PHP 8.3, `phpoffice/phpspreadsheet` ^5.9, Livewire 4, Flux UI, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-09-04-phase-2a-leave-design.md`

**Depends on:** Phase 2a-1 and 2a-2, both complete. `LeaveApplication`, `LeaveApproval`, `LeaveLedger`, `LeaveBalance` and `LeaveApplicationPolicy` exist and are tested.

## Global Constraints

- **Never run `git commit` or `git push`.** Dan commits.
- **English only** in code, comments and user-facing strings.
- Verification: `php artisan test`, `npm run build` when a Blade view changed, `vendor/bin/pint --dirty`. Report the real numbers.
- `authorize()` in `mount()` **and** again in every action reached from the browser.
- **The template is never written over.** Load, fill in memory, save to a temp path — the rule `PdsExporter` already follows.
- **The linked copy is generated, not maintained.** It is rebuilt by `php artisan form6:link`; nothing in this plan edits it, and nothing opens it in Excel.
- **Hold the `Spreadsheet` in a variable.** `IOFactory::load($p)->getSheet(0)` returns a worksheet whose parent is collected immediately, and every `getCell()` then returns `NULL`.
- Tests are PHPUnit classes extending `Tests\TestCase` with `RefreshDatabase`.

## What the template gives us, and what it does not

Read from the file, not from the CSC issuance. Sheet 0 is `CS Form No. 6, Rev 2020 1 of 2`; the print area is `$A$2:$J$69`, so **column J is the last column that prints**.

**Cells with a box of their own** — write the value and nothing else:

| Field | Cell | Note |
| --- | --- | --- |
| Office/Department | `B9` | merged `B9:D9` |
| Name | `E9` | merged `E9:J9` |
| Number of working days | `C49` | ships holding a leftover `1` |
| Inclusive dates | `C52` | ships holding a leftover date serial |
| Vacation / Sick total earned | `D60` / `E60` | |
| Vacation / Sick less this application | `D61` / `E61` | |
| Vacation / Sick balance | `D62` / `E62` | |
| HR officer's name (7.A) | `C63` | merged `C63:E63` |
| Division head's name (7.B) | `I63` | merged `I63:J63` |
| Disapproval reason (7.B) | `I60` | |
| Disapproved due to (7.D) | `H66` | |
| Others, type of leave | `B45` | the line under "Others:" |

**Captions with the blank inside them** — no cell of their own, so the whole
caption is overwritten with caption plus value:

| Field | Cell | Written as |
| --- | --- | --- |
| Date of filing | `A10` | `3.   DATE OF FILING   04/09/2026` |
| Position | `E10` | `4.   POSITION   Nurse II` |
| Salary | `J10` | `5.  SALARY   32,053` |
| As of (7.A) | `C57` | `As of 04/09/2026` |
| Days with pay | `C66` | `2.00 days with pay` |
| Days without pay | `C67` | `0.00 days without pay` |
| Others (Specify) | `C68` | `0.00 others (Specify)` |

`A10`, `E10` and `A69` are **RichText**, not strings. Writing a plain string
replaces the underlined blanks with flat text; that is accepted, and it is why
these are listed apart from the ordinary cells.

**Checkboxes**, ticked by writing `true` to the linked cell — the same
mechanism as the PDS:

| Group | Cells |
| --- | --- |
| Type of leave | `R15` VL, `R17` FL, `R19` SL, `R21` ML, `R23` PL, `R25` SPL, `R27` SOLO, `R29` STUDY, `R31` VAWC, `R33` REHAB, `R35` SLBW, `R37` CALAMITY, `R39` ADOPTION |
| Vacation/SPL detail | `T17` within the Philippines, `T19` abroad |
| Sick detail | `T23` in hospital, `T25` out patient |
| Study detail | `T37` master's, `T39` board review |
| Other | `T43` monetization, `T45` terminal leave |
| Commutation (6.D) | `T49` not requested, `T51` requested |
| Recommendation (7.B) | `T57` for approval, `T59` for disapproval |

**Left blank on purpose:** `G52` (signature of applicant), `A69` (the Chief's
signature line). The form is printed and signed by hand — the same decision the
PDS export made, for the same unanswered question about what the hospital
accepts as a signature.

**Not collected anywhere yet:** item 6.B's free text — where the vacation is
spent, the illness for a sick leave, the purpose of a study leave. Task 2 adds
those fields, because a form whose 6.B is blank is a form HR finishes by hand,
which is most of what this phase is meant to stop.

---

## Task 1: The cell map

**Files:**
- Create: `config/form6_template.php`
- Create: `app/Services/Leave/Form6Map.php`
- Test: `tests/Feature/Leave/Form6MapTest.php`

**Interfaces:**
- Produces: `Form6Map::path(): string`, `::sheet(): string`, `::dateFormat(): string`, `::cell(string $key): string`, `::caption(string $key): array{cell: string, format: string}`, `::tick(string $group, ?string $option): ?string` — a null or unmapped option returns null, because an unanswered question ticks nothing.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/Form6MapTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Services\Leave\Form6Map;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class Form6MapTest extends TestCase
{
    public function test_the_template_is_where_the_map_says_it_is(): void
    {
        $this->assertFileExists(app(Form6Map::class)->path());
    }

    public function test_the_sheet_named_in_the_map_is_in_the_workbook(): void
    {
        // A renamed sheet is the failure that would otherwise surface as a
        // null worksheet three layers down.
        $map = app(Form6Map::class);

        $book = IOFactory::load($map->path());

        $this->assertNotNull($book->getSheetByName($map->sheet()));

        $book->disconnectWorksheets();
    }

    public function test_every_checkbox_the_map_names_is_linked_in_the_template(): void
    {
        // This is the guard that catches an edit to the form. A tick written to
        // a cell no checkbox listens to is a leave type that silently prints
        // unticked.
        $map = app(Form6Map::class);

        $zip = new \ZipArchive;
        $zip->open($map->path());
        $vml = $zip->getFromName('xl/drawings/vmlDrawing1.vml');
        $zip->close();

        foreach (config('form6_template.ticks') as $group => $options) {
            foreach ($options as $option => $cell) {
                $column = preg_replace('/\d/', '', $cell);
                $row = preg_replace('/\D/', '', $cell);

                $this->assertStringContainsString(
                    "<x:FmlaLink>\${$column}\${$row}</x:FmlaLink>",
                    $vml,
                    "{$group}.{$option} points at {$cell}, which no checkbox is linked to"
                );
            }
        }
    }

    public function test_every_leave_type_in_the_seeder_has_a_box(): void
    {
        // Except Wellness, which has no box on the form and prints on the
        // "Others:" line.
        $codes = collect(config('form6_template.ticks.types'))->keys();

        foreach (['VL', 'FL', 'SL', 'ML', 'PL', 'SPL', 'SOLO', 'STUDY', 'VAWC', 'REHAB', 'SLBW', 'CALAMITY', 'ADOPTION'] as $code) {
            $this->assertTrue($codes->contains($code), "{$code} has no box");
        }

        $this->assertFalse($codes->contains('WELLNESS'));
    }

    public function test_a_missing_cell_key_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Form6Map::class)->cell('no_such_field');
    }

    public function test_an_unknown_tick_option_is_null_rather_than_an_error(): void
    {
        // An unanswered question ticks nothing. That is not a failure.
        $this->assertNull(app(Form6Map::class)->tick('commutation', 'unanswered'));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=Form6MapTest`
Expected: FAIL — `Class "App\Services\Leave\Form6Map" not found`.

- [ ] **Step 3: The config**

`config/form6_template.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| CS Form No. 6 (Revised 2020), on DTRC's letterhead
|--------------------------------------------------------------------------
|
| Every cell reference the exporter uses. Read off the file, not off the CSC
| issuance: the hospital's copy carries its own document code, its own
| effectivity, and its own arrangement of the two panels.
|
| Three things about this template are easy to get wrong.
|
| 1. The checkboxes have no cell of their own in the file the hospital
|    maintains. `php artisan form6:link` writes the links into the copy named
|    below, in columns R and T. Both sit outside the print area ($A$2:$J$69),
|    so nothing about them reaches the paper.
|
| 2. Seven fields have no input cell at all — the blank is inside the caption
|    string. They are listed under `captions` and the whole caption is
|    rewritten. Change the wording on the form and the format here has to
|    change with it.
|
| 3. `A10`, `E10` and `A69` are RichText. Writing a plain string flattens the
|    underlined blanks. Accepted, deliberately.
|
*/

return [

    'path' => storage_path('app/templates/CS Form No. 6 (Application for Leave) DTRC linked.xlsx'),

    'sheet' => 'CS Form No. 6, Rev 2020 1 of 2',

    'date_format' => 'd/m/Y',

    'cells' => [
        'office' => 'B9',
        'name' => 'E9',
        'days' => 'C49',
        'inclusive_dates' => 'C52',

        'other_type' => 'B45',

        'vacation_earned' => 'D60',
        'sick_earned' => 'E60',
        'vacation_less' => 'D61',
        'sick_less' => 'E61',
        'vacation_balance' => 'D62',
        'sick_balance' => 'E62',

        'hr_name' => 'C63',
        'division_head_name' => 'I63',

        'disapproval_reason' => 'I60',
        'disapproved_due_to' => 'H66',

        // Item 6.B free text. Only the sick-leave and women's-leave blocks have
        // a blank line of their own; the other two have to be written into
        // their captions, below.
        'sick_detail' => 'I27',
        'women_detail' => 'I33',
    ],

    'captions' => [
        'date_of_filing' => ['cell' => 'A10', 'format' => '3.   DATE OF FILING   :value'],
        'position' => ['cell' => 'E10', 'format' => '4.   POSITION   :value'],
        'salary' => ['cell' => 'J10', 'format' => '5.  SALARY   :value'],
        'as_of' => ['cell' => 'C57', 'format' => 'As of :value'],
        'days_with_pay' => ['cell' => 'C66', 'format' => ':value days with pay'],
        'days_without_pay' => ['cell' => 'C67', 'format' => ':value days without pay'],
        'days_others' => ['cell' => 'C68', 'format' => ':value others (Specify)'],

        // The vacation and study blocks have no blank line of their own, so
        // their free text goes into the caption beside the box it belongs to.
        'vacation_within' => ['cell' => 'I17', 'format' => 'Within the Philippines  :value'],
        'vacation_abroad' => ['cell' => 'I19', 'format' => 'Abroad (Specify)  :value'],
        'study_other' => ['cell' => 'I41', 'format' => 'Other purpose:  :value'],
    ],

    'ticks' => [

        // Keyed on leave_types.code. Wellness is deliberately absent: it has no
        // box on this form and prints on the "Others:" line instead.
        'types' => [
            'VL' => 'R15',
            'FL' => 'R17',
            'SL' => 'R19',
            'ML' => 'R21',
            'PL' => 'R23',
            'SPL' => 'R25',
            'SOLO' => 'R27',
            'STUDY' => 'R29',
            'VAWC' => 'R31',
            'REHAB' => 'R33',
            'SLBW' => 'R35',
            'CALAMITY' => 'R37',
            'ADOPTION' => 'R39',
        ],

        'vacation_where' => [
            'within_philippines' => 'T17',
            'abroad' => 'T19',
        ],

        'sick_where' => [
            'in_hospital' => 'T23',
            'out_patient' => 'T25',
        ],

        'study_purpose' => [
            'masters' => 'T37',
            'board_review' => 'T39',
        ],

        'benefit' => [
            'monetization' => 'T43',
            'terminal' => 'T45',
        ],

        'commutation' => [
            'not_requested' => 'T49',
            'requested' => 'T51',
        ],

        'recommendation' => [
            'approve' => 'T57',
            'disapprove' => 'T59',
        ],
    ],
];
```

- [ ] **Step 4: The map**

`app/Services/Leave/Form6Map.php`:

```php
<?php

namespace App\Services\Leave;

use InvalidArgumentException;

/**
 * The cell references, read from config rather than scattered through the
 * exporter.
 *
 * A missing key throws by name. A wrong cell reference on a page of a hundred
 * cells is invisible; a null one is worse, because it writes to A1.
 */
class Form6Map
{
    public function path(): string
    {
        return config('form6_template.path');
    }

    public function sheet(): string
    {
        return config('form6_template.sheet');
    }

    public function dateFormat(): string
    {
        return config('form6_template.date_format');
    }

    public function cell(string $key): string
    {
        $cell = config("form6_template.cells.{$key}");

        if ($cell === null) {
            throw new InvalidArgumentException("No cell is mapped for [{$key}].");
        }

        return $cell;
    }

    /** @return array{cell: string, format: string} */
    public function caption(string $key): array
    {
        $caption = config("form6_template.captions.{$key}");

        if ($caption === null) {
            throw new InvalidArgumentException("No caption is mapped for [{$key}].");
        }

        return $caption;
    }

    /** An option that is not on the form ticks nothing, which is not an error. */
    public function tick(string $group, ?string $option): ?string
    {
        if ($option === null) {
            return null;
        }

        return config("form6_template.ticks.{$group}.{$option}");
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=Form6MapTest`
Expected: PASS, 6 tests. Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 6: Hand Dan the commit message**

```
Add the CS Form 6 cell map
```

---

## Task 2: The details the form asks for

Item 6.B asks a different question of each type of leave. Nothing collects the
answers yet, so a printed form would leave that whole panel blank.

**Files:**
- Modify: `resources/views/pages/leave/⚡mine.blade.php`
- Modify: `app/Services/Leave/LeaveFiler.php`
- Test: modify `tests/Feature/Leave/MyLeaveScreenTest.php`
- Test: modify `tests/Feature/Leave/LeaveFilerTest.php`

**Interfaces:**
- Produces: `leave_applications.details` populated with the keys
  `vacation_where`, `vacation_detail`, `sick_where`, `sick_detail`,
  `study_purpose`, `study_detail`, `women_detail` — all optional, all strings.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Leave/LeaveFilerTest.php`:

```php
    public function test_the_details_are_kept_as_given(): void
    {
        // Item 6.B asks a different question of each type. A single free-text
        // box could not fill the boxes the form actually prints.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'details' => ['vacation_where' => 'within_philippines', 'vacation_detail' => 'Surigao City'],
        ]));

        $this->assertSame('within_philippines', $application->details['vacation_where']);
        $this->assertSame('Surigao City', $application->details['vacation_detail']);
    }

    public function test_details_that_are_not_on_the_form_are_dropped(): void
    {
        // The array comes from the browser. Anything can be in it, and it is
        // written to a column that ends up on a signed document.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'details' => ['vacation_where' => 'abroad', 'injected' => 'anything'],
        ]));

        $this->assertArrayNotHasKey('injected', $application->details);
        $this->assertSame('abroad', $application->details['vacation_where']);
    }

    public function test_empty_details_are_stored_as_null_not_as_an_empty_array(): void
    {
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'details' => ['vacation_where' => '', 'sick_detail' => '   '],
        ]));

        $this->assertNull($application->details);
    }
```

Add to `tests/Feature/Leave/MyLeaveScreenTest.php`:

```php
    public function test_the_form_collects_the_details_the_printed_form_asks_for(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('startApplying')
            ->set('form.leave_type_id', $this->vacation())
            ->set('form.date_from', now()->addWeek()->toDateString())
            ->set('form.date_to', now()->addWeek()->addDay()->toDateString())
            ->set('form.days', 2)
            ->set('form.details.vacation_where', 'within_philippines')
            ->set('form.details.vacation_detail', 'Surigao City')
            ->call('file')
            ->assertHasNoErrors();

        $application = \App\Models\LeaveApplication::sole();

        $this->assertSame('Surigao City', $application->details['vacation_detail']);
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --filter="LeaveFilerTest|MyLeaveScreenTest"`
Expected: FAIL on the three new filer tests and the screen test.

- [ ] **Step 3: Keep only the details the form has boxes for**

In `app/Services/Leave/LeaveFiler.php`, replace the `'details' => $attributes['details'] ?? null,` line in `$values` with `'details' => $this->details($attributes['details'] ?? null),` and add:

```php
    /** @var list<string> */
    private const DETAIL_KEYS = [
        'vacation_where', 'vacation_detail',
        'sick_where', 'sick_detail',
        'study_purpose', 'study_detail',
        'women_detail',
    ];

    /**
     * Item 6.B, and nothing else.
     *
     * The array arrives from the browser and is written to a column that ends
     * up on a signed document, so it is filtered to the keys the form actually
     * prints rather than stored as given.
     *
     * @param  array<string, mixed>|null  $details
     * @return array<string, string>|null
     */
    private function details(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $kept = [];

        foreach (self::DETAIL_KEYS as $key) {
            $value = trim((string) ($details[$key] ?? ''));

            if ($value !== '') {
                $kept[$key] = $value;
            }
        }

        // An empty array and null both print nothing; null says so in the
        // database too.
        return $kept === [] ? null : $kept;
    }
```

- [ ] **Step 4: Ask for them on the form**

In `resources/views/pages/leave/⚡mine.blade.php`, inside the modal after the
commutation select, add a block that shows only what the chosen type asks for.
`form.leave_type_id` must become `wire:model.live` for this to react.

```blade
            @php
                $chosen = $types->firstWhere('id', (int) ($form['leave_type_id'] ?? 0));
                $code = $chosen?->code;
            @endphp

            {{-- Item 6.B. Only the question this type asks: a sick leave has
                 nothing to say about a destination, and a form offering every
                 question at once is a form nobody reads. --}}
            @if (in_array($code, ['VL', 'SPL'], true))
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select wire:model="form.details.vacation_where" :label="__('Where')" :placeholder="__('Choose')">
                        <flux:select.option value="within_philippines">{{ __('Within the Philippines') }}</flux:select.option>
                        <flux:select.option value="abroad">{{ __('Abroad') }}</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="form.details.vacation_detail" :label="__('Specify')" />
                </div>
            @elseif ($code === 'SL')
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select wire:model="form.details.sick_where" :label="__('Where')" :placeholder="__('Choose')">
                        <flux:select.option value="in_hospital">{{ __('In hospital') }}</flux:select.option>
                        <flux:select.option value="out_patient">{{ __('Out patient') }}</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="form.details.sick_detail" :label="__('Illness')" />
                </div>
            @elseif ($code === 'STUDY')
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select wire:model="form.details.study_purpose" :label="__('Purpose')" :placeholder="__('Choose')">
                        <flux:select.option value="masters">{{ __("Completion of master's degree") }}</flux:select.option>
                        <flux:select.option value="board_review">{{ __('BAR / board examination review') }}</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="form.details.study_detail" :label="__('Other purpose') " />
                </div>
            @elseif ($code === 'SLBW')
                <flux:input wire:model="form.details.women_detail" :label="__('Illness')" />
            @endif
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter="LeaveFilerTest|MyLeaveScreenTest"` — expected PASS.

Then `php artisan test`, `npm run build`, `vendor/bin/pint --dirty`.

- [ ] **Step 6: Hand Dan the commit message**

```
Collect the CS Form 6 item 6.B details when leave is filed
```

---

## Task 3: Filling the form

**Files:**
- Create: `app/Services/Leave/Form6Exporter.php`
- Test: `tests/Feature/Leave/Form6ExporterTest.php`

**Interfaces:**
- Consumes: `Form6Map` (Task 1), `LeaveLedger`, `LeaveApplication` with its `approvals`, `type` and `employee`.
- Produces: `Form6Exporter::export(LeaveApplication $application): string` (a temp path) and `::filename(LeaveApplication $application): string`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/Form6ExporterTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Section;
use App\Models\User;
use App\Services\Leave\Form6Exporter;
use App\Services\Leave\LeaveDecision;
use App\Services\Leave\LeaveFiler;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class Form6ExporterTest extends TestCase
{
    use RefreshDatabase;

    private Employee $applicant;

    private Employee $divisionHead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);

        $division = Division::factory()->create(['name' => 'Medical Division']);
        $section = Section::factory()->create(['division_id' => $division->id, 'name' => 'Nursing Unit']);

        $this->divisionHead = Employee::factory()->create(['last_name' => 'Delos Santos', 'first_name' => 'Maria']);

        $division->update(['division_head_employee_id' => $this->divisionHead->id]);
        $section->update(['section_head_employee_id' => Employee::factory()->create()->id]);
        Employee::factory()->create(['is_chief_of_hospital' => true]);

        $this->applicant = Employee::factory()->create([
            'section_id' => $section->id,
            'last_name' => 'Guico',
            'first_name' => 'Ana',
            'position_id' => Position::factory()->create(['title' => 'Nurse II', 'salary_grade' => 16])->id,
        ]);

        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);
        app(LeaveLedger::class)->open($this->applicant, 'sick', 4);
    }

    /** @param  array<string, mixed>  $overrides */
    private function file(array $overrides = []): LeaveApplication
    {
        return app(LeaveFiler::class)->file($this->applicant, array_merge([
            'leave_type_id' => LeaveType::where('code', 'VL')->sole()->id,
            'date_from' => '2026-10-05',
            'date_to' => '2026-10-06',
            'days' => 2,
            'details' => null,
            'commutation' => 'not_requested',
        ], $overrides));
    }

    private function sheet(LeaveApplication $application): Worksheet
    {
        $book = IOFactory::load(app(Form6Exporter::class)->export($application));

        return $book->getSheetByName('CS Form No. 6, Rev 2020 1 of 2');
    }

    public function test_the_identity_block_is_filled(): void
    {
        $sheet = $this->sheet($this->file());

        $this->assertSame('Nursing Unit', $sheet->getCell('B9')->getValue());
        $this->assertStringContainsString('Guico', (string) $sheet->getCell('E9')->getValue());
        $this->assertStringContainsString('Nurse II', (string) $sheet->getCell('E10')->getValue());
    }

    public function test_the_caption_survives_the_value_written_into_it(): void
    {
        // Seven fields have no cell of their own, so the caption is overwritten
        // whole. Losing the caption would leave a bare date on the form.
        $sheet = $this->sheet($this->file());

        $this->assertStringContainsString('DATE OF FILING', (string) $sheet->getCell('A10')->getValue());
        $this->assertStringContainsString(now()->format('d/m/Y'), (string) $sheet->getCell('A10')->getValue());
    }

    public function test_the_type_of_leave_is_ticked_and_nothing_else_is(): void
    {
        $sheet = $this->sheet($this->file());

        $this->assertTrue($sheet->getCell('R15')->getValue());   // Vacation
        $this->assertFalse($sheet->getCell('R19')->getValue());  // Sick
        $this->assertFalse($sheet->getCell('R21')->getValue());  // Maternity
    }

    public function test_wellness_leave_prints_on_the_others_line(): void
    {
        // It has no box on this form. Leaving it unticked and unnamed would
        // print a leave application that does not say what leave it is for.
        $jobOrder = Employee::factory()->create([
            'employment_status' => \App\Enums\EmploymentStatus::JobOrder->value,
            'section_id' => $this->applicant->section_id,
        ]);

        $application = app(LeaveFiler::class)->file($jobOrder, [
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->sole()->id,
            'date_from' => now()->addWeeks(2)->toDateString(),
            'date_to' => now()->addWeeks(2)->addDay()->toDateString(),
            'days' => 2,
            'details' => null,
            'commutation' => 'not_requested',
        ]);

        $sheet = $this->sheet($application);

        $this->assertStringContainsString('Wellness', (string) $sheet->getCell('B45')->getValue());
    }

    public function test_the_leftover_sample_values_are_written_over(): void
    {
        // The template ships holding a 1 in C49 and a date serial in C52.
        $sheet = $this->sheet($this->file(['days' => 3]));

        $this->assertStringContainsString('3', (string) $sheet->getCell('C49')->getValue());
        $this->assertStringContainsString('05/10/2026', (string) $sheet->getCell('C52')->getValue());
        $this->assertStringNotContainsString('46210', (string) $sheet->getCell('C52')->getValue());
    }

    public function test_the_certification_grid_holds_the_balances_at_filing(): void
    {
        $sheet = $this->sheet($this->file());

        // Ten earned, two on this application, eight left.
        $this->assertSame('10.00', (string) $sheet->getCell('D60')->getValue());
        $this->assertSame('2.00', (string) $sheet->getCell('D61')->getValue());
        $this->assertSame('8.00', (string) $sheet->getCell('D62')->getValue());

        // Sick is untouched by a vacation application.
        $this->assertSame('4.00', (string) $sheet->getCell('E60')->getValue());
        $this->assertSame('0.00', (string) $sheet->getCell('E61')->getValue());
    }

    public function test_the_paid_and_unpaid_split_reaches_item_7c(): void
    {
        // Twelve days against ten credits.
        $sheet = $this->sheet($this->file(['days' => 12, 'date_to' => '2026-10-16']));

        $this->assertStringContainsString('10.00', (string) $sheet->getCell('C66')->getValue());
        $this->assertStringContainsString('days with pay', (string) $sheet->getCell('C66')->getValue());
        $this->assertStringContainsString('2.00', (string) $sheet->getCell('C67')->getValue());
    }

    public function test_the_commutation_is_ticked(): void
    {
        $sheet = $this->sheet($this->file(['commutation' => 'requested']));

        $this->assertTrue($sheet->getCell('T51')->getValue());
        $this->assertFalse($sheet->getCell('T49')->getValue());
    }

    public function test_the_sick_leave_detail_is_ticked_and_named(): void
    {
        $sheet = $this->sheet($this->file([
            'leave_type_id' => LeaveType::where('code', 'SL')->sole()->id,
            'details' => ['sick_where' => 'out_patient', 'sick_detail' => 'Dengue'],
        ]));

        $this->assertTrue($sheet->getCell('T25')->getValue());
        $this->assertFalse($sheet->getCell('T23')->getValue());
        $this->assertStringContainsString('Dengue', (string) $sheet->getCell('I27')->getValue());
    }

    public function test_a_pending_application_names_nobody_and_ticks_no_recommendation(): void
    {
        // Nobody has signed. Printing a name would put words in their mouth.
        $sheet = $this->sheet($this->file());

        $this->assertNull($sheet->getCell('C63')->getValue());
        $this->assertNull($sheet->getCell('I63')->getValue());
        $this->assertFalse($sheet->getCell('T57')->getValue());
    }

    public function test_an_approved_application_names_who_signed(): void
    {
        $application = $this->file();

        $this->actingAs(User::factory()->create());

        while ($approval = $application->fresh()->currentApproval()) {
            $application = app(LeaveDecision::class)->act(
                $application->fresh(), $approval, ApprovalAction::Approve, null
            );
        }

        $sheet = $this->sheet($application);

        $this->assertSame(LeaveStatus::Approved, $application->status);
        $this->assertStringContainsString('Delos Santos', (string) $sheet->getCell('I63')->getValue());
        $this->assertTrue($sheet->getCell('T57')->getValue());
    }

    public function test_a_disapproved_application_ticks_disapproval_and_prints_the_reason(): void
    {
        $application = $this->file();

        $this->actingAs(User::factory()->create());

        $application = app(LeaveDecision::class)->act(
            $application,
            $application->currentApproval(),
            ApprovalAction::Disapprove,
            'Needed on duty'
        );

        $sheet = $this->sheet($application);

        $this->assertTrue($sheet->getCell('T59')->getValue());
        $this->assertStringContainsString('Needed on duty', (string) $sheet->getCell('I60')->getValue());
    }

    public function test_the_signature_lines_are_left_blank(): void
    {
        // The form is printed and signed by hand.
        $sheet = $this->sheet($this->file());

        $this->assertStringNotContainsString('Guico', (string) $sheet->getCell('G52')->getValue());
    }

    public function test_the_template_is_never_written_over(): void
    {
        $before = md5_file(config('form6_template.path'));

        app(Form6Exporter::class)->export($this->file());

        $this->assertSame($before, md5_file(config('form6_template.path')));
    }

    public function test_the_filename_names_the_person_and_the_dates(): void
    {
        $name = app(Form6Exporter::class)->filename($this->file());

        $this->assertStringContainsString('GUICO', $name);
        $this->assertStringEndsWith('.xlsx', $name);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=Form6ExporterTest`
Expected: FAIL — `Class "App\Services\Leave\Form6Exporter" not found`.

- [ ] **Step 3: Write the exporter**

`app/Services/Leave/Form6Exporter.php`:

```php
<?php

namespace App\Services\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\LeaveApplication;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills DTRC's CS Form 6 with one leave application.
 *
 * The template is loaded, written to in memory, and saved somewhere else. It is
 * never saved back over itself — that file cannot be regenerated from anything
 * in this repository, and a single wrong path would take it.
 */
class Form6Exporter
{
    public function __construct(
        private readonly Form6Map $map,
        private readonly LeaveLedger $ledger,
    ) {}

    /** @return string the path of the filled workbook */
    public function export(LeaveApplication $application): string
    {
        $application->loadMissing([
            'employee.section.division', 'employee.position',
            'type', 'approvals.approver', 'approvals.actedBy',
        ]);

        $book = IOFactory::load($this->map->path());
        $sheet = $book->getSheetByName($this->map->sheet());

        $this->writeIdentity($sheet, $application);
        $this->writeApplication($sheet, $application);
        $this->writeDetails($sheet, $application);
        $this->writeCertification($sheet, $application);
        $this->writeAction($sheet, $application);

        $path = tempnam(sys_get_temp_dir(), 'form6').'.xlsx';

        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $book->disconnectWorksheets();

        return $path;
    }

    public function filename(LeaveApplication $application): string
    {
        $name = Str::of($application->employee->last_name.' '.$application->employee->first_name)
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '_')
            ->trim('_');

        return "CSForm6_{$name}_".$application->date_from->format('Y-m-d').'.xlsx';
    }

    private function writeIdentity(Worksheet $sheet, LeaveApplication $application): void
    {
        $employee = $application->employee;

        $this->put($sheet, $this->map->cell('office'),
            $employee->section?->name ?? $employee->section?->division?->name ?? '');

        $this->put($sheet, $this->map->cell('name'), $employee->fullName());

        $this->caption($sheet, 'date_of_filing',
            ($application->filed_at ?? now())->format($this->map->dateFormat()));

        $this->caption($sheet, 'position', $employee->position?->title ?? '');
        $this->caption($sheet, 'salary', (string) ($employee->position?->salary_grade ?? ''));
    }

    private function writeApplication(Worksheet $sheet, LeaveApplication $application): void
    {
        $type = $application->type;

        $cell = $this->map->tick('types', $type->code);

        if ($cell !== null) {
            $this->tick($sheet, $cell);
        } else {
            // Wellness Leave has no box on this form. Leaving it unticked and
            // unnamed would print an application that does not say what leave
            // it is for.
            $this->put($sheet, $this->map->cell('other_type'), $type->name);
        }

        $this->put($sheet, $this->map->cell('days'), number_format($application->days, 2));

        $format = $this->map->dateFormat();

        $this->put($sheet, $this->map->cell('inclusive_dates'), sprintf(
            '%s to %s',
            $application->date_from->format($format),
            $application->date_to->format($format),
        ));

        $commutation = $this->map->tick('commutation', $application->commutation);

        if ($commutation !== null) {
            $this->tick($sheet, $commutation);
        }
    }

    private function writeDetails(Worksheet $sheet, LeaveApplication $application): void
    {
        $details = $application->details ?? [];

        foreach (['vacation_where', 'sick_where', 'study_purpose'] as $group) {
            $cell = $this->map->tick($group, $details[$group] ?? null);

            if ($cell !== null) {
                $this->tick($sheet, $cell);
            }
        }

        // Sick leave and the women's benefit each have a blank line of their
        // own; the vacation and study blocks do not, so their text goes into
        // the caption beside the box it belongs to.
        foreach (['sick_detail', 'women_detail'] as $key) {
            if (($details[$key] ?? '') !== '') {
                $this->put($sheet, $this->map->cell($key), $details[$key]);
            }
        }

        if (($details['vacation_detail'] ?? '') !== '') {
            $this->caption(
                $sheet,
                ($details['vacation_where'] ?? '') === 'abroad' ? 'vacation_abroad' : 'vacation_within',
                $details['vacation_detail'],
            );
        }

        if (($details['study_detail'] ?? '') !== '') {
            $this->caption($sheet, 'study_other', $details['study_detail']);
        }
    }

    /**
     * Item 7.A. The figures are the ones the application was measured against
     * when it was filed, not the balance today — the form has to say what was
     * certified, and the balance moves.
     */
    private function writeCertification(Worksheet $sheet, LeaveApplication $application): void
    {
        $employee = $application->employee;
        $ledger = $application->type->ledger;

        foreach (['vacation', 'sick'] as $which) {
            $balance = $this->ledger->balance($employee, $which);
            $less = $ledger === $which ? $application->days_with_pay : 0.0;

            $this->put($sheet, $this->map->cell("{$which}_earned"), number_format($balance + $less, 2));
            $this->put($sheet, $this->map->cell("{$which}_less"), number_format($less, 2));
            $this->put($sheet, $this->map->cell("{$which}_balance"), number_format($balance, 2));
        }

        $this->caption($sheet, 'as_of',
            ($application->filed_at ?? now())->format($this->map->dateFormat()));

        $this->caption($sheet, 'days_with_pay', number_format($application->days_with_pay, 2));
        $this->caption($sheet, 'days_without_pay', number_format($application->days_without_pay, 2));
        $this->caption($sheet, 'days_others', number_format(0, 2));
    }

    /**
     * Items 7.A to 7.D. Only what has actually happened: an unsigned step names
     * nobody, because printing a name beside an empty signature line puts words
     * in that person's mouth.
     */
    private function writeAction(Worksheet $sheet, LeaveApplication $application): void
    {
        foreach ($application->approvals as $approval) {
            if ($approval->acted_at === null) {
                continue;
            }

            if ($approval->step === LeaveStep::Hr) {
                $this->put($sheet, $this->map->cell('hr_name'), $approval->actedBy?->name ?? '');
            }

            if ($approval->step === LeaveStep::DivisionHead) {
                $this->put($sheet, $this->map->cell('division_head_name'),
                    $approval->approver?->fullName() ?? '');
            }

            if ($approval->action === ApprovalAction::Disapprove) {
                $this->put($sheet, $this->map->cell('disapproval_reason'), (string) $approval->remarks);
                $this->put($sheet, $this->map->cell('disapproved_due_to'), (string) $approval->remarks);
            }
        }

        $recommendation = match ($application->status) {
            LeaveStatus::Approved => 'approve',
            LeaveStatus::Disapproved => 'disapprove',
            default => null,
        };

        $cell = $this->map->tick('recommendation', $recommendation);

        if ($cell !== null) {
            $this->tick($sheet, $cell);
        }
    }

    /** Everything is written as text: the form is read, not calculated. */
    private function put(Worksheet $sheet, string $cell, ?string $value): void
    {
        $sheet->getCell($cell)->setValueExplicit((string) $value, DataType::TYPE_STRING);
    }

    /** A caption whose blank is inside it, rewritten whole. */
    private function caption(Worksheet $sheet, string $key, string $value): void
    {
        $caption = $this->map->caption($key);

        $this->put($sheet, $caption['cell'], str_replace(':value', $value, $caption['format']));
    }

    private function tick(Worksheet $sheet, string $cell): void
    {
        $sheet->getCell($cell)->setValueExplicit(true, DataType::TYPE_BOOL);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=Form6ExporterTest`
Expected: PASS, 15 tests.

Expect to correct the exporter here rather than the test: the certification
figures and the "less this application" column are the parts most likely to be
a day out. Read the numbers, not the pass.

Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 5: Hand Dan the commit message**

```
Fill the DTRC CS Form 6 from a leave application
```

---

## Task 4: Downloading it

**Files:**
- Modify: `resources/views/pages/leave/⚡mine.blade.php`
- Modify: `resources/views/pages/leave/⚡approvals.blade.php`
- Modify: `app/Policies/LeaveApplicationPolicy.php`
- Test: `tests/Feature/Leave/Form6DownloadTest.php`

**Interfaces:**
- Produces: `LeaveApplicationPolicy::export()`, and a `download(int $id)` action on both leave screens returning a `BinaryFileResponse`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/Form6DownloadTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class Form6DownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Employee $applicant;

    private LeaveApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('employee');

        $this->applicant = Employee::factory()->create(['user_id' => $this->user->id]);

        $this->application = LeaveApplication::factory()->create([
            'employee_id' => $this->applicant->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->sole()->id,
            'status' => LeaveStatus::Approved,
        ]);
    }

    public function test_the_applicant_downloads_their_own_form(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('download', $this->application->id)
            ->assertFileDownloaded();
    }

    public function test_a_stranger_cannot_download_it(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('employee');
        Employee::factory()->create(['user_id' => $stranger->id]);

        Livewire::actingAs($stranger)
            ->test('pages::leave.mine')
            ->call('download', $this->application->id)
            ->assertForbidden();
    }

    public function test_hr_downloads_anybodys_form(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('hr');

        Livewire::actingAs($hr)
            ->test('pages::leave.approvals')
            ->call('download', $this->application->id)
            ->assertFileDownloaded();
    }

    public function test_an_approver_on_the_chain_downloads_it(): void
    {
        $head = Employee::factory()->create();
        $headUser = User::factory()->create();
        $headUser->assignRole('employee');
        $head->update(['user_id' => $headUser->id]);

        LeaveApproval::create([
            'leave_application_id' => $this->application->id,
            'sequence' => 1,
            'step' => LeaveStep::SectionHead,
            'approver_employee_id' => $head->id,
        ]);

        Livewire::actingAs($headUser)
            ->test('pages::leave.approvals')
            ->call('download', $this->application->id)
            ->assertFileDownloaded();
    }

    public function test_downloading_somebody_elses_form_is_recorded(): void
    {
        // The whole application leaves the system in one file, and a sick leave
        // says something about a person's health.
        $hr = User::factory()->create();
        $hr->assignRole('hr');

        Livewire::actingAs($hr)
            ->test('pages::leave.approvals')
            ->call('download', $this->application->id);

        $this->assertTrue(
            Activity::where('event', 'read')
                ->where('subject_id', $this->application->id)
                ->where('description', 'like', '%Downloaded%')
                ->exists()
        );
    }

    public function test_downloading_your_own_form_is_not_recorded(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('download', $this->application->id);

        $this->assertSame(
            0,
            Activity::where('description', 'like', '%Downloaded%')->count()
        );
    }

    public function test_a_pending_application_still_downloads(): void
    {
        // HR asks for the form before it is signed often enough — it is what
        // gets walked to the next desk.
        $this->application->update(['status' => LeaveStatus::Pending]);

        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('download', $this->application->id)
            ->assertFileDownloaded();
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=Form6DownloadTest`
Expected: FAIL — `Method download does not exist`.

- [ ] **Step 3: The ability**

Add to `app/Policies/LeaveApplicationPolicy.php`:

```php
    /**
     * Whoever may see it may print it. The form is what gets walked from desk
     * to desk, and refusing to produce it would send the office back to typing.
     */
    public function export(User $user, LeaveApplication $application): bool
    {
        return $this->view($user, $application);
    }
```

- [ ] **Step 4: The action, on both screens**

Add to `resources/views/pages/leave/⚡mine.blade.php` and
`resources/views/pages/leave/⚡approvals.blade.php` (identical in both):

```php
    public function download(int $id): BinaryFileResponse
    {
        $application = LeaveApplication::findOrFail($id);

        $this->authorize('export', $application);

        // The whole application leaves the system in one file. That is worth
        // more of a record than reading a row of it on screen.
        if ($application->employee_id !== auth()->user()?->employee?->id) {
            app(AuditRecorder::class)->recordRead($application, 'Downloaded the CS Form 6');
        }

        $exporter = app(Form6Exporter::class);

        return response()
            ->download($exporter->export($application), $exporter->filename($application))
            ->deleteFileAfterSend();
    }
```

with `use App\Services\Leave\Form6Exporter;`, `use App\Services\AuditRecorder;`
and `use Symfony\Component\HttpFoundation\BinaryFileResponse;` at the top of
each. `⚡mine.blade.php` already imports `LeaveApplication`; `⚡approvals.blade.php`
already imports both `LeaveApplication` and `AuditRecorder`.

Add the link to each table's action cell:

```blade
                            @can('export', $application)
                                <flux:link href="#" wire:click.prevent="download({{ $application->id }})">
                                    {{ __('Form 6') }}
                                </flux:link>
                            @endcan
```

- [ ] **Step 5: Run everything**

Run: `php artisan test --filter=Form6DownloadTest` — expected PASS, 7 tests.

Then `php artisan test`, `npm run build`, `vendor/bin/pint --dirty`. Report the
real numbers.

- [ ] **Step 6: Hand Dan the commit message**

```
Download an application as the DTRC CS Form 6
```

---

## What is still open after this

- **Opening balances for the 134 employees.** Until they exist, item 7.A prints
  zeros and every application is leave without pay. This is the last thing
  between the phase being finished and the phase being usable.
- **The day count for shift workers.** It is a proposal the applicant and HR can
  both change; the real answer needs the DTR, which is Phase 2b.
- **Whether HR wants the Chief's name printed on `A69`.** It is left blank, like
  every other signature line. `A69` is RichText and would flatten if written.
