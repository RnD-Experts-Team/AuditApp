<?php

namespace Tests\Feature\Reports;

use App\Http\Middleware\AuthTokenStoreScopeMiddleware;
use App\Http\Requests\ReportDatasetQueryRequest;
use App\Reports\ReportDatasets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The contract routes end to end, through the real auth middleware with
 * pizzasys faked. Two stores: 03795-00044 (id 1) and 03795-00042 (id 2);
 * stores.store holds the store code. No spatie roles are seeded: the routes
 * rely on auth.token.store alone.
 */
class ReportDatasetQueryTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private const CLEANING = '/api/report-datasets/audit.cleaning/query';

    private const EVALUATIONS = '/api/report-datasets/audit.evaluations/query';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        // The pizzasys name of this service (AUTH_SERVER_SERVICE_NAME=QA).
        config(['services.auth_server.service_name' => 'QA']);
        $this->seedAudit();
    }

    private function seedAudit(): void
    {
        $now = now();
        DB::table('stores')->insert([
            ['id' => 1, 'store' => '03795-00044', 'group' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'store' => '03795-00042', 'group' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('cleaning_tasks')->insert([
            ['id' => 1, 'name' => 'Mop floor', 'frequency' => 'daily', 'starts_at' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Clean hood', 'frequency' => 'weekly', 'starts_at' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now],
        ]);
        // Mop floor in store 1: done on its day, then a day late. Hood: in its week. Store 2: on time.
        DB::table('cleaning_completions')->insert([
            ['cleaning_task_id' => 1, 'store_id' => 1, 'period_start' => '2026-10-21', 'period_end' => '2026-10-21', 'completed_at' => '2026-10-21 15:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['cleaning_task_id' => 1, 'store_id' => 1, 'period_start' => '2026-10-22', 'period_end' => '2026-10-22', 'completed_at' => '2026-10-23 10:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['cleaning_task_id' => 2, 'store_id' => 1, 'period_start' => '2026-10-20', 'period_end' => '2026-10-26', 'completed_at' => '2026-10-24 10:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['cleaning_task_id' => 1, 'store_id' => 2, 'period_start' => '2026-10-22', 'period_end' => '2026-10-22', 'completed_at' => '2026-10-22 12:00:00', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Store 1: two finalized on 10-26 (60, 80), one finalized 10-27 (90), one still open.
        $evaluation = fn (int $store, string $key, ?string $finalizedAt, ?float $score) => [
            'store_id' => $store, 'period_type' => 'week', 'period_key' => $key, 'finalized_at' => $finalizedAt,
            'item_score' => $score, 'chart_score' => $score, 'final_score' => $score, 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('evaluations')->insert([
            $evaluation(1, '2026-W41', '2026-10-26 12:00:00', 60),
            $evaluation(1, '2026-W42', '2026-10-26 15:00:00', 80),
            $evaluation(1, '2026-W43', '2026-10-27 09:00:00', 90),
            $evaluation(1, '2026-W44', null, null),
            $evaluation(2, '2026-W42', '2026-10-26 12:00:00', 50),
        ]);
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'stores' => ['03795-00044'],
            'date_from' => '2026-10-20',
            'date_to' => '2026-10-27',
            'limit' => 50,
        ], $overrides);
    }

    public function test_cleaning_completions_are_counted_per_task_with_the_on_time_part(): void
    {
        $this->postJson(self::CLEANING, $this->body([
            'columns' => ['store', 'task'],
            'group_by' => ['store', 'task'],
            'aggregates' => [
                ['column' => 'completed', 'fn' => 'count', 'alias' => 'completed'],
                ['column' => 'on_time', 'fn' => 'sum', 'alias' => 'on_time'],
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['03795-00044', 'Clean hood', 1, 1],
                ['03795-00044', 'Mop floor', 2, 1],
            ]);
    }

    public function test_cleaning_completions_use_the_completion_date_and_can_be_filtered(): void
    {
        $this->postJson(self::CLEANING, $this->body([
            'columns' => ['date'],
            'group_by' => ['date'],
            'filters' => [['column' => 'frequency', 'op' => 'eq', 'value' => 'daily']],
            'aggregates' => [['column' => 'completed', 'fn' => 'count', 'alias' => 'completed']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['2026-10-21', 1], ['2026-10-23', 1]]);
    }

    public function test_evaluations_are_finalized_only_and_scored_per_day(): void
    {
        $this->postJson(self::EVALUATIONS, $this->body([
            'columns' => ['store', 'date'],
            'group_by' => ['store', 'date'],
            'aggregates' => [
                ['column' => 'score', 'fn' => 'avg', 'alias' => 'avg_score'],
                ['column' => 'score', 'fn' => 'min', 'alias' => 'min_score'],
                ['column' => 'score', 'fn' => 'max', 'alias' => 'max_score'],
                ['column' => 'count', 'fn' => 'count', 'alias' => 'evaluations'],
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['03795-00044', '2026-10-26', 70, 60, 80, 2],
                ['03795-00044', '2026-10-27', 90, 90, 90, 1],
            ]);
    }

    public function test_a_store_filter_in_the_body_is_what_scopes_the_rows(): void
    {
        $this->postJson(self::EVALUATIONS, $this->body([
            'stores' => ['03795-00042'],
            'columns' => ['store'],
            'group_by' => ['store'],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'evaluations']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00042', 1]]);

        // A code this service does not know matches nothing, rather than everything.
        $this->postJson(self::EVALUATIONS, $this->body([
            'stores' => ['99999-00001'],
            'columns' => ['store'],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', []);
    }

    public function test_no_stores_means_every_store(): void
    {
        $this->postJson(self::CLEANING, $this->body([
            'stores' => [],
            'columns' => [],
            'group_by' => ['store'],
            'aggregates' => [['column' => 'completed', 'fn' => 'count', 'alias' => 'completed']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00042', 1], ['03795-00044', 3]]);
    }

    public function test_a_store_pizzasys_refuses_is_a_403(): void
    {
        $this->tokenAuthorized = false;

        $this->postJson(self::EVALUATIONS, $this->body([
            'stores' => ['03795-00042'],
            'columns' => ['store'],
        ]), $this->headers())->assertForbidden();
    }

    public function test_unknown_columns_are_a_422(): void
    {
        foreach ([self::CLEANING, self::EVALUATIONS] as $path) {
            $this->postJson($path, $this->body(['columns' => ['store', 'store_number']]), $this->headers())
                ->assertUnprocessable()
                ->assertJsonPath('errors.columns', ['Unknown column store_number.']);
        }

        $this->postJson(self::EVALUATIONS, $this->body(['aggregates' => [['column' => 'score', 'fn' => 'count_distinct']]]), $this->headers())
            ->assertUnprocessable();

    }

    public function test_the_period_is_not_capped(): void
    {
        foreach ([self::CLEANING, self::EVALUATIONS] as $path) {
            $this->postJson($path, $this->body(['date_from' => '2020-01-01', 'date_to' => '2028-10-01', 'columns' => ['store']]), $this->headers())
                ->assertOk();
        }
    }

    public function test_a_caller_cannot_set_the_statement_timeout(): void
    {
        $this->assertArrayNotHasKey('timeout_ms', (new ReportDatasetQueryRequest())->rules());
    }

    public function test_the_catalog_names_qa_and_lists_both_datasets(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())
            ->assertOk()
            ->assertJsonPath('service', 'QA')
            ->assertJsonPath('contract_version', 1);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $response->json('contract_sha1'));
        $this->assertSame(['audit.cleaning', 'audit.evaluations'], array_column($response->json('datasets'), 'key'));
        $this->assertSame(['store', 'date'], $response->json('datasets.0.grain'));
        $this->assertSame('/report-datasets/audit.cleaning/query', $response->json('datasets.0.path'));
        $this->assertSame(['daily', 'weekly', 'monthly', 'hourly'], array_column($response->json('datasets.0.columns.3.options'), 'value'));
        $this->assertTrue($response->json('datasets.0.dated'));
        $this->assertTrue($response->json('datasets.1.dated'));
        // The SQL behind a column is never published.
        $this->assertStringNotContainsString('cleaning_completions.', $response->getContent());
    }

    public function test_every_dataset_has_its_own_literal_query_route(): void
    {
        foreach (ReportDatasets::DATASETS as $dataset) {
            $route = app('router')->getRoutes()->match(Request::create('/api/report-datasets/'.$dataset::KEY.'/query', 'POST'));
            $this->assertSame('api/report-datasets/'.$dataset::KEY.'/query', $route->uri());
            $this->assertContains(AuthTokenStoreScopeMiddleware::class, $route->gatherMiddleware());
            $this->assertContains('throttle:api-heavy', $route->gatherMiddleware());
        }
    }

    public function test_stream_mode_sends_ndjson_columns_rows_and_the_done_line(): void
    {
        $query = [
            'columns' => ['store', 'task'],
            'group_by' => ['store', 'task'],
            'aggregates' => [['column' => 'completed', 'fn' => 'count', 'alias' => 'completed']],
        ];
        $page = $this->postJson(self::CLEANING, $this->body($query), $this->headers())->assertOk()->json();

        $response = $this->postJson(self::CLEANING, $this->body(['stream' => true, 'limit' => null] + $query), $this->headers())->assertOk();

        $this->assertStringStartsWith('application/x-ndjson', $response->headers->get('Content-Type'));
        $lines = array_map(fn (string $line) => json_decode($line, true), explode("\n", rtrim($response->streamedContent(), "\n")));
        $rows = array_slice($lines, 1, -1);

        $this->assertSame(['columns' => $page['columns']], $lines[0]);
        $this->assertNotEmpty($rows);
        $this->assertEqualsCanonicalizing($page['rows'], $rows);
        $this->assertSame(['done' => true, 'row_count' => count($rows)], end($lines));
    }

    public function test_a_stream_query_with_a_limit_is_a_422(): void
    {
        $this->postJson(self::CLEANING, $this->body(['stream' => true, 'limit' => 10]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }
}
