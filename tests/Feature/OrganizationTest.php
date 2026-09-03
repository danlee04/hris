<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Position;
use App\Models\Section;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_division_holds_its_sections(): void
    {
        $division = Division::factory()->create(['name' => 'Medical Division']);
        Section::factory()->count(3)->create(['division_id' => $division->id]);

        $this->assertCount(3, $division->refresh()->sections);
        $this->assertSame('Medical Division', $division->sections->first()->division->name);
    }

    public function test_a_division_with_sections_cannot_be_deleted(): void
    {
        // restrictOnDelete: losing a division would orphan every employee under it.
        $division = Division::factory()->create();
        Section::factory()->create(['division_id' => $division->id]);

        $this->expectException(QueryException::class);

        $division->delete();
    }

    public function test_a_plantilla_item_number_is_unique(): void
    {
        Position::factory()->create(['item_number' => 'OSEC-DOHB-NUR1-314-2014']);

        $this->expectException(QueryException::class);

        Position::factory()->create(['item_number' => 'OSEC-DOHB-NUR1-314-2014']);
    }

    public function test_the_organization_seeder_can_run_twice(): void
    {
        // The seeder is re-run on every migrate:fresh --seed and on every test
        // that needs reference data. firstOrCreate is its whole contract.
        $this->seed(OrganizationSeeder::class);
        $this->seed(OrganizationSeeder::class);

        $this->assertSame(2, Division::count());
        $this->assertSame(2, Section::count());
        $this->assertSame(2, Position::count());
        $this->assertSame('ADMIN', Section::where('code', 'STAT')->first()->division->code);
    }
}
