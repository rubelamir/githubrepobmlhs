package com.bmlhs.mobile

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.bmlhs.mobile.data.ApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.time.LocalDate

private data class RoutineEditEntry(
    val period: String = "",
    val subject: String = "",
    val teacherMobile: String = "",
    val room: String = "",
)

private data class RoutineDay(val day: Int, val label: String, val entries: List<RoutineEditEntry>)
private data class RoutinePeriod(val number: Int, val label: String, val time: String)
private data class ExamSubject(val id: Int, val name: String, val fullMarks: Int)
private data class ExamEntry(
    val id: Int,
    val date: String,
    val subject: String,
    val subjectId: Int,
    val start: String,
    val end: String,
    val room: String,
    val fullMarks: String,
    val sortOrder: Int,
)
private data class PublicationExam(val id: Int, val name: String, val active: Boolean, val published: Boolean)
private data class ResultStudent(
    val roll: String,
    val name: String,
    val mcq: String,
    val cq: String,
    val practical: String,
    val total: String,
    val grade: String,
)
private data class ConfirmRequest(val title: String, val message: String, val action: () -> Unit)

/**
 * Admin routine manager. Pass account-summary module keys in [allowedModules]: `routine` and
 * `exam-routine` independently control their respective tools. Section 0 means all sections.
 */
@Composable
fun AdminRoutineScreen(
    api: ApiClient,
    token: String,
    allowedModules: Set<String>,
    onError: (String) -> Unit,
    initialExamRoutine: Boolean = false,
) {
    val scope = rememberCoroutineScope()
    val canManageRoutine = "routine" in allowedModules
    val canManageExamRoutine = "exam-routine" in allowedModules
    var examMode by remember(allowedModules, initialExamRoutine) {
        mutableStateOf(initialExamRoutine || (!canManageRoutine && canManageExamRoutine))
    }
    var classIdText by remember { mutableStateOf("") }
    var sectionIdText by remember { mutableStateOf("0") }
    var examIdText by remember { mutableStateOf("") }
    var sessionYear by remember { mutableStateOf(LocalDate.now().year.toString()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var success by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf<ConfirmRequest?>(null) }

    var routineDays by remember { mutableStateOf<List<RoutineDay>>(emptyList()) }
    var routinePeriods by remember { mutableStateOf<List<RoutinePeriod>>(emptyList()) }
    var selectedDay by remember { mutableStateOf(0) }
    var copyDayText by remember { mutableStateOf("") }
    var copyClassText by remember { mutableStateOf("") }
    var copySectionText by remember { mutableStateOf("0") }
    var settingAssemblyLabel by remember { mutableStateOf("") }
    var settingAssemblyTime by remember { mutableStateOf("") }
    var settingBreakLabel by remember { mutableStateOf("") }
    var settingBreakTime by remember { mutableStateOf("") }
    var settingSessionLabel by remember { mutableStateOf("") }
    var settingFooter by remember { mutableStateOf("") }
    var routinePublished by remember { mutableStateOf(false) }

    var examEntries by remember { mutableStateOf<List<ExamEntry>>(emptyList()) }
    var examSubjects by remember { mutableStateOf<List<ExamSubject>>(emptyList()) }
    var editingExamEntryId by remember { mutableStateOf(0) }
    var examDate by remember { mutableStateOf("") }
    var examSubjectId by remember { mutableStateOf("") }
    var examStart by remember { mutableStateOf("") }
    var examEnd by remember { mutableStateOf("") }
    var examRoom by remember { mutableStateOf("") }
    var examFullMarks by remember { mutableStateOf("") }
    var examNote by remember { mutableStateOf("") }
    var examSortOrder by remember { mutableStateOf("0") }

    fun fail(exception: Exception) {
        val message = exception.message?.takeIf { it.isNotBlank() } ?: "The request could not be completed."
        error = message
        success = ""
        onError(message)
    }

    fun safely(action: () -> Unit) {
        try {
            action()
        } catch (exception: Exception) {
            fail(exception)
        }
    }

    fun perform(block: suspend () -> String) {
        if (busy) return
        busy = true
        error = ""
        success = ""
        scope.launch {
            try {
                success = block()
            } catch (exception: CancellationException) {
                throw exception
            } catch (exception: Exception) {
                fail(exception)
            } finally {
                busy = false
            }
        }
    }

    fun classSection(): Pair<Int, Int> {
        val classId = classIdText.toIntOrNull() ?: throw IllegalArgumentException("Class ID must be a positive integer.")
        val sectionId = sectionIdText.toIntOrNull()
            ?: throw IllegalArgumentException("Section ID must be a non-negative integer (0 means all sections).")
        require(classId > 0) { "Class ID must be a positive integer." }
        require(sectionId >= 0) { "Section ID must be a non-negative integer (0 means all sections)." }
        return classId to sectionId
    }

    fun requireRoutineAccess() {
        require(canManageRoutine) { "The routine module is not enabled for this account." }
    }

    fun requireExamRoutineAccess() {
        require(canManageExamRoutine) { "The exam-routine module is not enabled for this account." }
    }

    suspend fun fetchRoutine(classId: Int, sectionId: Int) {
        requireRoutineAccess()
        val response = withContext(Dispatchers.IO) {
            api.adminRequest(token, "admin/routine.php", query = mapOf(
                "class_id" to classId.toString(),
                "section_id" to sectionId.toString(),
            ))
        }
        val data = response.optJSONObject("data") ?: throw IllegalStateException("Routine response did not include data.")
        routinePeriods = data.optJSONArray("periods").toRoutinePeriods()
        routineDays = data.optJSONArray("days").toRoutineDays()
        selectedDay = routineDays.firstOrNull()?.day ?: 0
        val settings = data.optJSONObject("settings") ?: JSONObject()
        settingAssemblyLabel = settings.optString("assembly_label")
        settingAssemblyTime = settings.optString("assembly_time")
        settingBreakLabel = settings.optString("break_label")
        settingBreakTime = settings.optString("break_time")
        settingSessionLabel = settings.optString("session_label")
        settingFooter = settings.optString("footer_note")
        routinePublished = settings.optBooleanValue("is_published")
    }

    fun readRoutine() {
        if (!canManageRoutine) return
        val (classId, sectionId) = try { classSection() } catch (exception: Exception) { fail(exception); return }
        perform {
            fetchRoutine(classId, sectionId)
            "Class routine loaded."
        }
    }

    fun routinePost(payload: JSONObject, refreshAfterSave: Boolean = true, successMessage: (JSONObject) -> String) {
        if (!canManageRoutine) return
        perform {
            requireRoutineAccess()
            val response = withContext(Dispatchers.IO) {
                api.adminRequest(token, "admin/routine.php", method = "POST", payload = payload)
            }
            val data = response.optJSONObject("data") ?: throw IllegalStateException("Routine response did not include data.")
            val result = successMessage(data)
            if (refreshAfterSave) {
                val (classId, sectionId) = classSection()
                fetchRoutine(classId, sectionId)
            }
            result
        }
    }

    fun selectedRoutineDay(): RoutineDay? = routineDays.firstOrNull { it.day == selectedDay }

    fun saveRoutineDay() {
        val (classId, sectionId) = try { classSection() } catch (exception: Exception) { fail(exception); return }
        val day = selectedRoutineDay() ?: run { fail(IllegalStateException("Load a routine before saving.")); return }
        val entries = JSONArray()
        day.entries.filter { it.subject.isNotBlank() }.forEach { entry ->
            val period = entry.period.toIntOrNull()
                ?: throw IllegalArgumentException("Every non-empty routine entry must use an active period number.")
            require(routinePeriods.any { it.number == period }) {
                "Every non-empty routine entry must use an active period number."
            }
            require(entry.subject.trim().length <= 150 && entry.teacherMobile.trim().length <= 50 && entry.room.trim().length <= 50) {
                "Subject is limited to 150 characters; teacher mobile and room are limited to 50 each."
            }
            entries.put(JSONObject()
                .put("period_no", period)
                .put("subject", entry.subject.trim())
                .put("teacher_mobile", entry.teacherMobile.trim())
                .put("room", entry.room.trim()))
        }
        require(entries.length() <= maxOf(1, routinePeriods.size) * 12) { "The API allows at most 12 entries per active period." }
        val payload = JSONObject()
            .put("action", "save_day")
            .put("class_id", classId)
            .put("section_id", sectionId)
            .put("day", day.day)
            .put("entries", entries)
        routinePost(payload) { data ->
            val conflicts = data.optJSONArray("conflicts")?.length() ?: 0
            "Day saved (${data.optInt("saved_entries")} entries).${if (conflicts > 0) " $conflicts teacher scheduling conflict(s) reported." else ""}"
        }
    }

    fun clearRoutineDay() {
        val (classId, sectionId) = try { classSection() } catch (exception: Exception) { fail(exception); return }
        val day = selectedRoutineDay() ?: return
        confirm = ConfirmRequest("Clear this day?", "All routine entries for this class, section and weekday will be removed.") {
            routinePost(JSONObject().put("action", "clear_day").put("class_id", classId)
                .put("section_id", sectionId).put("day", day.day)) { "Day cleared." }
        }
    }

    fun copyRoutineDay(toDay: Int) {
        val (classId, sectionId) = try { classSection() } catch (exception: Exception) { fail(exception); return }
        val day = selectedRoutineDay() ?: return
        require(toDay in 0..4 && toDay != day.day) { "Choose a different weekday from 0 through 4." }
        confirm = ConfirmRequest("Copy this day?", "The selected weekday's schedule will be copied to the destination day.") {
            routinePost(JSONObject().put("action", "copy_day").put("class_id", classId)
                .put("section_id", sectionId).put("day", day.day).put("to_day", toDay)) { data ->
                "Day copied (${data.optInt("copied")} entries)."
            }
        }
    }

    fun copyRoutineWeek() {
        val (classId, sectionId) = try { classSection() } catch (exception: Exception) { fail(exception); return }
        val toClassId = copyClassText.toIntOrNull()
            ?: throw IllegalArgumentException("Destination class ID must be positive.")
        val toSectionId = copySectionText.toIntOrNull()
            ?: throw IllegalArgumentException("Destination section ID must be non-negative.")
        require(toClassId > 0 && toSectionId >= 0) { "Destination class ID must be positive and section ID must be non-negative." }
        confirm = ConfirmRequest("Copy the whole week?", "This copies the complete routine from class $classId / section $sectionId to class $toClassId / section $toSectionId.") {
            routinePost(JSONObject().put("action", "copy_week").put("class_id", classId)
                .put("section_id", sectionId).put("to_class_id", toClassId).put("to_section_id", toSectionId)) { data ->
                "Week copied (${data.optInt("copied")} entries)."
            }
        }
    }

    fun saveRoutineSettings() {
        require(settingAssemblyLabel.length <= 80 && settingAssemblyTime.length <= 50 &&
            settingBreakLabel.length <= 80 && settingBreakTime.length <= 50 &&
            settingSessionLabel.length <= 40 && settingFooter.length <= 65535) {
            "Routine setting limits: assembly/break labels 80, their times 50, session label 40, footer 65,535 characters."
        }
        val settings = JSONObject()
            .put("assembly_label", settingAssemblyLabel)
            .put("assembly_time", settingAssemblyTime)
            .put("break_label", settingBreakLabel)
            .put("break_time", settingBreakTime)
            .put("session_label", settingSessionLabel)
            .put("footer_note", settingFooter)
            .put("is_published", routinePublished)
        routinePost(JSONObject().put("action", "save_settings").put("settings", settings), refreshAfterSave = false) {
            "Routine settings saved."
        }
    }

    fun examRoutineBase(action: String): JSONObject {
        val (classId, sectionId) = classSection()
        val examId = examIdText.toIntOrNull() ?: throw IllegalArgumentException("Exam ID must be a positive integer.")
        require(examId > 0) { "Exam ID must be a positive integer." }
        require(sessionYear.matches(Regex("^\\d{4}$"))) { "Session year must contain exactly four digits." }
        return JSONObject().put("action", action).put("exam_id", examId).put("class_id", classId)
            .put("section_id", sectionId).put("session_year", sessionYear)
    }

    suspend fun fetchExamRoutine(classId: Int, sectionId: Int, examId: Int) {
        requireExamRoutineAccess()
        val response = withContext(Dispatchers.IO) {
            api.adminRequest(token, "admin/exam-routine.php", query = mapOf(
                "exam_id" to examId.toString(),
                "class_id" to classId.toString(),
                "section_id" to sectionId.toString(),
                "session_year" to sessionYear,
            ))
        }
        val data = response.optJSONObject("data") ?: throw IllegalStateException("Exam routine response did not include data.")
        examSubjects = data.optJSONArray("subjects").toExamSubjects()
        examEntries = data.optJSONArray("entries").toExamEntries()
    }

    fun readExamRoutine() {
        if (!canManageExamRoutine) return
        try {
            val (classId, sectionId) = classSection()
            val examId = examIdText.toIntOrNull() ?: throw IllegalArgumentException("Exam ID must be a positive integer.")
            require(examId > 0) { "Exam ID must be a positive integer." }
            require(sessionYear.matches(Regex("^\\d{4}$"))) { "Session year must contain exactly four digits." }
            perform {
                fetchExamRoutine(classId, sectionId, examId)
                "Exam routine loaded."
            }
        } catch (exception: Exception) {
            fail(exception)
        }
    }

    fun postExamRoutine(payload: JSONObject, successMessage: (JSONObject) -> String) {
        if (!canManageExamRoutine) return
        perform {
            requireExamRoutineAccess()
            val response = withContext(Dispatchers.IO) {
                api.adminRequest(token, "admin/exam-routine.php", method = "POST", payload = payload)
            }
            val data = response.optJSONObject("data") ?: throw IllegalStateException("Exam routine response did not include data.")
            val result = successMessage(data)
            val (classId, sectionId) = classSection()
            val examId = examIdText.toIntOrNull() ?: throw IllegalStateException("Exam ID must be a positive integer.")
            fetchExamRoutine(classId, sectionId, examId)
            result
        }
    }

    fun clearExamForm() {
        editingExamEntryId = 0
        examDate = ""
        examSubjectId = ""
        examStart = ""
        examEnd = ""
        examRoom = ""
        examFullMarks = ""
        examNote = ""
        examSortOrder = "0"
    }

    fun saveExamEntry() {
        val subjectId = examSubjectId.toIntOrNull()
            ?: throw IllegalArgumentException("Choose a subject ID from the loaded subject list.")
        val sortOrder = examSortOrder.toIntOrNull()
            ?: throw IllegalArgumentException("Sort order must be an integer from 0 to 1,000,000.")
        require(examSubjects.any { it.id == subjectId }) { "Choose a subject ID from the loaded subject list." }
        require(examDate.isValidAdminDate()) { "Exam date must be a valid date in YYYY-MM-DD format." }
        require((examStart.isBlank() || examStart.matches(Regex("^(?:[01]\\d|2[0-3]):[0-5]\\d$"))) &&
            (examEnd.isBlank() || examEnd.matches(Regex("^(?:[01]\\d|2[0-3]):[0-5]\\d$")))) {
            "Start/end times must be blank or 24-hour HH:MM."
        }
        require(examRoom.trim().length <= 80 && examNote.trim().length <= 255 &&
            examFullMarks.trim().length <= 20 && sortOrder in 0..1_000_000) {
            "Room is limited to 80, note to 255, full marks to 20 characters; sort order must be 0–1,000,000."
        }
        require(examFullMarks.isBlank() || (examFullMarks.toDoubleOrNull()?.let { it.isFinite() && it >= 0 } == true)) {
            "Full marks must be blank or a non-negative number."
        }
        val payload = examRoutineBase("save")
            .put("subject_id", subjectId)
            .put("exam_date", examDate)
            .put("start_time", examStart)
            .put("end_time", examEnd)
            .put("room", examRoom.trim())
            .put("full_marks", examFullMarks)
            .put("note", examNote.trim())
            .put("sort_order", sortOrder)
        if (editingExamEntryId > 0) payload.put("id", editingExamEntryId)
        postExamRoutine(payload) { data ->
            clearExamForm()
            "Exam entry ${data.optString("status", "saved")}."
        }
    }

    fun clearExamRoutine() {
        confirm = ConfirmRequest("Clear this exam routine?", "All exam routine entries in the selected exam, class, section and year will be removed.") {
            postExamRoutine(examRoutineBase("clear")) { data -> "Exam routine cleared (${data.optInt("deleted")} entries)." }
        }
    }

    fun copyExamRoutine() {
        val toClass = copyClassText.toIntOrNull()
            ?: throw IllegalArgumentException("Destination class ID must be positive.")
        val toSection = copySectionText.toIntOrNull()
            ?: throw IllegalArgumentException("Destination section ID must be non-negative.")
        require(toClass > 0 && toSection >= 0) { "Destination class ID must be positive and section ID must be non-negative." }
        confirm = ConfirmRequest("Copy this exam routine?", "Entries will be copied to class $toClass / section $toSection for this exam and year.") {
            postExamRoutine(examRoutineBase("copy").put("to_class_id", toClass).put("to_section_id", toSection)) { data ->
                "Exam routine copied (${data.optInt("copied")} entries)."
            }
        }
    }

    fun deleteExamEntry(entry: ExamEntry) {
        confirm = ConfirmRequest("Delete this exam entry?", "The ${entry.subject} exam entry on ${entry.date} will be deleted.") {
            postExamRoutine(examRoutineBase("delete").put("id", entry.id)) { "Exam entry deleted." }
        }
    }

    LaunchedEffect(token) {
        error = ""
        success = ""
    }
    LaunchedEffect(allowedModules) {
        if (examMode && !canManageExamRoutine && canManageRoutine) examMode = false
        if (!examMode && !canManageRoutine && canManageExamRoutine) examMode = true
    }

    Column(
        Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("Routine management", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        if (!canManageRoutine && !canManageExamRoutine) {
            Text("Routine management is unavailable: neither routine nor exam-routine is enabled for this account.",
                color = MaterialTheme.colorScheme.error)
        } else {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (canManageRoutine) {
                    if (!examMode) Button(onClick = { examMode = false }, enabled = !busy) { Text("Class routine") }
                    else OutlinedButton(onClick = { examMode = false }, enabled = !busy) { Text("Class routine") }
                }
                if (canManageExamRoutine) {
                    if (examMode) Button(onClick = { examMode = true }, enabled = !busy) { Text("Exam routine") }
                    else OutlinedButton(onClick = { examMode = true }, enabled = !busy) { Text("Exam routine") }
                }
            }
        OutlinedTextField(classIdText, { classIdText = it.filter(Char::isDigit) }, label = { Text("Class ID") },
            enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(sectionIdText, { sectionIdText = it.filter(Char::isDigit) }, label = { Text("Section ID (0 = all)") },
            enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
        if (examMode) {
            OutlinedTextField(examIdText, { examIdText = it.filter(Char::isDigit) }, label = { Text("Exam ID") },
                enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(sessionYear, { sessionYear = it.filter(Char::isDigit).take(4) }, label = { Text("Session year (YYYY)") },
                enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            Button(onClick = { readExamRoutine() }, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                Text(if (busy) "Loading…" else "Load exam routine")
            }
            ExamRoutineEditor(
                entries = examEntries,
                subjects = examSubjects,
                editingId = editingExamEntryId,
                date = examDate,
                onDate = { examDate = it.take(10) },
                subjectId = examSubjectId,
                onSubjectId = { examSubjectId = it.filter(Char::isDigit) },
                start = examStart,
                onStart = { examStart = it.take(5) },
                end = examEnd,
                onEnd = { examEnd = it.take(5) },
                room = examRoom,
                onRoom = { examRoom = it.take(80) },
                fullMarks = examFullMarks,
                onFullMarks = { examFullMarks = it.take(20) },
                note = examNote,
                onNote = { examNote = it.take(255) },
                sortOrder = examSortOrder,
                onSortOrder = { examSortOrder = it.filter(Char::isDigit).take(7) },
                busy = busy,
                onSave = { safely { saveExamEntry() } },
                onReset = { clearExamForm() },
                onEdit = { entry ->
                    editingExamEntryId = entry.id
                    examDate = entry.date
                    examSubjectId = entry.subjectId.toString()
                    examStart = entry.start
                    examEnd = entry.end
                    examRoom = entry.room
                    examFullMarks = entry.fullMarks
                    examNote = ""
                    examSortOrder = entry.sortOrder.toString()
                },
                onDelete = { deleteExamEntry(it) },
            )
            RoutineCopyControls(copyClassText, { copyClassText = it.filter(Char::isDigit) },
                copySectionText, { copySectionText = it.filter(Char::isDigit) }, busy)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = { safely { copyExamRoutine() } }, enabled = !busy) { Text("Copy exam routine") }
                OutlinedButton(onClick = { safely { clearExamRoutine() } }, enabled = !busy) { Text("Clear exam routine") }
            }
        } else {
            Button(onClick = { readRoutine() }, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                Text(if (busy) "Loading…" else "Load class routine")
            }
            if (routineDays.isNotEmpty()) {
                Text("Weekday", fontWeight = FontWeight.SemiBold)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    routineDays.forEach { day ->
                        if (selectedDay == day.day) Button(onClick = { selectedDay = day.day }, enabled = !busy) { Text(day.label) }
                        else OutlinedButton(onClick = { selectedDay = day.day }, enabled = !busy) { Text(day.label) }
                    }
                }
                selectedRoutineDay()?.entries.orEmpty().forEachIndexed { index, entry ->
                    RoutineEntryEditor(
                        entry = entry,
                        periods = routinePeriods,
                        busy = busy,
                        onChange = { updated ->
                            val day = selectedRoutineDay() ?: return@RoutineEntryEditor
                            routineDays = routineDays.map { current ->
                                if (current.day == day.day) current.copy(entries = current.entries.mapIndexed { i, old -> if (i == index) updated else old })
                                else current
                            }
                        },
                        onRemove = {
                            val day = selectedRoutineDay() ?: return@RoutineEntryEditor
                            routineDays = routineDays.map { current ->
                                if (current.day == day.day) current.copy(entries = current.entries.filterIndexed { i, _ -> i != index })
                                else current
                            }
                        },
                    )
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = {
                        val day = selectedRoutineDay() ?: return@OutlinedButton
                        routineDays = routineDays.map { if (it.day == day.day) it.copy(entries = it.entries + RoutineEditEntry()) else it }
                    }, enabled = !busy) { Text("Add entry") }
                    Button(onClick = { safely { saveRoutineDay() } }, enabled = !busy) { Text("Save day") }
                    OutlinedButton(onClick = { safely { clearRoutineDay() } }, enabled = !busy) { Text("Clear day") }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(copyDayText, { copyDayText = it.filter(Char::isDigit).take(1) },
                        label = { Text("Destination weekday (0–4)") }, enabled = !busy, singleLine = true,
                        modifier = Modifier.weight(1f))
                    OutlinedButton(onClick = {
                        val target = copyDayText.toIntOrNull()
                        if (target == null) fail(IllegalArgumentException("Destination weekday must be from 0 through 4."))
                        else safely { copyRoutineDay(target) }
                    }, enabled = !busy) { Text("Copy day") }
                }
                RoutineCopyControls(copyClassText, { copyClassText = it.filter(Char::isDigit) },
                    copySectionText, { copySectionText = it.filter(Char::isDigit) }, busy)
                OutlinedButton(onClick = { safely { copyRoutineWeek() } }, enabled = !busy) { Text("Copy whole week") }
                Text("Routine display settings", fontWeight = FontWeight.SemiBold)
                SettingField(settingAssemblyLabel, { settingAssemblyLabel = it.take(80) }, "Assembly label")
                SettingField(settingAssemblyTime, { settingAssemblyTime = it.take(50) }, "Assembly time")
                SettingField(settingBreakLabel, { settingBreakLabel = it.take(80) }, "Break label")
                SettingField(settingBreakTime, { settingBreakTime = it.take(50) }, "Break time")
                SettingField(settingSessionLabel, { settingSessionLabel = it.take(40) }, "Session label")
                SettingField(settingFooter, { settingFooter = it.take(65535) }, "Footer note", singleLine = false)
                Row {
                    Text("Published", modifier = Modifier.weight(1f))
                    Switch(checked = routinePublished, onCheckedChange = { routinePublished = it }, enabled = !busy)
                }
                Button(onClick = { safely { saveRoutineSettings() } }, enabled = !busy) { Text("Save display settings") }
            }
        }
        if (busy) Text("Request in progress…")
        if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
        if (success.isNotBlank()) Text(success, color = MaterialTheme.colorScheme.primary)
        }
    }

    confirm?.let { request ->
        AlertDialog(
            onDismissRequest = { confirm = null },
            title = { Text(request.title) },
            text = { Text(request.message) },
            confirmButton = {
                TextButton(onClick = {
                    confirm = null
                    safely(request.action)
                }) { Text("Confirm") }
            },
            dismissButton = { TextButton(onClick = { confirm = null }) { Text("Cancel") } },
        )
    }
}

/** Exam publication controls require `result-publication`; they do not require `results`. */
@Composable
fun AdminResultsPublicationScreen(
    api: ApiClient,
    token: String,
    allowedModules: Set<String>,
    onError: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    var exams by remember { mutableStateOf<List<PublicationExam>>(emptyList()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var success by remember { mutableStateOf("") }
    val canManagePublication = "result-publication" in allowedModules

    fun fail(exception: Exception) {
        val message = exception.message?.takeIf { it.isNotBlank() } ?: "The request could not be completed."
        error = message
        success = ""
        onError(message)
    }

    fun load() {
        if (!canManagePublication) return
        if (busy) return
        busy = true
        error = ""
        success = ""
        scope.launch {
            try {
                val response = withContext(Dispatchers.IO) {
                    api.adminRequest(token, "admin/results.php", query = mapOf("view" to "publication"))
                }
                val data = response.optJSONObject("data") ?: throw IllegalStateException("Publication response did not include data.")
                val list = data.optJSONArray("exams") ?: throw IllegalStateException("Publication response did not include exams.")
                exams = list.toPublicationExams()
                success = "Publication status loaded."
            } catch (exception: CancellationException) {
                throw exception
            } catch (exception: Exception) {
                fail(exception)
            } finally {
                busy = false
            }
        }
    }

    fun setPublished(exam: PublicationExam, published: Boolean) {
        if (!canManagePublication) return
        if (busy) return
        busy = true
        error = ""
        success = ""
        scope.launch {
            try {
                val response = withContext(Dispatchers.IO) {
                    api.adminRequest(
                        token,
                        "admin/results.php",
                        method = "POST",
                        payload = JSONObject().put("action", "set_publication")
                            .put("exam_id", exam.id).put("published", published),
                    )
                }
                val data = response.optJSONObject("data") ?: throw IllegalStateException("Publication update response did not include data.")
                require(data.optInt("exam_id") == exam.id && data.has("published")) {
                    "Publication update response did not match the selected exam."
                }
                exams = exams.map { if (it.id == exam.id) it.copy(published = data.optBoolean("published")) else it }
                success = "${exam.name}: ${data.optString("status", if (published) "published" else "hidden")}."
            } catch (exception: CancellationException) {
                throw exception
            } catch (exception: Exception) {
                fail(exception)
            } finally {
                busy = false
            }
        }
    }

    LaunchedEffect(token, allowedModules) { load() }
    Column(
        Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("Results publication", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        if (!canManagePublication) {
            Text("Result publication settings are unavailable: the result-publication module is not enabled for this account.",
                color = MaterialTheme.colorScheme.error)
        } else {
            Text("Show or hide results for each exam on the public results page.")
            Button(onClick = { load() }, enabled = !busy) { Text(if (busy) "Loading…" else "Refresh status") }
            if (exams.isEmpty() && !busy && error.isBlank()) Text("No exams are available.")
            exams.forEach { exam ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Column(Modifier.weight(1f)) {
                            Text(exam.name, fontWeight = FontWeight.SemiBold)
                            Text("${if (exam.active) "Active" else "Inactive"} · ${if (exam.published) "Published" else "Hidden"}")
                        }
                        Switch(checked = exam.published, onCheckedChange = { setPublished(exam, it) }, enabled = !busy)
                    }
                }
            }
            if (busy) Text("Request in progress…")
            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            if (success.isNotBlank()) Text(success, color = MaterialTheme.colorScheme.primary)
        }
    }
}

@Composable
fun AdminResultsScreen(
    api: ApiClient,
    token: String,
    allowedModules: Set<String>,
    onError: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val canViewResults = "results" in allowedModules
    var classId by remember { mutableStateOf("") }
    var sectionId by remember { mutableStateOf("0") }
    var examId by remember { mutableStateOf("") }
    var subjectId by remember { mutableStateOf("") }
    var sessionYear by remember { mutableStateOf(LocalDate.now().year.toString()) }
    var students by remember { mutableStateOf<List<ResultStudent>>(emptyList()) }
    var heading by remember { mutableStateOf("") }
    var submissionStatus by remember { mutableStateOf("") }
    var marksVisible by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var success by remember { mutableStateOf("") }

    fun fail(exception: Exception) {
        val message = exception.message?.takeIf { it.isNotBlank() } ?: "The request could not be completed."
        error = message
        success = ""
        onError(message)
    }

    fun loadResults() {
        if (!canViewResults || busy) return
        try {
            val classValue = classId.toIntOrNull()
                ?: throw IllegalArgumentException("Class ID must be a positive integer.")
            val sectionValue = sectionId.toIntOrNull()
                ?: throw IllegalArgumentException("Section ID must be a non-negative integer.")
            val examValue = examId.toIntOrNull()
                ?: throw IllegalArgumentException("Exam ID must be a positive integer.")
            val subjectValue = subjectId.toIntOrNull()
                ?: throw IllegalArgumentException("Subject ID must be a positive integer.")
            require(classValue > 0 && examValue > 0 && subjectValue > 0 && sectionValue >= 0) {
                "Class, exam and subject IDs must be positive; section ID must be non-negative."
            }
            require(sessionYear.matches(Regex("^\\d{4}$"))) { "Session year must contain exactly four digits." }
            busy = true
            error = ""
            success = ""
            scope.launch {
                try {
                    require("results" in allowedModules) { "The results module is not enabled for this account." }
                    val response = withContext(Dispatchers.IO) {
                        api.adminRequest(token, "admin/results.php", query = mapOf(
                            "class_id" to classValue.toString(),
                            "section_id" to sectionValue.toString(),
                            "exam_id" to examValue.toString(),
                            "subject_id" to subjectValue.toString(),
                            "session_year" to sessionYear,
                        ))
                    }
                    val data = response.optJSONObject("data")
                        ?: throw IllegalStateException("Results response did not include data.")
                    val subject = data.optJSONObject("subject")
                    val exam = data.optJSONObject("exam")
                    val classData = data.optJSONObject("class")
                    heading = listOfNotNull(classData?.optString("name"), exam?.optString("name"), subject?.optString("name"))
                        .filter { !it.isNullOrBlank() }.joinToString(" · ")
                    submissionStatus = data.optString("submission_status")
                    marksVisible = data.optBoolean("marks_visible")
                    val rows = data.optJSONArray("students")
                        ?: throw IllegalStateException("Results response did not include students.")
                    students = rows.toResultStudents()
                    success = "Results loaded (${students.size} students)."
                } catch (exception: CancellationException) {
                    throw exception
                } catch (exception: Exception) {
                    fail(exception)
                } finally {
                    busy = false
                }
            }
        } catch (exception: Exception) {
            fail(exception)
        }
    }

    Column(
        Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Text("Results", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        if (!canViewResults) {
            Text("Results are unavailable: the results module is not enabled for this account.",
                color = MaterialTheme.colorScheme.error)
        } else {
            OutlinedTextField(classId, { classId = it.filter(Char::isDigit) }, label = { Text("Class ID") },
                enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(sectionId, { sectionId = it.filter(Char::isDigit) }, label = { Text("Section ID (0 = all)") },
                enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(examId, { examId = it.filter(Char::isDigit) }, label = { Text("Exam ID") },
                enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(subjectId, { subjectId = it.filter(Char::isDigit) }, label = { Text("Subject ID") },
                enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(sessionYear, { sessionYear = it.filter(Char::isDigit).take(4) }, label = { Text("Session year (YYYY)") },
                enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            Button(onClick = { loadResults() }, enabled = !busy) { Text(if (busy) "Loading…" else "Load results") }
            if (heading.isNotBlank()) Text(heading, fontWeight = FontWeight.SemiBold)
            if (submissionStatus.isNotBlank()) {
                Text("Submission: $submissionStatus · ${if (marksVisible) "Marks visible" else "Marks hidden"}")
            }
            students.forEach { row ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Text("${row.roll} · ${row.name}", fontWeight = FontWeight.SemiBold)
                        Text("MCQ ${row.mcq} · CQ ${row.cq} · Practical ${row.practical}")
                        Text("Total ${row.total} · Grade ${row.grade}")
                    }
                }
            }
            if (busy) Text("Request in progress…")
            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            if (success.isNotBlank()) Text(success, color = MaterialTheme.colorScheme.primary)
        }
    }
}

@Composable
private fun RoutineEntryEditor(
    entry: RoutineEditEntry,
    periods: List<RoutinePeriod>,
    busy: Boolean,
    onChange: (RoutineEditEntry) -> Unit,
    onRemove: () -> Unit,
) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("Routine entry", fontWeight = FontWeight.SemiBold)
            OutlinedTextField(entry.period, { onChange(entry.copy(period = it.filter(Char::isDigit).take(4))) },
                label = { Text("Period number") }, enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            periods.forEach { period -> Text("${period.number}: ${period.label} ${period.time}") }
            OutlinedTextField(entry.subject, { onChange(entry.copy(subject = it.take(150))) },
                label = { Text("Subject (max 150)") }, enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(entry.teacherMobile, { onChange(entry.copy(teacherMobile = it.take(50))) },
                label = { Text("Teacher mobile (optional, max 50)") }, enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(entry.room, { onChange(entry.copy(room = it.take(50))) },
                label = { Text("Room (optional, max 50)") }, enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
            TextButton(onClick = onRemove, enabled = !busy) { Text("Remove entry") }
        }
    }
}

@Composable
private fun ExamRoutineEditor(
    entries: List<ExamEntry>,
    subjects: List<ExamSubject>,
    editingId: Int,
    date: String,
    onDate: (String) -> Unit,
    subjectId: String,
    onSubjectId: (String) -> Unit,
    start: String,
    onStart: (String) -> Unit,
    end: String,
    onEnd: (String) -> Unit,
    room: String,
    onRoom: (String) -> Unit,
    fullMarks: String,
    onFullMarks: (String) -> Unit,
    note: String,
    onNote: (String) -> Unit,
    sortOrder: String,
    onSortOrder: (String) -> Unit,
    busy: Boolean,
    onSave: () -> Unit,
    onReset: () -> Unit,
    onEdit: (ExamEntry) -> Unit,
    onDelete: (ExamEntry) -> Unit,
) {
    Text("Exam routine entries", fontWeight = FontWeight.SemiBold)
    if (subjects.isNotEmpty()) Text("Subjects: ${subjects.joinToString { "${it.id}=${it.name} (full marks ${it.fullMarks})" }}")
    if (entries.isEmpty()) Text("No exam routine entries loaded.")
    entries.forEach { entry ->
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text("${entry.date} · ${entry.subject}", fontWeight = FontWeight.SemiBold)
                Text("${entry.start.ifBlank { "—" }}–${entry.end.ifBlank { "—" }} · Room ${entry.room.ifBlank { "—" }} · Full marks ${entry.fullMarks}")
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { onEdit(entry) }, enabled = !busy) { Text("Edit") }
                    TextButton(onClick = { onDelete(entry) }, enabled = !busy) { Text("Delete") }
                }
            }
        }
    }
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(if (editingId > 0) "Edit exam entry" else "Add exam entry", fontWeight = FontWeight.SemiBold)
            OutlinedTextField(date, onDate, label = { Text("Exam date (YYYY-MM-DD)") }, enabled = !busy,
                singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(subjectId, onSubjectId, label = { Text("Class subject ID") }, enabled = !busy,
                singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(start, onStart, label = { Text("Start time (HH:MM, optional)") }, enabled = !busy,
                singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(end, onEnd, label = { Text("End time (HH:MM, optional)") }, enabled = !busy,
                singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(room, onRoom, label = { Text("Room (max 80)") }, enabled = !busy,
                singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(fullMarks, onFullMarks, label = { Text("Full marks (optional)") }, enabled = !busy,
                singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(note, onNote, label = { Text("Note (max 255)") }, enabled = !busy,
                singleLine = true, modifier = Modifier.fillMaxWidth())
            if (editingId > 0) {
                Text("The read API does not return an existing note; enter it again before saving if it must be retained.")
            }
            OutlinedTextField(sortOrder, onSortOrder, label = { Text("Sort order (0–1,000,000)") }, enabled = !busy,
                singleLine = true, modifier = Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = onSave, enabled = !busy) { Text(if (editingId > 0) "Save changes" else "Add entry") }
                OutlinedButton(onClick = onReset, enabled = !busy) { Text("Reset") }
            }
        }
    }
}

@Composable
private fun RoutineCopyControls(
    classId: String,
    onClassId: (String) -> Unit,
    sectionId: String,
    onSectionId: (String) -> Unit,
    busy: Boolean,
) {
    Text("Copy destination", fontWeight = FontWeight.SemiBold)
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedTextField(classId, onClassId, label = { Text("Class ID") }, enabled = !busy,
            singleLine = true, modifier = Modifier.weight(1f))
        OutlinedTextField(sectionId, onSectionId, label = { Text("Section ID (0 = all)") }, enabled = !busy,
            singleLine = true, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun SettingField(value: String, onValue: (String) -> Unit, label: String, singleLine: Boolean = true) {
    OutlinedTextField(value, onValue, label = { Text(label) }, singleLine = singleLine,
        minLines = if (singleLine) 1 else 2, maxLines = if (singleLine) 1 else 6,
        modifier = Modifier.fillMaxWidth())
}

private fun JSONArray?.toRoutinePeriods(): List<RoutinePeriod> =
    (0 until (this?.length() ?: 0)).mapNotNull { index ->
        val row = this?.optJSONObject(index) ?: return@mapNotNull null
        RoutinePeriod(row.optInt("period_no"), row.optString("label"), row.optString("time"))
    }

private fun JSONArray?.toRoutineDays(): List<RoutineDay> =
    (0 until (this?.length() ?: 0)).mapNotNull { index ->
        val row = this?.optJSONObject(index) ?: return@mapNotNull null
        val day = row.optInt("day", -1)
        if (day !in 0..4) return@mapNotNull null
        val entries = row.optJSONArray("entries")
        RoutineDay(day, row.optString("label"), (0 until (entries?.length() ?: 0)).mapNotNull { entryIndex ->
            val entry = entries?.optJSONObject(entryIndex) ?: return@mapNotNull null
            RoutineEditEntry(entry.optString("period_no"), entry.optString("subject"),
                entry.optString("teacher_mobile"), entry.optString("room"))
        })
    }

private fun JSONArray?.toExamSubjects(): List<ExamSubject> =
    (0 until (this?.length() ?: 0)).mapNotNull { index ->
        val row = this?.optJSONObject(index) ?: return@mapNotNull null
        val id = row.optInt("id")
        if (id < 1) null else ExamSubject(id, row.optString("name"), row.optInt("full_marks", 100))
    }

private fun JSONArray?.toExamEntries(): List<ExamEntry> =
    (0 until (this?.length() ?: 0)).mapNotNull { index ->
        val row = this?.optJSONObject(index) ?: return@mapNotNull null
        val id = row.optInt("id")
        if (id < 1) null else ExamEntry(id, row.optString("exam_date"), row.optString("subject"),
            row.optInt("class_subject_id"), row.optString("start_time"), row.optString("end_time"),
            row.optString("room"), row.optString("full_marks"), row.optInt("sort_order"))
    }

private fun JSONArray?.toPublicationExams(): List<PublicationExam> =
    (0 until (this?.length() ?: 0)).mapNotNull { index ->
        val row = this?.optJSONObject(index) ?: return@mapNotNull null
        val id = row.optInt("id")
        if (id < 1) null else PublicationExam(id, row.optString("name"), row.optBoolean("active"),
            row.optBoolean("published"))
    }

private fun JSONArray.toResultStudents(): List<ResultStudent> =
    (0 until length()).mapNotNull { index ->
        val row = optJSONObject(index) ?: return@mapNotNull null
        ResultStudent(
            roll = row.optString("roll"),
            name = row.optString("name"),
            mcq = row.resultValue("mcq"),
            cq = row.resultValue("cq"),
            practical = row.resultValue("practical"),
            total = row.resultValue("total"),
            grade = row.resultValue("grade"),
        )
    }

private fun JSONObject.resultValue(key: String): String {
    val value = opt(key)
    if (value == null || value === JSONObject.NULL) return "—"
    val text = value.toString()
    return text.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: text
}

private fun JSONObject.optBooleanValue(name: String): Boolean = when (val value = opt(name)) {
    is Boolean -> value
    is Number -> value.toInt() != 0
    is String -> value == "1" || value.equals("true", ignoreCase = true)
    else -> false
}

private fun String.isValidAdminDate(): Boolean = try {
    matches(Regex("^\\d{4}-\\d{2}-\\d{2}$")) && LocalDate.parse(this).toString() == this
} catch (_: Exception) {
    false
}
