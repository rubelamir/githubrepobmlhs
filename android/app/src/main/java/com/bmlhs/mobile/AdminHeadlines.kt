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

private data class AdminHeadline(
    val id: Int,
    val text: String,
    val createdAt: String,
    val isLive: Boolean,
)

@Composable
internal fun AdminHeadlines(api: ApiClient, token: String) {
    val scope = rememberCoroutineScope()
    var headlines by remember { mutableStateOf<List<AdminHeadline>>(emptyList()) }
    var text by remember { mutableStateOf("") }
    var error by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var deleteHeadline by remember { mutableStateOf<AdminHeadline?>(null) }

    suspend fun reload() {
        val response = withContext(Dispatchers.IO) { api.adminHeadlines(token) }
        headlines = parseAdminHeadlines(response.optJSONObject("data")?.optJSONArray("headlines") ?: JSONArray())
    }

    LaunchedEffect(token) {
        busy = true
        try {
            reload()
            error = ""
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "Headline সার্ভারে সংযোগ করা যায়নি।"
        } catch (exception: JSONException) {
            error = "Headline-এর সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    fun perform(action: String, headline: AdminHeadline? = null) {
        if (busy) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject().put("action", action)
                if (action == "create") payload.put("text", text.trim())
                headline?.let { payload.put("id", it.id) }
                withContext(Dispatchers.IO) { api.manageAdminHeadline(token, payload) }
                if (action == "create") text = ""
                reload()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "Headline সংরক্ষণে সার্ভার সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "Headline-এর সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Notice Headlines", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("এগুলো ওয়েবসাইটের header ticker-এ চলে।")
            OutlinedTextField(
                value = text,
                onValueChange = { text = it.take(500) },
                label = { Text("Headline (সর্বোচ্চ ৫০০ অক্ষর)") },
                enabled = !busy,
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Button(
                enabled = !busy && text.isNotBlank(),
                onClick = { perform("create") },
                modifier = Modifier.fillMaxWidth(),
            ) { Text(if (busy) "অপেক্ষা করুন…" else "Headline যোগ করুন") }
            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("সাম্প্রতিক ১০০টি headline", fontWeight = FontWeight.SemiBold)
                OutlinedButton(enabled = !busy, onClick = {
                    busy = true
                    error = ""
                    scope.launch {
                        try {
                            reload()
                        } catch (exception: ApiException) {
                            error = exception.message
                        } catch (exception: IOException) {
                            error = "Headline সার্ভারে সংযোগ করা যায়নি।"
                        } catch (exception: JSONException) {
                            error = "Headline-এর সার্ভার উত্তর পড়া যায়নি।"
                        } finally {
                            busy = false
                        }
                    }
                }) { Text("Refresh") }
            }
            if (headlines.isEmpty() && !busy) Text("কোনো headline নেই।")
            headlines.forEach { headline ->
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(headline.text, style = MaterialTheme.typography.bodyLarge)
                        Text(
                            "${if (headline.isLive) "Ticker-এ দৃশ্যমান" else "লুকানো"} · ${headline.createdAt}",
                            color = if (headline.isLive) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                            style = MaterialTheme.typography.bodySmall,
                        )
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(
                                enabled = !busy,
                                onClick = { perform("toggle", headline) },
                            ) { Text(if (headline.isLive) "লুকান" else "প্রকাশ করুন") }
                            OutlinedButton(
                                enabled = !busy,
                                onClick = { deleteHeadline = headline },
                            ) { Text("মুছুন") }
                        }
                    }
                }
            }
        }
    }

    deleteHeadline?.let { headline ->
        AlertDialog(
            onDismissRequest = { deleteHeadline = null },
            title = { Text("Headline মুছবেন?") },
            text = { Text("“${headline.text}” স্থায়ীভাবে মুছে যাবে।") },
            confirmButton = {
                Button(onClick = {
                    deleteHeadline = null
                    perform("delete", headline)
                }) { Text("মুছুন") }
            },
            dismissButton = {
                OutlinedButton(onClick = { deleteHeadline = null }) { Text("বাতিল") }
            },
        )
    }
}

private fun parseAdminHeadlines(rows: JSONArray): List<AdminHeadline> =
    (0 until rows.length()).mapNotNull { index ->
        rows.optJSONObject(index)?.let { row ->
            AdminHeadline(
                id = row.optInt("id"),
                text = row.optString("text"),
                createdAt = row.optString("created_at"),
                isLive = row.optBoolean("status"),
            )
        }
    }
