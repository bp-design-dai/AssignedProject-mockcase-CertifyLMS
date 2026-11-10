<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContentStatus;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Services\Learning\ProgressSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Published-content completion aggregates. Each existing query and rounding convention is preserved. */
class LearningProgressService
{
    /**
     * 学習進捗 (Section→Chapter→Part→資格 完了率) の 4 階層サマリを算出する。
     */
    public function summarize(Enrollment $enrollment): ProgressSummary
    {
        $totals = $this->fetchSectionTotals($enrollment);

        $partsTotal = Part::query()
            ->where('certification_id', $enrollment->certification_id)
            ->where('status', ContentStatus::Published->value)
            ->count();

        $chaptersTotal = Chapter::query()
            ->whereHas('part', function ($q) use ($enrollment) {
                $q->where('certification_id', $enrollment->certification_id)
                    ->where('status', ContentStatus::Published->value);
            })
            ->where('status', ContentStatus::Published->value)
            ->count();

        $sectionsTotal = (int) $totals->sections_total;
        $sectionsCompleted = (int) $totals->sections_completed;
        $sectionRatio = $sectionsTotal === 0 ? 0.0 : round($sectionsCompleted / $sectionsTotal, 4);

        $chaptersCompleted = $this->countCompletedChapters($enrollment);
        $partsCompleted = $this->countCompletedParts($enrollment);

        $chapterRatio = $chaptersTotal === 0 ? 0.0 : round($chaptersCompleted / $chaptersTotal, 4);
        $partRatio = $partsTotal === 0 ? 0.0 : round($partsCompleted / $partsTotal, 4);

        return new ProgressSummary(
            sectionsTotal: $sectionsTotal,
            sectionsCompleted: $sectionsCompleted,
            sectionCompletionRatio: $sectionRatio,
            chaptersTotal: $chaptersTotal,
            chaptersCompleted: $chaptersCompleted,
            chapterCompletionRatio: $chapterRatio,
            partsTotal: $partsTotal,
            partsCompleted: $partsCompleted,
            partCompletionRatio: $partRatio,
            overallCompletionRatio: $sectionRatio,
        );
    }

    private function fetchSectionTotals(Enrollment $enrollment): object
    {
        return DB::table('sections')
            ->join('chapters', 'chapters.id', '=', 'sections.chapter_id')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->leftJoin('section_progresses', function ($join) use ($enrollment) {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->where('sections.status', ContentStatus::Published->value)
            ->selectRaw('COUNT(sections.id) AS sections_total, COUNT(section_progresses.id) AS sections_completed')
            ->first() ?? (object) ['sections_total' => 0, 'sections_completed' => 0];
    }

    private function countCompletedChapters(Enrollment $enrollment): int
    {
        // 公開済 Chapter のうち、配下の公開済 Section が全て読了済かを Chapter 単位で判定。
        $rows = DB::table('chapters')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->leftJoin('sections', function ($join) {
                $join->on('sections.chapter_id', '=', 'chapters.id')
                    ->where('sections.status', ContentStatus::Published->value);
            })
            ->leftJoin('section_progresses', function ($join) use ($enrollment) {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->groupBy('chapters.id')
            ->selectRaw('chapters.id AS chapter_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done')
            ->get();

        $completed = 0;
        foreach ($rows as $row) {
            if ((int) $row->total > 0 && (int) $row->total === (int) $row->done) {
                $completed++;
            }
        }

        return $completed;
    }

    private function countCompletedParts(Enrollment $enrollment): int
    {
        $rows = DB::table('parts')
            ->leftJoin('chapters', function ($join) {
                $join->on('chapters.part_id', '=', 'parts.id')
                    ->where('chapters.status', ContentStatus::Published->value);
            })
            ->leftJoin('sections', function ($join) {
                $join->on('sections.chapter_id', '=', 'chapters.id')
                    ->where('sections.status', ContentStatus::Published->value);
            })
            ->leftJoin('section_progresses', function ($join) use ($enrollment) {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->groupBy('parts.id')
            ->selectRaw('parts.id AS part_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done')
            ->get();

        $completed = 0;
        foreach ($rows as $row) {
            if ((int) $row->total > 0 && (int) $row->total === (int) $row->done) {
                $completed++;
            }
        }

        return $completed;
    }

    /**
     * 受講中の各 Enrollment の Section 単位完了率を 1 クエリでまとめて算出する (N+1 回避)。
     * 戻り値のキーは Enrollment.id、値は Section 単位の完了率(0.0〜1.0、未集計時 0.0)。
     *
     * @param Collection<int, Enrollment> $enrollments
     *
     * @return array<string, float>
     */
    public function completionRatiosForEnrollments(Collection $enrollments): array
    {
        if ($enrollments->isEmpty()) {
            return [];
        }

        $enrollmentIds = $enrollments->pluck('id')->all();
        $certificationIds = $enrollments->pluck('certification_id')->unique()->values()->all();

        $rows = DB::table('sections')
            ->join('chapters', 'chapters.id', '=', 'sections.chapter_id')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->join('enrollments', 'enrollments.certification_id', '=', 'parts.certification_id')
            ->leftJoin('section_progresses', function ($join): void {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->on('section_progresses.enrollment_id', '=', 'enrollments.id');
            })
            ->whereIn('enrollments.id', $enrollmentIds)
            ->whereIn('parts.certification_id', $certificationIds)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->where('sections.status', ContentStatus::Published->value)
            ->groupBy('enrollments.id')
            ->selectRaw('enrollments.id AS enrollment_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done')
            ->get();

        $result = [];
        foreach ($enrollmentIds as $id) {
            $result[$id] = 0.0;
        }

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $done = (int) $row->done;
            $result[(string) $row->enrollment_id] = $total === 0 ? 0.0 : round($done / $total, 4);
        }

        return $result;
    }

    /** @return Collection<int, Chapter> */
    public function chaptersWithSectionTotals(Part $part): Collection
    {
        return $part->chapters()
            ->where('status', ContentStatus::Published->value)
            ->ordered()
            ->withCount([
                'sections as sections_total_count' => fn ($q) => $q
                    ->where('status', ContentStatus::Published->value),
            ])
            ->get();

    }

    /** @param Collection<int, Chapter> $chapters
     * @return array<string, int>
     */
    public function completedSectionsByChapter(?Enrollment $enrollment, Collection $chapters): array
    {
        $completedByChapter = [];
        if ($enrollment !== null && $chapters->isNotEmpty()) {
            $rows = DB::table('sections')
                ->join('section_progresses', function ($join) use ($enrollment) {
                    $join->on('section_progresses.section_id', '=', 'sections.id')
                        ->where('section_progresses.enrollment_id', '=', $enrollment->id);
                })
                ->whereIn('sections.chapter_id', $chapters->pluck('id'))
                ->where('sections.status', ContentStatus::Published->value)
                ->groupBy('sections.chapter_id')
                ->selectRaw('sections.chapter_id AS chapter_id, COUNT(*) AS done')
                ->get();

            foreach ($rows as $row) {
                $completedByChapter[(string) $row->chapter_id] = (int) $row->done;
            }
        }

        return $completedByChapter;
    }

    /** Chapter cards historically use integer percentages and >=, unlike the summary's four-decimal ratios. */
    public function chapterProgress(Collection $chapters, array $completedByChapter): array
    {
        $result = [];
        foreach ($chapters as $chapter) {
            $total = (int) ($chapter->sections_total_count ?? 0);
            $done = (int) ($completedByChapter[$chapter->id] ?? 0);
            $result[$chapter->id] = [
                'total' => $total,
                'done' => $done,
                'isCompleted' => $total > 0 && $done >= $total,
                'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            ];
        }

        return $result;
    }
}
