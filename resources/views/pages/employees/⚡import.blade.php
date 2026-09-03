<?php

use App\Models\Employee;
use App\Services\EmployeeImport\CsvRow;
use App\Services\EmployeeImport\EmployeeCsvParser;
use App\Services\EmployeeImport\EmployeeImporter;
use App\Services\EmployeeImport\ImportPreview;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import employees')] class extends Component {
    use WithFileUploads;

    public $file = null;

    /**
     * Flattened for display only. A Livewire public property must survive a
     * round trip through JSON, so ImportPreview and CsvRow cannot live here —
     * Livewire supports scalars, arrays, Collections and Eloquent models, and
     * throws on a plain PHP object. The value objects stay inside the methods.
     *
     * @var list<array{line:int, employee_number:string, name:string, errors:list<string>}>
     */
    public array $previewRows = [];

    public int $validCount = 0;

    public int $errorCount = 0;

    public ?int $imported = null;

    public function mount(): void
    {
        $this->authorize('import', Employee::class);
    }

    /** Parsing happens the moment a file arrives. Nothing is written. */
    public function updatedFile(): void
    {
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $this->imported = null;
        $this->show(app(EmployeeCsvParser::class)->parse($this->file->getRealPath()));
    }

    public function commit(): void
    {
        $this->authorize('import', Employee::class);

        if ($this->file === null) {
            $this->addError('file', __('Upload a file first.'));

            return;
        }

        // Livewire keeps an upload in a temporary directory that is swept
        // periodically. On a slow afternoon the preview can outlive the file
        // behind it, and re-parsing a path that is gone throws.
        $path = $this->file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            $this->reset('file', 'previewRows', 'validCount', 'errorCount');
            $this->addError('file', __('The upload expired. Please choose the file again.'));

            return;
        }

        // Re-parse rather than trusting the preview. Between the upload and
        // this click, someone else may have imported one of these employee
        // numbers — and the preview cannot know that.
        $preview = app(EmployeeCsvParser::class)->parse($path);

        if ($preview->hasErrors()) {
            $this->show($preview);
            $this->addError('file', __('Fix every row listed below before importing.'));

            return;
        }

        $this->imported = app(EmployeeImporter::class)->import($preview);

        $this->reset('file', 'previewRows', 'validCount', 'errorCount');
    }

    private function show(ImportPreview $preview): void
    {
        $this->previewRows = array_map(fn (CsvRow $row) => [
            'line' => $row->lineNumber,
            'employee_number' => $row->data['employee_number'] ?? '',
            'name' => trim(($row->data['last_name'] ?? '').', '.($row->data['first_name'] ?? ''), ', '),
            'errors' => $row->errors,
        ], $preview->rows);

        $this->validCount = count($preview->validRows());
        $this->errorCount = count($preview->invalidRows());
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Import employees') }}</flux:heading>
    <flux:subheading>
        {{ __('Upload a CSV. Nothing is written until you review the preview and confirm.') }}
    </flux:subheading>

    <div class="mt-6 max-w-xl">
        <flux:input type="file" wire:model="file" :label="__('CSV file')" accept=".csv" />
        <flux:error name="file" />

        <flux:text class="mt-2 text-sm">
            {{ __('Columns, in order:') }}
            <code class="text-xs">{{ implode(', ', App\Services\EmployeeImport\EmployeeCsvParser::COLUMNS) }}</code>
        </flux:text>
    </div>

    @if ($imported !== null)
        <flux:callout class="mt-6" variant="success" icon="check-circle">
            {{ __(':count employees imported.', ['count' => $imported]) }}
        </flux:callout>
    @endif

    @if ($previewRows !== [])
        <div class="mt-8">
            <flux:heading size="lg">
                {{ __(':valid ready, :invalid with errors', [
                    'valid' => $validCount,
                    'invalid' => $errorCount,
                ]) }}
            </flux:heading>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Line') }}</flux:table.column>
                    <flux:table.column>{{ __('Employee No.') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Problems') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($previewRows as $row)
                        <flux:table.row wire:key="row-{{ $row['line'] }}">
                            <flux:table.cell>{{ $row['line'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['employee_number'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row['errors'] === [])
                                    <flux:badge color="green" size="sm">{{ __('OK') }}</flux:badge>
                                @else
                                    <ul class="text-sm text-red-600 dark:text-red-400">
                                        @foreach ($row['errors'] as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if ($errorCount > 0)
                {{-- A disabled button that says nothing looks like a broken one. --}}
                <flux:callout class="mt-6" variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>
                        {{ __('Import is blocked') }}
                    </flux:callout.heading>
                    <flux:callout.text>
                        {{ __(':count row(s) above still have problems. Nothing is imported while any row is wrong — importing the good half would leave you with no way to tell which half is missing. Fix them in the file, save it, and upload it again.', ['count' => $errorCount]) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            {{-- Flux renders its own spinner from wire:target; no wire:loading spans needed. --}}
            <flux:button
                class="mt-6"
                variant="primary"
                wire:click="commit"
                :disabled="$errorCount > 0"
            >
                {{ __('Import :count employees', ['count' => $validCount]) }}
            </flux:button>
        </div>
    @endif
</section>
