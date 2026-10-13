package com.bmlhs.mobile

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.bmlhs.mobile.data.ApiClient
import com.bmlhs.mobile.data.ApiException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException
import java.time.LocalDate

private data class TeacherClassChoice(
    val classId: Int,
    val className: String,
    val sectionId: Int,
    val sectionName: String,
) {
    val label: String
        get() = listOf(className, sectionName).filter(String::isNotBlank).joinToString(" · ")
}

private data class AttendanceEntry(
    val studentId: Int,
    val roll: String,
    val name: String,
    val status: String,
    val remarks: String,
)

private data class MarkEntry(
    val studentId: Int,
    val roll: String,
    val name: String,
    val mcq: String,
    val cq: String,
    val practical: String,
)

@Composable
internal fun TeacherTools(api: ApiClient, token: String, summary: JSONObject?, sessionYear: String) {
    val scope = rememberCoroutineScope()
    val attendanceClasses = remember(summary) {
        parseTeacherClasses(summary?.optJSONArray("assigned_classes"))
    }
    val subjectClasses = remember(summary) {
        parseTeacherClasses(summary?.optJSONArray("assigned_subject_classes"))
    }
    var workflow by remember { mutableStateOf("attendance") }
    var selectedAttendanceClass by remember { mutableStateOf<TeacherClassChoice?>(null) }
    var selectedSubjectClass by remember { mutableStateOf<TeacherClassChoice?>(null) }
    var attendanceDate by remember { mutableStateOf(LocalDate.now().toString()) }
    var attendanceRows by remember { mutableStateOf<List<AttendanceEntry>>(emptyList()) }
    var subjectOptions by remember { mutableStateOf<JSONArray?>(null) }
    var examOptions by remember { mutableStateOf<JSONArray?>(null) }
    var selectedSubjectId by remember { mutableStateOf(0) }
    var selectedExamId by remember { mutableStateOf(0) }
    var selectedSubjectName by remember { mutableStateOf("") }
    var marksSubject by remember { mutableStateOf<JSONObject?>(null) }
    var marksRows by remember { mutableStateOf<List<MarkEntry>>(emptyList()) }
    var submissionStatus by remember { mutableStateOf("draft") }
    var confirmFinalSubmit by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf("") }

    fun runRequest(block: suspend () -> Unit) {
        if (busy) return
        busy = true
        message = ""
        scope.launch {
            try {
                block()
            } catch (exception: ApiException) {
                message = exception.message
            } catch (exception: IOException) {
                message = "সার্ভারের সাথে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                message = "সার্ভারের উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("শিক্ষক কার্যক্রম", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (workflow == "attendance") {
                    Button(onClick = { workflow = "attendance" }) { Text("উপস্থিতি") }
                    OutlinedButton(onClick = {
                        workflow = "marks"
                        selectedSubjectClass = null
                        subjectOptions = null
                        examOptions = null
                        selectedSubjectId = 0
                        selectedExamId = 0
                        marksRows = emptyList()
                    }) { Text("নম্বর") }
                } else {
                    OutlinedButton(onClick = { workflow = "attendance" }) { Text("উপস্থিতি") }
                    Button(onClick = { workflow = "marks" }) { Text("নম্বর") }
                }
            }
            if (workflow == "attendance") {
                Text("আপনার দায়িত্বপ্রাপ্ত শ্রেণী/শাখা", fontWeight = FontWeight.SemiBold)
                TeacherClassChoices(attendanceClasses, selectedAttendanceClass, !busy) {
                    selectedAttendanceClass = it
                    attendanceRows = emptyList()
                    message = ""
                }
                OutlinedTextField(
                    value = attendanceDate,
                    onValueChange = {
                        attendanceDate = it.take(10)
                        attendanceRows = emptyList()
                    },
                    label = { Text("তারিখ (YYYY-MM-DD)") },
                    singleLine = true,
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth(),
                )
                Button(
                    enabled = !busy && selectedAttendanceClass != null && isDate(attendanceDate),
                    onClick = {
                        val choice = selectedAttendanceClass ?: return@Button
                        runRequest {
                            val response = withContext(Dispatchers.IO) {
                                api.teacherAttendance(token, choice.classId, choice.sectionId, sessionYear, attendanceDate)
                            }
                            val students = response.optJSONObject("data")?.optJSONArray("students")
                                ?: JSONArray()
                            attendanceRows = parseAttendance(students)
                        }
                    },
                ) { Text(if (busy) "লোড হচ্ছে…" else "উপস্থিতির তালিকা লোড করুন") }
                if (attendanceRows.isNotEmpty()) {
                    Text("${attendanceRows.size} জন শিক্ষার্থী", fontWeight = FontWeight.SemiBold)
                    attendanceRows.forEachIndexed { index, student ->
                        AttendanceCard(student) { updated ->
                            attendanceRows = attendanceRows.toMutableList().also { it[index] = updated }
                        }
                    }
                    Button(
                        enabled = !busy,
                        onClick = {
                            runRequest {
                                val payload = JSONObject()
                                    .put("date", attendanceDate)
                                    .put("class_id", selectedAttendanceClass?.classId)
                                    .put("section_id", selectedAttendanceClass?.sectionId)
                                    .put("session_year", sessionYear)
                                    .put("students", JSONArray().apply {
                                        attendanceRows.forEach { student ->
                                            put(
                                                JSONObject()
                                                    .put("student_id", student.studentId)
                                                    .put("status", student.status)
                                                    .put("remarks", student.remarks),
                                            )
                                        }
                                    })
                                val result = withContext(Dispatchers.IO) {
                                    api.saveTeacherAttendance(token, payload)
                                }
                                val saved = result.optJSONObject("data")?.optInt("saved", -1) ?: -1
                                if (saved != attendanceRows.size) {
                                    throw ApiException(502, "সার্ভার সব শিক্ষার্থীর উপস্থিতি সংরক্ষণের নিশ্চয়তা দেয়নি।")
                                }
                                val choice = selectedAttendanceClass
                                    ?: throw ApiException(502, "শ্রেণি/শাখার নির্বাচন হারিয়ে গেছে; উপস্থিতি যাচাই করা যায়নি।")
                                val verification = withContext(Dispatchers.IO) {
                                    api.teacherAttendance(token, choice.classId, choice.sectionId, sessionYear, attendanceDate)
                                }
                                val confirmedRows = parseAttendance(
                                    verification.optJSONObject("data")?.optJSONArray("students") ?: JSONArray(),
                                )
                                val expectedRows = attendanceRows.associateBy(AttendanceEntry::studentId)
                                val verified = confirmedRows.size == expectedRows.size &&
                                    confirmedRows.all { serverRow ->
                                        expectedRows[serverRow.studentId]?.let { expected ->
                                            serverRow.status == expected.status && serverRow.remarks == expected.remarks
                                        } == true
                                    }
                                if (!verified) {
                                    throw ApiException(502, "সার্ভার অনুরোধ গ্রহণ করেছে, কিন্তু সংরক্ষিত উপস্থিতি পুনরায় পড়ে মেলেনি। তালিকা refresh করে যাচাই করুন।")
                                }
                                attendanceRows = confirmedRows
                                message = "$saved জনের উপস্থিতি live সার্ভারে সংরক্ষণ ও যাচাই হয়েছে।"
                            }
                        },
                    ) { Text("উপস্থিতি সংরক্ষণ করুন") }
                }
            } else {
                Text("যে বিষয়ের নম্বর দেওয়ার দায়িত্ব আপনার", fontWeight = FontWeight.SemiBold)
                TeacherClassChoices(subjectClasses, selectedSubjectClass, !busy) {
                    selectedSubjectClass = it
                    selectedSubjectId = 0
                    selectedExamId = 0
                    marksRows = emptyList()
                    runRequest {
                        val response = withContext(Dispatchers.IO) {
                            api.teacherMarks(token, it.classId, it.sectionId, sessionYear)
                        }
                        val data = response.optJSONObject("data")
                        subjectOptions = data?.optJSONArray("subjects") ?: JSONArray()
                        examOptions = data?.optJSONArray("exams") ?: JSONArray()
                    }
                }
                if (selectedSubjectClass != null) {
                    Text("বিষয়", fontWeight = FontWeight.SemiBold)
                    TeacherOptionChoices(subjectOptions, selectedSubjectId, !busy) { id, name ->
                        selectedSubjectId = id
                        selectedSubjectName = name
                        marksRows = emptyList()
                    }
                    Text("পরীক্ষা", fontWeight = FontWeight.SemiBold)
                    TeacherOptionChoices(examOptions, selectedExamId, !busy) { id, _ ->
                        selectedExamId = id
                        marksRows = emptyList()
                    }
                    Button(
                        enabled = !busy && selectedSubjectId > 0 && selectedExamId > 0,
                        onClick = {
                            val choice = selectedSubjectClass ?: return@Button
                            runRequest {
                                val response = withContext(Dispatchers.IO) {
                                    api.teacherMarks(
                                        token,
                                        choice.classId,
                                        choice.sectionId,
                                        sessionYear,
                                        selectedExamId,
                                        selectedSubjectId,
                                    )
                                }
                                val data = response.optJSONObject("data")
                                marksSubject = data?.optJSONObject("subject")
                                submissionStatus = data?.optString("submission_status", "draft") ?: "draft"
                                marksRows = parseMarks(data?.optJSONArray("students") ?: JSONArray())
                            }
                        },
                    ) { Text(if (busy) "লোড হচ্ছে…" else "মার্কশিট লোড করুন") }
                }
                if (marksRows.isNotEmpty()) {
                    val subject = marksSubject
                    Text(
                        "$selectedSubjectName · পূর্ণমান ${subject?.optDouble("full_marks", 100.0) ?: 100.0} · " +
                            if (submissionStatus == "submitted") "Final Submitted" else "Draft",
                        fontWeight = FontWeight.SemiBold,
                    )
                    marksRows.forEachIndexed { index, student ->
                        MarkCard(student, subject, submissionStatus != "submitted") { updated ->
                            marksRows = marksRows.toMutableList().also { it[index] = updated }
                        }
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(
                            enabled = !busy && submissionStatus != "submitted",
                            onClick = {
                                val choice = selectedSubjectClass ?: return@Button
                                runRequest {
                                    val response = withContext(Dispatchers.IO) {
                                        api.saveTeacherMarks(token, marksPayload(choice, sessionYear, selectedExamId, selectedSubjectId, "save", marksRows))
                                    }
                                    val saved = response.optJSONObject("data")?.optInt("saved", -1) ?: -1
                                    if (saved != marksRows.size) {
                                        throw ApiException(502, "সার্ভার সব শিক্ষার্থীর নম্বর সংরক্ষণের নিশ্চয়তা দেয়নি।")
                                    }
                                    val verification = withContext(Dispatchers.IO) {
                                        api.teacherMarks(token, choice.classId, choice.sectionId, sessionYear, selectedExamId, selectedSubjectId)
                                    }
                                    val data = verification.optJSONObject("data")
                                    val confirmedRows = parseMarks(data?.optJSONArray("students") ?: JSONArray())
                                    val expectedRows = marksRows.associateBy(MarkEntry::studentId)
                                    val verified = confirmedRows.size == expectedRows.size &&
                                        confirmedRows.all { serverRow ->
                                            expectedRows[serverRow.studentId]?.let { expected ->
                                                sameMark(serverRow.mcq, expected.mcq) &&
                                                    sameMark(serverRow.cq, expected.cq) &&
                                                    sameMark(serverRow.practical, expected.practical)
                                            } == true
                                        }
                                    if (!verified) {
                                        throw ApiException(502, "সার্ভার অনুরোধ গ্রহণ করেছে, কিন্তু সংরক্ষিত নম্বর পুনরায় পড়ে মেলেনি। মার্কশিট refresh করে যাচাই করুন।")
                                    }
                                    marksRows = confirmedRows
                                    submissionStatus = data?.optString("submission_status", "draft") ?: "draft"
                                    message = "$saved জনের নম্বর live সার্ভারে সংরক্ষণ ও যাচাই হয়েছে।"
                                }
                            },
                        ) { Text("Draft সংরক্ষণ") }
                        Button(
                            enabled = !busy && submissionStatus != "submitted",
                            onClick = { confirmFinalSubmit = true },
                        ) { Text("Final Submit") }
                    }
                    Text("Final Submit করার পর বিষয়টি পুনরায় সম্পাদনা করতে Admin-এর reopen করতে হবে।", style = MaterialTheme.typography.bodySmall)
                }
            }
            if (confirmFinalSubmit) {
                AlertDialog(
                    onDismissRequest = { confirmFinalSubmit = false },
                    title = { Text("Final Submit করবেন?") },
                    text = { Text("বর্তমান নম্বর আগে সংরক্ষণ করে Final Submit করা হবে। এরপর Admin reopen না করা পর্যন্ত পরিবর্তন করা যাবে না।") },
                    confirmButton = {
                        Button(onClick = {
                            confirmFinalSubmit = false
                            val choice = selectedSubjectClass ?: return@Button
                            runRequest {
                                val payload = marksPayload(
                                    choice,
                                    sessionYear,
                                    selectedExamId,
                                    selectedSubjectId,
                                    "save",
                                    marksRows,
                                )
                                val saved = withContext(Dispatchers.IO) { api.saveTeacherMarks(token, payload) }
                                if (saved.optJSONObject("data")?.optInt("saved", -1) != marksRows.size) {
                                    throw ApiException(502, "সার্ভার সব শিক্ষার্থীর নম্বর সংরক্ষণের নিশ্চয়তা দেয়নি; Final Submit করা হয়নি।")
                                }
                                val beforeSubmit = withContext(Dispatchers.IO) {
                                    api.teacherMarks(token, choice.classId, choice.sectionId, sessionYear, selectedExamId, selectedSubjectId)
                                }
                                val beforeData = beforeSubmit.optJSONObject("data")
                                val confirmedRows = parseMarks(beforeData?.optJSONArray("students") ?: JSONArray())
                                val expectedRows = marksRows.associateBy(MarkEntry::studentId)
                                val verified = confirmedRows.size == expectedRows.size &&
                                    confirmedRows.all { serverRow ->
                                        expectedRows[serverRow.studentId]?.let { expected ->
                                            sameMark(serverRow.mcq, expected.mcq) &&
                                                sameMark(serverRow.cq, expected.cq) &&
                                                sameMark(serverRow.practical, expected.practical)
                                        } == true
                                    }
                                if (!verified) {
                                    throw ApiException(502, "সার্ভারে সংরক্ষিত নম্বর যাচাই হয়নি; Final Submit করা হয়নি।")
                                }
                                val response = withContext(Dispatchers.IO) {
                                    api.saveTeacherMarks(
                                        token,
                                        marksPayload(choice, sessionYear, selectedExamId, selectedSubjectId, "final_submit", emptyList()),
                                    )
                                }
                                if (response.optJSONObject("data")?.optString("status") != "submitted") {
                                    throw ApiException(502, "Final Submit-এর সার্ভার নিশ্চিতকরণ পাওয়া যায়নি।")
                                }
                                val afterSubmit = withContext(Dispatchers.IO) {
                                    api.teacherMarks(token, choice.classId, choice.sectionId, sessionYear, selectedExamId, selectedSubjectId)
                                }
                                val afterData = afterSubmit.optJSONObject("data")
                                if (afterData?.optString("submission_status") != "submitted") {
                                    throw ApiException(502, "সার্ভারে Final Submit অবস্থা যাচাই করা যায়নি। Admin-এর সঙ্গে যাচাই করুন।")
                                }
                                marksRows = parseMarks(afterData.optJSONArray("students") ?: JSONArray())
                                submissionStatus = "submitted"
                                message = "নম্বর live সার্ভারে সংরক্ষণ, যাচাই ও Final Submit হয়েছে।"
                            }
                        }) { Text("চূড়ান্ত জমা") }
                    },
                    dismissButton = {
                        OutlinedButton(onClick = { confirmFinalSubmit = false }) { Text("বাতিল") }
                    },
                )
            }
            if (message.isNotBlank()) {
                Text(message, color = MaterialTheme.colorScheme.primary)
            }
        }
    }
}

@Composable
private fun TeacherClassChoices(
    choices: List<TeacherClassChoice>,
    selected: TeacherClassChoice?,
    enabled: Boolean,
    onSelect: (TeacherClassChoice) -> Unit,
) {
    if (choices.isEmpty()) {
        Text("এই শিক্ষাবর্ষে কোনো দায়িত্ব পাওয়া যায়নি।")
        return
    }
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        choices.forEach { choice ->
            val label = choice.label.ifBlank { "শ্রেণী ${choice.classId} · শাখা ${choice.sectionId}" }
            if (choice == selected) {
                Button(enabled = enabled, onClick = { onSelect(choice) }, modifier = Modifier.fillMaxWidth()) { Text(label) }
            } else {
                OutlinedButton(enabled = enabled, onClick = { onSelect(choice) }, modifier = Modifier.fillMaxWidth()) { Text(label) }
            }
        }
    }
}

@Composable
private fun TeacherOptionChoices(items: JSONArray?, selectedId: Int, enabled: Boolean, onSelect: (Int, String) -> Unit) {
    if (items == null || items.length() == 0) {
        Text("কোনো বিষয়/পরীক্ষা পাওয়া যায়নি।")
        return
    }
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        (0 until items.length()).forEach { index ->
            val option = items.optJSONObject(index) ?: return@forEach
            val id = option.optInt("id")
            val label = option.optString("name")
            if (id == selectedId) {
                Button(enabled = enabled, onClick = { onSelect(id, label) }, modifier = Modifier.fillMaxWidth()) { Text(label) }
            } else {
                OutlinedButton(enabled = enabled, onClick = { onSelect(id, label) }, modifier = Modifier.fillMaxWidth()) { Text(label) }
            }
        }
    }
}

@Composable
private fun AttendanceCard(entry: AttendanceEntry, onChange: (AttendanceEntry) -> Unit) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text("${entry.roll} · ${entry.name}", fontWeight = FontWeight.SemiBold)
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                listOf("present" to "উপস্থিত", "absent" to "অনুপস্থিত", "late" to "বিলম্ব").forEach { (status, label) ->
                    if (entry.status == status) {
                        Button(
                            onClick = { onChange(entry.copy(status = status)) },
                            modifier = Modifier.weight(1f),
                        ) { Text(label) }
                    } else {
                        OutlinedButton(
                            onClick = { onChange(entry.copy(status = status)) },
                            modifier = Modifier.weight(1f),
                        ) { Text(label) }
                    }
                }
            }
            OutlinedTextField(
                value = entry.remarks,
                onValueChange = { onChange(entry.copy(remarks = it.take(255))) },
                label = { Text("মন্তব্য (ঐচ্ছিক)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

@Composable
private fun MarkCard(entry: MarkEntry, subject: JSONObject?, enabled: Boolean, onChange: (MarkEntry) -> Unit) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text("${entry.roll} · ${entry.name}", fontWeight = FontWeight.SemiBold)
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                if (subject?.optBoolean("has_mcq", true) == true) {
                    MarkField("MCQ", entry.mcq, enabled, Modifier.weight(1f)) { onChange(entry.copy(mcq = it)) }
                }
                if (subject?.optBoolean("has_cq", true) == true) {
                    MarkField("CQ", entry.cq, enabled, Modifier.weight(1f)) { onChange(entry.copy(cq = it)) }
                }
                if (subject?.optBoolean("has_practical", false) == true) {
                    MarkField("Practical", entry.practical, enabled, Modifier.weight(1f)) { onChange(entry.copy(practical = it)) }
                }
            }
        }
    }
}

@Composable
private fun MarkField(label: String, value: String, enabled: Boolean, modifier: Modifier, onChange: (String) -> Unit) {
    OutlinedTextField(
        value = value,
        onValueChange = { onChange(it.filter { character -> character.isDigit() || character == '.' }.take(7)) },
        label = { Text(label) },
        singleLine = true,
        readOnly = !enabled,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
        modifier = modifier,
    )
}

private fun parseTeacherClasses(rows: JSONArray?): List<TeacherClassChoice> {
    if (rows == null) return emptyList()
    val unique = linkedMapOf<String, TeacherClassChoice>()
    (0 until rows.length()).forEach { index ->
        val row = rows.optJSONObject(index) ?: return@forEach
        val choice = TeacherClassChoice(
            row.optInt("class_id"),
            row.optString("class_name"),
            row.optInt("section_id"),
            row.optString("section_name"),
        )
        if (choice.classId > 0) unique["${choice.classId}:${choice.sectionId}"] = choice
    }
    return unique.values.toList()
}

private fun parseAttendance(rows: JSONArray): List<AttendanceEntry> =
    (0 until rows.length()).mapNotNull { index ->
        rows.optJSONObject(index)?.let { row ->
            AttendanceEntry(
                row.optInt("student_id"),
                row.optString("roll"),
                row.optString("name"),
                row.optString("status", "present"),
                row.optString("remarks"),
            )
        }
    }

private fun parseMarks(rows: JSONArray): List<MarkEntry> =
    (0 until rows.length()).mapNotNull { index ->
        rows.optJSONObject(index)?.let { row ->
            MarkEntry(
                row.optInt("student_id"),
                row.optString("roll"),
                row.optString("name"),
                row.optionalMark("mcq"),
                row.optionalMark("cq"),
                row.optionalMark("practical"),
            )
        }
    }

private fun JSONObject.optionalMark(key: String): String =
    if (isNull(key)) "" else {
        val raw = optString(key, "")
        raw.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: raw
    }

private fun sameMark(left: String, right: String): Boolean {
    val leftValue = left.toBigDecimalOrNull()
    val rightValue = right.toBigDecimalOrNull()
    return when {
        leftValue == null && rightValue == null -> left.isBlank() && right.isBlank()
        leftValue == null || rightValue == null -> false
        else -> leftValue.compareTo(rightValue) == 0
    }
}

private fun marksPayload(
    choice: TeacherClassChoice,
    sessionYear: String,
    examId: Int,
    subjectId: Int,
    action: String,
    rows: List<MarkEntry>,
): JSONObject =
    JSONObject()
        .put("class_id", choice.classId)
        .put("section_id", choice.sectionId)
        .put("session_year", sessionYear)
        .put("exam_id", examId)
        .put("subject_id", subjectId)
        .put("action", action)
        .put("students", JSONArray().apply {
            rows.forEach { row ->
                put(
                    JSONObject()
                        .put("student_id", row.studentId)
                        .put("mcq", row.mcq.ifBlank { JSONObject.NULL })
                        .put("cq", row.cq.ifBlank { JSONObject.NULL })
                        .put("practical", row.practical.ifBlank { JSONObject.NULL }),
                )
            }
        })

private fun isDate(value: String): Boolean =
    runCatching { LocalDate.parse(value).toString() == value }.getOrDefault(false)
