<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Plan;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;

final class LearningProgressFixture
{
    public static function scenarios(): array
    {
        return array_map(fn ($name) => [$name], ['empty', 'unread', 'partial', 'complete', 'rounding', 'empty_containers', 'empty_and_complete', 'hidden_and_foreign']);
    }

    public static function expected(string $scenario): array
    {
        [$st, $sd, $sr, $ct, $cd, $cr, $pt, $pd, $pr] = match ($scenario) {
            'empty' => [0, 0, 0.0, 0, 0, 0.0, 0, 0, 0.0],
            'unread' => [3, 0, 0.0, 1, 0, 0.0, 1, 0, 0.0],
            'partial', 'hidden_and_foreign' => [3, 1, 0.3333, 1, 0, 0.0, 1, 0, 0.0],
            'complete' => [3, 3, 1.0, 1, 1, 1.0, 1, 1, 1.0],
            'rounding' => [3, 2, 0.6667, 3, 2, 0.6667, 3, 2, 0.6667],
            'empty_containers' => [0, 0, 0.0, 1, 0, 0.0, 2, 0, 0.0],
            'empty_and_complete' => [1, 1, 1.0, 1, 1, 1.0, 2, 1, 0.5],
        };

        return ['sectionsTotal' => $st, 'sectionsCompleted' => $sd, 'sectionCompletionRatio' => $sr,
            'chaptersTotal' => $ct, 'chaptersCompleted' => $cd, 'chapterCompletionRatio' => $cr,
            'partsTotal' => $pt, 'partsCompleted' => $pd, 'partCompletionRatio' => $pr,
            'overallCompletionRatio' => $sr];
    }

    public static function create(string $scenario): array
    {
        $student = User::factory()->student()->inProgress()->withPlan(Plan::factory()->published()->create())->create();
        $certification = Certification::factory()->published()->create(['name' => '架空資格 '.$scenario]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $parts = [];
        $sections = [];
        $chapterDisplay = [];
        if ($scenario !== 'empty') {
            $partCount = $scenario === 'rounding' ? 3 : (in_array($scenario, ['empty_containers', 'empty_and_complete'], true) ? 2 : 1);
            for ($i = 0; $i < $partCount; $i++) {
                $part = Part::factory()->for($certification)->published()->create(['order' => $i + 1]);
                $parts[] = $part;
                if (($scenario === 'empty_containers' && $i === 0) || ($scenario === 'empty_and_complete' && $i === 1)) {
                    continue;
                }
                $chapter = Chapter::factory()->for($part)->published()->create(['order' => 1]);
                $total = match ($scenario) {
                    'empty_containers' => 0, 'rounding', 'empty_and_complete' => 1, default => 3
                };
                $done = match ($scenario) {
                    'complete', 'empty_and_complete' => $total, 'rounding' => $i < 2 ? 1 : 0, 'partial', 'hidden_and_foreign' => 1, default => 0
                };
                $chapterDisplay[$chapter->id] = ['total' => $total, 'done' => $done,
                    'isCompleted' => $total > 0 && $done >= $total,
                    'percent' => $total > 0 ? (int) round($done / $total * 100) : 0];
                for ($j = 0; $j < $total; $j++) {
                    $section = Section::factory()->for($chapter)->published()->create(['order' => $j + 1]);
                    $sections[] = $section;
                    if ($j < $done) {
                        SectionProgress::factory()->forEnrollment($enrollment)->forSection($section)->create();
                    }
                }
            }
        }
        if ($scenario === 'hidden_and_foreign') {
            $chapter = $sections[0]->chapter;
            $hiddenSection = Section::factory()->for($chapter)->draft()->create();
            $hiddenChapter = Chapter::factory()->for($parts[0])->draft()->create();
            $sectionBelowHiddenChapter = Section::factory()->for($hiddenChapter)->published()->create();
            $hiddenPart = Part::factory()->for($certification)->draft()->create();
            $hiddenPartChapter = Chapter::factory()->for($hiddenPart)->published()->create();
            $sectionBelowHiddenPart = Section::factory()->for($hiddenPartChapter)->published()->create();
            foreach ([$hiddenSection, $sectionBelowHiddenChapter, $sectionBelowHiddenPart] as $hidden) {
                SectionProgress::factory()->forEnrollment($enrollment)->forSection($hidden)->create();
            }
            $other = Enrollment::factory()->for($certification)->learning()->create();
            foreach ($sections as $section) {
                SectionProgress::factory()->forEnrollment($other)->forSection($section)->create();
            }
            $foreignCert = Certification::factory()->published()->create();
            $foreignEnrollment = Enrollment::factory()->for($student)->for($foreignCert)->learning()->create();
            $foreignPart = Part::factory()->for($foreignCert)->published()->create();
            $foreignChapter = Chapter::factory()->for($foreignPart)->published()->create();
            $foreignSection = Section::factory()->for($foreignChapter)->published()->create();
            SectionProgress::factory()->forEnrollment($foreignEnrollment)->forSection($foreignSection)->create();
            // Even a cross-certification progress row must not enter this certification's denominator/numerator.
            SectionProgress::factory()->forEnrollment($enrollment)->forSection($foreignSection)->create();
        }

        return compact('student', 'certification', 'enrollment', 'parts', 'sections', 'chapterDisplay');
    }
}
