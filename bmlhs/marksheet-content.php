<article class="school-marksheet school-detailed-marksheet" id="printDiv">
    <?php if (!empty($marksheetPdf)): ?>
    <table class="marksheet-pdf-header">
        <tr>
            <td class="marksheet-pdf-logo"><img src="<?= h($marksheetLogo) ?>" width="68" height="68" style="width:18mm;height:18mm" alt=""></td>
            <td class="marksheet-pdf-school">
                <h1><?= h((string) $school['school_name_bn']) ?></h1>
                <p><?= h((string) $school['address']) ?></p>
                <p>ইআইআইএন: <?= h(bnDigits((string) $school['eiin'])) ?> · মোবাইল: <?= h((string) ($school['mobile'] ?? '')) ?> · ইমেইল: <?= h((string) ($school['email'] ?? '')) ?></p>
            </td>
            <td class="marksheet-pdf-qr"><?php if ($marksheetQr !== ''): ?><img src="<?= h($marksheetQr) ?>" width="68" height="68" style="width:18mm;height:18mm" alt="মার্কশিট যাচাইয়ের QR কোড"><?php endif; ?></td>
        </tr>
    </table>
    <?php else: ?>
    <header class="school-marksheet-head">
        <img src="<?= h($marksheetLogo) ?>" alt="">
        <div>
            <h1><?= h((string) $school['school_name_bn']) ?></h1>
            <p><?= h((string) $school['address']) ?></p>
            <p>ইআইআইএন: <?= h(bnDigits((string) $school['eiin'])) ?> · মোবাইল: <?= h((string) ($school['mobile'] ?? '')) ?> · ইমেইল: <?= h((string) ($school['email'] ?? '')) ?></p>
        </div>
        <?php if ($marksheetQr !== ''): ?>
        <img class="school-marksheet-qr" src="<?= h($marksheetQr) ?>" alt="মার্কশিট যাচাইয়ের QR কোড">
        <?php endif; ?>
    </header>
    <?php endif; ?>
    <div class="school-marksheet-title">
        <h2>একাডেমিক ট্রান্সক্রিপ্ট</h2>
        <span><?= h((string) $examName) ?> · শিক্ষাবর্ষ <?= h(bnDigits($sessionYear)) ?></span>
    </div>
    <?php if (!empty($marksheetPdf)): ?>
    <table class="marksheet-pdf-meta">
        <tr>
            <td class="marksheet-pdf-photo" rowspan="3"><img src="<?= h($photo) ?>" width="76" height="94" style="width:20mm;height:25mm" alt=""></td>
            <td><strong>নাম:</strong> <?= h((string) $student['name_ban']) ?></td>
            <td><strong>রোল:</strong> <?= h(bnDigits((string) $student['cls_roll'])) ?></td>
            <td><strong>শ্রেণী:</strong> <?= h(classLabel($db, $classId)) ?></td>
        </tr>
        <tr>
            <td><strong>পিতার নাম:</strong> <?= publicHasDisplayValue($student['fname_ban'] ?? '') ? h((string) $student['fname_ban']) : '—' ?></td>
            <td><strong>মাতার নাম:</strong> <?= publicHasDisplayValue($student['mname_ban'] ?? '') ? h((string) $student['mname_ban']) : '—' ?></td>
            <td><strong>শিক্ষাবর্ষ:</strong> <?= h(bnDigits($sessionYear)) ?></td>
        </tr>
        <tr>
            <td colspan="3"><strong>শাখা:</strong> <?= $stuSection > 0 && publicHasDisplayValue(sectionLabel($db, $stuSection)) ? h(sectionLabel($db, $stuSection)) : '—' ?></td>
        </tr>
    </table>
    <?php else: ?>
    <div class="school-marksheet-meta">
        <img class="school-marksheet-photo" src="<?= h($photo) ?>" alt="">
        <dl>
            <div><dt>নাম</dt><dd><?= h((string) $student['name_ban']) ?></dd></div>
            <div><dt>রোল</dt><dd><?= h(bnDigits((string) $student['cls_roll'])) ?></dd></div>
            <div><dt>শ্রেণী</dt><dd><?= h(classLabel($db, $classId)) ?></dd></div>
            <div><dt>পিতার নাম</dt><dd><?= publicHasDisplayValue($student['fname_ban'] ?? '') ? h((string) $student['fname_ban']) : '—' ?></dd></div>
            <div><dt>মাতার নাম</dt><dd><?= publicHasDisplayValue($student['mname_ban'] ?? '') ? h((string) $student['mname_ban']) : '—' ?></dd></div>
            <div><dt>শিক্ষাবর্ষ</dt><dd><?= h(bnDigits($sessionYear)) ?></dd></div>
            <?php if ($stuSection > 0 && publicHasDisplayValue(sectionLabel($db, $stuSection))): ?>
            <div><dt>শাখা</dt><dd><?= h(sectionLabel($db, $stuSection)) ?></dd></div>
            <?php endif; ?>
        </dl>
    </div>
    <?php endif; ?>
    <?php if (!empty($marksheetPdf)): ?>
    <table class="marksheet-pdf-summary">
        <tr>
            <td><span>ফলাফল</span><br><strong class="<?= $sheet['failed'] ? 'is-fail' : 'is-pass' ?>"><?= $sheet['failed'] ? 'অকৃতকার্য' : 'উত্তীর্ণ' ?></strong></td>
            <td><span>মোট নম্বর</span><br><strong><?= h(bnDigits((string) (int) round((float) $sheet['total']))) ?> / <?= h(bnDigits((string) (int) round((float) $sheet['full_total']))) ?></strong></td>
            <td><span>জিপিএ</span><br><strong><?= h(bnDigits(number_format((float) $sheet['gpa'], 2))) ?></strong></td>
            <td><span>গ্রেড</span><br><table class="marksheet-pdf-grade-pill"><tr><td class="<?= resultGradeClass((string) $sheet['letter']) ?>"><?= h((string) $sheet['letter']) ?></td></tr></table></td>
            <td><span>মেধাক্রম</span><br><strong><?= h(bnDigits((string) (int) $selected['rank'])) ?></strong></td>
        </tr>
    </table>
    <?php else: ?>
    <div class="school-marksheet-summary">
        <div><span>ফলাফল</span><strong class="<?= $sheet['failed'] ? 'is-fail' : 'is-pass' ?>"><?= $sheet['failed'] ? 'অকৃতকার্য' : 'উত্তীর্ণ' ?></strong></div>
        <div><span>মোট নম্বর</span><strong><?= h(bnDigits((string) (int) round((float) $sheet['total']))) ?> / <?= h(bnDigits((string) (int) round((float) $sheet['full_total']))) ?></strong></div>
        <div><span>জিপিএ</span><strong><?= h(bnDigits(number_format((float) $sheet['gpa'], 2))) ?></strong></div>
        <div><span>গ্রেড</span><strong><span class="school-grade-pill <?= resultGradeClass((string) $sheet['letter']) ?>"><?= h((string) $sheet['letter']) ?></span></strong></div>
        <div><span>মেধাক্রম</span><strong><?= h(bnDigits((string) (int) $selected['rank'])) ?></strong></div>
    </div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table school-result-table school-marksheet-table mb-0">
            <thead>
                <tr>
                    <th>বিষয়</th>
                    <th>পূর্ণমান</th>
                    <th>সর্বোচ্চ</th>
                    <th>MCQ</th>
                    <th>CQ</th>
                    <th>Pr</th>
                    <th>প্রাপ্ত নম্বর</th>
                    <th>গ্রেড</th>
                    <th>জিপি</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sheet['rows'] as $paper): ?>
                    <?php $subject = $paper['subject']; ?>
                    <tr>
                        <td>
                            <?= h((string) ($subject['subject_name_ban'] ?: $subject['subject_name'])) ?>
                            <?php if ((int) ($subject['is_optional'] ?? 0) === 1): ?>
                                <small class="school-subject-note">চতুর্থ বিষয়</small>
                            <?php endif; ?>
                        </td>
                        <td><?= h(bnDigits((string) (int) ($subject['full_marks'] ?? 100))) ?></td>
                        <td><?= h(bnDigits((string) (int) round((float) ($subjectHighestMarks[(int) $subject['id']] ?? 0)))) ?></td>
                        <td><?= $paper['mcq'] === null || $paper['mcq'] === '' ? '—' : h(bnDigits(formatMarkValue($paper['mcq']))) ?></td>
                        <td><?= ((int) ($subject['has_cq'] ?? 1) === 1) ? ($paper['cq'] === null || $paper['cq'] === '' ? '—' : h(bnDigits(formatMarkValue($paper['cq'])))) : '—' ?></td>
                        <td><?= ((int) ($subject['has_practical'] ?? 0) === 1) ? ($paper['practical'] === null || $paper['practical'] === '' ? '—' : h(bnDigits(formatMarkValue($paper['practical'])))) : '—' ?></td>
                        <td><?= h(bnDigits((string) (int) round((float) $paper['total']))) ?></td>
                        <td><?php if (!empty($marksheetPdf)): ?><table class="marksheet-pdf-grade-pill"><tr><td class="<?= resultGradeClass((string) $paper['grade']) ?>"><?= h((string) $paper['grade']) ?></td></tr></table><?php else: ?><span class="school-grade-pill <?= resultGradeClass((string) $paper['grade']) ?>"><?= h((string) $paper['grade']) ?></span><?php endif; ?></td>
                        <td><?= h(bnDigits(number_format(gradePoint((string) $paper['grade']), 2))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="6" class="text-end">সর্বমোট</th>
                    <th><?= h(bnDigits((string) (int) round((float) $sheet['total']))) ?></th>
                    <th><?= h((string) $sheet['letter']) ?></th>
                    <th><?= h(bnDigits(number_format((float) $sheet['gpa'], 2))) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if (!empty($marksheetPdf)): ?>
    <table class="marksheet-pdf-grade-scale">
        <tr>
            <th>গ্রেডিং স্কেল</th>
            <td>A+ ৮০–১০০ (৫.০০)</td>
            <td>A ৭০–৭৯ (৪.০০)</td>
            <td>A− ৬০–৬৯ (৩.৫০)</td>
            <td>B ৫০–৫৯ (৩.০০)</td>
            <td>C ৪০–৪৯ (২.০০)</td>
            <td>D ৩৩–৩৯ (১.০০)</td>
            <td>F ০–৩২ (০.০০)</td>
        </tr>
    </table>
    <?php else: ?>
    <div class="school-grade-scale">
        <strong>গ্রেডিং স্কেল</strong>
        <span>A+ ৮০–১০০ (৫.০০)</span>
        <span>A ৭০–৭৯ (৪.০০)</span>
        <span>A− ৬০–৬৯ (৩.৫০)</span>
        <span>B ৫০–৫৯ (৩.০০)</span>
        <span>C ৪০–৪৯ (২.০০)</span>
        <span>D ৩৩–৩৯ (১.০০)</span>
        <span>F ০–৩২ (০.০০)</span>
    </div>
    <?php endif; ?>
    <?php if ($failedSubjects !== []): ?>
        <p class="school-marksheet-failed"><strong>অনুত্তীর্ণ বিষয়:</strong>
            <?= h(implode('، ', array_map(
                static fn (array $paper): string => (string) ($paper['subject']['subject_name_ban'] ?: $paper['subject']['subject_name']),
                $failedSubjects
            ))) ?>
        </p>
    <?php endif; ?>
    <?php if ((string) $sheet['remarks'] !== ''): ?>
        <p class="school-marksheet-note mb-0"><strong>মন্তব্য:</strong> <?= h((string) $sheet['remarks']) ?></p>
    <?php endif; ?>
</article>
