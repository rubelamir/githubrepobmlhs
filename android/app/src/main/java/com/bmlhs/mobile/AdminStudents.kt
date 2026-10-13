package com.bmlhs.mobile

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.bmlhs.mobile.data.ApiClient
import com.bmlhs.mobile.data.ApiException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException

private const val ADMIN_STUDENT_PAGE_SIZE = 50

private data class AdminStudentRow(
    val id: Int,
    val nameEnglish: String,
    val nameBangla: String,
    val classNameEnglish: String,
    val classNameBangla: String,
    val sectionEnglish: String,
    val sectionBangla: String,
    val roll: String,
    val session: String,
    val mobile: String,
    val status: Int,
)

private val adminStudentEditableFields = listOf(
    "name_ban" to "শিক্ষার্থীর নাম (বাংলা)",
    "name_eng" to "Student name (English)",
    "fname_ban" to "পিতার নাম (বাংলা)",
    "fname_eng" to "Father's name (English)",
    "father_nid_no" to "পিতার NID",
    "mname_ban" to "মাতার নাম (বাংলা)",
    "mname_eng" to "Mother's name (English)",
    "mother_nid_no" to "মাতার NID",
    "gender" to "Gender",
    "religion" to "Religion",
    "birth_date" to "জন্মতারিখ (YYYY-MM-DD)",
    "birth_reg_no" to "জন্মনিবন্ধন নম্বর",
    "mobile" to "মোবাইল",
    "post_office" to "ডাকঘর",
    "post_office_en" to "Post office (English)",
    "village" to "গ্রাম",
    "village_en" to "Village (English)",
    "house_name" to "বাড়ি/হাউসের নাম",
    "last_class" to "সর্বশেষ পড়া শ্রেণি",
    "exam_roll" to "পরীক্ষার রোল",
    "passing_year" to "পাসের বছর",
    "scholarship_stipend_info" to "বৃত্তি/উপবৃত্তির তথ্য",
    "last_school_name" to "সর্বশেষ বিদ্যালয়ের নাম",
)

@Composable
internal fun AdminStudents(api: ApiClient, token: String, allowedModules: Set<String>) {
    val scope = rememberCoroutineScope()
    var students by remember { mutableStateOf<List<AdminStudentRow>>(emptyList()) }
    var search by remember { mutableStateOf("") }
    var sessionYear by remember { mutableStateOf("") }
    var statusFilter by remember { mutableStateOf("") }
    var statusMenu by remember { mutableStateOf(false) }
    var appliedSearch by remember { mutableStateOf("") }
    var appliedSession by remember { mutableStateOf("") }
    var appliedStatus by remember { mutableStateOf("") }
    var showingCandidates by remember { mutableStateOf(false) }
    var showingApplications by remember { mutableStateOf(false) }
    var offset by remember { mutableStateOf(0) }
    var hasMore by remember { mutableStateOf(false) }
    var selectedId by remember { mutableStateOf<Int?>(null) }
    var promotionStudent by remember { mutableStateOf<AdminStudentRow?>(null) }
    var admissionStudent by remember { mutableStateOf<AdminStudentRow?>(null) }
    var pendingAdmissionAction by remember { mutableStateOf<Pair<Int, Boolean>?>(null) }
    var createStudentDialog by remember { mutableStateOf(false) }
    var resetPasswordStudentId by remember { mutableStateOf<Int?>(null) }
    var detail by remember { mutableStateOf<JSONObject?>(null) }
    var draft by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var editing by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }

    suspend fun loadPage(
        pageOffset: Int,
        query: String,
        status: String,
        session: String,
        candidates: Boolean = false,
        applications: Boolean = false,
    ) {
        val requiredModule = if (candidates) "promotions" else "students"
        require(requiredModule in allowedModules) {
            "The $requiredModule module is not enabled for this screen."
        }
        val params = linkedMapOf(
            "action" to when {
                candidates -> "promotion_candidates"
                applications -> "admission_applications"
                else -> "list"
            },
            "limit" to ADMIN_STUDENT_PAGE_SIZE.toString(),
            "offset" to pageOffset.toString(),
        )
        if (!candidates && !applications && query.isNotBlank()) params["q"] = query.trim()
        if (!candidates && !applications && status.isNotBlank()) params["status"] = status
        if (!candidates && !applications && session.isNotBlank()) params["session_year"] = session.trim()
        val response = withContext(Dispatchers.IO) {
            api.adminRequest(token, "admin/students.php", query = params)
        }
        val data = response.getJSONObject("data")
        val rows = data.getJSONArray("students")
        students = List(rows.length()) { index -> parseAdminStudentRow(rows.getJSONObject(index)) }
        hasMore = data.getJSONObject("pagination").getBoolean("has_more")
        offset = pageOffset
    }

    suspend fun loadStudent(studentId: Int) {
        require("students" in allowedModules) {
            "The students module is not enabled for this screen."
        }
        val response = withContext(Dispatchers.IO) {
            api.adminRequest(
                token,
                "admin/students.php",
                query = mapOf("action" to "detail", "id" to studentId.toString()),
            )
        }
        val student = response.getJSONObject("data").getJSONObject("student")
        detail = student
        draft = adminStudentEditableFields
            .filter { (key, _) -> student.has(key) }
            .associate { (key, _) -> key to student.nullableText(key) }
        editing = false
    }

    LaunchedEffect(token, allowedModules) {
        busy = true
        error = ""
        if ("students" !in allowedModules && "promotions" !in allowedModules) {
            students = emptyList()
            detail = null
            selectedId = null
            busy = false
            return@LaunchedEffect
        }
        try {
            showingCandidates = "students" !in allowedModules
            if (showingCandidates) {
                selectedId = null
                detail = null
                editing = false
            }
            loadPage(
                0,
                appliedSearch,
                appliedStatus,
                appliedSession,
                candidates = showingCandidates,
                applications = showingApplications,
            )
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "শিক্ষার্থীর তালিকায় সংযোগ করা যায়নি। আবার চেষ্টা করুন।"
        } catch (exception: JSONException) {
            error = "শিক্ষার্থীর তালিকার সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    fun requestPage(
        pageOffset: Int,
        query: String,
        status: String,
        session: String,
        candidates: Boolean = showingCandidates,
        applications: Boolean = showingApplications,
    ) {
        if (busy) return
        busy = true
        error = ""
        message = ""
        scope.launch {
            try {
                loadPage(pageOffset, query, status, session, candidates, applications)
                showingCandidates = candidates
                showingApplications = applications
                if (pageOffset == 0 && !candidates && !applications) {
                    appliedSearch = query
                    appliedStatus = status
                    appliedSession = session
                }
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "শিক্ষার্থীর তালিকায় সংযোগ করা যায়নি। আবার চেষ্টা করুন।"
            } catch (exception: JSONException) {
                error = "শিক্ষার্থীর তালিকার সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    fun openStudent(studentId: Int) {
        if (busy || "students" !in allowedModules) return
        selectedId = studentId
        detail = null
        draft = emptyMap()
        editing = false
        error = ""
        message = ""
        busy = true
        scope.launch {
            try {
                loadStudent(studentId)
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "শিক্ষার্থীর বিস্তারিত তথ্য লোড করা যায়নি। আবার চেষ্টা করুন।"
            } catch (exception: JSONException) {
                error = "শিক্ষার্থীর বিস্তারিত তথ্যের সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    fun saveStudent() {
        if ("students" !in allowedModules) return
        val studentId = selectedId ?: return
        val original = detail ?: return
        val changed = JSONObject()
        adminStudentEditableFields.forEach { (key, _) ->
            if (draft.containsKey(key) && draft[key] != original.nullableText(key)) {
                changed.put(key, draft.getValue(key).trim())
            }
        }
        if (changed.length() == 0 || busy) return
        busy = true
        error = ""
        message = ""
        scope.launch {
            try {
                val payload = JSONObject()
                    .put("action", "update")
                    .put("id", studentId)
                    .put("fields", changed)
                withContext(Dispatchers.IO) {
                    api.adminRequest(token, "admin/students.php", method = "POST", payload = payload)
                }
                loadStudent(studentId)
                val confirmedStudent = detail
                    ?: throw JSONException("The updated student could not be read back from the server.")
                val verified = changed.keys().asSequence().all { field ->
                    confirmedStudent.nullableText(field) == changed.optString(field)
                }
                if (!verified) {
                    throw ApiException(502, "সার্ভার অনুরোধ গ্রহণ করেছে, কিন্তু শিক্ষার্থীর পরিবর্তিত তথ্য পুনরায় পড়ে মেলেনি। তালিকা refresh করে যাচাই করুন।")
                }
                loadPage(offset, appliedSearch, appliedStatus, appliedSession)
                message = "শিক্ষার্থীর তথ্য সংরক্ষণ হয়েছে।"
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "তথ্য সংরক্ষণে সার্ভারের সাথে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "শিক্ষার্থীর তথ্যের সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("শিক্ষার্থী ব্যবস্থাপনা", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            val canManageStudents = "students" in allowedModules
            val canManagePromotions = "promotions" in allowedModules

            if (canManageStudents && canManagePromotions) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (!showingCandidates && !showingApplications) {
                        Button(enabled = false, onClick = {}) { Text("শিক্ষার্থী") }
                    } else {
                        OutlinedButton(
                            enabled = !busy,
                            onClick = {
                                selectedId = null
                                detail = null
                                requestPage(
                                    0,
                                    appliedSearch,
                                    appliedStatus,
                                    appliedSession,
                                    candidates = false,
                                    applications = false,
                                )
                            },
                        ) { Text("শিক্ষার্থী") }
                    }
                    if (showingCandidates) {
                        Button(enabled = false, onClick = {}) { Text("উন্নয়ন প্রার্থী") }
                    } else {
                        OutlinedButton(
                            enabled = !busy,
                            onClick = {
                                selectedId = null
                                detail = null
                                requestPage(0, "", "", "", candidates = true, applications = false)
                            },
                        ) { Text("উন্নয়ন প্রার্থী") }
                    }
                    if (showingApplications) {
                        Button(enabled = false, onClick = {}) { Text("ভর্তি আবেদন") }
                    } else {
                        OutlinedButton(
                            enabled = !busy,
                            onClick = {
                                selectedId = null
                                detail = null
                                requestPage(0, "", "", "", candidates = false, applications = true)
                            },
                        ) { Text("ভর্তি আবেদন") }
                    }
                }
            } else if (canManageStudents && !canManagePromotions) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (!showingApplications) {
                        Button(enabled = false, onClick = {}) { Text("শিক্ষার্থী") }
                    } else {
                        OutlinedButton(enabled = !busy, onClick = {
                            requestPage(0, appliedSearch, appliedStatus, appliedSession, applications = false)
                        }) { Text("শিক্ষার্থী") }
                    }
                    if (showingApplications) {
                        Button(enabled = false, onClick = {}) { Text("ভর্তি আবেদন") }
                    } else {
                        OutlinedButton(enabled = !busy, onClick = {
                            selectedId = null
                            detail = null
                            requestPage(0, "", "", "", applications = true)
                        }) { Text("ভর্তি আবেদন") }
                    }
                }
            }

            if (canManageStudents && !showingCandidates && !showingApplications) {
                Text("তালিকাটি একবারে সর্বোচ্চ $ADMIN_STUDENT_PAGE_SIZE জন দেখায়। ভর্তি-অবস্থা, নাম, রোল বা মোবাইল দিয়ে খুঁজুন।")
                OutlinedTextField(
                    value = search,
                    onValueChange = { search = it.take(80) },
                    label = { Text("নাম, রোল বা মোবাইল") },
                    singleLine = true,
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = sessionYear,
                    onValueChange = { sessionYear = it.filter(Char::isDigit).take(4) },
                    label = { Text("Session year (ঐচ্ছিক, YYYY)") },
                    singleLine = true,
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Column {
                        OutlinedButton(enabled = !busy, onClick = { statusMenu = true }) {
                            Text(statusFilterLabel(statusFilter))
                        }
                        DropdownMenu(expanded = statusMenu, onDismissRequest = { statusMenu = false }) {
                            val options = listOf("" to "Default: admitted", "all" to "All statuses") +
                                (0..4).map { it.toString() to "Status $it" }
                            options.forEach { (value, label) ->
                                DropdownMenuItem(
                                    text = { Text(label) },
                                    onClick = {
                                        statusFilter = value
                                        statusMenu = false
                                    },
                                )
                            }
                        }
                    }
                    Button(
                        enabled = !busy && (
                            sessionYear.isBlank() ||
                                (sessionYear.length == 4 &&
                                    sessionYear.toIntOrNull()?.let { it in 2000..2199 } == true)
                            ),
                        onClick = {
                            requestPage(0, search.trim(), statusFilter, sessionYear.trim(), candidates = false)
                        },
                    ) { Text("Filter") }
                }
            } else if (showingCandidates && canManagePromotions) {
                Text("প্রতিটি শিক্ষার্থীর জন্য API যাচাইকৃত পরের শ্রেণি/শাখা ও SSC ব্যাচের বিকল্প দেখায়।")
            } else if (showingApplications && canManageStudents) {
                Text("অনলাইন আবেদন যাচাই করুন; অনুমোদনের সময় সক্রিয় শাখা, সেশন ও রোল নির্ধারণ করতে হবে।")
            } else if (!canManageStudents && !canManagePromotions) {
                Text("এই সেশনে শিক্ষার্থী বা উন্নয়ন ব্যবস্থাপনার অনুমতি নেই।")
            } else if (!canManageStudents) {
                Text("শিক্ষার্থী তালিকা/সম্পাদনার অনুমতি নেই।")
            }

            if (canManageStudents && !showingCandidates && !showingApplications) {
                OutlinedButton(
                    enabled = !busy,
                    onClick = {
                        error = ""
                        createStudentDialog = true
                    },
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("নতুন শিক্ষার্থী ভর্তি") }
            }

            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            if (message.isNotBlank()) Text(message, color = MaterialTheme.colorScheme.primary)
            if (busy) CircularProgressIndicator()

            if (selectedId != null && canManageStudents && !showingCandidates && !showingApplications) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(
                        enabled = !busy,
                        onClick = {
                            selectedId = null
                            detail = null
                            editing = false
                            error = ""
                            message = ""
                        },
                    ) { Text("তালিকায় ফিরুন") }
                    if (detail != null && !editing) {
                        Button(enabled = !busy, onClick = { editing = true }) { Text("তথ্য সম্পাদনা") }
                        OutlinedButton(
                            enabled = !busy,
                            onClick = { resetPasswordStudentId = selectedId },
                        ) { Text("পাসওয়ার্ড রিসেট") }
                        if (detail?.optInt("status") == 2) {
                            OutlinedButton(
                                enabled = !busy,
                                onClick = { selectedId?.let { pendingAdmissionAction = it to false } },
                            ) { Text("আবেদন পুনরায় চালু") }
                        }
                    }
                }
                detail?.let { student ->
                    Text(
                        "${student.displayName()} · ID ${student.getInt("id")}",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                    )
                    Text(
                        "${student.nullableText("class_name")} ${student.nullableText("section_name")} · " +
                            "Roll ${student.nullableText("cls_roll")} · Session ${student.nullableText("session_year")}",
                    )
                    if (editing) {
                        adminStudentEditableFields
                            .filter { (key, _) -> draft.containsKey(key) }
                            .forEach { (key, label) ->
                                when (key) {
                                    "gender" -> StudentChoiceField(
                                        label = label,
                                        value = draft.getValue(key),
                                        choices = listOf("male" to "Male", "female" to "Female"),
                                        enabled = !busy,
                                        onValueChange = { draft = draft + (key to it) },
                                    )
                                    "religion" -> StudentChoiceField(
                                        label = label,
                                        value = draft.getValue(key),
                                        choices = listOf(
                                            "" to "Not specified",
                                            "Islam" to "Islam",
                                            "Hindu" to "Hindu",
                                            "Buddhist" to "Buddhist",
                                            "Christian" to "Christian",
                                        ),
                                        enabled = !busy,
                                        onValueChange = { draft = draft + (key to it) },
                                    )
                                    else -> OutlinedTextField(
                                        value = draft.getValue(key),
                                        onValueChange = { draft = draft + (key to it.take(255)) },
                                        label = { Text(label) },
                                        singleLine = key != "scholarship_stipend_info" &&
                                            key != "last_school_name",
                                        enabled = !busy,
                                        modifier = Modifier.fillMaxWidth(),
                                    )
                                }
                            }
                        Button(
                            enabled = !busy && adminStudentEditableFields.any { (key, _) ->
                                draft.containsKey(key) && draft[key] != student.nullableText(key)
                            },
                            onClick = ::saveStudent,
                            modifier = Modifier.fillMaxWidth(),
                        ) { Text(if (busy) "অপেক্ষা করুন…" else "পরিবর্তন সংরক্ষণ") }
                        OutlinedButton(
                            enabled = !busy,
                            onClick = {
                                draft = adminStudentEditableFields
                                    .filter { (key, _) -> student.has(key) }
                                    .associate { (key, _) -> key to student.nullableText(key) }
                                editing = false
                            },
                            modifier = Modifier.fillMaxWidth(),
                        ) { Text("বাতিল") }
                    } else {
                        adminStudentEditableFields
                            .filter { (key, _) -> student.has(key) }
                            .forEach { (key, label) ->
                                Text("$label: ${student.nullableText(key)}")
                            }
                    }
                }
            } else if ((showingCandidates && canManagePromotions) ||
                ((!showingCandidates || showingApplications) && canManageStudents)
            ) {
                if (students.isEmpty() && !busy) {
                    Text("এই ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                students.forEach { student ->
                    Card(modifier = Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text(student.displayName(), fontWeight = FontWeight.SemiBold)
                            Text(
                                if (showingApplications) {
                                    "আবেদনকৃত ${student.classLabel()} · Session ${student.session}"
                                } else {
                                    "${student.classLabel()} · Roll ${student.roll} · Session ${student.session}"
                                },
                            )
                            if (student.mobile.isNotBlank()) Text("মোবাইল: ${student.mobile}")
                            Text("Status ${student.status}", style = MaterialTheme.typography.bodySmall)
                            if (!showingCandidates && canManageStudents) {
                                TextButton(enabled = !busy, onClick = { openStudent(student.id) }) {
                                    Text("বিস্তারিত ও সম্পাদনা")
                                }
                            } else if (showingCandidates && canManagePromotions) {
                                TextButton(enabled = !busy, onClick = { promotionStudent = student }) {
                                    Text("উন্নয়ন করুন")
                                }
                            } else if (showingApplications && canManageStudents) {
                                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                    TextButton(enabled = !busy, onClick = { admissionStudent = student }) {
                                        Text("যাচাই / অনুমোদন")
                                    }
                                    TextButton(
                                        enabled = !busy,
                                        onClick = { pendingAdmissionAction = student.id to true },
                                    ) { Text("প্রত্যাখ্যান") }
                                }
                            }
                        }
                    }
                }
                if (students.isNotEmpty()) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        OutlinedButton(
                            enabled = !busy && offset > 0,
                            onClick = {
                                requestPage(
                                    (offset - ADMIN_STUDENT_PAGE_SIZE).coerceAtLeast(0),
                                    appliedSearch,
                                    appliedStatus,
                                    appliedSession,
                                    candidates = showingCandidates,
                                    applications = showingApplications,
                                )
                            },
                        ) { Text("আগের পৃষ্ঠা") }
                        Text("পৃষ্ঠা ${offset / ADMIN_STUDENT_PAGE_SIZE + 1}")
                        OutlinedButton(
                            enabled = !busy && hasMore && offset + ADMIN_STUDENT_PAGE_SIZE <= 100_000,
                            onClick = {
                                requestPage(
                                    offset + ADMIN_STUDENT_PAGE_SIZE,
                                    appliedSearch,
                                    appliedStatus,
                                    appliedSession,
                                    candidates = showingCandidates,
                                    applications = showingApplications,
                                )
                            },
                        ) { Text("পরের পৃষ্ঠা") }
                    }
                    if (hasMore && offset + ADMIN_STUDENT_PAGE_SIZE > 100_000) {
                        Text(
                            "API তালিকা-সীমায় পৌঁছেছে; এর পরের পৃষ্ঠা দেখানো যাবে না।",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
            if (canManageStudents || canManagePromotions) {
                Text(
                    "শিক্ষার্থী মোছার সুবিধা নেই; ভর্তি ও একাডেমিক ইতিহাস সুরক্ষিত রাখা হয়।",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
    promotionStudent?.let { student ->
        AdminStudentPromotionDialog(
            api = api,
            token = token,
            student = student,
            onDismiss = { promotionStudent = null },
            onSuccess = {
                promotionStudent = null
                requestPage(0, "", "", "", candidates = true)
                message = "শিক্ষার্থীর শ্রেণি-উন্নয়ন সম্পন্ন হয়েছে।"
            },
        )
    }
    admissionStudent?.let { student ->
        AdminAdmissionApprovalDialog(
            api = api,
            token = token,
            student = student,
            onDismiss = { admissionStudent = null },
            onSuccess = {
                admissionStudent = null
                requestPage(0, "", "", "", applications = true)
                message = "অনলাইন ভর্তি আবেদন অনুমোদিত হয়েছে।"
            },
        )
    }
    if (createStudentDialog && "students" in allowedModules) {
        AdminStudentCreateDialog(
            api = api,
            token = token,
            onDismiss = { createStudentDialog = false },
            onSuccess = {
                createStudentDialog = false
                selectedId = null
                detail = null
                requestPage(0, appliedSearch, appliedStatus, appliedSession, candidates = false, applications = false)
                message = "নতুন শিক্ষার্থী ভর্তি হয়েছে।"
            },
        )
    }
    resetPasswordStudentId?.let { studentId ->
        AdminStudentPasswordResetDialog(
            api = api,
            token = token,
            studentId = studentId,
            onDismiss = { resetPasswordStudentId = null },
            onSuccess = {
                resetPasswordStudentId = null
                message = "শিক্ষার্থীর পাসওয়ার্ড পরিবর্তন হয়েছে; পুরোনো লগইন সেশনগুলো বন্ধ করা হয়েছে।"
            },
        )
    }
    pendingAdmissionAction?.let { (studentId, rejecting) ->
        AdminAdmissionStatusDialog(
            api = api,
            token = token,
            studentId = studentId,
            rejecting = rejecting,
            onDismiss = { pendingAdmissionAction = null },
            onSuccess = {
                pendingAdmissionAction = null
                if (rejecting) {
                    requestPage(0, "", "", "", applications = true)
                    message = "ভর্তি আবেদন প্রত্যাখ্যান করা হয়েছে। Status 2 তালিকা থেকে আবার চালু করা যাবে।"
                } else {
                    requestPage(0, "", "", "", applications = true)
                    message = "ভর্তি আবেদন আবার অপেক্ষমাণ করা হয়েছে।"
                }
            },
        )
    }
}

private data class PromotionDestination(
    val id: Int,
    val label: String,
    val sections: List<Pair<String, String>>,
)

@Composable
private fun AdminStudentPromotionDialog(
    api: ApiClient,
    token: String,
    student: AdminStudentRow,
    onDismiss: () -> Unit,
    onSuccess: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var modes by remember { mutableStateOf<List<String>>(emptyList()) }
    var destinations by remember { mutableStateOf<List<PromotionDestination>>(emptyList()) }
    var mode by remember { mutableStateOf("") }
    var section by remember { mutableStateOf("") }
    var session by remember { mutableStateOf("") }
    var roll by remember { mutableStateOf(student.roll) }
    var batchYear by remember { mutableStateOf("") }
    var remarks by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var loaded by remember { mutableStateOf(false) }

    LaunchedEffect(token, student.id) {
        busy = true
        try {
            val response = withContext(Dispatchers.IO) {
                api.adminRequest(
                    token,
                    "admin/students.php",
                    query = mapOf("action" to "promotion_options", "id" to student.id.toString()),
                )
            }
            val options = response.getJSONObject("data").getJSONObject("promotion_options")
            val modeJson = options.getJSONArray("modes")
            modes = List(modeJson.length()) { modeJson.getString(it) }
            mode = modes.firstOrNull().orEmpty()
            val destinationJson = options.getJSONArray("destinations")
            destinations = List(destinationJson.length()) { index ->
                val destination = destinationJson.getJSONObject(index)
                val sectionsJson = destination.getJSONArray("sections")
                val classNameBn = if (destination.isNull("name_ban")) "" else destination.optString("name_ban")
                PromotionDestination(
                    id = destination.getInt("id"),
                    label = classNameBn.ifBlank { destination.getString("name") },
                    sections = List(sectionsJson.length()) { sectionIndex ->
                        val sectionOption = sectionsJson.getJSONObject(sectionIndex)
                        val sectionNameBn =
                            if (sectionOption.isNull("section_name_ban")) "" else sectionOption.optString("section_name_ban")
                        sectionOption.getInt("id").toString() to
                            sectionNameBn.ifBlank {
                                sectionOption.getString("section_name")
                            }
                    },
                )
            }
            val currentYear = java.time.Year.now().value
            val sourceYear = maxOf(
                student.session.toIntOrNull() ?: currentYear,
                options.optString("ssc_batch_year").toIntOrNull() ?: currentYear,
            )
            session = maxOf(currentYear, sourceYear + 1).toString()
            batchYear = maxOf(currentYear, sourceYear).toString()
            section = destinations.firstOrNull()?.sections?.firstOrNull()?.first.orEmpty()
            loaded = true
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "উন্নয়নের বিকল্প লোড করা যায়নি।"
        } catch (exception: JSONException) {
            error = "উন্নয়নের বিকল্পের সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    val destination = destinations.firstOrNull()
    val validYear = session.toIntOrNull()?.let { it in 2000..2199 } == true
    val validRoll = roll.toLongOrNull()?.let { it > 0 && it <= Int.MAX_VALUE.toLong() } == true
    val canSubmit = loaded && !busy && mode in modes && when (mode) {
        "class", "ssc_return" -> destination != null && section in destination.sections.map { it.first } &&
            validYear && validRoll
        "ssc" -> batchYear.toIntOrNull()?.let { it in 2000..2199 } == true
        else -> false
    }

    fun submit() {
        if (!canSubmit) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject()
                    .put("action", "promote")
                    .put("id", student.id)
                    .put("mode", mode)
                    .put("remarks", remarks.trim())
                when (mode) {
                    "class", "ssc_return" -> payload
                        .put("to_class", destination!!.id)
                        .put("to_section", section.toInt())
                        .put("to_session", session)
                        .put("to_roll", roll.toInt())
                    "ssc" -> payload.put("ssc_batch_year", batchYear)
                }
                withContext(Dispatchers.IO) {
                    api.adminRequest(token, "admin/students.php", method = "POST", payload = payload)
                }
                onSuccess()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "শ্রেণি-উন্নয়ন সংরক্ষণে সার্ভারে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "উন্নয়নের সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("শিক্ষার্থীর শ্রেণি-উন্নয়ন") },
        text = {
            Column(
                modifier = Modifier.verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text("${student.displayName()} · ${student.classLabel()} · Roll ${student.roll}")
                if (busy && !loaded) CircularProgressIndicator()
                if (modes.size > 1) {
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        modes.forEach { availableMode ->
                            val label = when (availableMode) {
                                "class" -> "পরের শ্রেণি"
                                "ssc" -> "SSC ব্যাচ"
                                else -> "Class 10-এ ফেরত"
                            }
                            if (mode == availableMode) {
                                Button(enabled = !busy, onClick = { mode = availableMode }) { Text(label) }
                            } else {
                                OutlinedButton(enabled = !busy, onClick = { mode = availableMode }) { Text(label) }
                            }
                        }
                    }
                } else if (mode.isNotBlank()) {
                    Text(
                        when (mode) {
                            "class" -> "পরের শ্রেণিতে উন্নীত হবে।"
                            "ssc" -> "SSC candidate batch-এ স্থানান্তর হবে।"
                            else -> "শিক্ষার্থী Class 10-এ ফেরত যাবে।"
                        },
                    )
                }
                if (mode == "ssc") {
                    OutlinedTextField(
                        batchYear,
                        { batchYear = it.filter(Char::isDigit).take(4) },
                        label = { Text("SSC ব্যাচ/পাসের বছর") },
                        enabled = !busy,
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                } else if (mode == "class" || mode == "ssc_return") {
                    Text("গন্তব্য শ্রেণি: ${destination?.label ?: "উপলভ্য নয়"}")
                    destination?.let { target ->
                        StudentChoiceField(
                            label = "গন্তব্য শাখা",
                            value = section,
                            choices = target.sections,
                            enabled = !busy,
                            onValueChange = { section = it },
                        )
                    }
                    OutlinedTextField(
                        session,
                        { session = it.filter(Char::isDigit).take(4) },
                        label = { Text("নতুন সেশন (YYYY)") },
                        enabled = !busy,
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(
                        roll,
                        { roll = it.filter(Char::isDigit).take(10) },
                        label = { Text("নতুন রোল") },
                        enabled = !busy,
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
                OutlinedTextField(
                    remarks,
                    { remarks = it.take(255) },
                    label = { Text("মন্তব্য (ঐচ্ছিক)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
                if (loaded && modes.isEmpty()) Text("এই শিক্ষার্থীর জন্য কোনো বৈধ উন্নয়ন বিকল্প নেই।")
            }
        },
        confirmButton = {
            Button(enabled = canSubmit, onClick = ::submit) {
                Text(if (busy) "অপেক্ষা করুন…" else "উন্নয়ন নিশ্চিত করুন")
            }
        },
        dismissButton = {
            OutlinedButton(enabled = !busy, onClick = onDismiss) { Text("বাতিল") }
        },
    )
}

@Composable
private fun AdminStudentCreateDialog(
    api: ApiClient,
    token: String,
    onDismiss: () -> Unit,
    onSuccess: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var classes by remember { mutableStateOf<List<NewStudentClassOption>>(emptyList()) }
    var selectedClass by remember { mutableStateOf("") }
    var selectedSection by remember { mutableStateOf("") }
    var session by remember { mutableStateOf(java.time.Year.now().value.toString()) }
    var roll by remember { mutableStateOf("") }
    var nameBangla by remember { mutableStateOf("") }
    var nameEnglish by remember { mutableStateOf("") }
    var fatherBangla by remember { mutableStateOf("") }
    var fatherEnglish by remember { mutableStateOf("") }
    var motherBangla by remember { mutableStateOf("") }
    var motherEnglish by remember { mutableStateOf("") }
    var gender by remember { mutableStateOf("") }
    var religion by remember { mutableStateOf("") }
    var birthDate by remember { mutableStateOf("") }
    var birthRegistration by remember { mutableStateOf("") }
    var village by remember { mutableStateOf("") }
    var villageEnglish by remember { mutableStateOf("") }
    var postOffice by remember { mutableStateOf("") }
    var postOfficeEnglish by remember { mutableStateOf("") }
    var houseName by remember { mutableStateOf("") }
    var lastClass by remember { mutableStateOf("") }
    var examRoll by remember { mutableStateOf("") }
    var passingYear by remember { mutableStateOf("") }
    var stipendInfo by remember { mutableStateOf("") }
    var lastSchoolName by remember { mutableStateOf("") }
    var mobile by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var loaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var rollAvailability by remember { mutableStateOf<Boolean?>(null) }
    var checkingRoll by remember { mutableStateOf(false) }
    var rollCheckError by remember { mutableStateOf("") }

    LaunchedEffect(token) {
        busy = true
        try {
            val response = withContext(Dispatchers.IO) {
                api.adminRequest(
                    token,
                    "admin/students.php",
                    query = mapOf("action" to "create_options"),
                )
            }
            val classJson = response.getJSONObject("data").getJSONArray("classes")
            classes = List(classJson.length()) { index ->
                val row = classJson.getJSONObject(index)
                val sectionsJson = row.getJSONArray("sections")
                val sections = List(sectionsJson.length()) { sectionIndex ->
                    val section = sectionsJson.getJSONObject(sectionIndex)
                    val banglaName = if (section.isNull("section_name_ban")) "" else section.optString("section_name_ban")
                    section.getInt("id").toString() to banglaName.ifBlank { section.getString("section_name") }
                }
                val banglaName = if (row.isNull("name_ban")) "" else row.optString("name_ban")
                NewStudentClassOption(
                    id = row.getInt("id"),
                    label = banglaName.ifBlank { row.getString("name") },
                    sections = sections,
                )
            }
            selectedClass = classes.firstOrNull { it.sections.isNotEmpty() }?.id?.toString().orEmpty()
            selectedSection = classes.firstOrNull { it.id.toString() == selectedClass }
                ?.sections?.firstOrNull()?.first.orEmpty()
            loaded = true
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "ভর্তির জন্য শ্রেণি ও শাখার তালিকা লোড করা যায়নি।"
        } catch (exception: JSONException) {
            error = "শ্রেণি/শাখার সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    val activeClass = classes.firstOrNull { it.id.toString() == selectedClass }
    val validSession = session.toIntOrNull()?.let { it in 2000..2199 } == true
    val validRoll = roll.toLongOrNull()?.let { it > 0 && it <= Int.MAX_VALUE.toLong() } == true
    val validBirthDate = birthDate.isBlank() || birthDate.matches(Regex("\\d{4}-\\d{2}-\\d{2}"))
    val canCreate = loaded && !busy && activeClass?.sections?.any { it.first == selectedSection } == true &&
        validSession && validRoll && rollAvailability == true && !checkingRoll &&
        nameBangla.isNotBlank() && nameEnglish.isNotBlank() &&
        fatherBangla.isNotBlank() && fatherEnglish.isNotBlank() && gender in listOf("male", "female") &&
        mobile.count { it.isDigit() } >= 6 && password.length in 8..1024 && validBirthDate

    LaunchedEffect(token, selectedClass, selectedSection, session, roll) {
        rollAvailability = null
        rollCheckError = ""
        val classId = selectedClass.toIntOrNull()
        val sectionId = selectedSection.toIntOrNull()
        if (
            classId == null || sectionId == null || !validSession || !validRoll ||
            activeClass?.sections?.none { it.first == selectedSection } != false
        ) {
            checkingRoll = false
            return@LaunchedEffect
        }
        checkingRoll = true
        delay(350)
        try {
            val response = withContext(Dispatchers.IO) {
                api.adminRequest(
                    token,
                    "admin/students.php",
                    query = mapOf(
                        "action" to "roll_check",
                        "class_id" to classId.toString(),
                        "section_id" to sectionId.toString(),
                        "session_year" to session,
                        "roll" to roll,
                    ),
                )
            }
            rollAvailability = response.getJSONObject("data").getBoolean("available")
        } catch (exception: ApiException) {
            rollCheckError = exception.message
        } catch (exception: IOException) {
            rollCheckError = "রোল যাচাই করতে সার্ভারে সংযোগ করা যায়নি।"
        } catch (exception: JSONException) {
            rollCheckError = "রোল যাচাইয়ের সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            checkingRoll = false
        }
    }

    fun create() {
        if (!canCreate) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject()
                    .put("action", "create")
                    .put("class_id", activeClass!!.id)
                    .put("section_id", selectedSection.toInt())
                    .put("session_year", session)
                    .put("roll", roll)
                    .put("name_ban", nameBangla.trim())
                    .put("name_eng", nameEnglish.trim())
                    .put("fname_ban", fatherBangla.trim())
                    .put("fname_eng", fatherEnglish.trim())
                    .put("mname_ban", motherBangla.trim())
                    .put("mname_eng", motherEnglish.trim())
                    .put("gender", gender)
                    .put("religion", religion)
                    .put("birth_date", birthDate.trim())
                    .put("birth_reg_no", birthRegistration.trim())
                    .put("village", village.trim())
                    .put("village_en", villageEnglish.trim())
                    .put("post_office", postOffice.trim())
                    .put("post_office_en", postOfficeEnglish.trim())
                    .put("house_name", houseName.trim())
                    .put("last_class", lastClass.trim())
                    .put("exam_roll", examRoll.trim())
                    .put("passing_year", passingYear.trim())
                    .put("scholarship_stipend_info", stipendInfo.trim())
                    .put("last_school_name", lastSchoolName.trim())
                    .put("mobile", mobile.trim())
                    .put("password", password)
                withContext(Dispatchers.IO) {
                    api.adminRequest(token, "admin/students.php", method = "POST", payload = payload)
                }
                onSuccess()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "শিক্ষার্থী ভর্তি করতে সার্ভারের সাথে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "ভর্তির সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("নতুন শিক্ষার্থী ভর্তি") },
        text = {
            Column(
                modifier = Modifier.verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text("ভর্তির পর শিক্ষার্থী মোবাইল ও প্রদত্ত প্রাথমিক পাসওয়ার্ড দিয়ে লগইন করতে পারবে।")
                if (!loaded && busy) CircularProgressIndicator()
                StudentChoiceField(
                    label = "শ্রেণি",
                    value = selectedClass,
                    choices = classes.filter { it.sections.isNotEmpty() }.map { it.id.toString() to it.label },
                    enabled = !busy,
                    onValueChange = { classId ->
                        selectedClass = classId
                        selectedSection = classes.firstOrNull { it.id.toString() == classId }
                            ?.sections?.firstOrNull()?.first.orEmpty()
                    },
                )
                StudentChoiceField(
                    label = "শাখা",
                    value = selectedSection,
                    choices = activeClass?.sections.orEmpty(),
                    enabled = !busy,
                    onValueChange = { selectedSection = it },
                )
                OutlinedTextField(
                    session,
                    { session = it.filter(Char::isDigit).take(4) },
                    label = { Text("সেশন (YYYY)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    roll,
                    { roll = it.filter(Char::isDigit).take(10) },
                    label = { Text("রোল") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                when {
                    checkingRoll -> Text("রোলের প্রাপ্যতা যাচাই হচ্ছে…")
                    rollCheckError.isNotBlank() -> Text(rollCheckError, color = MaterialTheme.colorScheme.error)
                    rollAvailability == true -> Text("এই শ্রেণি/শাখা/সেশনে রোলটি খালি আছে।")
                    rollAvailability == false -> Text(
                        "এই শ্রেণি/শাখা/সেশনে রোলটি আগে থেকেই ব্যবহৃত; অন্য রোল দিন।",
                        color = MaterialTheme.colorScheme.error,
                    )
                }
                OutlinedTextField(
                    nameBangla,
                    { nameBangla = it.take(255) },
                    label = { Text("শিক্ষার্থীর নাম (বাংলা) *") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    nameEnglish,
                    { nameEnglish = it.take(255) },
                    label = { Text("Student name (English) *") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    fatherBangla,
                    { fatherBangla = it.take(255) },
                    label = { Text("পিতার নাম (বাংলা) *") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    fatherEnglish,
                    { fatherEnglish = it.take(255) },
                    label = { Text("Father's name (English) *") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    motherBangla,
                    { motherBangla = it.take(255) },
                    label = { Text("মাতার নাম (বাংলা)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    motherEnglish,
                    { motherEnglish = it.take(255) },
                    label = { Text("Mother's name (English)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                StudentChoiceField(
                    label = "Gender",
                    value = gender,
                    choices = listOf("male" to "Male", "female" to "Female"),
                    enabled = !busy,
                    onValueChange = { gender = it },
                )
                StudentChoiceField(
                    label = "ধর্ম (ঐচ্ছিক)",
                    value = religion,
                    choices = listOf(
                        "" to "নির্বাচন করুন",
                        "Islam" to "Islam",
                        "Hindu" to "Hindu",
                        "Buddhist" to "Buddhist",
                        "Christian" to "Christian",
                    ),
                    enabled = !busy,
                    onValueChange = { religion = it },
                )
                OutlinedTextField(
                    birthDate,
                    { birthDate = it.take(10) },
                    label = { Text("জন্মতারিখ (YYYY-MM-DD, ঐচ্ছিক)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    birthRegistration,
                    { birthRegistration = it.take(255) },
                    label = { Text("জন্মনিবন্ধন নম্বর (ঐচ্ছিক)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Text("ঠিকানা (ঐচ্ছিক)", style = MaterialTheme.typography.titleSmall)
                OutlinedTextField(
                    village,
                    { village = it.take(255) },
                    label = { Text("গ্রাম") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    villageEnglish,
                    { villageEnglish = it.take(255) },
                    label = { Text("Village (English)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    postOffice,
                    { postOffice = it.take(255) },
                    label = { Text("ডাকঘর") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    postOfficeEnglish,
                    { postOfficeEnglish = it.take(255) },
                    label = { Text("Post office (English)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    houseName,
                    { houseName = it.take(255) },
                    label = { Text("বাড়ি/হাউসের নাম") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Text("পূর্ববর্তী বিদ্যালয়ের তথ্য (ঐচ্ছিক)", style = MaterialTheme.typography.titleSmall)
                OutlinedTextField(
                    lastClass,
                    { lastClass = it.take(255) },
                    label = { Text("সর্বশেষ পড়া শ্রেণি") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    examRoll,
                    { examRoll = it.take(255) },
                    label = { Text("পরীক্ষার রোল") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    passingYear,
                    { passingYear = it.filter(Char::isDigit).take(4) },
                    label = { Text("পাসের বছর") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    stipendInfo,
                    { stipendInfo = it.take(255) },
                    label = { Text("বৃত্তি/উপবৃত্তির তথ্য") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    lastSchoolName,
                    { lastSchoolName = it.take(255) },
                    label = { Text("সর্বশেষ বিদ্যালয়ের নাম") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    mobile,
                    { mobile = it.take(40) },
                    label = { Text("শিক্ষার্থীর মোবাইল *") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    password,
                    { password = it.take(1024) },
                    label = { Text("প্রাথমিক পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর) *") },
                    enabled = !busy,
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth(),
                )
                if (loaded && classes.none { it.sections.isNotEmpty() }) {
                    Text("কোনো সক্রিয় শ্রেণি/শাখা পাওয়া যায়নি।")
                }
                if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            }
        },
        confirmButton = {
            Button(enabled = canCreate, onClick = ::create) {
                Text(if (busy) "অপেক্ষা করুন…" else "ভর্তি নিশ্চিত করুন")
            }
        },
        dismissButton = { OutlinedButton(enabled = !busy, onClick = onDismiss) { Text("বাতিল") } },
    )
}

@Composable
private fun AdminStudentPasswordResetDialog(
    api: ApiClient,
    token: String,
    studentId: Int,
    onDismiss: () -> Unit,
    onSuccess: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var password by remember { mutableStateOf("") }
    var confirmation by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    val canSubmit = !busy && password.length in 8..1024 && password == confirmation

    fun submit() {
        if (!canSubmit) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject()
                    .put("action", "reset_password")
                    .put("id", studentId)
                    .put("password", password)
                withContext(Dispatchers.IO) {
                    api.adminRequest(token, "admin/students.php", method = "POST", payload = payload)
                }
                onSuccess()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "পাসওয়ার্ড পরিবর্তন করতে সার্ভারে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "পাসওয়ার্ড পরিবর্তনের সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("শিক্ষার্থীর পাসওয়ার্ড রিসেট") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন। পরিবর্তনের পর শিক্ষার্থীর পুরোনো সব অ্যাপ সেশন বন্ধ হবে।")
                OutlinedTextField(
                    password,
                    { password = it.take(1024) },
                    label = { Text("নতুন পাসওয়ার্ড") },
                    enabled = !busy,
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    confirmation,
                    { confirmation = it.take(1024) },
                    label = { Text("নতুন পাসওয়ার্ড আবার লিখুন") },
                    enabled = !busy,
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth(),
                )
                if (confirmation.isNotEmpty() && password != confirmation) {
                    Text("দুটি পাসওয়ার্ড মিলছে না।", color = MaterialTheme.colorScheme.error)
                }
                if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            }
        },
        confirmButton = {
            Button(enabled = canSubmit, onClick = ::submit) {
                Text(if (busy) "অপেক্ষা করুন…" else "পাসওয়ার্ড পরিবর্তন")
            }
        },
        dismissButton = { OutlinedButton(enabled = !busy, onClick = onDismiss) { Text("বাতিল") } },
    )
}

@Composable
private fun AdminAdmissionStatusDialog(
    api: ApiClient,
    token: String,
    studentId: Int,
    rejecting: Boolean,
    onDismiss: () -> Unit,
    onSuccess: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    val action = if (rejecting) "reject_admission" else "restore_admission"

    fun submit() {
        if (busy) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject().put("action", action).put("id", studentId)
                withContext(Dispatchers.IO) {
                    api.adminRequest(token, "admin/students.php", method = "POST", payload = payload)
                }
                onSuccess()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "ভর্তি আবেদনের অবস্থা পরিবর্তনে সার্ভারে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "সার্ভারের উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(if (rejecting) "ভর্তি আবেদন প্রত্যাখ্যান" else "ভর্তি আবেদন পুনরায় চালু") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    if (rejecting) {
                        "আবেদনটি প্রত্যাখ্যান করলে রেকর্ড মুছে যাবে না। পরে Status 2 শিক্ষার্থী তালিকা থেকে আবেদনটি আবার অপেক্ষমাণ করা যাবে।"
                    } else {
                        "আবেদনটি আবার অপেক্ষমাণ হবে; এরপর ভর্তি আবেদন তালিকা থেকে যাচাই ও অনুমোদন করা যাবে।"
                    },
                )
                if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            }
        },
        confirmButton = {
            Button(enabled = !busy, onClick = ::submit) {
                Text(
                    when {
                        busy -> "অপেক্ষা করুন…"
                        rejecting -> "প্রত্যাখ্যান নিশ্চিত করুন"
                        else -> "আবার চালু করুন"
                    },
                )
            }
        },
        dismissButton = { OutlinedButton(enabled = !busy, onClick = onDismiss) { Text("বাতিল") } },
    )
}

private data class AdmissionSection(val id: Int, val label: String)
private data class NewStudentClassOption(
    val id: Int,
    val label: String,
    val sections: List<Pair<String, String>>,
)

@Composable
private fun AdminAdmissionApprovalDialog(
    api: ApiClient,
    token: String,
    student: AdminStudentRow,
    onDismiss: () -> Unit,
    onSuccess: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var sections by remember { mutableStateOf<List<AdmissionSection>>(emptyList()) }
    var selectedSection by remember { mutableStateOf("") }
    var session by remember { mutableStateOf("") }
    var roll by remember { mutableStateOf("") }
    var applicant by remember { mutableStateOf<JSONObject?>(null) }
    var busy by remember { mutableStateOf(false) }
    var loaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }

    LaunchedEffect(token, student.id) {
        busy = true
        try {
            val optionsResult = withContext(Dispatchers.IO) {
                api.adminRequest(
                    token,
                    "admin/students.php",
                    query = mapOf("action" to "admission_options", "id" to student.id.toString()),
                )
            }
            val options = optionsResult.getJSONObject("data").getJSONObject("admission_options")
            val sectionRows = options.getJSONArray("sections")
            sections = List(sectionRows.length()) { index ->
                val row = sectionRows.getJSONObject(index)
                val banglaName = if (row.isNull("section_name_ban")) "" else row.optString("section_name_ban")
                AdmissionSection(
                    row.getInt("id"),
                    banglaName.ifBlank { row.getString("section_name") },
                )
            }
            selectedSection = sections.firstOrNull()?.id?.toString().orEmpty()
            session = options.optString("requested_session")
                .takeIf { it.matches(Regex("(19|20|21)\\d{2}")) }
                ?: java.time.Year.now().value.toString()
            val detailResult = withContext(Dispatchers.IO) {
                api.adminRequest(
                    token,
                    "admin/students.php",
                    query = mapOf("action" to "detail", "id" to student.id.toString()),
                )
            }
            applicant = detailResult.getJSONObject("data").getJSONObject("student")
            loaded = true
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "ভর্তি আবেদনের তথ্য লোড করা যায়নি।"
        } catch (exception: JSONException) {
            error = "ভর্তি আবেদনের সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    val validSession = session.toIntOrNull()?.let { it in 2000..2199 } == true
    val validRoll = roll.toLongOrNull()?.let { it > 0 && it <= Int.MAX_VALUE.toLong() } == true
    val canApprove = loaded && !busy && sections.any { it.id.toString() == selectedSection } &&
        validSession && validRoll

    fun approve() {
        if (!canApprove) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject()
                    .put("action", "approve_admission")
                    .put("id", student.id)
                    .put("section_id", selectedSection.toInt())
                    .put("session_year", session)
                    .put("roll", roll)
                withContext(Dispatchers.IO) {
                    api.adminRequest(token, "admin/students.php", method = "POST", payload = payload)
                }
                onSuccess()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "ভর্তি আবেদন অনুমোদনে সার্ভারে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "অনুমোদনের সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("অনলাইন ভর্তি আবেদন যাচাই") },
        text = {
            Column(
                modifier = Modifier.verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text(student.displayName(), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                Text("আবেদনকৃত শ্রেণি: ${student.classLabel()} · আবেদন সেশন ${student.session}")
                applicant?.let { data ->
                    Text("ইংরেজি নাম: ${data.nullableText("name_eng")}")
                    Text("পিতা: ${data.nullableText("fname_ban")} · ${data.nullableText("fname_eng")}")
                    Text("মাতা: ${data.nullableText("mname_ban")} · ${data.nullableText("mname_eng")}")
                    Text("জন্মতারিখ: ${data.nullableText("birth_date")}")
                    Text("জন্মনিবন্ধন: ${data.nullableText("birth_reg_no")}")
                    Text("মোবাইল: ${data.nullableText("mobile")}")
                }
                StudentChoiceField(
                    label = "ভর্তির শাখা",
                    value = selectedSection,
                    choices = sections.map { it.id.toString() to it.label },
                    enabled = !busy,
                    onValueChange = { selectedSection = it },
                )
                OutlinedTextField(
                    session,
                    { session = it.filter(Char::isDigit).take(4) },
                    label = { Text("ভর্তির সেশন (YYYY)") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    roll,
                    { roll = it.filter(Char::isDigit).take(10) },
                    label = { Text("নির্ধারিত রোল") },
                    enabled = !busy,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                if (sections.isEmpty() && loaded) Text("এই শ্রেণির কোনো সক্রিয় শাখা নেই।")
                if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            }
        },
        confirmButton = {
            Button(enabled = canApprove, onClick = ::approve) {
                Text(if (busy) "যাচাই হচ্ছে…" else "যাচাই করে ভর্তি অনুমোদন")
            }
        },
        dismissButton = { OutlinedButton(enabled = !busy, onClick = onDismiss) { Text("বাতিল") } },
    )
}

@Composable
private fun StudentChoiceField(
    label: String,
    value: String,
    choices: List<Pair<String, String>>,
    enabled: Boolean,
    onValueChange: (String) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    Column {
        Text(label, style = MaterialTheme.typography.labelLarge)
        OutlinedButton(enabled = enabled, onClick = { expanded = true }) {
            Text(choices.firstOrNull { it.first == value }?.second ?: value.ifBlank { "নির্বাচন করুন" })
        }
        DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            choices.forEach { (choice, title) ->
                DropdownMenuItem(
                    text = { Text(title) },
                    onClick = {
                        onValueChange(choice)
                        expanded = false
                    },
                )
            }
        }
    }
}

private fun parseAdminStudentRow(json: JSONObject): AdminStudentRow = AdminStudentRow(
    id = json.getInt("id"),
    nameEnglish = json.nullableText("name_eng"),
    nameBangla = json.nullableText("name_ban"),
    classNameEnglish = json.nullableText("class_name"),
    classNameBangla = json.nullableText("class_name_ban"),
    sectionEnglish = json.nullableText("section_name"),
    sectionBangla = json.nullableText("section_name_ban"),
    roll = json.nullableText("cls_roll"),
    session = json.nullableText("session_year"),
    mobile = json.nullableText("mobile"),
    status = json.getInt("status"),
)

private fun JSONObject.nullableText(key: String): String {
    if (!has(key)) throw JSONException("Missing expected student field: $key")
    return if (isNull(key)) "" else getString(key)
}

private fun JSONObject.displayName(): String =
    nullableText("name_ban").ifBlank { nullableText("name_eng") }.ifBlank { "শিক্ষার্থী" }

private fun AdminStudentRow.displayName(): String =
    nameBangla.ifBlank { nameEnglish }.ifBlank { "শিক্ষার্থী" }

private fun AdminStudentRow.classLabel(): String {
    val className = classNameBangla.ifBlank { classNameEnglish }
    val sectionName = sectionBangla.ifBlank { sectionEnglish }
    return listOf(className, sectionName).filter(String::isNotBlank).joinToString(" · ")
        .ifBlank { "শ্রেণি/শাখা উল্লেখ নেই" }
}

private fun statusFilterLabel(status: String): String = when (status) {
    "" -> "Default: admitted"
    "all" -> "All statuses"
    else -> "Status $status"
}
