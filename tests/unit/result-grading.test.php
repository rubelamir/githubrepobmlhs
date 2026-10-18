<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/academics.php';

function assertSameValue(string $label, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . $label . ' => ' . var_export($actual, true) . ' (expected ' . var_export($expected, true) . ')' . PHP_EOL);
        exit(1);
    }
}

$ordinary = ['has_mcq' => 1, 'has_cq' => 1, 'has_practical' => 0, 'full_marks' => 100, 'pass_marks' => 33];
$practical = ['has_mcq' => 1, 'has_cq' => 1, 'has_practical' => 1, 'full_marks' => 100, 'pass_marks' => 33];
$cqOnly = ['has_mcq' => 0, 'has_cq' => 1, 'has_practical' => 0, 'full_marks' => 100, 'pass_marks' => 33];

assertSameValue('ordinary rules', subjectComponentRules($ordinary), [
    'mcq' => ['full' => 30.0, 'pass' => 10.0],
    'cq' => ['full' => 70.0, 'pass' => 23.0],
    'practical' => null,
]);
assertSameValue('practical rules', subjectComponentRules($practical), [
    'mcq' => ['full' => 25.0, 'pass' => 8.0],
    'cq' => ['full' => 50.0, 'pass' => 17.0],
    'practical' => ['full' => 25.0, 'pass' => 8.0],
]);
assertSameValue('cq-only keeps subject pass', subjectComponentRules($cqOnly)['cq'], ['full' => 100.0, 'pass' => 33.0]);

$half = ['has_mcq' => 1, 'has_cq' => 1, 'has_practical' => 0, 'full_marks' => 50, 'pass_marks' => 17];
assertSameValue('50-mark paper scales 70/30', subjectComponentRules($half), [
    'mcq' => ['full' => 15.0, 'pass' => 5.0],
    'cq' => ['full' => 35.0, 'pass' => 11.5],
    'practical' => null,
]);
assertSameValue('half paper below CQ pass fails', subjectPaperGrade($half, 15, 11, null), 'F');
assertSameValue('half paper 28+12 is A+', subjectPaperGrade($half, 12, 28, null), 'A+');

assertSameValue('CQ 22 fails', subjectPaperGrade($ordinary, 30, 22, null), 'F');
assertSameValue('MCQ 9 fails', subjectPaperGrade($ordinary, 9, 23, null), 'F');
assertSameValue('both parts fail', subjectPaperGrade($ordinary, 9, 22, null), 'F');
assertSameValue('23+10 is D', subjectPaperGrade($ordinary, 10, 23, null), 'D');
assertSameValue('50+30 is A+', subjectPaperGrade($ordinary, 30, 50, null), 'A+');
assertSameValue('70+9 is A', subjectPaperGrade($ordinary, 30, 49, null), 'A');
assertSameValue('blank MCQ fails', subjectPaperGrade($ordinary, null, 70, null), 'F');

assertSameValue('practical CQ 16 fails', subjectPaperGrade($practical, 25, 16, 25), 'F');
assertSameValue('practical MCQ 7 fails', subjectPaperGrade($practical, 7, 50, 25), 'F');
assertSameValue('practical 7 fails', subjectPaperGrade($practical, 25, 50, 7), 'F');
assertSameValue('17+8+8 is D', subjectPaperGrade($practical, 8, 17, 8), 'D');
assertSameValue('40+20+20 is A+', subjectPaperGrade($practical, 20, 40, 20), 'A+');

assertSameValue('cq-only 32 is F', subjectPaperGrade($cqOnly, null, 32, null), 'F');
assertSameValue('cq-only 80 is A+', subjectPaperGrade($cqOnly, null, 80, null), 'A+');

$bands = [
    [80, 'A+'], [79, 'A'], [70, 'A'], [69, 'A-'], [60, 'A-'],
    [59, 'B'], [50, 'B'], [49, 'C'], [40, 'C'], [39, 'D'], [33, 'D'], [32, 'F'],
];
foreach ($bands as [$mark, $grade]) {
    assertSameValue('band ' . $mark, subjectPaperGrade($cqOnly, null, $mark, null), $grade);
    assertSameValue('point ' . $grade . ' ' . $mark, gradePoint($grade), match ($grade) {
        'A+' => 5.0, 'A' => 4.0, 'A-' => 3.5, 'B' => 3.0, 'C' => 2.0, 'D' => 1.0, default => 0.0,
    });
}

assertSameValue('bonus A+', fourthSubjectBonus('A+'), 3.0);
assertSameValue('bonus A', fourthSubjectBonus('A'), 2.0);
assertSameValue('bonus A-', fourthSubjectBonus('A-'), 1.5);
assertSameValue('bonus B', fourthSubjectBonus('B'), 1.0);
assertSameValue('bonus C', fourthSubjectBonus('C'), 0.0);
assertSameValue('bonus D', fourthSubjectBonus('D'), 0.0);
assertSameValue('bonus F', fourthSubjectBonus('F'), 0.0);

$capped = examResultGpa([
    ['grade' => 'A+', 'optional' => false],
    ['grade' => 'A+', 'optional' => false],
    ['grade' => 'A', 'optional' => false],
    ['grade' => 'A+', 'optional' => true],
]);
assertSameValue('bonus gpa capped', $capped['gpa'], 5.0);
assertSameValue('bonus letter', $capped['letter'], 'A+');
assertSameValue('bonus not failed', $capped['failed'], false);

$withBonus = examResultGpa([
    ['grade' => 'A', 'optional' => false],
    ['grade' => 'B', 'optional' => false],
    ['grade' => 'A-', 'optional' => true],
]);
assertSameValue('gpa uses bonus only', $withBonus['gpa'], 4.25);
assertSameValue('letter A', $withBonus['letter'], 'A');

$compulsoryFail = examResultGpa([
    ['grade' => 'A+', 'optional' => false],
    ['grade' => 'F', 'optional' => false],
    ['grade' => 'A+', 'optional' => true],
]);
assertSameValue('compulsory F zeros gpa', $compulsoryFail['gpa'], 0.0);
assertSameValue('compulsory F letter', $compulsoryFail['letter'], 'F');
assertSameValue('compulsory F failed', $compulsoryFail['failed'], true);

$fourthFail = examResultGpa([
    ['grade' => 'A+', 'optional' => false],
    ['grade' => 'A', 'optional' => false],
    ['grade' => 'F', 'optional' => true],
]);
assertSameValue('fourth F keeps gpa', $fourthFail['gpa'], 4.5);
assertSameValue('fourth F not failed', $fourthFail['failed'], false);

$lowFourth = examResultGpa([
    ['grade' => 'A', 'optional' => false],
    ['grade' => 'A', 'optional' => false],
    ['grade' => 'C', 'optional' => true],
]);
assertSameValue('C bonus is zero', $lowFourth['gpa'], 4.0);

$limit = subjectComponentLimitError($ordinary, 31, 70, null, '12');
if ($limit === null || !str_contains($limit, 'MCQ') || !str_contains($limit, '12')) {
    fwrite(STDERR, 'FAIL: component limit should name the roll and MCQ' . PHP_EOL);
    exit(1);
}
assertSameValue('within limit', subjectComponentLimitError($ordinary, 30, 70, null, '12'), null);
assertSameValue('pass summary', subjectPassSummary($ordinary), 'CQ 23/70, MCQ 10/30');
assertSameValue('practical summary', subjectPassSummary($practical), 'CQ 17/50, MCQ 8/25, Practical 8/25');

echo "PASS\n";
