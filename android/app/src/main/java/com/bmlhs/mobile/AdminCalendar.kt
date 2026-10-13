package com.bmlhs.mobile

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
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
import java.time.LocalDate

private data class CalendarItem(
    val id: Int,
    val title: String,
    val startDate: String,
    val endDate: String,
    val type: String,
    val description: String,
    val isLive: Boolean,
)

@Composable
internal fun AdminCalendar(api: ApiClient, token: String) {
    val scope = rememberCoroutineScope()
    var items by remember { mutableStateOf<List<CalendarItem>>(emptyList()) }
    var editingItem by remember { mutableStateOf<CalendarItem?>(null) }
    var title by remember { mutableStateOf("") }
    var startDate by remember { mutableStateOf("") }
    var endDate by remember { mutableStateOf("") }
    var eventType by remember { mutableStateOf("event") }
    var description by remember { mutableStateOf("") }
    var error by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var deleteItem by remember { mutableStateOf<CalendarItem?>(null) }

    suspend fun reload() {
        val response = withContext(Dispatchers.IO) { api.adminCalendar(token) }
        items = parseCalendarItems(response.optJSONObject("data")?.optJSONArray("items") ?: JSONArray())
    }

    fun clearForm() {
        editingItem = null
        title = ""
        startDate = ""
        endDate = ""
        eventType = "event"
        description = ""
    }

    LaunchedEffect(token) {
        busy = true
        try {
            reload()
            error = ""
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "একাডেমিক ক্যালেন্ডারে সংযোগ করা যায়নি।"
        } catch (exception: JSONException) {
            error = "ক্যালেন্ডারের সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    fun perform(action: String, item: CalendarItem? = null) {
        if (busy) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject().put("action", action)
                if (action == "create" || action == "update") {
                    payload.put("title", title.trim())
                        .put("event_date", startDate)
                        .put("end_date", endDate)
                        .put("event_type", eventType)
                        .put("description", description.trim())
                    if (action == "update") payload.put("id", editingItem?.id)
                } else if (item != null) {
                    payload.put("id", item.id)
                }
                withContext(Dispatchers.IO) { api.manageAdminCalendar(token, payload) }
                if (action == "create" || action == "update") clearForm()
                reload()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "ক্যালেন্ডার সংরক্ষণে সার্ভার সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "ক্যালেন্ডারের সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    val formValid = title.isNotBlank() && title.length <= 255 &&
        isValidCalendarDate(startDate) &&
        (endDate.isBlank() || (isValidCalendarDate(endDate) && endDate >= startDate)) &&
        description.length <= 500

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("একাডেমিক ক্যালেন্ডার", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("ছুটি, পরীক্ষা ও অনুষ্ঠান Public calendar-এ প্রকাশ করুন।")
            OutlinedTextField(
                value = title,
                onValueChange = { title = it.take(255) },
                label = { Text("শিরোনাম") },
                enabled = !busy,
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Text("ধরন", fontWeight = FontWeight.SemiBold)
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("holiday" to "ছুটি", "exam" to "পরীক্ষা", "event" to "অনুষ্ঠান").forEach { (type, label) ->
                    if (eventType == type) {
                        Button(enabled = !busy, onClick = { eventType = type }) { Text(label) }
                    } else {
                        OutlinedButton(enabled = !busy, onClick = { eventType = type }) { Text(label) }
                    }
                }
            }
            OutlinedTextField(
                value = startDate,
                onValueChange = { startDate = it.take(10) },
                label = { Text("শুরুর তারিখ (YYYY-MM-DD)") },
                enabled = !busy,
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = endDate,
                onValueChange = { endDate = it.take(10) },
                label = { Text("শেষের তারিখ (ঐচ্ছিক)") },
                enabled = !busy,
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = description,
                onValueChange = { description = it.take(500) },
                label = { Text("বিবরণ (ঐচ্ছিক)") },
                enabled = !busy,
                minLines = 2,
                maxLines = 5,
                modifier = Modifier.fillMaxWidth(),
            )
            if (endDate.isNotBlank() && (!isValidCalendarDate(endDate) || endDate < startDate)) {
                Text("শেষের তারিখ শুরুর তারিখের সমান বা পরের হতে হবে।", color = MaterialTheme.colorScheme.error)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    enabled = !busy && formValid,
                    onClick = { perform(if (editingItem == null) "create" else "update") },
                ) {
                    Text(
                        when {
                            busy -> "অপেক্ষা করুন…"
                            editingItem == null -> "ক্যালেন্ডারে প্রকাশ করুন"
                            else -> "পরিবর্তন সংরক্ষণ করুন"
                        },
                    )
                }
                if (editingItem != null) {
                    OutlinedButton(enabled = !busy, onClick = ::clearForm) { Text("বাতিল") }
                }
            }
            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("সাম্প্রতিক ২০০টি আইটেম", fontWeight = FontWeight.SemiBold)
                OutlinedButton(enabled = !busy, onClick = {
                    busy = true
                    scope.launch {
                        try {
                            reload()
                            error = ""
                        } catch (exception: ApiException) {
                            error = exception.message
                        } catch (exception: IOException) {
                            error = "একাডেমিক ক্যালেন্ডারে সংযোগ করা যায়নি।"
                        } catch (exception: JSONException) {
                            error = "ক্যালেন্ডারের সার্ভার উত্তর পড়া যায়নি।"
                        } finally {
                            busy = false
                        }
                    }
                }) { Text("Refresh") }
            }
            if (items.isEmpty() && !busy) Text("ক্যালেন্ডারে কোনো আইটেম নেই।")
            items.forEach { item ->
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(item.title, fontWeight = FontWeight.SemiBold)
                        Text(
                            listOfNotNull(item.startDate, item.endDate.takeIf(String::isNotBlank))
                                .joinToString(" – ") + " · ${calendarTypeLabel(item.type)}",
                        )
                        if (item.description.isNotBlank()) Text(item.description)
                        Text(
                            if (item.isLive) "Public-এ প্রকাশিত" else "Public-এ লুকানো",
                            color = if (item.isLive) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                            style = MaterialTheme.typography.bodySmall,
                        )
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            OutlinedButton(
                                enabled = !busy,
                                onClick = {
                                    editingItem = item
                                    title = item.title
                                    startDate = item.startDate
                                    endDate = item.endDate
                                    eventType = item.type
                                    description = item.description
                                },
                            ) { Text("সম্পাদনা") }
                            OutlinedButton(
                                enabled = !busy,
                                onClick = { perform("toggle", item) },
                            ) { Text(if (item.isLive) "লুকান" else "প্রকাশ করুন") }
                            OutlinedButton(
                                enabled = !busy,
                                onClick = { deleteItem = item },
                            ) { Text("মুছুন") }
                        }
                    }
                }
            }
        }
    }

    deleteItem?.let { item ->
        AlertDialog(
            onDismissRequest = { deleteItem = null },
            title = { Text("ক্যালেন্ডার আইটেম মুছবেন?") },
            text = { Text("“${item.title}” Public calendar থেকে স্থায়ীভাবে মুছে যাবে।") },
            confirmButton = {
                Button(onClick = {
                    deleteItem = null
                    perform("delete", item)
                }) { Text("মুছুন") }
            },
            dismissButton = {
                OutlinedButton(onClick = { deleteItem = null }) { Text("বাতিল") }
            },
        )
    }
}

private fun parseCalendarItems(rows: JSONArray): List<CalendarItem> =
    (0 until rows.length()).mapNotNull { index ->
        rows.optJSONObject(index)?.let { row ->
            CalendarItem(
                id = row.optInt("id"),
                title = row.optString("title"),
                startDate = row.optString("event_date"),
                endDate = row.optString("end_date").takeIf { it != "null" }.orEmpty(),
                type = row.optString("event_type", "event"),
                description = row.optString("description").takeIf { it != "null" }.orEmpty(),
                isLive = row.optBoolean("status"),
            )
        }
    }

private fun isValidCalendarDate(value: String): Boolean =
    runCatching { LocalDate.parse(value).toString() == value }.getOrDefault(false)

private fun calendarTypeLabel(type: String): String =
    when (type) {
        "holiday" -> "ছুটি"
        "exam" -> "পরীক্ষা"
        else -> "অনুষ্ঠান"
    }
