package com.bmlhs.mobile

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
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

private enum class AcademicResource(
    val module: String,
    val title: String,
    val endpoint: String,
    val collection: String,
) {
    CLASSES("classes", "Classes", "admin/classes.php", "classes"),
    SECTIONS("sections", "Sections", "admin/sections.php", "sections"),
    SUBJECTS("subjects", "Subjects", "admin/subjects.php", "subjects"),
    EXAMS("exams", "Exams", "admin/exams.php", "exams"),
    PERIODS("periods", "Periods", "admin/periods.php", "periods"),
}

private data class AcademicOption(val id: Int, val label: String)

@Composable
internal fun AdminAcademic(api: ApiClient, token: String, allowedModules: Set<String>) {
    val scope = rememberCoroutineScope()
    val availableResources = AcademicResource.entries.filter { it.module in allowedModules }
    var resource by remember(allowedModules) {
        mutableStateOf(availableResources.firstOrNull() ?: AcademicResource.CLASSES)
    }
    var rows by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var rowsForResource by remember { mutableStateOf<AcademicResource?>(null) }
    var classes by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var sections by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var allSections by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var subjectSections by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var selectedClassId by remember { mutableStateOf(0) }
    var selectedSectionId by remember { mutableStateOf(0) }
    var editingId by remember { mutableStateOf<Int?>(null) }
    var formValues by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var formFlags by remember { mutableStateOf<Map<String, Boolean>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var deleteRow by remember { mutableStateOf<JSONObject?>(null) }
    var chooseDialog by remember { mutableStateOf<Pair<String, List<AcademicOption>>?>(null) }

    suspend fun fetch(
        resourceToLoad: AcademicResource,
        classId: Int = selectedClassId,
        sectionId: Int = selectedSectionId,
    ): JSONObject =
        withContext(Dispatchers.IO) {
            require(resourceToLoad.module in allowedModules) {
                "The ${resourceToLoad.module} module is not enabled for this screen."
            }
            val query = when (resourceToLoad) {
                AcademicResource.SECTIONS -> if (classId > 0) mapOf("class_id" to classId.toString()) else emptyMap()
                AcademicResource.SUBJECTS -> buildMap {
                    if (classId > 0) put("class_id", classId.toString())
                    if (sectionId > 0) put("section_id", sectionId.toString())
                }
                else -> emptyMap()
            }
            api.adminRequest(token, resourceToLoad.endpoint, query = query)
        }

    fun readRows(response: JSONObject, collection: String): List<JSONObject> {
        val array = response.optJSONObject("data")?.optJSONArray(collection) ?: JSONArray()
        return List(array.length()) { index -> array.getJSONObject(index) }
    }

    suspend fun reload(
        target: AcademicResource = resource,
        classId: Int = selectedClassId,
        sectionId: Int = selectedSectionId,
    ) {
        val response = fetch(target, classId, sectionId)
        val data = response.optJSONObject("data")
        rows = readRows(response, target.collection)
        rowsForResource = target
        if (target == AcademicResource.CLASSES) {
            classes = rows
            if (selectedClassId > 0 && classes.none { it.optInt("id") == selectedClassId }) selectedClassId = 0
        }
        if (target == AcademicResource.SECTIONS) {
            sections = rows
            if (classId == 0) allSections = rows
            if (selectedSectionId > 0 && sections.none { it.optInt("id") == selectedSectionId }) selectedSectionId = 0
        }
        if (target == AcademicResource.SUBJECTS) {
            data?.optJSONArray("classes")?.let { array ->
                classes = List(array.length()) { array.getJSONObject(it) }
            }
            data?.optJSONArray("sections")?.let { array ->
                subjectSections = List(array.length()) { array.getJSONObject(it) }
            }
        }
    }

    suspend fun refreshSubjectSectionOptions(classId: Int) {
        if ("subjects" !in allowedModules || classId <= 0) {
            subjectSections = emptyList()
            return
        }
        val response = fetch(AcademicResource.SUBJECTS, classId, 0)
        val data = response.optJSONObject("data")
        subjectSections = readRows(response, "sections")
        data?.optJSONArray("classes")?.let { array ->
            classes = List(array.length()) { array.getJSONObject(it) }
        }
    }

    fun clearForm() {
        editingId = null
        formValues = defaultAcademicValues(resource)
        formFlags = defaultAcademicFlags(resource)
    }

    fun beginEdit(row: JSONObject) {
        editingId = row.optInt("id")
        formValues = academicFormValues(resource, row)
        formFlags = academicFormFlags(resource, row)
    }

    LaunchedEffect(resource, token, allowedModules) {
        busy = true
        error = ""
        rows = emptyList()
        rowsForResource = null
        try {
            if (resource.module !in allowedModules) {
                return@LaunchedEffect
            }
            if (resource == AcademicResource.SECTIONS && "classes" in allowedModules && classes.isEmpty()) {
                val classResponse = fetch(AcademicResource.CLASSES)
                classes = readRows(classResponse, "classes")
            }
            reload(resource)
            clearForm()
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "একাডেমিক তথ্য সার্ভারে সংযোগ করা যায়নি।"
        } catch (exception: JSONException) {
            error = "একাডেমিক তথ্যের সার্ভার উত্তর পড়া যায়নি।"
        } catch (exception: IllegalArgumentException) {
            error = exception.message ?: "একাডেমিক তথ্য লোড করা যায়নি।"
        } finally {
            busy = false
        }
    }

    fun reportFailure(exception: Exception, operation: String) {
        error = when (exception) {
            is ApiException -> exception.message
            is IOException -> "$operation সার্ভারে সংযোগ করা যায়নি।"
            is JSONException -> "একাডেমিক তথ্যের সার্ভার উত্তর পড়া যায়নি।"
            else -> "$operation সম্পন্ন করা যায়নি।"
        }
    }

    fun perform(action: String, row: JSONObject? = null) {
        if (busy || resource.module !in allowedModules) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = if (action == "create" || action == "update") {
                    academicPayload(resource, action, editingId, formValues, formFlags)
                } else {
                    JSONObject().put("action", action).put("id", row?.optInt("id") ?: 0)
                }
                withContext(Dispatchers.IO) {
                    api.adminRequest(token, resource.endpoint, method = "POST", payload = payload)
                }
                if (action == "create" || action == "update") clearForm()
                reload(resource)
            } catch (exception: ApiException) {
                reportFailure(exception, "একাডেমিক পরিবর্তন")
            } catch (exception: IOException) {
                reportFailure(exception, "একাডেমিক পরিবর্তন")
            } catch (exception: JSONException) {
                reportFailure(exception, "একাডেমিক পরিবর্তন")
            } catch (exception: IllegalArgumentException) {
                reportFailure(exception, "একাডেমিক পরিবর্তন")
            } finally {
                busy = false
            }
        }
    }

    val formValid = isAcademicFormValid(resource, formValues)
    val resourceAllowed = resource.module in allowedModules
    val resourceDataReady = resourceAllowed && rowsForResource == resource
    val subjectClassOptions = classes.map {
        AcademicOption(it.optInt("id"), it.optString("class_name", "Class ${it.optInt("id")}"))
    }
    val sectionClassOptions = if ("classes" in allowedModules) subjectClassOptions else emptyList()
    val subjectSectionOptions = listOf(AcademicOption(0, "সাধারণ / সব section")) + subjectSections.map {
        AcademicOption(it.optInt("id"), it.optString("section_name", "Section ${it.optInt("id")}"))
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("একাডেমিক কাঠামো", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            if (availableResources.isEmpty()) {
                Text("এই স্ক্রিনে কোনো একাডেমিক মডিউল অনুমোদিত নয়।")
            }
            Row(
                modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                availableResources.forEach { tab ->
                    if (resource == tab) {
                        Button(enabled = !busy, onClick = {}) { Text(tab.title) }
                    } else {
                        OutlinedButton(enabled = !busy, onClick = {
                            resource = tab
                            rows = emptyList()
                            rowsForResource = null
                            busy = true
                            deleteRow = null
                            chooseDialog = null
                            clearForm()
                        }) { Text(tab.title) }
                    }
                }
            }

            if (resourceAllowed && ((resource == AcademicResource.SECTIONS && "classes" in allowedModules) ||
                    resource == AcademicResource.SUBJECTS)
            ) {
                PickerField(
                    label = "Class",
                    selected = classes.firstOrNull { it.optInt("id") == selectedClassId }
                        ?.let { it.optString("class_name", "Class $selectedClassId") } ?: "সব class",
                    options = listOf(AcademicOption(0, "সব class")) + subjectClassOptions,
                    enabled = !busy,
                    onChoose = { chooseDialog = "class-filter" to it },
                )
            }
            if (resourceAllowed && resource == AcademicResource.SUBJECTS) {
                PickerField(
                    label = "Section",
                    selected = subjectSectionOptions.firstOrNull { it.id == selectedSectionId }?.label ?: "সব section",
                    options = subjectSectionOptions,
                    enabled = !busy && selectedClassId > 0,
                    onChoose = { chooseDialog = "section-filter" to it },
                )
            }

            if (resourceAllowed) {
                Text(
                    if (editingId == null) "নতুন ${resource.title}" else "${resource.title} সম্পাদনা",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                )
                AcademicEditForm(
                    resource = resource,
                    values = formValues,
                    flags = formFlags,
                    busy = busy,
                    classOptions = if (resource == AcademicResource.SUBJECTS) subjectClassOptions else sectionClassOptions,
                    sectionOptions = if (resource == AcademicResource.SUBJECTS) subjectSections.map {
                        AcademicOption(it.optInt("id"), it.optString("section_name", "Section ${it.optInt("id")}"))
                    } else allSections.filter {
                        it.optInt("class_id") == (formValues["class_id"]?.toIntOrNull() ?: 0)
                    }.map {
                        AcademicOption(it.optInt("id"), it.optString("section_name", "Section ${it.optInt("id")}"))
                    },
                    onValueChange = { key, value -> formValues = formValues + (key to value) },
                    onFlagChange = { key, value -> formFlags = formFlags + (key to value) },
                    onSelectClass = {
                        chooseDialog = "form-class" to
                            if (resource == AcademicResource.SUBJECTS) subjectClassOptions else sectionClassOptions
                    },
                    onSelectSection = { options -> chooseDialog = "form-section" to options },
                    selectedClassId = formValues["class_id"]?.toIntOrNull() ?: 0,
                    selectedSectionId = formValues["section_id"]?.toIntOrNull() ?: 0,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        enabled = !busy && formValid,
                        onClick = { perform(if (editingId == null) "create" else "update") },
                    ) { Text(if (busy) "অপেক্ষা করুন…" else if (editingId == null) "যোগ করুন" else "আপডেট করুন") }
                    if (editingId != null) {
                        OutlinedButton(enabled = !busy, onClick = { clearForm() }) { Text("বাতিল") }
                    }
                }
            }

            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("${resource.title} তালিকা (${if (resourceDataReady) rows.size else 0})", fontWeight = FontWeight.SemiBold)
                OutlinedButton(enabled = !busy && resourceAllowed, onClick = {
                    busy = true
                    error = ""
                    scope.launch {
                        try {
                            reload(resource)
                        } catch (exception: ApiException) {
                            reportFailure(exception, "তালিকা লোড")
                        } catch (exception: IOException) {
                            reportFailure(exception, "তালিকা লোড")
                        } catch (exception: JSONException) {
                            reportFailure(exception, "তালিকা লোড")
                        } finally {
                            busy = false
                        }
                    }
                }) { Text("Refresh") }
            }
            if (resourceDataReady && rows.isEmpty() && !busy) {
                Text("কোনো ${resource.title.lowercase()} নেই।", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (resourceDataReady) rows.forEach { row ->
                AcademicRowCard(
                    resource = resource,
                    row = row,
                    classes = if (resource == AcademicResource.SECTIONS && "classes" !in allowedModules) emptyList() else classes,
                    sections = if (resource == AcademicResource.SUBJECTS) subjectSections else allSections,
                    busy = busy,
                    onEdit = { beginEdit(row) },
                    onToggle = { perform("toggle", row) },
                    onToggleAdmission = { perform("toggle_admission", row) },
                    onDelete = { deleteRow = row },
                )
            }
        }
    }

    chooseDialog?.takeIf { resourceAllowed }?.let { (purpose, options) ->
        AlertDialog(
            onDismissRequest = { chooseDialog = null },
            title = { Text(if (purpose.contains("class")) "Class নির্বাচন" else "Section নির্বাচন") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    options.forEach { option ->
                        TextButton(
                            onClick = {
                                when (purpose) {
                                    "class-filter" -> {
                                        selectedClassId = option.id
                                        selectedSectionId = 0
                                        busy = true
                                        scope.launch {
                                            try {
                                                reload(resource, option.id, 0)
                                                error = ""
                                            } catch (exception: ApiException) {
                                                reportFailure(exception, "তালিকা লোড")
                                            } catch (exception: IOException) {
                                                reportFailure(exception, "তালিকা লোড")
                                            } catch (exception: JSONException) {
                                                reportFailure(exception, "তালিকা লোড")
                                            } finally {
                                                busy = false
                                            }
                                        }
                                    }
                                    "section-filter" -> {
                                        selectedSectionId = option.id
                                        busy = true
                                        scope.launch {
                                            try {
                                                reload(resource, selectedClassId, option.id)
                                                error = ""
                                            } catch (exception: ApiException) {
                                                reportFailure(exception, "তালিকা লোড")
                                            } catch (exception: IOException) {
                                                reportFailure(exception, "তালিকা লোড")
                                            } catch (exception: JSONException) {
                                                reportFailure(exception, "তালিকা লোড")
                                            } finally {
                                                busy = false
                                            }
                                        }
                                    }
                                    "form-class" -> {
                                        formValues = formValues + ("class_id" to option.id.toString()) +
                                            ("section_id" to "0")
                                        if (resource == AcademicResource.SUBJECTS) {
                                            busy = true
                                            scope.launch {
                                                try {
                                                    refreshSubjectSectionOptions(option.id)
                                                } catch (exception: ApiException) {
                                                    reportFailure(exception, "Section তালিকা লোড")
                                                } catch (exception: IOException) {
                                                    reportFailure(exception, "Section তালিকা লোড")
                                                } catch (exception: JSONException) {
                                                    reportFailure(exception, "Section তালিকা লোড")
                                                } finally {
                                                    busy = false
                                                }
                                            }
                                        }
                                    }
                                    "form-section" -> formValues = formValues + ("section_id" to option.id.toString())
                                }
                                chooseDialog = null
                            },
                            modifier = Modifier.fillMaxWidth(),
                        ) { Text(option.label) }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { chooseDialog = null }) { Text("বন্ধ") } },
        )
    }

    deleteRow?.takeIf { resourceAllowed }?.let { row ->
        AlertDialog(
            onDismissRequest = { deleteRow = null },
            title = { Text("${resource.title} মুছবেন?") },
            text = { Text("“${academicRowTitle(resource, row)}” স্থায়ীভাবে মুছে যাবে। ব্যবহৃত record হলে server মুছতে বাধা দেবে।") },
            confirmButton = {
                Button(enabled = !busy, onClick = {
                    deleteRow = null
                    perform("delete", row)
                }) { Text("মুছুন") }
            },
            dismissButton = { OutlinedButton(onClick = { deleteRow = null }) { Text("বাতিল") } },
        )
    }
}

@Composable
private fun AcademicEditForm(
    resource: AcademicResource,
    values: Map<String, String>,
    flags: Map<String, Boolean>,
    busy: Boolean,
    classOptions: List<AcademicOption>,
    sectionOptions: List<AcademicOption>,
    onValueChange: (String, String) -> Unit,
    onFlagChange: (String, Boolean) -> Unit,
    onSelectClass: () -> Unit,
    onSelectSection: (List<AcademicOption>) -> Unit,
    selectedClassId: Int,
    selectedSectionId: Int,
) {
    when (resource) {
        AcademicResource.CLASSES -> {
            AcademicTextInput("class_name", "Class name (English)", values, busy, onValueChange, 100)
            AcademicTextInput("class_name_ban", "Class name (বাংলা)", values, busy, onValueChange, 100)
            AcademicTextInput("sort_order", "Sort order", values, busy, onValueChange, 9, number = true)
            AcademicFlag("is_active", "Active", flags, busy, onFlagChange)
            AcademicFlag("open_for_admission", "Admission open", flags, busy, onFlagChange)
        }
        AcademicResource.SECTIONS -> {
            if (classOptions.isNotEmpty()) {
                PickerField(
                    "Class",
                    classOptions.firstOrNull { it.id == selectedClassId }?.label ?: "নির্বাচন করুন",
                    classOptions,
                    !busy,
                    { onSelectClass() },
                )
            } else {
                AcademicTextInput("class_id", "Class ID", values, busy, onValueChange, 9, number = true)
            }
            AcademicTextInput("section_name", "Section name (English)", values, busy, onValueChange, 100)
            AcademicTextInput("section_name_ban", "Section name (বাংলা)", values, busy, onValueChange, 100)
            AcademicTextInput("sort_order", "Sort order", values, busy, onValueChange, 9, number = true)
            AcademicFlag("is_active", "Active", flags, busy, onFlagChange)
        }
        AcademicResource.SUBJECTS -> {
            PickerField(
                "Class",
                classOptions.firstOrNull { it.id == selectedClassId }?.label ?: "নির্বাচন করুন",
                classOptions,
                !busy,
                { onSelectClass() },
            )
            PickerField(
                "Section",
                (listOf(AcademicOption(0, "সাধারণ / সব section")) + sectionOptions).firstOrNull { it.id == selectedSectionId }?.label
                    ?: "সাধারণ / সব section",
                listOf(AcademicOption(0, "সাধারণ / সব section")) + sectionOptions,
                !busy && selectedClassId > 0,
                { onSelectSection(listOf(AcademicOption(0, "সাধারণ / সব section")) + sectionOptions) },
            )
            AcademicTextInput("subject_name", "Subject name (English)", values, busy, onValueChange, 100)
            AcademicTextInput("subject_name_ban", "Subject name (বাংলা)", values, busy, onValueChange, 100)
            AcademicTextInput("subject_code", "Subject code (optional)", values, busy, onValueChange, 32)
            AcademicTextInput("teacher_mobile", "Teacher mobile (optional)", values, busy, onValueChange, 32)
            AcademicTextInput("full_marks", "Full marks", values, busy, onValueChange, 9, number = true)
            AcademicTextInput("pass_marks", "Pass marks", values, busy, onValueChange, 9, number = true)
            AcademicTextInput("sort_order", "Sort order", values, busy, onValueChange, 9, number = true)
            AcademicFlag("has_mcq", "MCQ", flags, busy, onFlagChange)
            AcademicFlag("has_cq", "CQ", flags, busy, onFlagChange)
            AcademicFlag("has_practical", "Practical", flags, busy, onFlagChange)
            AcademicFlag("is_optional", "Optional subject", flags, busy, onFlagChange)
            AcademicFlag("is_active", "Active", flags, busy, onFlagChange)
        }
        AcademicResource.EXAMS -> {
            AcademicTextInput("exam_name_bangla", "Exam name (বাংলা)", values, busy, onValueChange, 100)
            AcademicTextInput("exam_name_english", "Exam name (English, optional)", values, busy, onValueChange, 100)
            AcademicTextInput("sort_order", "Sort order", values, busy, onValueChange, 9, number = true)
            AcademicFlag("is_active", "Active", flags, busy, onFlagChange)
        }
        AcademicResource.PERIODS -> {
            AcademicTextInput("period_no", "Period number", values, busy, onValueChange, 9, number = true)
            AcademicTextInput("period_time", "Period time", values, busy, onValueChange, 100)
            AcademicTextInput("period_label", "Period label (optional)", values, busy, onValueChange, 100)
            AcademicTextInput("sort_order", "Sort order", values, busy, onValueChange, 9, number = true)
            AcademicFlag("is_active", "Active", flags, busy, onFlagChange)
        }
    }
}

@Composable
private fun AcademicTextInput(
    key: String,
    label: String,
    values: Map<String, String>,
    busy: Boolean,
    onValueChange: (String, String) -> Unit,
    maxLength: Int,
    number: Boolean = false,
) {
    OutlinedTextField(
        value = values[key].orEmpty(),
        onValueChange = { incoming ->
            val value = if (number) incoming.filter(Char::isDigit).take(maxLength) else incoming.take(maxLength)
            onValueChange(key, value)
        },
        label = { Text(label) },
        enabled = !busy,
        singleLine = true,
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun AcademicFlag(
    key: String,
    label: String,
    flags: Map<String, Boolean>,
    busy: Boolean,
    onFlagChange: (String, Boolean) -> Unit,
) {
    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        TextButton(enabled = !busy, onClick = { onFlagChange(key, !(flags[key] ?: false)) }) {
            Text(if (flags[key] == true) "☑ $label" else "☐ $label")
        }
    }
}

@Composable
private fun PickerField(
    label: String,
    selected: String,
    options: List<AcademicOption>,
    enabled: Boolean,
    onChoose: (List<AcademicOption>) -> Unit,
) {
    OutlinedButton(enabled = enabled, onClick = { onChoose(options) }, modifier = Modifier.fillMaxWidth()) {
        Text("$label: $selected")
    }
}

@Composable
private fun AcademicRowCard(
    resource: AcademicResource,
    row: JSONObject,
    classes: List<JSONObject>,
    sections: List<JSONObject>,
    busy: Boolean,
    onEdit: () -> Unit,
    onToggle: () -> Unit,
    onToggleAdmission: () -> Unit,
    onDelete: () -> Unit,
) {
    val active = row.optBoolean("is_active", true)
    val className = classes.firstOrNull { it.optInt("id") == row.optInt("class_id") }
        ?.optString("class_name", "")?.takeIf(String::isNotBlank)
    val sectionName = sections.firstOrNull { it.optInt("id") == row.optInt("section_id") }
        ?.optString("section_name", "")?.takeIf(String::isNotBlank)
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(academicRowTitle(resource, row), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
            when (resource) {
                AcademicResource.CLASSES -> Text(
                    "${row.optString("class_name_ban")} · Sort ${row.optInt("sort_order")} · " +
                        if (row.optBoolean("open_for_admission")) "Admission open" else "Admission closed",
                )
                AcademicResource.SECTIONS -> Text(
                    "${className ?: "Class ${row.optInt("class_id")}"} · ${row.optString("section_name_ban")} · Sort ${row.optInt("sort_order")}",
                )
                AcademicResource.SUBJECTS -> Text(
                    "${className ?: "Class ${row.optInt("class_id")}"} · " +
                        "${sectionName ?: if (row.optInt("section_id") == 0) "সাধারণ" else "Section ${row.optInt("section_id")}"} · " +
                        "Full ${row.optInt("full_marks")} / Pass ${row.optInt("pass_marks")} · Sort ${row.optInt("sort_order")}",
                )
                AcademicResource.EXAMS -> Text(
                    "${row.optString("exam_name_bangla")} · Sort ${row.optInt("sort_order")}",
                )
                AcademicResource.PERIODS -> Text(
                    "${row.optString("period_time")} · ${row.optString("period_label")} · Sort ${row.optInt("sort_order")}",
                )
            }
            Text(
                if (active) "সক্রিয়" else "নিষ্ক্রিয়",
                color = if (active) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                OutlinedButton(enabled = !busy, onClick = onEdit) { Text("Edit") }
                OutlinedButton(enabled = !busy, onClick = onToggle) { Text(if (active) "নিষ্ক্রিয় করুন" else "সক্রিয় করুন") }
                if (resource == AcademicResource.CLASSES) {
                    OutlinedButton(enabled = !busy, onClick = onToggleAdmission) {
                        Text(if (row.optBoolean("open_for_admission")) "Admission বন্ধ" else "Admission খুলুন")
                    }
                }
                OutlinedButton(enabled = !busy, onClick = onDelete) { Text("মুছুন") }
            }
        }
    }
}

private fun academicRowTitle(resource: AcademicResource, row: JSONObject): String = when (resource) {
    AcademicResource.CLASSES -> row.optString("class_name", "Class ${row.optInt("id")}")
    AcademicResource.SECTIONS -> row.optString("section_name", "Section ${row.optInt("id")}")
    AcademicResource.SUBJECTS -> row.optString("subject_name", "Subject ${row.optInt("id")}")
    AcademicResource.EXAMS -> row.optString("exam_name_english").ifBlank {
        row.optString("exam_name_bangla", "Exam ${row.optInt("id")}")
    }
    AcademicResource.PERIODS -> row.optString("period_label").ifBlank { "Period ${row.optInt("period_no")}" }
}

private fun defaultAcademicValues(resource: AcademicResource): Map<String, String> = when (resource) {
    AcademicResource.CLASSES -> mapOf("class_name" to "", "class_name_ban" to "", "sort_order" to "0")
    AcademicResource.SECTIONS -> mapOf("class_id" to "", "section_name" to "", "section_name_ban" to "", "sort_order" to "0")
    AcademicResource.SUBJECTS -> mapOf(
        "class_id" to "", "section_id" to "0", "subject_name" to "", "subject_name_ban" to "",
        "subject_code" to "", "teacher_mobile" to "", "full_marks" to "100", "pass_marks" to "33", "sort_order" to "0",
    )
    AcademicResource.EXAMS -> mapOf("exam_name_bangla" to "", "exam_name_english" to "", "sort_order" to "0")
    AcademicResource.PERIODS -> mapOf(
        "period_no" to "", "period_time" to "", "period_label" to "", "sort_order" to "0",
    )
}

private fun defaultAcademicFlags(resource: AcademicResource): Map<String, Boolean> = when (resource) {
    AcademicResource.CLASSES -> mapOf("is_active" to true, "open_for_admission" to false)
    AcademicResource.SECTIONS, AcademicResource.EXAMS, AcademicResource.PERIODS -> mapOf("is_active" to true)
    AcademicResource.SUBJECTS -> mapOf(
        "has_mcq" to false, "has_cq" to false, "has_practical" to false, "is_optional" to false, "is_active" to true,
    )
}

private fun academicFormValues(resource: AcademicResource, row: JSONObject): Map<String, String> =
    defaultAcademicValues(resource).mapValues { (key, default) ->
        when (key) {
            "class_id", "section_id", "period_no", "sort_order", "full_marks", "pass_marks" ->
                row.optInt(key, default.toIntOrNull() ?: 0).toString()
            else -> row.optString(key, default)
        }
    }

private fun academicFormFlags(resource: AcademicResource, row: JSONObject): Map<String, Boolean> =
    defaultAcademicFlags(resource).mapValues { (key, default) -> row.optBoolean(key, default) }

private fun isAcademicFormValid(resource: AcademicResource, values: Map<String, String>): Boolean {
    fun text(key: String) = values[key].orEmpty()
    fun nonNegative(key: String) = text(key).toIntOrNull()?.let { it >= 0 } == true
    fun positive(key: String) = text(key).toIntOrNull()?.let { it > 0 } == true
    return when (resource) {
        AcademicResource.CLASSES -> text("class_name").isNotBlank() && text("class_name_ban").isNotBlank() &&
            text("class_name").length <= 100 && text("class_name_ban").length <= 100 && nonNegative("sort_order")
        AcademicResource.SECTIONS -> positive("class_id") && text("section_name").isNotBlank() &&
            text("section_name_ban").isNotBlank() && text("section_name").length <= 100 &&
            text("section_name_ban").length <= 100 && nonNegative("sort_order")
        AcademicResource.SUBJECTS -> positive("class_id") &&
            (text("section_id").toIntOrNull()?.let { it >= 0 } == true) &&
            text("subject_name").isNotBlank() && text("subject_name_ban").isNotBlank() &&
            text("subject_name").length <= 100 && text("subject_name_ban").length <= 100 &&
            text("subject_code").length <= 32 && text("teacher_mobile").length <= 32 &&
            positive("full_marks") && nonNegative("pass_marks") &&
            text("pass_marks").toIntOrNull()!! <= text("full_marks").toIntOrNull()!! && nonNegative("sort_order")
        AcademicResource.EXAMS -> text("exam_name_bangla").isNotBlank() &&
            text("exam_name_bangla").length <= 100 && text("exam_name_english").length <= 100 &&
            nonNegative("sort_order")
        AcademicResource.PERIODS -> positive("period_no") && text("period_time").isNotBlank() &&
            text("period_time").length <= 100 && text("period_label").length <= 100 && nonNegative("sort_order")
    }
}

private fun academicPayload(
    resource: AcademicResource,
    action: String,
    id: Int?,
    values: Map<String, String>,
    flags: Map<String, Boolean>,
): JSONObject {
    val payload = JSONObject().put("action", action)
    if (action == "update") payload.put("id", id ?: throw IllegalArgumentException("An edit ID is required."))
    fun text(key: String) = values[key].orEmpty().trim()
    fun number(key: String) = values[key]?.toIntOrNull()
        ?: throw IllegalArgumentException("$key must be an integer.")
    fun putFlag(key: String) { payload.put(key, flags[key] ?: false) }
    when (resource) {
        AcademicResource.CLASSES -> {
            payload.put("class_name", text("class_name"))
                .put("class_name_ban", text("class_name_ban"))
                .put("sort_order", number("sort_order"))
            putFlag("is_active")
            putFlag("open_for_admission")
        }
        AcademicResource.SECTIONS -> {
            payload.put("class_id", number("class_id"))
                .put("section_name", text("section_name"))
                .put("section_name_ban", text("section_name_ban"))
                .put("sort_order", number("sort_order"))
            putFlag("is_active")
        }
        AcademicResource.SUBJECTS -> {
            payload.put("class_id", number("class_id"))
                .put("section_id", number("section_id"))
                .put("subject_name", text("subject_name"))
                .put("subject_name_ban", text("subject_name_ban"))
                .put("subject_code", text("subject_code"))
                .put("teacher_mobile", text("teacher_mobile"))
                .put("full_marks", number("full_marks"))
                .put("pass_marks", number("pass_marks"))
                .put("sort_order", number("sort_order"))
            putFlag("has_mcq")
            putFlag("has_cq")
            putFlag("has_practical")
            putFlag("is_optional")
            putFlag("is_active")
        }
        AcademicResource.EXAMS -> {
            payload.put("exam_name_bangla", text("exam_name_bangla"))
                .put("exam_name_english", text("exam_name_english"))
                .put("sort_order", number("sort_order"))
            putFlag("is_active")
        }
        AcademicResource.PERIODS -> {
            payload.put("period_no", number("period_no"))
                .put("period_time", text("period_time"))
                .put("period_label", text("period_label"))
                .put("sort_order", number("sort_order"))
            putFlag("is_active")
        }
    }
    return payload
}
